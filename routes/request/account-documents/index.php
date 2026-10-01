<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountDocumentStorageService.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    requireAdmin();
    $entityType = (string) ($_GET['entity_type'] ?? $_GET['account_request_type'] ?? '');
    $entityId = (int) ($_GET['entity_id'] ?? $_GET['account_request_id'] ?? 0);
    $includeHistory = filter_var($_GET['include_history'] ?? true, FILTER_VALIDATE_BOOLEAN);

    $data = accountDocumentList($conn, $entityType, $entityId, $includeHistory);
    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Account documents fetched successfully.',
        'data' => $data,
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('Account documents list error: ' . $error->getMessage());
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load Account documents.' : $error->getMessage(),
    ]);
}
