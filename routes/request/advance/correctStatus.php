<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountAdvancePaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = requireAdmin();
    $actor = [
        'id' => (int) $userData['id'],
        'email' => (string) $userData['email'],
    ];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }

    $requestIds = accountAdvanceBatchIds($data['requestIds'] ?? $data['request_ids'] ?? null);
    $targetStatus = trim((string) (
        $data['new_status']
        ?? $data['target_status']
        ?? $data['payment_status']
        ?? ''
    ));
    $reason = trim((string) ($data['reason'] ?? $data['correction_reason'] ?? ''));
    $correctionMode = defined('ACCOUNT_ADVANCE_STATUS_CORRECTION_MODE')
        ? (string) ACCOUNT_ADVANCE_STATUS_CORRECTION_MODE
        : trim((string) ($data['correction_mode'] ?? 'single'));
    if (count($requestIds) > 1) {
        $correctionMode = 'bulk';
    }

    accountAdvanceEnsurePaymentStorage($conn);
    $conn->begin_transaction();
    try {
        $result = accountAdvanceCorrectStatus(
            $conn,
            $requestIds,
            $targetStatus,
            $reason,
            $actor,
            [
                'confirm_not_paid' => $data['confirm_not_paid'] ?? false,
                'processing_business_days' => $data['processing_business_days'] ?? null,
                'processing_method' => $data['processing_method'] ?? null,
                'processing_reference' => $data['processing_reference'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'correction_mode' => $correctionMode,
            ]
        );

        $changes = array_map(
            static fn(array $item): string => sprintf(
                '%d: %s to %s',
                (int) $item['request_id'],
                (string) $item['previous_status'],
                (string) $item['new_status']
            ),
            $result['updated'] ?? []
        );
        $action = sprintf(
            '%s completed %s Advance Fund Request payment status correction [%s] (%s). Reason: %s',
            $actor['email'],
            (string) ($result['correction_mode'] ?? $correctionMode),
            (string) ($result['correction_reference'] ?? 'not available'),
            implode(', ', $changes),
            $reason
        );
        $action = function_exists('mb_substr')
            ? mb_substr($action, 0, 255)
            : substr($action, 0, 255);
        $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $logStmt->bind_param('iss', $actor['id'], $action, $actor['email']);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();
        echo json_encode([
            'status' => 'Success',
            'message' => ($result['correction_mode'] ?? $correctionMode) === 'bulk'
                ? count($requestIds) . ' selected payment statuses were corrected successfully.'
                : 'Payment status was corrected successfully.',
            'data' => $result,
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $error) {
    error_log('Advance payment status correction error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
