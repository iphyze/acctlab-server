<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertWriteAccess($user, $account);

    $accountId = (int) $account['id'];
    $sourceReceiptId = filter_var($data['source_receipt_transaction_id'] ?? null, FILTER_VALIDATE_INT);
    if ($sourceReceiptId === false || $sourceReceiptId === null || $sourceReceiptId <= 0) {
        throw new InvalidArgumentException('Select the cash receipt from which the mutilated notes came.', 422);
    }
    $linkedDisbursementId = null;
    if (!empty($data['linked_disbursement_transaction_id'])) {
        $parsed = filter_var($data['linked_disbursement_transaction_id'], FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed <= 0) {
            throw new InvalidArgumentException('The linked disbursement is invalid.', 422);
        }
        $linkedDisbursementId = (int) $parsed;
    }

    $amount = cashParseAmount($data['amount'] ?? null, 'Mutilated cash amount');
    $discoveredDate = cashParseDate($data['date'] ?? $data['discovered_date'] ?? null, 'Discovery date');
    $accountingYear = cashAssertAccountingPeriod($user, $discoveredDate);
    $note = cashNullableText($data['note'] ?? $data['description'] ?? null, 500);
    $idempotencyKey = cashIdempotencyKey($data);

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $discoveredDate);
        cashAssertEntryDateAllowed($conn, $accountId, $discoveredDate);

        $existing = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($existing) {
            if (strtoupper((string) ($existing['transaction_type'] ?? '')) !== 'MUTILATED_CASH_SET_ASIDE') {
                throw new RuntimeException('This idempotency key has already been used for another cash transaction.', 409);
            }
            $findStmt = $conn->prepare('SELECT id FROM cash_mutilated_cash WHERE set_aside_transaction_id = ? LIMIT 1');
            if (!$findStmt) {
                throw new RuntimeException('Unable to read the existing mutilated cash record.', 500);
            }
            $existingId = (int) $existing['id'];
            $findStmt->bind_param('i', $existingId);
            $findStmt->execute();
            $row = $findStmt->get_result()->fetch_assoc();
            $findStmt->close();
            $record = $row ? cashFetchMutilatedCash($conn, (int) $row['id']) : null;
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This mutilated cash entry was already recorded.',
                'data' => [
                    'mutilated_cash' => $record,
                    'cash_balance' => cashGetBalance($conn, $accountId),
                    'usable_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $sourceStmt = $conn->prepare("SELECT * FROM cash_transactions
                                     WHERE id = ? AND account_id = ? FOR UPDATE");
        if (!$sourceStmt) {
            throw new RuntimeException('Unable to validate the selected cash receipt.', 500);
        }
        $sourceStmt->bind_param('ii', $sourceReceiptId, $accountId);
        $sourceStmt->execute();
        $source = $sourceStmt->get_result()->fetch_assoc();
        $sourceStmt->close();
        if (!$source
            || strtoupper((string) $source['status']) !== 'POSTED'
            || strtoupper((string) $source['direction']) !== 'IN'
            || !in_array(strtoupper((string) $source['transaction_type']), ['CASH_RECEIPT', 'OPENING_BALANCE', 'CASH_RETURN'], true)
        ) {
            throw new InvalidArgumentException('The selected cash receipt is not available as a mutilated-cash source.', 422);
        }
        if ((string) $source['transaction_date'] > $discoveredDate) {
            throw new InvalidArgumentException('The discovery date cannot be earlier than the selected cash receipt.', 422);
        }

        $allocatedStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS amount
                                        FROM cash_mutilated_cash
                                        WHERE account_id = ?
                                          AND status <> 'REVERSED'
                                          AND COALESCE(source_receipt_transaction_id,
                                              CASE WHEN linked_disbursement_transaction_id IS NULL THEN source_transaction_id ELSE NULL END) = ?
                                        FOR UPDATE");
        if (!$allocatedStmt) {
            throw new RuntimeException('Unable to verify the remaining receipt amount.', 500);
        }
        $allocatedStmt->bind_param('ii', $accountId, $sourceReceiptId);
        $allocatedStmt->execute();
        $allocated = round((float) ($allocatedStmt->get_result()->fetch_assoc()['amount'] ?? 0), 2);
        $allocatedStmt->close();
        $remaining = round((float) $source['amount'] - $allocated, 2);
        if ($amount > $remaining) {
            throw new RuntimeException(
                'The selected receipt has only NGN ' . number_format(max(0, $remaining), 2, '.', ',') . ' remaining for mutilated-cash classification.',
                409
            );
        }

        $usableBefore = cashGetUsableBalance($conn, $accountId);
        if ((int) $account['allow_negative_balance'] !== 1 && $amount > $usableBefore) {
            throw new RuntimeException(
                'Only NGN ' . number_format(max(0, $usableBefore), 2, '.', ',')
                . ' is currently available as usable cash. A larger mutilated-cash classification would make usable cash negative.',
                409
            );
        }

        $linkedDisbursement = null;
        if ($linkedDisbursementId !== null) {
            $linkedStmt = $conn->prepare("SELECT id, transaction_reference, transaction_date, person_name, amount
                                         FROM cash_transactions
                                         WHERE id = ? AND account_id = ? AND status = 'POSTED'
                                           AND direction = 'OUT'
                                           AND transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT')
                                         LIMIT 1");
            if (!$linkedStmt) {
                throw new RuntimeException('Unable to validate the linked disbursement.', 500);
            }
            $linkedStmt->bind_param('ii', $linkedDisbursementId, $accountId);
            $linkedStmt->execute();
            $linkedDisbursement = $linkedStmt->get_result()->fetch_assoc();
            $linkedStmt->close();
            if (!$linkedDisbursement) {
                throw new InvalidArgumentException('The linked disbursement is unavailable.', 422);
            }
            if ((string) $linkedDisbursement['transaction_date'] > $discoveredDate) {
                throw new InvalidArgumentException('The discovery date cannot be earlier than the linked disbursement.', 422);
            }
        }

        $reference = cashGenerateReference($conn, 'CASH-MUT', $discoveredDate);
        $reason = 'Mutilated cash reclassified from ' . (string) $source['transaction_reference'];
        $classificationTransactionId = cashInsertTransaction($conn, [
            'account_id' => $accountId,
            'transaction_reference' => $reference,
            'transaction_date' => $discoveredDate,
            'transaction_type' => 'MUTILATED_CASH_SET_ASIDE',
            'direction' => 'OUT',
            'person_name' => 'Mutilated cash reserve',
            'amount' => $amount,
            'reason' => $reason,
            'description' => $note,
            'category_id' => null,
            'external_reference' => (string) $source['transaction_reference'],
            'disbursement_type' => 'MUTILATED',
            'receipt_status' => 'NOT_REQUIRED',
            'idempotency_key' => $idempotencyKey,
            'accounting_year' => $accountingYear,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
            'metadata' => [
                'entry_source' => 'cash_desk_mutilated_cash_reclassification',
                'source_receipt_transaction_id' => (int) $sourceReceiptId,
                'linked_disbursement_transaction_id' => $linkedDisbursementId,
                'affects_cash_balance' => false,
                'client_ip' => clientIpAddress(),
            ],
        ]);

        $insertStmt = $conn->prepare("INSERT INTO cash_mutilated_cash (
                account_id,
                source_transaction_id,
                source_receipt_transaction_id,
                linked_disbursement_transaction_id,
                set_aside_transaction_id,
                amount,
                discovered_date,
                note,
                status,
                accounting_year,
                discovered_by_user_id,
                discovered_by_email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_RETURN', ?, ?, ?)");
        if (!$insertStmt) {
            throw new RuntimeException('Unable to prepare the mutilated cash register entry.', 500);
        }
        $createdByUserId = (int) $user['id'];
        $createdByEmail = (string) $user['email'];
        $insertStmt->bind_param(
            'iiiiidssiis',
            $accountId,
            $sourceReceiptId,
            $sourceReceiptId,
            $linkedDisbursementId,
            $classificationTransactionId,
            $amount,
            $discoveredDate,
            $note,
            $accountingYear,
            $createdByUserId,
            $createdByEmail
        );
        if (!$insertStmt->execute()) {
            $message = $insertStmt->error;
            $insertStmt->close();
            throw new RuntimeException('Unable to register mutilated cash: ' . $message, 500);
        }
        $recordId = (int) $insertStmt->insert_id;
        $insertStmt->close();

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s classified NGN %s as mutilated cash from receipt %s%s.',
                $user['email'],
                number_format($amount, 2, '.', ','),
                $source['transaction_reference'],
                $linkedDisbursement ? ' while processing ' . $linkedDisbursement['transaction_reference'] : ''
            )
        );

        $record = cashFetchMutilatedCash($conn, $recordId);
        $cashBalance = cashGetBalance($conn, $accountId);
        $usableBalance = cashGetUsableBalance($conn, $accountId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'Mutilated cash has been recorded separately without changing the cashbook balance.',
            'data' => [
                'mutilated_cash' => $record,
                'classification_transaction' => cashFetchTransaction($conn, $classificationTransactionId),
                'cash_balance' => $cashBalance,
                'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, $accountId),
                'usable_balance' => $usableBalance,
                'idempotent_replay' => false,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to record mutilated cash.');
}
