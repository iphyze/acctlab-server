<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountPaymentProcessingService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    $user = requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        throw new RuntimeException('Route not found.', 405);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }
    $writeConn = databaseActiveConnection($conn);
    $payload = accountPaymentProcessingAction($writeConn, $data, $user);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Processing transactions updated successfully.',
        'data' => $payload,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Unified payment processing action error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
