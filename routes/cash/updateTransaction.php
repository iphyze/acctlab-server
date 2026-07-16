<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    cashAssertAdmin($user, 'Only an Admin or Super Admin can correct a Cash Desk entry.');
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    $accountId = (int) $account['id'];
    $transactionId = cashTransactionId($data);
    $correctionReason = cashRequiredText($data, 'correction_reason', 'Correction reason', 1000);

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        $lockStmt = $conn->prepare('SELECT * FROM cash_transactions WHERE id = ? AND account_id = ? FOR UPDATE');
        if (!$lockStmt) {
            throw new RuntimeException('Unable to lock the cash transaction.', 500);
        }
        $lockStmt->bind_param('ii', $transactionId, $accountId);
        $lockStmt->execute();
        $original = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if (!$original) {
            throw new RuntimeException('Cash transaction not found.', 404);
        }
        if (strtoupper((string) $original['status']) !== 'POSTED') {
            throw new RuntimeException('Only posted cash entries can be corrected.', 409);
        }

        $type = strtoupper((string) $original['transaction_type']);
        $editableTypes = [
            'CASH_RECEIPT',
            'OPENING_BALANCE',
            'DIRECT_DISBURSEMENT',
            'IOU_DISBURSEMENT',
            'CASH_RETURN',
            'REIMBURSEMENT',
        ];
        if (!in_array($type, $editableTypes, true)) {
            throw new RuntimeException('This system-generated Cash Desk entry cannot be edited directly.', 409);
        }

        $reversalStmt = $conn->prepare('SELECT id FROM cash_transactions WHERE reversal_of_transaction_id = ? LIMIT 1');
        if (!$reversalStmt) {
            throw new RuntimeException('Unable to verify the transaction reversal state.', 500);
        }
        $reversalStmt->bind_param('i', $transactionId);
        $reversalStmt->execute();
        $hasReversal = (bool) $reversalStmt->get_result()->fetch_assoc();
        $reversalStmt->close();
        if ($hasReversal) {
            throw new RuntimeException('A reversed transaction cannot be edited.', 409);
        }

        cashAssertDateIsOpen($conn, $accountId, (string) $original['transaction_date']);

        $isLinkedSettlement = in_array($type, ['CASH_RETURN', 'REIMBURSEMENT'], true);
        $transactionDate = $isLinkedSettlement
            ? (string) $original['transaction_date']
            : cashParseDate($data['transaction_date'] ?? $data['date'] ?? $original['transaction_date'], 'Transaction date');
        $accountingYear = cashAssertAccountingPeriod($user, $transactionDate);
        cashAssertDateIsOpen($conn, $accountId, $transactionDate);
        if ($transactionDate !== (string) $original['transaction_date']) {
            cashAssertEntryDateAllowed($conn, $accountId, $transactionDate);
        }

        $linkedDiscoveryStmt = $conn->prepare("SELECT MIN(discovered_date) AS earliest_discovery_date
                                              FROM cash_mutilated_cash
                                              WHERE account_id = ?
                                                AND status <> 'REVERSED'
                                                AND (
                                                    source_receipt_transaction_id = ?
                                                    OR linked_disbursement_transaction_id = ?
                                                    OR (source_receipt_transaction_id IS NULL AND source_transaction_id = ?)
                                                )");
        if (!$linkedDiscoveryStmt) {
            throw new RuntimeException('Unable to verify mutilated cash linked to this entry.', 500);
        }
        $linkedDiscoveryStmt->bind_param('iiii', $accountId, $transactionId, $transactionId, $transactionId);
        $linkedDiscoveryStmt->execute();
        $linkedDiscoveryDate = $linkedDiscoveryStmt->get_result()->fetch_assoc()['earliest_discovery_date'] ?? null;
        $linkedDiscoveryStmt->close();
        if ($linkedDiscoveryDate !== null && $transactionDate > (string) $linkedDiscoveryDate) {
            throw new RuntimeException(
                'This entry is linked to mutilated cash discovered on ' . (string) $linkedDiscoveryDate
                . ', so its transaction date cannot be moved after that date.',
                409
            );
        }

        $personName = cashRequiredText(
            ['person_name' => $data['person_name'] ?? $data['name'] ?? $original['person_name']],
            'person_name',
            $type === 'CASH_RECEIPT' ? 'Cash source' : 'Person or source',
            160
        );
        $amount = $isLinkedSettlement
            ? round((float) $original['amount'], 2)
            : cashParseAmount($data['amount'] ?? $original['amount'], 'Amount');
        $reasonInput = array_key_exists('reason', $data) ? $data['reason'] : $original['reason'];
        $descriptionInput = array_key_exists('description', $data) ? $data['description'] : $original['description'];
        $externalReferenceInput = array_key_exists('external_reference', $data)
            ? $data['external_reference']
            : (array_key_exists('reference', $data) ? $data['reference'] : $original['external_reference']);
        $reason = cashNullableText($reasonInput, 255);
        $description = cashNullableText($descriptionInput, 5000);
        $externalReference = cashNullableText($externalReferenceInput, 120);

        $categoryId = null;
        if (in_array($type, ['DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT'], true)) {
            if (array_key_exists('category_id', $data)) {
                $categoryId = $data['category_id'] === null || $data['category_id'] === ''
                    ? null
                    : (int) $data['category_id'];
            } else {
                $categoryId = $original['category_id'] !== null ? (int) $original['category_id'] : null;
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
        }

        $receiptStatus = strtoupper(trim((string) ($data['receipt_status'] ?? $original['receipt_status'] ?? 'NOT_REQUIRED')));
        if (!in_array($receiptStatus, ['PENDING', 'PARTIAL', 'RECEIVED', 'NOT_REQUIRED'], true)) {
            throw new InvalidArgumentException('The receipt status is invalid.', 422);
        }
        if (!in_array($type, ['DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT'], true)) {
            $receiptStatus = (string) ($original['receipt_status'] ?? 'NOT_REQUIRED');
        }
        $settings = cashGetSettings($conn, $accountId);
        if ($type === 'DIRECT_DISBURSEMENT'
            && (bool) ($settings['require_receipt_for_direct_expense'] ?? false)
            && $receiptStatus === 'NOT_REQUIRED'
        ) {
            throw new InvalidArgumentException('This account requires supporting documentation for direct expenses.', 422);
        }

        $sourceIou = null;
        if ($type === 'IOU_DISBURSEMENT') {
            $iouStmt = $conn->prepare('SELECT * FROM cash_ious WHERE source_transaction_id = ? AND account_id = ? FOR UPDATE');
            if (!$iouStmt) {
                throw new RuntimeException('Unable to lock the linked IOU.', 500);
            }
            $iouStmt->bind_param('ii', $transactionId, $accountId);
            $iouStmt->execute();
            $sourceIou = $iouStmt->get_result()->fetch_assoc();
            $iouStmt->close();
            if (!$sourceIou) {
                throw new RuntimeException('The linked IOU could not be found.', 409);
            }

            $expectedRetirementDate = !empty($data['expected_retirement_date'])
                ? cashParseIsoDate($data['expected_retirement_date'], 'Expected retirement date', true)
                : (string) $sourceIou['expected_retirement_date'];
            if ($expectedRetirementDate < $transactionDate) {
                throw new InvalidArgumentException('Expected retirement date cannot be earlier than the transaction date.', 422);
            }

            $amountChanged = abs($amount - (float) $original['amount']) >= 0.01;
            if ($amountChanged) {
                $activityStmt = $conn->prepare("SELECT
                        (SELECT COUNT(*) FROM cash_iou_actions WHERE iou_id = ?) AS action_count,
                        (SELECT COUNT(*) FROM cash_receipts WHERE iou_id = ? AND status = 'ACTIVE') AS receipt_count");
                if (!$activityStmt) {
                    throw new RuntimeException('Unable to verify linked IOU activity.', 500);
                }
                $iouId = (int) $sourceIou['id'];
                $activityStmt->bind_param('ii', $iouId, $iouId);
                $activityStmt->execute();
                $activity = $activityStmt->get_result()->fetch_assoc() ?: [];
                $activityStmt->close();
                if (strtoupper((string) $sourceIou['status']) !== 'OPEN'
                    || (int) ($activity['action_count'] ?? 0) > 0
                    || (int) ($activity['receipt_count'] ?? 0) > 0
                ) {
                    throw new RuntimeException('The IOU amount cannot be changed after retirement or receipt activity has started. Reverse and repost it instead.', 409);
                }
            }
        } else {
            $expectedRetirementDate = null;
        }

        if (strtoupper((string) $original['direction']) === 'IN') {
            $allocatedStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS amount
                                            FROM cash_mutilated_cash
                                            WHERE account_id = ?
                                              AND status <> 'REVERSED'
                                              AND COALESCE(source_receipt_transaction_id,
                                                  CASE WHEN linked_disbursement_transaction_id IS NULL THEN source_transaction_id ELSE NULL END) = ?");
            if (!$allocatedStmt) {
                throw new RuntimeException('Unable to verify mutilated cash linked to this receipt.', 500);
            }
            $allocatedStmt->bind_param('ii', $accountId, $transactionId);
            $allocatedStmt->execute();
            $allocatedMutilated = round((float) ($allocatedStmt->get_result()->fetch_assoc()['amount'] ?? 0), 2);
            $allocatedStmt->close();
            if ($amount < $allocatedMutilated) {
                throw new RuntimeException(
                    'This receipt already has NGN ' . number_format($allocatedMutilated, 2, '.', ',') . ' classified as mutilated cash, so its amount cannot be reduced below that value.',
                    409
                );
            }
        }

        $oldSigned = cashTransactionAffectsBalance($type)
            ? (strtoupper((string) $original['direction']) === 'IN' ? (float) $original['amount'] : -(float) $original['amount'])
            : 0.0;
        $newSigned = cashTransactionAffectsBalance($type)
            ? (strtoupper((string) $original['direction']) === 'IN' ? $amount : -$amount)
            : 0.0;
        $usableBefore = cashGetUsableBalance($conn, $accountId);
        $projectedUsable = round($usableBefore + ($newSigned - $oldSigned), 2);
        if ((int) $account['allow_negative_balance'] !== 1 && $projectedUsable < 0) {
            throw new RuntimeException(
                'This correction would make usable cash negative. Current usable balance is NGN ' . number_format($usableBefore, 2, '.', ',') . '.',
                409
            );
        }

        $oldValues = [
            'transaction_date' => $original['transaction_date'],
            'person_name' => $original['person_name'],
            'amount' => round((float) $original['amount'], 2),
            'reason' => $original['reason'],
            'description' => $original['description'],
            'category_id' => $original['category_id'] !== null ? (int) $original['category_id'] : null,
            'external_reference' => $original['external_reference'],
            'receipt_status' => $original['receipt_status'],
            'expected_retirement_date' => $sourceIou['expected_retirement_date'] ?? null,
        ];
        $newValues = [
            'transaction_date' => $transactionDate,
            'person_name' => $personName,
            'amount' => $amount,
            'reason' => $reason,
            'description' => $description,
            'category_id' => $categoryId,
            'external_reference' => $externalReference,
            'receipt_status' => $receiptStatus,
            'expected_retirement_date' => $expectedRetirementDate,
        ];

        $updateStmt = $conn->prepare("UPDATE cash_transactions
                                     SET transaction_date = ?, person_name = ?, amount = ?, reason = ?, description = ?,
                                         category_id = ?, external_reference = ?, receipt_status = ?, accounting_year = ?
                                     WHERE id = ? AND account_id = ?");
        if (!$updateStmt) {
            throw new RuntimeException('Unable to prepare the cash transaction correction.', 500);
        }
        $updateStmt->bind_param(
            'ssdssissiii',
            $transactionDate,
            $personName,
            $amount,
            $reason,
            $description,
            $categoryId,
            $externalReference,
            $receiptStatus,
            $accountingYear,
            $transactionId,
            $accountId
        );
        if (!$updateStmt->execute()) {
            $message = $updateStmt->error;
            $updateStmt->close();
            throw new RuntimeException('Unable to update the cash transaction: ' . $message, 500);
        }
        $updateStmt->close();

        if ($sourceIou) {
            $iouId = (int) $sourceIou['id'];
            $newOutstanding = abs($amount - (float) $original['amount']) >= 0.01
                ? $amount
                : round((float) $sourceIou['outstanding_amount'], 2);
            $iouUpdateStmt = $conn->prepare("UPDATE cash_ious
                                            SET recipient_name = ?, amount_advanced = ?, outstanding_amount = ?,
                                                reason = ?, description = ?, expected_retirement_date = ?, receipt_status = ?
                                            WHERE id = ? AND account_id = ?");
            if (!$iouUpdateStmt) {
                throw new RuntimeException('Unable to prepare the linked IOU correction.', 500);
            }
            $iouUpdateStmt->bind_param(
                'sddssssii',
                $personName,
                $amount,
                $newOutstanding,
                $reason,
                $description,
                $expectedRetirementDate,
                $receiptStatus,
                $iouId,
                $accountId
            );
            $iouUpdateStmt->execute();
            $iouUpdateStmt->close();
        }

        $oldJson = json_encode($oldValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $newJson = json_encode($newValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($oldJson === false || $newJson === false) {
            throw new RuntimeException('Unable to prepare the correction audit history.', 500);
        }
        $historyStmt = $conn->prepare("INSERT INTO cash_transaction_edits (
                account_id, transaction_id, correction_reason, old_values, new_values,
                edited_by_user_id, edited_by_email
            ) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$historyStmt) {
            throw new RuntimeException('Unable to prepare the correction audit history.', 500);
        }
        $editedByUserId = (int) $user['id'];
        $editedByEmail = (string) $user['email'];
        $historyStmt->bind_param(
            'iisssis',
            $accountId,
            $transactionId,
            $correctionReason,
            $oldJson,
            $newJson,
            $editedByUserId,
            $editedByEmail
        );
        $historyStmt->execute();
        $historyStmt->close();

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s corrected Cash Desk transaction %s. Reason: %s',
                $user['email'],
                $original['transaction_reference'],
                $correctionReason
            )
        );

        $transaction = cashFetchTransaction($conn, $transactionId);
        $editHistory = cashFetchTransactionEditHistory($conn, $transactionId);
        $cashBalance = cashGetBalance($conn, $accountId);
        $usableBalance = cashGetUsableBalance($conn, $accountId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'Cash entry corrected successfully. The original values remain in the audit history.',
            'data' => [
                'transaction' => $transaction,
                'edit_history' => $editHistory,
                'cash_balance' => $cashBalance,
                'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, $accountId),
                'available_balance' => $usableBalance,
            ],
        ]);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to correct the cash entry.');
}
