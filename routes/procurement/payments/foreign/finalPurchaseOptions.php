<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxFinalPurchaseService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxFinalPurchaseStorage($conn);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    procurementRequireAnyPermission($conn, ['payments.fx_final.view', 'payments.fx_final.create', 'payments.fx_final.update']);

    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $search = trim((string) ($_GET['search'] ?? ''));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

    if ($type === 'currencies') {
        jsonResponse(['status' => 'Success', 'data' => array_map(static fn(string $code): array => ['value' => $code, 'label' => $code], PROCUREMENT_FX_FINAL_CURRENCIES)]);
    }
    if ($type === 'projects') {
        $sql = 'SELECT id, code, location AS project_name FROM location_table WHERE 1=1';
        $params = []; $types = '';
        if ($search !== '') { $sql .= ' AND (code LIKE ? OR location LIKE ?)'; $like = '%' . $search . '%'; $params = [$like, $like]; $types = 'ss'; }
        $sql .= ' ORDER BY location ASC, code ASC LIMIT ?'; $params[] = $limit; $types .= 'i';
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($rows as &$row) $row['id'] = (int) $row['id']; unset($row);
        jsonResponse(['status' => 'Success', 'data' => $rows]);
    }
    if ($type === 'suppliers') {
        $sql = 'SELECT id, supplier_name, supplier_number AS supplier_ledger, wht_status FROM suppliers_table WHERE 1=1';
        $params = []; $types = '';
        if ($search !== '') { $sql .= ' AND (supplier_name LIKE ? OR CAST(supplier_number AS CHAR) LIKE ?)'; $like = '%' . $search . '%'; $params = [$like, $search . '%']; $types = 'ss'; }
        $sql .= ' ORDER BY supplier_name ASC LIMIT ?'; $params[] = $limit; $types .= 'i';
        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($rows as &$row) { $row['id'] = (int) $row['id']; $row['supplier_ledger'] = (string) $row['supplier_ledger']; } unset($row);
        jsonResponse(['status' => 'Success', 'data' => $rows]);
    }
    throw new RuntimeException('Option type must be projects, suppliers or currencies.', 400);
} catch (Throwable $error) {
    $status = (int) $error->getCode(); if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('FX Final Purchase options error: ' . $error->getMessage());
    jsonResponse(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to load FX Final Purchase options.' : $error->getMessage()], $status);
}
