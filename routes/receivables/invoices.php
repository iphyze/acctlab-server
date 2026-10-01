<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/accountReceivablesService.php';

try {
    $actor = requireAdmin();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $data = $id > 0
            ? accountReceivablesGetInvoice($conn, $id)
            : accountReceivablesInvoiceList($conn, $_GET);

        jsonResponse(['status' => 'Success', 'data' => $data]);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON request body.', 400);
    }

    if ($method === 'POST') {
        $data = accountReceivablesCreateInvoice($conn, $payload, $actor);
        jsonResponse([
            'status' => 'Success',
            'message' => 'Receivables record created successfully.',
            'data' => $data,
        ], 201);
    }

    if ($method === 'PUT') {
        if (($payload['action'] ?? '') === 'bulk_update') {
            $data = accountReceivablesBulkUpdateInvoices(
                $conn,
                $payload['ids'] ?? [],
                $payload['changes'] ?? [],
                $actor
            );
            jsonResponse([
                'status' => 'Success',
                'message' => sprintf('%d receivables record(s) updated.', (int) ($data['updated_count'] ?? 0)),
                'data' => $data,
            ]);
        }

        $id = (int) ($payload['id'] ?? 0);
        $data = accountReceivablesUpdateInvoice($conn, $id, $payload, $actor);
        jsonResponse([
            'status' => 'Success',
            'message' => 'Receivables record updated successfully.',
            'data' => $data,
        ]);
    }

    if ($method === 'DELETE') {
        if (($payload['action'] ?? '') === 'bulk_delete') {
            $data = accountReceivablesBulkDeleteInvoices($conn, $payload['ids'] ?? [], $actor);
            jsonResponse([
                'status' => 'Success',
                'message' => sprintf('%d receivables record(s) deleted.', (int) ($data['deleted_count'] ?? 0)),
                'data' => $data,
            ]);
        }

        $id = (int) ($payload['id'] ?? 0);
        accountReceivablesDeleteInvoice($conn, $id, $actor);
        jsonResponse([
            'status' => 'Success',
            'message' => 'Receivables record deleted successfully.',
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
            ? 'Unable to process the receivables invoice register request.'
            : $error->getMessage(),
    ], $status);
}
