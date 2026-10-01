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

    $data = accountDocumentCreateUploadIntent($conn, $payload, $actor);
    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Secure Account document upload session created.',
        'data' => $data,
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('Account document upload intent error: ' . $error->getMessage());
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to prepare the Account document upload.' : $error->getMessage(),
    ]);
}
