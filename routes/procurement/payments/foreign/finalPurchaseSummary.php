<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxFinalPurchaseService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxFinalPurchaseStorage($conn);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    procurementRequirePermission($conn, 'payments.fx_final.view');

    $search = trim((string) ($_GET['search'] ?? ''));
    $year = (int) ($_GET['year'] ?? 0);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);
    $currency = trim((string) ($_GET['currency'] ?? ''));
    if ($year !== 0 && ($year < 2000 || $year > 2100)) throw new RuntimeException('Year filter is invalid.', 400);
    if ($currency !== '') $currency = procurementFxFinalNormalizeCurrency($currency);

    $where = ['p.deleted_at IS NULL']; $params = []; $types = '';
    if ($search !== '') {
        $where[] = '(p.purchase_number LIKE ? OR p.po_number LIKE ? OR p.grn_ref LIKE ? OR p.invoice_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ? OR p.supplier_ledger LIKE ?)';
        $like = '%' . $search . '%'; for ($i = 0; $i < 8; $i++) $params[] = $like; $types .= 'ssssssss';
    }
    if ($year > 0) { $where[] = 'p.purchase_date >= ? AND p.purchase_date < ?'; $params[] = sprintf('%04d-01-01', $year); $params[] = sprintf('%04d-01-01', $year + 1); $types .= 'ss'; }
    if ($projectId > 0) { $where[] = 'p.project_id = ?'; $params[] = $projectId; $types .= 'i'; }
    if ($supplierId > 0) { $where[] = 'p.supplier_id = ?'; $params[] = $supplierId; $types .= 'i'; }
    if ($currency !== '') { $where[] = 'p.currency = ?'; $params[] = $currency; $types .= 's'; }
    $whereSql = implode(' AND ', $where);
    $relation = procurementRequestCanonicalFxFinalReadRelation();

    // FX currencies must never be summed into a single mixed-currency value.
    $stmt = $conn->prepare(
        "SELECT p.currency,
                COUNT(*) AS total_count, COALESCE(SUM(p.purchase_value), 0.00) AS total_value,
                SUM(CASE WHEN p.approval_status = 'Unapproved' THEN 1 ELSE 0 END) AS pending_approval_count,
                COALESCE(SUM(CASE WHEN p.approval_status = 'Unapproved' THEN p.purchase_value ELSE 0.00 END), 0.00) AS pending_approval_value,
                SUM(CASE WHEN p.approval_status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
                COALESCE(SUM(CASE WHEN p.approval_status = 'Approved' THEN p.purchase_value ELSE 0.00 END), 0.00) AS approved_value,
                SUM(CASE WHEN p.payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
                COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' THEN p.purchase_value ELSE 0.00 END), 0.00) AS paid_value
         FROM {$relation} p WHERE {$whereSql}
         GROUP BY p.currency ORDER BY p.currency ASC"
    );
    if ($params !== []) $stmt->bind_param($types, ...$params);
    $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $totalCount = 0; $currencies = [];
    foreach ($rows as $row) {
        $totalCount += (int) $row['total_count'];
        $currencies[] = [
            'currency' => (string) $row['currency'],
            'total' => ['count' => (int) $row['total_count'], 'value' => number_format((float) $row['total_value'], 2, '.', '')],
            'pending_approval' => ['count' => (int) $row['pending_approval_count'], 'value' => number_format((float) $row['pending_approval_value'], 2, '.', '')],
            'approved' => ['count' => (int) $row['approved_count'], 'value' => number_format((float) $row['approved_value'], 2, '.', '')],
            'paid' => ['count' => (int) $row['paid_count'], 'value' => number_format((float) $row['paid_value'], 2, '.', '')],
        ];
    }
    jsonResponse(['status' => 'Success', 'data' => [
        'scope' => ['search' => $search, 'year' => $year ?: null, 'project_id' => $projectId ?: null, 'supplier_id' => $supplierId ?: null, 'currency' => $currency ?: null],
        'total_count' => $totalCount, 'currencies' => $currencies,
    ]]);
} catch (Throwable $error) {
    $status = (int) $error->getCode(); if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('FX Final Purchase summary error: ' . $error->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to load the FX Final Purchase summary.' : $error->getMessage()], $status);
}
