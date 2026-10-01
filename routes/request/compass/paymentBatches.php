<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountCompassFundRequestService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    $user = requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid input format.', 400);
        }
        $batch = accountCompassCreatePaymentBatch($conn, $data, $user);
        http_response_code(201);
        echo json_encode([
            'status' => 'Success',
            'message' => ((string) ($batch['completion_mode'] ?? '') === 'Immediate')
                ? 'Compass payments completed successfully.'
                : 'Compass payments moved to Processing successfully.',
            'data' => $batch,
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Compass payment batch ID is required.', 400);
        }
        echo json_encode(['status' => 'Success', 'data' => accountCompassGetPaymentBatch($conn, $id)]);
        exit;
    }

    throw new RuntimeException('Route not found.', 405);
} catch (Throwable $error) {
    error_log('Compass payment batch error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
