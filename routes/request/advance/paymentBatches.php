<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountAdvancePaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    $user = requireAdmin();
    accountAdvanceEnsurePaymentStorage($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ((string) ($_GET['credit_preview'] ?? '') === '1') {
            $rawIds = $_GET['request_ids'] ?? [];
            if (is_string($rawIds)) {
                $rawIds = array_filter(array_map('trim', explode(',', $rawIds)), static fn(string $id): bool => $id !== '');
            }
            $payload = accountAdvancePreviewCreditOffsets($conn, is_array($rawIds) ? $rawIds : []);
            echo json_encode(['status' => 'Success', 'data' => $payload]);
            exit;
        }

        $batchId = (int) ($_GET['id'] ?? 0);
        $payload = $batchId > 0
            ? accountAdvanceGetBatch($conn, $batchId)
            : accountAdvanceListBatches($conn, $_GET);
        echo json_encode(['status' => 'Success', 'data' => $payload]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid input format.', 400);
        }
        $batch = accountAdvanceCreatePaymentBatch($conn, $data, $user);
        http_response_code(201);
        echo json_encode([
            'status' => 'Success',
            'message' => 'Advance payments moved to Processing successfully.',
            'data' => $batch,
        ]);
        exit;
    }

    throw new RuntimeException('Route not found.', 405);
} catch (Throwable $error) {
    error_log('Advance payment batch error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
