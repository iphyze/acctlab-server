<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestPaymentLifecycleService.php';
require_once 'includes/manualFxPaymentProcessingService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserIntegrity = (string) $userData['integrity'];
    $loggedInUserEmail = (string) $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Unauthorized: Only Admins can update FX payments', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid input format. Expected JSON object.', 400);
    }

    if (!isset($data['paymentIds']) || !is_array($data['paymentIds']) || count($data['paymentIds']) === 0) {
        throw new Exception('Please select at least one FX payment to update.', 400);
    }

    if (!isset($data['payment_status']) || trim((string) $data['payment_status']) === '') {
        throw new Exception('Payment status is required.', 400);
    }

    $paymentIds = array_values(array_unique(array_map('intval', $data['paymentIds'])));
    $paymentIds = array_values(array_filter($paymentIds, static fn(int $id): bool => $id > 0));
    sort($paymentIds, SORT_NUMERIC);
    $paymentStatus = trim((string) $data['payment_status']);

    if ($paymentIds === []) {
        throw new Exception('Please select at least one valid FX payment to update.', 400);
    }
    if (count($paymentIds) > 100) {
        throw new Exception('Too many IDs provided. Maximum allowed is 100.', 400);
    }

    $validStatuses = ['Pending', 'Paid', 'Unconfirmed'];
    if (!in_array($paymentStatus, $validStatuses, true)) {
        throw new Exception('Invalid payment status provided. Allowed: Pending, Paid, Unconfirmed.', 400);
    }

    $writeConn = databaseActiveConnection($conn);
    $writeConn->begin_transaction();
    $transactionStarted = true;

    // Lock all requested instructions inside the same transaction used for the
    // payment update and Fund Request synchronization.
    fxFundRequestLifecycleLockInstructionStatuses($writeConn, $paymentIds);

    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
    $typeString = str_repeat('i', count($paymentIds));
    $updateQuery = "UPDATE fx_instruction_letter_table
                    SET payment_status = ?, updated_at = NOW()
                    WHERE id IN ($placeholders)";
    $stmt = $writeConn->prepare($updateQuery);
    $params = array_merge([$paymentStatus], $paymentIds);
    $types = 's' . $typeString;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    $targetStatuses = [];
    foreach ($paymentIds as $paymentId) {
        $targetStatuses[$paymentId] = $paymentStatus;
    }
    $linkedSync = fxFundRequestLifecycleSyncMany(
        $writeConn,
        $targetStatuses,
        $loggedInUserId,
        $loggedInUserEmail,
        'FX payment status update'
    );

    $manualSync = [];
    foreach ($paymentIds as $paymentId) {
        $sync = manualFxPaymentProcessingSyncStatus(
            $writeConn,
            $paymentId,
            $paymentStatus,
            ['id' => $loggedInUserId, 'email' => $loggedInUserEmail]
        );
        if (($sync['updated_items'] ?? []) !== []) {
            $manualSync[$paymentId] = $sync;
        }
    }

    fxFundRequestInsertLog(
        $writeConn,
        $loggedInUserId,
        $loggedInUserEmail,
        $loggedInUserEmail . " updated FX payment_status to '{$paymentStatus}' for record(s) with ID(s): "
            . implode(', ', $paymentIds) . ' in fx_instruction_letter_table.'
    );

    $writeConn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX payment status updated successfully.',
        'linked_fund_requests_synchronized' => count($linkedSync),
        'manual_processing_synchronized' => count($manualSync),
    ]);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX payment status update error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
