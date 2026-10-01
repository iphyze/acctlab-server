<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/accountReceivablesDeductionsService.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
}

try {
    $actor = requireAdmin();
    jsonResponse([
        'status' => 'Success',
        'data' => accountReceivablesDeductionsReport($conn, $_GET, $actor),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load Receivables deductions and tax analysis.' : $error->getMessage(),
    ], $status);
}
