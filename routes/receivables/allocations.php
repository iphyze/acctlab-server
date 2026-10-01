<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/accountReceivablesAllocationService.php';

try {
    $actor = requireAdmin();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        if (isset($_GET['available_advances']) && filter_var($_GET['available_advances'], FILTER_VALIDATE_BOOLEAN)) {
            jsonResponse(['status' => 'Success', 'data' => accountReceivablesAvailableAdvances($conn, $_GET)]);
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $data = $id > 0
            ? accountReceivablesGetAllocation($conn, $id)
            : accountReceivablesAllocationList($conn, $_GET);

        jsonResponse(['status' => 'Success', 'data' => $data]);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON request body.', 400);
    }

    if ($method === 'POST') {
        $data = accountReceivablesCreateAllocation($conn, $payload, $actor);
        $message = ($data['allocation_type'] ?? '') === 'RECEIPT'
            ? 'Payment recorded successfully.'
            : (($data['allocation_type'] ?? '') === 'ADVANCE_AMORTISATION'
                ? 'Advance amortisation recorded successfully.'
                : 'Receivables allocation recorded successfully.');
        jsonResponse([
            'status' => 'Success',
            'message' => $message,
            'data' => $data,
        ], 201);
    }

    if ($method === 'PUT' && ($payload['action'] ?? '') === 'reverse') {
        $id = (int) ($payload['id'] ?? 0);
        $data = accountReceivablesReverseAllocation($conn, $id, $payload, $actor);
        jsonResponse([
            'status' => 'Success',
            'message' => 'Receivables allocation reversed successfully.',
            'data' => $data,
        ]);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to process the receivables allocation request.'
            : $error->getMessage(),
    ], $status);
}
