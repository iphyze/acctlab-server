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
    $finalizationDate = cashParseDate($data['date'] ?? $data['finalization_date'] ?? null, 'Finalization date');
    cashAssertAccountingPeriod($user, $finalizationDate);
    $note = cashNullableText($data['note'] ?? $data['description'] ?? null, 5000);
    $idempotencyKey = cashIdempotencyKey($data);
    $requestedReceiptStatus = array_key_exists('receipt_status', $data)
        ? cashValidateIouReceiptStatus($data['receipt_status'])
        : null;

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $finalizationDate);
        cashAssertEntryDateAllowed($conn, $accountId, $finalizationDate);
        $iou = cashLockIou($conn, $iouId, $accountId);
        cashAssertIouOpenForRetirement($iou);
        cashAssertIouActionDate($iou, $finalizationDate);

        $existingAction = cashFindIouActionByIdempotency($conn, $iouId, $idempotencyKey);
        if ($existingAction) {
            $currentIou = cashFetchIou($conn, $iouId, $accountId);
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This IOU finalization was already recorded.',
                'data' => [
                    'iou' => $currentIou,
                    'action' => $existingAction,
                    'available_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        $state = cashUpdateIouState($conn, $iou, $user, true);
        if ($requestedReceiptStatus !== null) {
            cashSetIouReceiptStatus($conn, $iou, $requestedReceiptStatus);
        }

        $reference = cashGenerateIouActionReference($conn, 'IOU-FIN', $finalizationDate);
        $actionId = cashInsertIouAction($conn, [
            'iou_id' => $iouId,
            'action_reference' => $reference,
            'action_type' => 'FINALIZE',
            'action_date' => $finalizationDate,
            'amount_spent' => 0,
            'cash_returned' => 0,
            'reimbursement_paid' => 0,
            'linked_transaction_id' => null,
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
                '%s finalised IOU %s for %s. New status: %s.',
                $user['email'],
                $iou['iou_reference'],
                $iou['recipient_name'],
                $state['status']
            )
        );

        $updatedIou = cashFetchIou($conn, $iouId, $accountId);
        $action = cashFetchIouAction($conn, $actionId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'The IOU has been finalised successfully.',
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
    cashHandleError($error, 'Unable to finalise the IOU.');
}
