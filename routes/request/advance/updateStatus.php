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

    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserIntegrity = (string) $userData['integrity'];
    $loggedInUserEmail = (string) $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Unauthorized: Only Admins are authorized to update.', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }

    $requestIds = accountAdvanceBatchIds($data['requestIds'] ?? null);
    $paymentStatus = trim((string) ($data['payment_status'] ?? ''));
    if ($paymentStatus === '') {
        throw new RuntimeException('Payment status is required.', 400);
    }

    accountAdvanceEnsurePaymentStorage($conn);
    $conn->begin_transaction();
    try {
        accountAdvanceApplyDirectStatus(
            $conn,
            $requestIds,
            $paymentStatus,
            ['id' => $loggedInUserId, 'email' => $loggedInUserEmail],
            [
                'processing_method' => $data['processing_method'] ?? null,
                'processing_reference' => $data['processing_reference'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'reason' => $data['reason'] ?? $data['account_remarks'] ?? null,
                'processing_business_days' => $data['processing_business_days'] ?? null,
                'completion_mode' => $data['completion_mode'] ?? null,
            ]
        );

        $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $normalized = strcasecmp($paymentStatus, 'Unconfirmed') === 0 ? 'Processing' : $paymentStatus;
        $logAction = "$loggedInUserEmail updated payment status to '$normalized' for Advance Fund Request ID(s): "
            . implode(', ', $requestIds) . '.';
        $logStmt->bind_param('iss', $loggedInUserId, $logAction, $loggedInUserEmail);
        $logStmt->execute();
        $logStmt->close();
        $conn->commit();

        echo json_encode([
            'status' => 'Success',
            'message' => 'Request status has been updated successfully.',
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $error) {
    error_log('Advance status update error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
