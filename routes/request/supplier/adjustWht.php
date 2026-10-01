<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountWhtAdjustmentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }

    $requestId = (int) ($data['requestId'] ?? $data['request_id'] ?? 0);
    $requestedWht = $data['wht_status'] ?? $data['wht_rate'] ?? null;
    $reason = $data['reason'] ?? $data['adjustment_reason'] ?? '';
    $actor = [
        'id' => (int) $userData['id'],
        'email' => (string) $userData['email'],
    ];

    // Complete all schema/storage initialization before the atomic WHT transaction.
    procurementEnsureLocalFinalPurchaseStorage($conn);
    accountSupplierEnsurePaymentStorage($conn);
    procurementNotificationEnsureStorage($conn);
    accountNotificationEnsureStorage($conn);

    $conn->begin_transaction();
    try {
        $result = accountAdjustSupplierRequestWht(
            $conn,
            $requestId,
            $requestedWht,
            $reason,
            $actor
        );

        $action = sprintf(
            '%s adjusted Supplier Fund Request %d WHT from %s to %s. Reason: %s',
            $actor['email'],
            $requestId,
            (string) $result['previous_wht_status'],
            (string) $result['new_wht_status'],
            (string) $result['reason']
        );
        $action = function_exists('mb_substr') ? mb_substr($action, 0, 255) : substr($action, 0, 255);
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actor['id'], $action, $actor['email']);
        $log->execute();
        $log->close();

        $conn->commit();
        echo json_encode([
            'status' => 'Success',
            'message' => 'Supplier Fund Request WHT adjusted successfully.',
            'data' => $result,
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $error) {
    error_log('Supplier Fund Request WHT adjustment error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
