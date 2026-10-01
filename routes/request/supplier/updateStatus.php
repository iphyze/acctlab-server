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

    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserIntegrity = (string) $userData['integrity'];
    $loggedInUserEmail = (string) $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Unauthorized: Only Admins are authorized to update', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid input format.', 400);
    }

    $requestIds = accountSupplierBatchIds($data['requestIds'] ?? null);
    $paymentStatus = trim((string) ($data['payment_status'] ?? ''));
    if ($paymentStatus === '') {
        throw new Exception('Payment status is required.', 400);
    }

    accountSupplierEnsurePaymentStorage($conn);
    $conn->begin_transaction();
    try {
        accountSupplierApplyDirectStatus(
            $conn,
            $requestIds,
            $paymentStatus,
            ['id' => $loggedInUserId, 'email' => $loggedInUserEmail],
            [
                'processing_method' => $data['processing_method'] ?? null,
                'processing_reference' => $data['processing_reference'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'reason' => $data['reason'] ?? null,
            ]
        );

        $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $normalized = $paymentStatus === 'Unconfirmed' ? 'Processing' : $paymentStatus;
        $logAction = "$loggedInUserEmail updated payment status to '$normalized' for Supplier Fund Request ID(s): " . implode(', ', $requestIds) . '.';
        $logStmt->bind_param('iss', $loggedInUserId, $logAction, $loggedInUserEmail);
        $logStmt->execute();
        $logStmt->close();
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => 'Request status has been updated successfully.',
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $e) {
    error_log('Supplier status update error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
