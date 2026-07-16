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
    $receiptId = cashReceiptId($data);
    $reason = cashRequiredText($data, 'reason', 'Archive reason', 255);

    $conn->begin_transaction();
    try {
        $receipt = cashFetchReceipt($conn, $receiptId, $accountId);
        $stmt = $conn->prepare("UPDATE cash_receipts
                                SET status = 'ARCHIVED', deleted_at = NOW(), deleted_by_email = ?
                                WHERE id = ? AND status = 'ACTIVE'");
        if (!$stmt) {
            throw new RuntimeException('Unable to archive the receipt.', 500);
        }
        $email = (string) $user['email'];
        $stmt->bind_param('si', $email, $receiptId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Receipt not found or already archived.', 409);
        }
        $stmt->close();

        cashRefreshReceiptStatus(
            $conn,
            $receipt['transaction_id'] !== null ? (int) $receipt['transaction_id'] : null,
            $receipt['iou_id'] !== null ? (int) $receipt['iou_id'] : null
        );
        cashLogAction($conn, $user, sprintf('%s archived cash receipt %s. Reason: %s.', $user['email'], $receipt['original_filename'], $reason));
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'Receipt archived successfully. Its history has been retained.',
            'data' => ['receipt_id' => $receiptId],
        ]);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to archive the receipt.');
}
