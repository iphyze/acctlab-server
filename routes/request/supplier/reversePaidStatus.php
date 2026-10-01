<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Route not found', 400);
    }

    $userData = requireAdmin();
    $actor = [
        'id' => (int) $userData['id'],
        'email' => (string) $userData['email'],
    ];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid input format.', 400);
    }

    $requestIds = accountSupplierBatchIds($data['requestIds'] ?? null);
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
        $result = accountSupplierReversePaidStatus(
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
            "%s corrected Paid status to '%s' for Supplier Fund Request ID(s): %s. Reason: %s",
            $actor['email'],
            $result['target_status'],
            implode(', ', $requestIds),
            $reason
        );
        $logStmt->bind_param('iss', $actor['id'], $action, $actor['email']);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();
        http_response_code(200);
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
    error_log('Supplier Paid status correction error: ' . $error->getMessage());
    http_response_code($error->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
