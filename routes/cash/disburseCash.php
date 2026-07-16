<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertWriteAccess($user, $account);

    $transactionDate = cashParseDate($data['date'] ?? $data['transaction_date'] ?? null, 'Disbursement date');
    $accountingYear = cashAssertAccountingPeriod($user, $transactionDate);
    $recipient = cashRequiredText($data, 'name', 'Recipient name', 160);
    $amount = cashParseAmount($data['amount'] ?? null, 'Amount disbursed');
    $reason = cashNullableText($data['reason'] ?? null, 255);
    $description = cashNullableText($data['description'] ?? null, 5000);
    $externalReference = cashNullableText($data['reference'] ?? $data['external_reference'] ?? null, 120);
    $idempotencyKey = cashIdempotencyKey($data);
    $accountId = (int) $account['id'];

    $disbursementType = strtoupper(trim((string) ($data['disbursement_type'] ?? $data['type'] ?? 'DIRECT')));
    if (!in_array($disbursementType, ['DIRECT', 'IOU'], true)) {
        throw new InvalidArgumentException('Disbursement type must be DIRECT or IOU.', 422);
    }

    $categoryId = isset($data['category_id']) && $data['category_id'] !== ''
        ? (int) $data['category_id']
        : null;
    if ($categoryId !== null && $categoryId <= 0) {
        throw new InvalidArgumentException('category_id must be a positive integer.', 422);
    }

    $receiptStatus = strtoupper(trim((string) ($data['receipt_status'] ?? 'PENDING')));
    if (!in_array($receiptStatus, ['PENDING', 'NOT_REQUIRED'], true)) {
        throw new InvalidArgumentException('receipt_status must be PENDING or NOT_REQUIRED until a supporting receipt is uploaded.', 422);
    }

    $settings = cashGetSettings($conn, $accountId);
    if (
        $disbursementType === 'DIRECT'
        && (bool) ($settings['require_receipt_for_direct_expense'] ?? false)
        && $receiptStatus === 'NOT_REQUIRED'
    ) {
        throw new InvalidArgumentException('A direct expense cannot be marked as not requiring a receipt for this Cash Desk account.', 422);
    }

    $expectedRetirementDate = null;
    if ($disbursementType === 'IOU') {
        if (!empty($data['expected_retirement_date'])) {
            $expectedRetirementDate = cashParseIsoDate($data['expected_retirement_date'], 'Expected retirement date', true);
        } else {
            $expectedRetirementDate = (new DateTimeImmutable($transactionDate))
                ->modify('+' . max(1, (int) ($settings['default_iou_due_days'] ?? 7)) . ' days')
                ->format('Y-m-d');
        }

        if ($expectedRetirementDate < $transactionDate) {
            throw new InvalidArgumentException('Expected retirement date cannot be earlier than the disbursement date.', 422);
        }
        $receiptStatus = 'PENDING';
    }

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $transactionDate);
        cashAssertEntryDateAllowed($conn, $accountId, $transactionDate);

        $existing = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($existing) {
            $transaction = cashFetchTransaction($conn, (int) $existing['id']);
            $balance = cashGetUsableBalance($conn, $accountId);
            $conn->commit();

            jsonResponse([
                'status' => 'Success',
                'message' => 'This cash disbursement was already posted.',
                'data' => [
                    'transaction' => $transaction,
                    'available_balance' => $balance,
                    'idempotent_replay' => true,
                ],
            ]);
        }

        if ($categoryId !== null) {
            $categoryStmt = $conn->prepare('SELECT id FROM cash_categories WHERE id = ? AND is_active = 1 LIMIT 1');
            if (!$categoryStmt) {
                throw new RuntimeException('Unable to validate the expense category.', 500);
            }
            $categoryStmt->bind_param('i', $categoryId);
            $categoryStmt->execute();
            $category = $categoryStmt->get_result()->fetch_assoc();
            $categoryStmt->close();
            if (!$category) {
                throw new InvalidArgumentException('The selected expense category is unavailable.', 422);
            }
        }

        $balanceBefore = cashGetUsableBalance($conn, $accountId);
        if ($account['allow_negative_balance'] !== 1 && $amount > $balanceBefore) {
            throw new RuntimeException(
                'Insufficient usable cash balance. The payment requires NGN '
                . number_format($amount, 2, '.', ',')
                . ', while the usable balance is NGN '
                . number_format($balanceBefore, 2, '.', ',')
                . '.',
                409
            );
        }

        $possibleDuplicate = cashFindPossibleDuplicate($conn, $accountId, $transactionDate, $recipient, $amount, 'OUT');
        $transactionType = $disbursementType === 'IOU' ? 'IOU_DISBURSEMENT' : 'DIRECT_DISBURSEMENT';
        $reference = cashGenerateReference($conn, $disbursementType === 'IOU' ? 'CASH-IOU' : 'CASH-OUT', $transactionDate);
        $transactionId = cashInsertTransaction($conn, [
            'account_id' => $accountId,
            'transaction_reference' => $reference,
            'transaction_date' => $transactionDate,
            'transaction_type' => $transactionType,
            'direction' => 'OUT',
            'person_name' => $recipient,
            'amount' => $amount,
            'reason' => $reason,
            'description' => $description,
            'category_id' => $categoryId,
            'external_reference' => $externalReference,
            'disbursement_type' => $disbursementType,
            'receipt_status' => $receiptStatus,
            'idempotency_key' => $idempotencyKey,
            'accounting_year' => $accountingYear,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
            'metadata' => [
                'entry_source' => 'cash_desk',
                'client_ip' => clientIpAddress(),
            ],
        ]);

        $iouId = null;
        if ($disbursementType === 'IOU') {
            $iouReference = cashGenerateIouReference($conn, $transactionDate);
            $iouStmt = $conn->prepare("INSERT INTO cash_ious (
                    account_id,
                    source_transaction_id,
                    iou_reference,
                    recipient_name,
                    amount_advanced,
                    outstanding_amount,
                    reason,
                    description,
                    expected_retirement_date,
                    status,
                    receipt_status,
                    accounting_year,
                    created_by_user_id,
                    created_by_email
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'OPEN', 'PENDING', ?, ?, ?)");
            if (!$iouStmt) {
                throw new RuntimeException('Unable to create the IOU record.', 500);
            }

            $createdByUserId = (int) $user['id'];
            $createdByEmail = (string) $user['email'];
            $iouStmt->bind_param(
                'iissddsssiis',
                $accountId,
                $transactionId,
                $iouReference,
                $recipient,
                $amount,
                $amount,
                $reason,
                $description,
                $expectedRetirementDate,
                $accountingYear,
                $createdByUserId,
                $createdByEmail
            );
            if (!$iouStmt->execute()) {
                $message = $iouStmt->error;
                $iouStmt->close();
                throw new RuntimeException('Unable to create the IOU record: ' . $message, 500);
            }
            $iouId = (int) $iouStmt->insert_id;
            $iouStmt->close();
        }

        $balanceAfter = cashGetUsableBalance($conn, $accountId);
        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s posted %s cash disbursement of NGN %s to %s from %s (%s).',
                $user['email'],
                $disbursementType,
                number_format($amount, 2, '.', ','),
                $recipient,
                $account['account_name'],
                $reference
            )
        );

        $transaction = cashFetchTransaction($conn, $transactionId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => $disbursementType === 'IOU'
                ? 'Cash IOU has been posted successfully.'
                : 'Cash disbursement has been posted successfully.',
            'data' => [
                'transaction' => $transaction,
                'iou_id' => $iouId,
                'balance_before' => $balanceBefore,
                'available_balance' => $balanceAfter,
                'idempotent_replay' => false,
                'possible_duplicate' => $possibleDuplicate,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to post the cash disbursement.');
}
