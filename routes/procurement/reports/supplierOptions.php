<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementSupplierActivityReportService.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequireAnyPermission($conn, procurementSupplierActivityReportPermissionCodes());

    $search = trim((string) ($_GET['search'] ?? ''));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $rows = procurementSupplierActivityReportMasterSuppliers($conn, $search, $limit);

    jsonResponse([
        'status' => 'Success',
        'data' => array_map(static fn(array $row): array => [
            'value' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['supplier_name'] ?? ''),
            'supplier_ledger' => (string) ($row['supplier_ledger'] ?? ''),
        ], $rows),
        'meta' => [
            'search' => $search,
            'limit' => $limit,
            'count' => count($rows),
        ],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('ProcureDesk report supplier options error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load supplier options.' : $error->getMessage(),
    ], $status);
}
