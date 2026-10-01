<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountAdvancePaymentService.php';
require_once 'includes/accountSupplierPaymentService.php';
require_once 'includes/accountPaymentReminderService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'], true)) {
        throw new RuntimeException('Route not found.', 405);
    }

    $user = requireAdmin();
    $actor = [
        'id' => (int) ($user['id'] ?? 0),
        'email' => (string) ($user['email'] ?? ''),
    ];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }

    $requestType = $data['request_type'] ?? $data['type'] ?? '';
    $requestId = (int) ($data['request_id'] ?? $data['id'] ?? 0);
    $reason = trim((string) ($data['reason'] ?? ''));

    accountAdvanceEnsurePaymentStorage($conn);
    accountSupplierEnsurePaymentStorage($conn);
    accountPaymentReminderEnsureStorage($conn);

    $conn->begin_transaction();
    try {
        $reminder = accountPaymentReminderReinitiate(
            $conn,
            $requestType,
            $requestId,
            $reason,
            $actor
        );

        $action = sprintf(
            '%s reinitiated %s payment reminder for request %d. Reason: %s',
            $actor['email'],
            (string) ($reminder['request_type'] ?? accountPaymentReminderNormalizeRequestType($requestType)),
            $requestId,
            $reason
        );
        $action = function_exists('mb_substr') ? mb_substr($action, 0, 255) : substr($action, 0, 255);
        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $log->bind_param('iss', $actor['id'], $action, $actor['email']);
        $log->execute();
        $log->close();

        $conn->commit();
        echo json_encode([
            'status' => 'Success',
            'message' => 'Payment reminder was reinitiated and queued for delivery.',
            'data' => $reminder,
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $error) {
    error_log('Payment reminder reinitiation error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
