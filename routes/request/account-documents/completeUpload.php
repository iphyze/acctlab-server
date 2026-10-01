<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountDocumentStorageService.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $actor = requireAdmin();
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request body.', 400);
    }
    $documentId = (int) ($payload['document_id'] ?? 0);
    if ($documentId <= 0) {
        throw new RuntimeException('A valid Account document upload is required.', 422);
    }

    $document = accountDocumentCompleteUpload($conn, $documentId, $actor);
    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Account document uploaded successfully.',
        'data' => ['document' => $document],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('Account document completion error: ' . $error->getMessage());
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to finalize the Account document upload.' : $error->getMessage(),
    ]);
}
