<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    cashRequireIouActionsSchema($conn);

    $iouId = cashIouId();
    $accountId = (int) $account['id'];
    $iou = cashFetchIou($conn, $iouId, $accountId);
    $actions = cashFetchIouActions($conn, $iouId);

    $receiptStmt = $conn->prepare("SELECT
            id,
            transaction_id,
            iou_id,
            document_type,
            original_filename,
            stored_filename,
            storage_path,
            mime_type,
            file_size,
            status,
            uploaded_by_user_id,
            uploaded_by_email,
            created_at
        FROM cash_receipts
        WHERE iou_id = ? AND status = 'ACTIVE'
        ORDER BY created_at DESC, id DESC");
    if (!$receiptStmt) {
        throw new RuntimeException('Unable to load the IOU receipts.', 500);
    }
    $receiptStmt->bind_param('i', $iouId);
    $receiptStmt->execute();
    $receipts = $receiptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $receiptStmt->close();

    foreach ($receipts as &$receipt) {
        foreach (['id', 'transaction_id', 'iou_id', 'file_size', 'uploaded_by_user_id'] as $field) {
            $receipt[$field] = $receipt[$field] !== null ? (int) $receipt[$field] : null;
        }
    }
    unset($receipt);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash IOU details fetched successfully.',
        'data' => [
            'iou' => $iou,
            'actions' => $actions,
            'receipts' => $receipts,
            'available_balance' => cashGetUsableBalance($conn, $accountId),
            'permissions' => [
                'can_post' => in_array($user['integrity'], ['Admin', 'Super_Admin'], true)
                    || in_array($account['access_level'], ['MANAGER', 'CASHIER'], true),
                'can_manage' => in_array($user['integrity'], ['Admin', 'Super_Admin'], true)
                    || $account['access_level'] === 'MANAGER',
            ],
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load the cash IOU.');
}
