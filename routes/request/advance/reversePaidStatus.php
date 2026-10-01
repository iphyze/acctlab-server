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

    $requestIds = accountAdvanceBatchIds($data['requestIds'] ?? null);
    $targetStatus = trim((string) ($data['target_status'] ?? ''));
    $reason = trim((string) ($data['reason'] ?? ''));
    $confirmedNotPaid = filter_var(
        $data['confirm_not_paid'] ?? false,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) === true;
    $processingBusinessDays = (int) ($data['processing_business_days'] ?? 0);

    $conn->begin_transaction();
    try {
        $result = accountAdvanceReversePaidStatus(
            $conn,
            $requestIds,
            $targetStatus,
            $reason,
            $confirmedNotPaid,
            $processingBusinessDays,
            $actor
        );

        $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $action = sprintf(
            "%s corrected Paid status to '%s' for Advance Fund Request ID(s): %s. Reason: %s",
            $actor['email'],
            $result['target_status'],
            implode(', ', $requestIds),
            $reason
        );
        $logStmt->bind_param('iss', $actor['id'], $action, $actor['email']);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();
        echo json_encode([
            'status' => 'Success',
            'message' => count($requestIds) === 1
                ? 'Paid status was corrected successfully.'
                : count($requestIds) . ' Paid requests were corrected successfully.',
            'data' => $result,
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $error) {
    error_log('Advance Paid status correction error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
