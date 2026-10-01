<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestReturnToPendingService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Route not found', 400);
    }

    $user = requireAdmin();
    $writeConn = databaseActiveConnection($conn);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request format. Expected a JSON object.', 400);
    }

    $requestIds = $data['requestIds'] ?? $data['request_ids'] ?? [];
    if (!is_array($requestIds) || $requestIds === []) {
        throw new RuntimeException('Please select at least one FX Fund Request.', 400);
    }
    $reason = trim((string) ($data['reason'] ?? ''));

    $writeConn->begin_transaction();
    $transactionStarted = true;

    $result = fxFundRequestReturnToPending(
        $writeConn,
        $requestIds,
        ['id' => (int) $user['id'], 'email' => (string) $user['email']],
        $reason
    );

    $writeConn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Selected FX Fund Request(s) returned to Pending successfully. Payment history was preserved.',
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX Fund Request return-to-Pending error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
