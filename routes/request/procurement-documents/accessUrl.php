<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountProcurementDocumentService.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    requireAdmin();
    $accountRequestType = (string) ($_GET['account_request_type'] ?? '');
    $accountRequestId = (int) ($_GET['account_request_id'] ?? 0);
    $documentId = (int) ($_GET['document_id'] ?? 0);
    $mode = (string) ($_GET['mode'] ?? 'preview');

    $data = accountProcurementDocumentAccessUrl(
        $conn,
        $accountRequestType,
        $accountRequestId,
        $documentId,
        $mode
    );

    http_response_code(200);
    echo json_encode(['status' => 'Success', 'data' => $data]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Account procurement document access error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to prepare secure document access.' : $error->getMessage(),
    ]);
}
