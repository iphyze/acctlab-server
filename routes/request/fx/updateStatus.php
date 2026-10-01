<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestPaymentLifecycleService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Route not found', 400);
    }

    $user = requireAdmin();
    $userId = (int) $user['id'];
    $userEmail = (string) $user['email'];
    $writeConn = databaseActiveConnection($conn);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    if (!isset($data['requestIds']) || !is_array($data['requestIds']) || $data['requestIds'] === []) {
        throw new Exception('Please select at least one FX Fund Request.', 400);
    }

    $targetStatus = trim((string) ($data['payment_status'] ?? $data['status'] ?? ''));
    if ($targetStatus === '') {
        throw new Exception('Payment status is required.', 400);
    }
    $reason = trim((string) ($data['reason'] ?? ''));

    $requestIds = fxFundRequestLifecycleNormalizeRequestIds($data['requestIds']);
    if ($requestIds === []) {
        throw new Exception('Please select at least one valid FX Fund Request.', 400);
    }
    if (count($requestIds) > 100) {
        throw new Exception('Too many FX Fund Requests selected. Maximum allowed is 100.', 400);
    }

    $writeConn->begin_transaction();
    $transactionStarted = true;

    $result = fxFundRequestLifecycleApplyRequestStatus(
        $writeConn,
        $requestIds,
        $targetStatus,
        $userId,
        $userEmail,
        $reason
    );

    $writeConn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => $targetStatus === 'Pending'
            ? 'Selected direct AcctLab FX Fund Request(s) reopened to Pending successfully.'
            : 'Selected FX Fund Request status updated successfully.',
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX Fund Request status update error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
