<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountAdvancePaymentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }
    requireAdmin();
    $result = accountAdvanceReviewItems($conn, $_GET);
    echo json_encode(['status' => 'Success'] + $result);
} catch (Throwable $error) {
    error_log('Advance payment review error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
