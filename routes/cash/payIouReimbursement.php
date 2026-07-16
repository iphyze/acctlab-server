<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertWriteAccess($user, $account);
    cashRequireIouActionsSchema($conn);

    $iouId = cashIouId($data);
    $accountId = (int) $account['id'];
    $paymentDate = cashParseDate($data['date'] ?? $data['payment_date'] ?? null, 'Reimbursement date');
    $accountingYear = cashAssertAccountingPeriod($user, $paymentDate);
    $amount = cashParseAmount($data['amount'] ?? $data['reimbursement_paid'] ?? null, 'Reimbursement amount');
    $note = cashNullableText($data['note'] ?? $data['description'] ?? null, 5000);
    $externalReference = cashNullableText($data['reference'] ?? $data['external_reference'] ?? null, 120);
    $idempotencyKey = cashIdempotencyKey($data);

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $paymentDate);
        cashAssertEntryDateAllowed($conn, $accountId, $paymentDate);
        $iou = cashLockIou($conn, $iouId, $accountId);
        cashAssertIouActionDate($iou, $paymentDate);

        $status = strtoupper((string) $iou['status']);
        if ($status !== 'PENDING_REIMBURSEMENT') {
            throw new RuntimeException('This IOU is not awaiting reimbursement.', 409);
        }

        $existingAction = cashFindIouActionByIdempotency($conn, $iouId, $idempotencyKey);
        if ($existingAction) {
            $currentIou = cashFetchIou($conn, $iouId, $accountId);
            $transaction = $existingAction['linked_transaction_id']
                ? cashFetchTransaction($conn, (int) $existingAction['linked_transaction_id'])
                : null;
            $availableBalance = cashGetUsableBalance($conn, $accountId);
            $conn->commit();

            jsonResponse([
                'status' => 'Success',
                'message' => 'This IOU reimbursement was already posted.',
                'data' => [
                    'iou' => $currentIou,
                    'action' => $existingAction,
                    'transaction' => $transaction,
                    'available_balance' => $availableBalance,
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $existingTransaction = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($existingTransaction) {
            throw new RuntimeException('This idempotency key has already been used for another cash transaction.', 409);
        }

        $amountDue = round(
            (float) $iou['actual_amount_spent']
            - (
                (float) $iou['amount_advanced']
                + (float) $iou['reimbursement_paid']
                - (float) $iou['amount_returned']
            ),
            2
        );
        if ($amountDue <= 0) {
            throw new RuntimeException('There is no reimbursement currently due on this IOU.', 409);
        }
        if ($amount - $amountDue > 0.009) {
            throw new RuntimeException(
                'Reimbursement cannot exceed the amount currently due: NGN ' . number_format($amountDue, 2, '.', ',') . '.',
                422
            );
        }

        $balanceBefore = cashGetUsableBalance($conn, $accountId);
        if ((int) $account['allow_negative_balance'] !== 1 && $amount > $balanceBefore) {
            throw new RuntimeException(
                'Insufficient cash balance. Available balance is NGN ' . number_format($balanceBefore, 2, '.', ',') . '.',
                409
            );
        }

        $transactionReference = cashGenerateReference($conn, 'CASH-REIMB', $paymentDate);
        $transactionId = cashInsertTransaction($conn, [
            'account_id' => $accountId,
            'transaction_reference' => $transactionReference,
            'transaction_date' => $paymentDate,
            'transaction_type' => 'REIMBURSEMENT',
            'direction' => 'OUT',
            'person_name' => (string) $iou['recipient_name'],
            'amount' => $amount,
            'reason' => 'IOU additional reimbursement',
            'description' => $note,
            'category_id' => $iou['category_id'] ?? null,
            'external_reference' => $externalReference,
            'disbursement_type' => 'IOU_REIMBURSEMENT',
            'receipt_status' => (string) ($iou['receipt_status'] ?? 'PENDING'),
            'idempotency_key' => $idempotencyKey,
            'accounting_year' => $accountingYear,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
            'metadata' => [
                'entry_source' => 'cash_desk_iou',
                'iou_id' => $iouId,
                'iou_reference' => $iou['iou_reference'],
                'client_ip' => clientIpAddress(),
            ],
        ]);

        $iou['reimbursement_paid'] = round((float) $iou['reimbursement_paid'] + $amount, 2);
        $state = cashUpdateIouState($conn, $iou, $user, true);

        $actionReference = cashGenerateIouActionReference($conn, 'IOU-REIMB', $paymentDate);
        $actionId = cashInsertIouAction($conn, [
            'iou_id' => $iouId,
            'action_reference' => $actionReference,
            'action_type' => 'REIMBURSEMENT',
            'action_date' => $paymentDate,
            'amount_spent' => 0,
            'cash_returned' => 0,
            'reimbursement_paid' => $amount,
            'linked_transaction_id' => $transactionId,
            'is_final_submission' => true,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
        ]);

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s paid IOU reimbursement of NGN %s to %s for %s. New status: %s.',
                $user['email'],
                number_format($amount, 2, '.', ','),
                $iou['recipient_name'],
                $iou['iou_reference'],
                $state['status']
            )
        );

        $updatedIou = cashFetchIou($conn, $iouId, $accountId);
        $transaction = cashFetchTransaction($conn, $transactionId);
        $action = cashFetchIouAction($conn, $actionId);
        $availableBalance = cashGetUsableBalance($conn, $accountId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => $state['status'] === 'CLOSED'
                ? 'Reimbursement posted and the IOU has been closed.'
                : 'Partial reimbursement posted successfully.',
            'data' => [
                'iou' => $updatedIou,
                'action' => $action,
                'transaction' => $transaction,
                'balance_before' => $balanceBefore,
                'available_balance' => $availableBalance,
                'idempotent_replay' => false,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to post the IOU reimbursement.');
}
