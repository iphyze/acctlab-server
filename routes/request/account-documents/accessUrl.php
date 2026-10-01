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
    $documentId = (int) ($_GET['document_id'] ?? 0);
    $mode = (string) ($_GET['mode'] ?? 'preview');
    if ($documentId <= 0) {
        throw new RuntimeException('A valid Account document is required.', 422);
    }

    $data = accountDocumentAccessUrl(
        $conn,
        $entityType,
        $entityId,
        $documentId,
        $mode
    );
    http_response_code(200);
    echo json_encode(['status' => 'Success', 'data' => $data]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('Account document access error: ' . $error->getMessage());
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to prepare secure Account document access.' : $error->getMessage(),
    ]);
}
