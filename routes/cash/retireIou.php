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
    $retirementDate = cashParseDate($data['date'] ?? $data['retirement_date'] ?? null, 'Retirement date');
    cashAssertAccountingPeriod($user, $retirementDate);
    $amountSpent = cashParseNonNegativeAmount($data['amount_spent'] ?? $data['actual_amount_spent'] ?? 0, 'Amount spent');
    $finalize = cashParseBoolean($data['finalize'] ?? $data['is_final_submission'] ?? false);
    $note = cashNullableText($data['note'] ?? $data['description'] ?? null, 5000);
    $idempotencyKey = cashIdempotencyKey($data);

    if ($amountSpent <= 0 && !$finalize) {
        throw new InvalidArgumentException('Enter an amount spent or mark the retirement as final.', 422);
    }

    $requestedReceiptStatus = array_key_exists('receipt_status', $data)
        ? cashValidateIouReceiptStatus($data['receipt_status'])
        : null;

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $retirementDate);
        cashAssertEntryDateAllowed($conn, $accountId, $retirementDate);
        $iou = cashLockIou($conn, $iouId, $accountId);
        cashAssertIouOpenForRetirement($iou);
        cashAssertIouActionDate($iou, $retirementDate);

        $existingAction = cashFindIouActionByIdempotency($conn, $iouId, $idempotencyKey);
        if ($existingAction) {
            $currentIou = cashFetchIou($conn, $iouId, $accountId);
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This IOU retirement was already recorded.',
                'data' => [
                    'iou' => $currentIou,
                    'action' => $existingAction,
                    'available_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $iou['actual_amount_spent'] = round((float) $iou['actual_amount_spent'] + $amountSpent, 2);
        $state = cashUpdateIouState($conn, $iou, $user, $finalize);

        if ($requestedReceiptStatus !== null) {
            cashSetIouReceiptStatus($conn, $iou, $requestedReceiptStatus);
        }

        $reference = cashGenerateIouActionReference($conn, 'IOU-RET', $retirementDate);
        $actionId = cashInsertIouAction($conn, [
            'iou_id' => $iouId,
            'action_reference' => $reference,
            'action_type' => $finalize ? 'FINAL_RETIREMENT' : 'PARTIAL_RETIREMENT',
            'action_date' => $retirementDate,
            'amount_spent' => $amountSpent,
            'cash_returned' => 0,
            'reimbursement_paid' => 0,
            'linked_transaction_id' => null,
            'is_final_submission' => $finalize,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
        ]);

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s recorded %s IOU retirement of NGN %s for %s (%s). New status: %s.',
                $user['email'],
                $finalize ? 'final' : 'partial',
                number_format($amountSpent, 2, '.', ','),
                $iou['recipient_name'],
                $iou['iou_reference'],
                $state['status']
            )
        );

        $updatedIou = cashFetchIou($conn, $iouId, $accountId);
        $action = cashFetchIouAction($conn, $actionId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => $finalize
                ? 'The IOU retirement has been finalised successfully.'
                : 'The partial IOU retirement has been recorded successfully.',
            'data' => [
                'iou' => $updatedIou,
                'action' => $action,
                'available_balance' => cashGetUsableBalance($conn, $accountId),
                'idempotent_replay' => false,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to record the IOU retirement.');
}
