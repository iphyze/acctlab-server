<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestPaymentDeletionService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserIntegrity = (string) $userData['integrity'];
    $loggedInUserEmail = (string) $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Unauthorized: Only Admins are authorized to delete FX payments', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['paymentIds']) || !is_array($data['paymentIds']) || count($data['paymentIds']) === 0) {
        throw new Exception('Please select at least one payment to delete.', 400);
    }

    $paymentIds = array_values(array_unique(array_map('intval', $data['paymentIds'])));
    $paymentIds = array_values(array_filter($paymentIds, static fn(int $id): bool => $id > 0));
    sort($paymentIds, SORT_NUMERIC);
    if ($paymentIds === []) {
        throw new Exception('Please select at least one valid payment to delete.', 400);
    }
    if (count($paymentIds) > 100) {
        throw new Exception('Too many IDs provided. Maximum allowed is 100.', 400);
    }

    $reason = trim((string) ($data['reason'] ?? $data['deletion_reason'] ?? ''));

    $writeConn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $writeConn->begin_transaction();
    $transactionStarted = true;

    $result = fxFundRequestPaymentDelete(
        $writeConn,
        $paymentIds,
        ['id' => $loggedInUserId, 'email' => $loggedInUserEmail],
        $reason
    );

    $writeConn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => ($result['reopened_request_ids'] ?? []) !== []
            ? 'FX payment record(s) deleted successfully and linked Fund Request(s) returned to Pending.'
            : 'FX payment record(s) deleted successfully.',
        'data' => $result,
        // Preserve the earlier response keys for existing clients.
        'direct_payment_ids' => $result['direct_payment_ids'] ?? [],
        'generated_payment_ids' => $result['generated_payment_ids'] ?? [],
        'reopened_request_ids' => $result['reopened_request_ids'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX payment delete error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
