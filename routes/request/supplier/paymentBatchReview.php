<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }
    requireAdmin();
    $result = accountSupplierReviewItems($conn, $_GET);
    echo json_encode(['status' => 'Success'] + $result);
} catch (Throwable $error) {
    error_log('Supplier payment review error: ' . $error->getMessage());
    http_response_code($error->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
