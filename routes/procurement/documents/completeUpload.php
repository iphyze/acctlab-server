<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementDocumentStorageService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }
    procurementEnsureAuthenticationTables($conn);
    procurementDocumentAssertStorageReady($conn);
    procurementRequireCsrfToken();
    $actor = procurementRequirePermission($conn, 'documents.manage');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request body.', 400);
    }
    $documentId = (int) ($payload['document_id'] ?? 0);
    if ($documentId <= 0) {
        throw new RuntimeException('A valid document upload is required.', 422);
    }
    $document = procurementDocumentCompleteUpload($conn, $documentId, $actor);
    jsonResponse([
        'status' => 'Success',
        'message' => 'Document uploaded successfully.',
        'data' => ['document' => $document],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement document completion error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to finalize the document upload.' : $error->getMessage(),
    ], $status);
}
