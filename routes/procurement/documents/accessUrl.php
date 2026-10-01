<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementDocumentStorageService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }
    procurementEnsureAuthenticationTables($conn);
    procurementDocumentAssertStorageReady($conn);
    $actor = procurementRequirePermission($conn, 'documents.view');
    $documentId = (int) ($_GET['document_id'] ?? 0);
    $mode = (string) ($_GET['mode'] ?? 'preview');
    if ($documentId <= 0) {
        throw new RuntimeException('A valid document is required.', 422);
    }
    $data = procurementDocumentAccessUrl($conn, $documentId, $mode, $actor);
    jsonResponse(['status' => 'Success', 'data' => $data]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement document access URL error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to prepare document access.' : $error->getMessage(),
    ], $status);
}
