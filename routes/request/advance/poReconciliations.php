<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';

header('Content-Type: application/json');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $userData = authenticateUser();
    if (!in_array((string) ($userData['integrity'] ?? ''), ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Only authorized Account users can view PO reconciliations.', 403);
    }

    $filters = [
        'status' => trim((string) ($_GET['status'] ?? '')),
        'po_id' => (int) ($_GET['po_id'] ?? 0),
        'advance_payment_request_id' => (int) ($_GET['advance_payment_request_id'] ?? 0),
    ];

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'PO amendment reconciliations fetched successfully.',
        'data' => procurementLocalAdvanceListAccountPoReconciliations($conn, $filters),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Advance PO reconciliation error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to fetch PO amendment reconciliations.'
            : $error->getMessage(),
    ]);
}
