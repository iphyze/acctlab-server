<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertManageAccess($user, $account);

    $transactionId = cashTransactionId($data);
    $reversalDate = cashParseDate($data['date'] ?? $data['reversal_date'] ?? null, 'Reversal date');
    $accountingYear = cashAssertAccountingPeriod($user, $reversalDate);
    $reason = cashRequiredText($data, 'reason', 'Reversal reason', 255);
    $description = cashNullableText($data['description'] ?? $data['note'] ?? null, 5000);
    $idempotencyKey = cashIdempotencyKey($data);
    $accountId = (int) $account['id'];

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $reversalDate);
        cashAssertEntryDateAllowed($conn, $accountId, $reversalDate);

        $stmt = $conn->prepare('SELECT * FROM cash_transactions WHERE id = ? AND account_id = ? FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Unable to lock the cash transaction.', 500);
        }
        $stmt->bind_param('ii', $transactionId, $accountId);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$original) {
            throw new RuntimeException('Cash transaction not found.', 404);
        }
        $originalType = strtoupper((string) $original['transaction_type']);
        if ($originalType === 'REVERSAL') {
            throw new RuntimeException('A reversal transaction cannot be reversed directly.', 409);
        }
        if (in_array($originalType, ['MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT', 'MUTILATED_CASH_BANK_RETURN'], true)) {
            throw new RuntimeException('Mutilated cash ledger entries cannot be reversed directly. Reverse the original disbursement or use the mutilated cash register.', 409);
        }
        if (strtoupper((string) $original['status']) !== 'POSTED') {
            throw new RuntimeException('This cash transaction has already been reversed or is unavailable.', 409);
        }
        if ($reversalDate < (string) $original['transaction_date']) {
            throw new InvalidArgumentException('The reversal date cannot be earlier than the original transaction date.', 422);
        }

        $existingReversalStmt = $conn->prepare('SELECT id FROM cash_transactions WHERE reversal_of_transaction_id = ? LIMIT 1');
        if (!$existingReversalStmt) {
            throw new RuntimeException('Unable to verify the transaction reversal state.', 500);
        }
        $existingReversalStmt->bind_param('i', $transactionId);
        $existingReversalStmt->execute();
        $existingReversalId = (int) (($existingReversalStmt->get_result()->fetch_assoc()['id'] ?? 0));
        $existingReversalStmt->close();
        if ($existingReversalId > 0) {
            $existing = cashFetchTransaction($conn, $existingReversalId);
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This transaction has already been reversed.',
                'data' => [
                    'original_transaction' => cashFetchTransaction($conn, $transactionId),
                    'reversal_transaction' => $existing,
                    'available_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $idempotent = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($idempotent) {
            if ((int) ($idempotent['reversal_of_transaction_id'] ?? 0) !== $transactionId) {
                throw new RuntimeException('This idempotency key has already been used for another cash transaction.', 409);
            }
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This reversal was already posted.',
                'data' => [
                    'original_transaction' => cashFetchTransaction($conn, $transactionId),
                    'reversal_transaction' => cashFetchTransaction($conn, (int) $idempotent['id']),
                    'available_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $receiptMutilatedStmt = $conn->prepare("SELECT COUNT(*) AS total
                                                FROM cash_mutilated_cash
                                                WHERE account_id = ?
                                                  AND source_receipt_transaction_id = ?
                                                  AND status <> 'REVERSED'");
        if (!$receiptMutilatedStmt) {
            throw new RuntimeException('Unable to verify mutilated cash attributed to this receipt.', 500);
        }
        $receiptMutilatedStmt->bind_param('ii', $accountId, $transactionId);
        $receiptMutilatedStmt->execute();
        $receiptMutilatedCount = (int) ($receiptMutilatedStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $receiptMutilatedStmt->close();
        if ($receiptMutilatedCount > 0) {
            throw new RuntimeException(
                'This cash receipt has mutilated cash attributed to it and cannot be reversed directly. Use the admin correction workflow where appropriate so the receipt remains available in the mutilated-cash audit trail.',
                409
            );
        }

        $linkedMutilatedCash = cashFetchLegacyMutilatedCashByDisbursement($conn, $transactionId);
        if ($linkedMutilatedCash && $linkedMutilatedCash['status'] !== 'PENDING_RETURN') {
            throw new RuntimeException('This disbursement has mutilated cash that has already been returned to the bank or replaced, so it cannot be reversed directly.', 409);
        }

        $amount = round((float) $original['amount'], 2);
        $reversalDirection = strtoupper((string) $original['direction']) === 'IN' ? 'OUT' : 'IN';
        $balanceBefore = cashGetUsableBalance($conn, $accountId);
        if ($reversalDirection === 'OUT' && (int) $account['allow_negative_balance'] !== 1 && $amount > $balanceBefore) {
            throw new RuntimeException(
                'This reversal would make the Cash Desk balance negative. Available balance is NGN ' . number_format($balanceBefore, 2, '.', ',') . '.',
                409
            );
        }

        $sourceIouStmt = $conn->prepare('SELECT * FROM cash_ious WHERE source_transaction_id = ? AND account_id = ? FOR UPDATE');
        if (!$sourceIouStmt) {
            throw new RuntimeException('Unable to verify the linked IOU.', 500);
        }
        $sourceIouStmt->bind_param('ii', $transactionId, $accountId);
        $sourceIouStmt->execute();
        $sourceIou = $sourceIouStmt->get_result()->fetch_assoc() ?: null;
        $sourceIouStmt->close();

        $mutilatedReversalId = null;
        if ($linkedMutilatedCash) {
            $setAsideTransactionId = (int) $linkedMutilatedCash['set_aside_transaction_id'];
            $setAsideStmt = $conn->prepare('SELECT * FROM cash_transactions WHERE id = ? AND account_id = ? FOR UPDATE');
            if (!$setAsideStmt) {
                throw new RuntimeException('Unable to lock the linked mutilated cash transaction.', 500);
            }
            $setAsideStmt->bind_param('ii', $setAsideTransactionId, $accountId);
            $setAsideStmt->execute();
            $setAsideTransaction = $setAsideStmt->get_result()->fetch_assoc();
            $setAsideStmt->close();

            if (!$setAsideTransaction || strtoupper((string) $setAsideTransaction['status']) !== 'POSTED') {
                throw new RuntimeException('The linked mutilated cash reserve is unavailable for reversal.', 409);
            }

            // Mutilated-cash classifications are balance-neutral in the receipt-linked model.
            // Reversing the reserve means marking the original classification and register record
            // as reversed; creating a normal cash REVERSAL would incorrectly increase the ledger.

            $markMutilatedStmt = $conn->prepare("UPDATE cash_transactions SET status = 'REVERSED' WHERE id = ?");
            if (!$markMutilatedStmt) {
                throw new RuntimeException('Unable to reverse the mutilated cash reserve.', 500);
            }
            $markMutilatedStmt->bind_param('i', $setAsideTransactionId);
            $markMutilatedStmt->execute();
            $markMutilatedStmt->close();

            $recordStatus = 'REVERSED';
            $resolutionType = 'REVERSED';
            $reversedByUserId = (int) $user['id'];
            $reversedByEmail = (string) $user['email'];
            $mutilatedRecordId = (int) $linkedMutilatedCash['id'];
            $reverseRecordStmt = $conn->prepare("UPDATE cash_mutilated_cash
                SET status = ?, resolution_type = ?, return_date = ?, resolution_note = ?, resolved_by_user_id = ?,
                    resolved_by_email = ?, resolved_at = NOW()
                WHERE id = ?");
            if (!$reverseRecordStmt) {
                throw new RuntimeException('Unable to reverse the mutilated cash register entry.', 500);
            }
            $reverseRecordStmt->bind_param(
                'ssssisi',
                $recordStatus,
                $resolutionType,
                $reversalDate,
                $reason,
                $reversedByUserId,
                $reversedByEmail,
                $mutilatedRecordId
            );
            $reverseRecordStmt->execute();
            $reverseRecordStmt->close();
        }

        if ($sourceIou) {
            $sourceIouId = (int) $sourceIou['id'];
            $activityStmt = $conn->prepare("SELECT
                    (SELECT COUNT(*) FROM cash_iou_actions WHERE iou_id = ?) AS action_count,
                    (SELECT COUNT(*) FROM cash_receipts WHERE iou_id = ? AND status = 'ACTIVE') AS receipt_count");
            if (!$activityStmt) {
                throw new RuntimeException('Unable to verify the IOU activity.', 500);
            }
            $activityStmt->bind_param('ii', $sourceIouId, $sourceIouId);
            $activityStmt->execute();
            $activity = $activityStmt->get_result()->fetch_assoc() ?: [];
            $activityStmt->close();

            if (strtoupper((string) $sourceIou['status']) !== 'OPEN' || (int) ($activity['action_count'] ?? 0) > 0 || (int) ($activity['receipt_count'] ?? 0) > 0) {
                throw new RuntimeException('This IOU disbursement already has retirement or receipt activity and cannot be reversed directly.', 409);
            }
        }

        $linkedActionStmt = $conn->prepare("SELECT cia.*, ci.account_id, ci.source_transaction_id, ci.status AS iou_status
                                            FROM cash_iou_actions cia
                                            INNER JOIN cash_ious ci ON ci.id = cia.iou_id
                                            WHERE cia.linked_transaction_id = ? AND ci.account_id = ?
                                            LIMIT 1
                                            FOR UPDATE");
        if (!$linkedActionStmt) {
            throw new RuntimeException('Unable to verify linked IOU settlement activity.', 500);
        }
        $linkedActionStmt->bind_param('ii', $transactionId, $accountId);
        $linkedActionStmt->execute();
        $linkedAction = $linkedActionStmt->get_result()->fetch_assoc() ?: null;
        $linkedActionStmt->close();

        $reference = cashGenerateReference($conn, 'CASH-REV', $reversalDate);
        $reversalId = cashInsertTransaction($conn, [
            'account_id' => $accountId,
            'transaction_reference' => $reference,
            'transaction_date' => $reversalDate,
            'transaction_type' => 'REVERSAL',
            'direction' => $reversalDirection,
            'person_name' => (string) $original['person_name'],
            'amount' => $amount,
            'reason' => $reason,
            'description' => $description,
            'category_id' => $original['category_id'] !== null ? (int) $original['category_id'] : null,
            'external_reference' => (string) $original['transaction_reference'],
            'disbursement_type' => 'REVERSAL',
            'receipt_status' => 'NOT_REQUIRED',
            'idempotency_key' => $idempotencyKey,
            'accounting_year' => $accountingYear,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
            'metadata' => [
                'entry_source' => 'cash_desk_reversal',
                'reversal_of_transaction_id' => $transactionId,
                'original_transaction_reference' => $original['transaction_reference'],
                'client_ip' => clientIpAddress(),
            ],
        ]);

        $linkStmt = $conn->prepare('UPDATE cash_transactions SET reversal_of_transaction_id = ? WHERE id = ?');
        if (!$linkStmt) {
            throw new RuntimeException('Unable to link the reversal transaction.', 500);
        }
        $linkStmt->bind_param('ii', $transactionId, $reversalId);
        $linkStmt->execute();
        $linkStmt->close();

        $markStmt = $conn->prepare("UPDATE cash_transactions SET status = 'REVERSED' WHERE id = ?");
        if (!$markStmt) {
            throw new RuntimeException('Unable to mark the original transaction as reversed.', 500);
        }
        $markStmt->bind_param('i', $transactionId);
        $markStmt->execute();
        $markStmt->close();

        if ($sourceIou) {
            $sourceIouId = (int) $sourceIou['id'];
            $iouReverseStmt = $conn->prepare("UPDATE cash_ious
                                             SET status = 'REVERSED', outstanding_amount = 0,
                                                 closed_by_user_id = ?, closed_by_email = ?, closed_at = NOW()
                                             WHERE id = ?");
            if (!$iouReverseStmt) {
                throw new RuntimeException('Unable to reverse the linked IOU.', 500);
            }
            $userId = (int) $user['id'];
            $userEmail = (string) $user['email'];
            $iouReverseStmt->bind_param('isi', $userId, $userEmail, $sourceIouId);
            $iouReverseStmt->execute();
            $iouReverseStmt->close();
        }

        if ($linkedAction) {
            cashRequireIouActionsSchema($conn);
            $iouId = (int) $linkedAction['iou_id'];
            $iou = cashLockIou($conn, $iouId, $accountId);
            $actionType = strtoupper((string) $linkedAction['action_type']);
            $cashReturned = 0.0;
            $reimbursementPaid = 0.0;

            if ($actionType === 'CASH_RETURN') {
                $cashReturned = round((float) $linkedAction['cash_returned'], 2);
                $iou['amount_returned'] = round(max(0, (float) $iou['amount_returned'] - $cashReturned), 2);
            } elseif ($actionType === 'REIMBURSEMENT') {
                $reimbursementPaid = round((float) $linkedAction['reimbursement_paid'], 2);
                $iou['reimbursement_paid'] = round(max(0, (float) $iou['reimbursement_paid'] - $reimbursementPaid), 2);
            } else {
                throw new RuntimeException('The linked IOU activity cannot be reversed through the cash ledger.', 409);
            }

            $finalStmt = $conn->prepare("SELECT COUNT(*) AS total
                                         FROM cash_iou_actions
                                         WHERE iou_id = ?
                                           AND action_type IN ('FINAL_RETIREMENT', 'FINALIZE')
                                           AND is_final_submission = 1");
            if (!$finalStmt) {
                throw new RuntimeException('Unable to determine the IOU finalisation state.', 500);
            }
            $finalStmt->bind_param('i', $iouId);
            $finalStmt->execute();
            $isFinalized = (int) ($finalStmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
            $finalStmt->close();

            cashUpdateIouState($conn, $iou, $user, $isFinalized);
            $actionReference = cashGenerateIouActionReference($conn, 'IOU-REV', $reversalDate);
            cashInsertIouAction($conn, [
                'iou_id' => $iouId,
                'action_reference' => $actionReference,
                'action_type' => 'REVERSAL',
                'action_date' => $reversalDate,
                'amount_spent' => 0,
                'cash_returned' => -$cashReturned,
                'reimbursement_paid' => -$reimbursementPaid,
                'linked_transaction_id' => $reversalId,
                'is_final_submission' => $isFinalized,
                'note' => 'Reversal of ' . $original['transaction_reference'] . ': ' . $reason,
                'idempotency_key' => null,
                'created_by_user_id' => $user['id'],
                'created_by_email' => $user['email'],
            ]);
        }

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s reversed cash transaction %s for NGN %s. Reason: %s.',
                $user['email'],
                $original['transaction_reference'],
                number_format($amount, 2, '.', ','),
                $reason
            )
        );

        $originalTransaction = cashFetchTransaction($conn, $transactionId);
        $reversalTransaction = cashFetchTransaction($conn, $reversalId);
        $balanceAfter = cashGetUsableBalance($conn, $accountId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'Cash transaction reversed successfully.',
            'data' => [
                'original_transaction' => $originalTransaction,
                'reversal_transaction' => $reversalTransaction,
                'mutilated_cash_reversal_transaction' => $mutilatedReversalId !== null
                    ? cashFetchTransaction($conn, $mutilatedReversalId)
                    : null,
                'balance_before' => $balanceBefore,
                'available_balance' => $balanceAfter,
                'idempotent_replay' => false,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to reverse the cash transaction.');
}
