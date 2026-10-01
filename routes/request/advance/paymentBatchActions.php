<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountAdvancePaymentService.php';

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
        accountAdvanceEnsurePaymentStorage($conn);
        $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
        $stmt = $conn->prepare(
            "SELECT item.legacy_source_id AS id
             FROM account_payment_batch_items item
             INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
             INNER JOIN advance_payment_request apr ON apr.id = item.request_id
             LEFT JOIN {$localAdvanceRelation} p
               ON p.id = apr.procurement_purchase_id
              AND p.advance_payment_request_id = apr.id
              AND p.deleted_at IS NULL
             WHERE item.request_type = 'local_advance_purchase'
               AND batch.request_type = 'local_advance_purchase'
               AND batch.legacy_source_id = ?
               AND item.status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
               AND apr.payment_status = 'Processing'
               AND (
                    COALESCE(apr.procurement_purchase_id, 0) = 0
                    OR (p.id IS NOT NULL AND p.approval_status = 'Approved' AND p.handoff_status = 'In Account')
               )
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

    $result = accountAdvanceMarkItems($conn, $itemIds, $action, $data, $user);
    echo json_encode([
        'status' => 'Success',
        'message' => count($result['successful']) . ' payment(s) updated.',
        'data' => $result,
    ]);
} catch (Throwable $error) {
    error_log('Advance payment batch action error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
