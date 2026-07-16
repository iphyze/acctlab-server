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
    $returnDate = cashParseDate($data['date'] ?? $data['return_date'] ?? null, 'Cash return date');
    $accountingYear = cashAssertAccountingPeriod($user, $returnDate);
    $amount = cashParseAmount($data['amount'] ?? $data['cash_returned'] ?? null, 'Cash returned');
    $note = cashNullableText($data['note'] ?? $data['description'] ?? null, 5000);
    $externalReference = cashNullableText($data['reference'] ?? $data['external_reference'] ?? null, 120);
    $idempotencyKey = cashIdempotencyKey($data);

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $returnDate);
        cashAssertEntryDateAllowed($conn, $accountId, $returnDate);
        $iou = cashLockIou($conn, $iouId, $accountId);
        cashAssertIouActionDate($iou, $returnDate);

        $status = strtoupper((string) $iou['status']);
        if (in_array($status, ['CLOSED', 'REVERSED'], true)) {
            throw new RuntimeException('This IOU is already closed and cannot receive another cash return.', 409);
        }
        if ($status === 'PENDING_REIMBURSEMENT') {
            throw new RuntimeException('This IOU is awaiting reimbursement, not a cash return.', 409);
        }

        $existingAction = cashFindIouActionByIdempotency($conn, $iouId, $idempotencyKey);
        if ($existingAction) {
            $currentIou = cashFetchIou($conn, $iouId, $accountId);
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This IOU cash return was already posted.',
                'data' => [
                    'iou' => $currentIou,
                    'action' => $existingAction,
                    'transaction' => $existingAction['linked_transaction_id']
                        ? cashFetchTransaction($conn, (int) $existingAction['linked_transaction_id'])
                        : null,
                    'available_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $existingTransaction = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($existingTransaction) {
            throw new RuntimeException('This idempotency key has already been used for another cash transaction.', 409);
        }

        $maxReturn = round(
            (float) $iou['amount_advanced']
            + (float) $iou['reimbursement_paid']
            - (float) $iou['amount_returned']
            - (float) $iou['actual_amount_spent'],
            2
        );
        if ($maxReturn <= 0) {
            throw new RuntimeException('There is no cash return currently due on this IOU.', 409);
        }
        if ($amount - $maxReturn > 0.009) {
            throw new RuntimeException(
                'Cash returned cannot exceed the amount currently due: NGN ' . number_format($maxReturn, 2, '.', ',') . '.',
                422
            );
        }

        $transactionReference = cashGenerateReference($conn, 'CASH-RET', $returnDate);
        $transactionId = cashInsertTransaction($conn, [
            'account_id' => $accountId,
            'transaction_reference' => $transactionReference,
            'transaction_date' => $returnDate,
            'transaction_type' => 'CASH_RETURN',
            'direction' => 'IN',
            'person_name' => (string) $iou['recipient_name'],
            'amount' => $amount,
            'reason' => 'IOU cash return',
            'description' => $note,
            'category_id' => $iou['category_id'] ?? null,
            'external_reference' => $externalReference,
            'disbursement_type' => 'IOU_RETURN',
            'receipt_status' => 'NOT_REQUIRED',
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

        $wasFinalized = cashIouIsFinalizedStatus($status);
        $iou['amount_returned'] = round((float) $iou['amount_returned'] + $amount, 2);
        $state = cashUpdateIouState($conn, $iou, $user, $wasFinalized);

        $actionReference = cashGenerateIouActionReference($conn, 'IOU-RETURN', $returnDate);
        $actionId = cashInsertIouAction($conn, [
            'iou_id' => $iouId,
            'action_reference' => $actionReference,
            'action_type' => 'CASH_RETURN',
            'action_date' => $returnDate,
            'amount_spent' => 0,
            'cash_returned' => $amount,
            'reimbursement_paid' => 0,
            'linked_transaction_id' => $transactionId,
            'is_final_submission' => $wasFinalized,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
        ]);

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s recorded cash return of NGN %s for IOU %s from %s. New status: %s.',
                $user['email'],
                number_format($amount, 2, '.', ','),
                $iou['iou_reference'],
                $iou['recipient_name'],
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
                ? 'Cash return posted and the IOU has been closed.'
                : 'Cash return posted successfully.',
            'data' => [
                'iou' => $updatedIou,
                'action' => $action,
                'transaction' => $transaction,
                'available_balance' => $availableBalance,
                'idempotent_replay' => false,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to record the IOU cash return.');
}
