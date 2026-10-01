<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PATCH', 'PUT'], true)) {
        throw new RuntimeException('Route not found.', 405);
    }
    $user = requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }
    $action = strtolower(trim((string) ($data['action'] ?? '')));
    $itemIds = $data['item_ids'] ?? [];

    if (($data['all_eligible'] ?? false) === true) {
        $batchId = (int) ($data['batch_id'] ?? 0);
        if ($batchId <= 0) {
            throw new RuntimeException('Batch ID is required when selecting all eligible payments.', 400);
        }
        accountSupplierEnsurePaymentStorage($conn);
        $stmt = $conn->prepare(
            "SELECT item.legacy_source_id AS id
             FROM account_payment_batch_items item
             INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
             WHERE item.request_type = 'local_final_purchase'
               AND batch.request_type = 'local_final_purchase'
               AND batch.legacy_source_id = ?
               AND item.status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
             ORDER BY item.legacy_source_id ASC
             LIMIT 100"
        );
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $itemIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
        );
        $stmt->close();
    }

    $result = accountSupplierMarkItems($conn, $itemIds, $action, $data, $user);
    echo json_encode([
        'status' => 'Success',
        'message' => count($result['successful']) . ' payment(s) updated.',
        'data' => $result,
    ]);
} catch (Throwable $error) {
    error_log('Supplier payment batch action error: ' . $error->getMessage());
    http_response_code($error->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
