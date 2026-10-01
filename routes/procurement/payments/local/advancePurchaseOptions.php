<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementLocalAdvanceEnsureStorage($conn);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequireAnyPermission($conn, [
        'payments.local_advance.view',
        'payments.local_advance.create',
        'payments.local_advance.update',
    ]);

    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $search = trim((string) ($_GET['search'] ?? ''));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

    if ($type === 'projects') {
        $sql = 'SELECT id, code, location AS project_name FROM location_table WHERE 1=1';
        $params = [];
        $types = '';
        if ($search !== '') {
            $sql .= ' AND (code LIKE ? OR location LIKE ?)';
            $like = '%' . $search . '%';
            $params = [$like, $like];
            $types = 'ss';
        }
        $sql .= ' ORDER BY location ASC, code ASC LIMIT ?';
        $params[] = $limit;
        $types .= 'i';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        jsonResponse(['status' => 'Success', 'data' => $rows]);
    }

    if ($type === 'suppliers') {
        $sql = 'SELECT id, supplier_name, supplier_number AS supplier_ledger, wht_status
                FROM suppliers_table
                WHERE 1=1';
        $params = [];
        $types = '';
        if ($search !== '') {
            $sql .= ' AND (supplier_name LIKE ? OR CAST(supplier_number AS CHAR) LIKE ?)';
            $like = '%' . $search . '%';
            $params = [$like, $search . '%'];
            $types = 'ss';
        }
        $sql .= ' ORDER BY supplier_name ASC LIMIT ?';
        $params[] = $limit;
        $types .= 'i';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_ledger'] = (string) $row['supplier_ledger'];
        }
        unset($row);
        jsonResponse(['status' => 'Success', 'data' => $rows]);
    }

    if ($type === 'po') {
        [$poNumber, $normalized] = procurementLocalAdvanceNormalizePoNumber(
            (string) ($_GET['po_number'] ?? $search)
        );
        $po = procurementLocalAdvanceFetchPoByNormalized($conn, $normalized);
        if (!$po) {
            jsonResponse([
                'status' => 'Success',
                'data' => [
                    'exists' => false,
                    'po_number' => $poNumber,
                    'allocated_percentage' => '0.000000',
                    'available_percentage' => '100.000000',
                ],
            ]);
        }
        $allocatedUnits = procurementLocalAdvanceAllocatedUnits($conn, (int) $po['id']);
        $po['id'] = (int) $po['id'];
        $po['project_id'] = (int) $po['project_id'];
        $po['supplier_id'] = (int) $po['supplier_id'];
        $po['version'] = (int) $po['version'];
        $po['exists'] = true;
        $po['allocated_percentage'] = procurementLocalAdvancePercentString($allocatedUnits);
        $po['available_percentage'] = procurementLocalAdvancePercentString(
            max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $allocatedUnits)
        );
        jsonResponse(['status' => 'Success', 'data' => $po]);
    }

    if ($type === 'pos') {
        $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
        $sql = "SELECT p.*,
                       (SELECT COALESCE(SUM(r.po_percentage), 0)
                        FROM {$localAdvanceRelation} r
                        WHERE r.po_id = p.id AND r.deleted_at IS NULL AND r.payment_status <> 'Cancelled') AS allocated_percentage
                FROM procurement_local_advance_pos p
                WHERE p.request_scope = 'local_advance_purchase'";
        $params = [];
        $types = '';
        if ($search !== '') {
            $sql .= ' AND (p.po_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ?)';
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like];
            $types = 'ssss';
        }
        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ?';
        $params[] = $limit;
        $types .= 'i';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['project_id'] = (int) $row['project_id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['version'] = (int) $row['version'];
            $allocatedUnits = procurementLocalAdvanceAllocatedUnits($conn, (int) $row['id']);
            $row['allocated_percentage'] = procurementLocalAdvancePercentString($allocatedUnits);
            $row['available_percentage'] = procurementLocalAdvancePercentString(
                max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $allocatedUnits)
            );
        }
        unset($row);
        jsonResponse(['status' => 'Success', 'data' => $rows]);
    }

    throw new RuntimeException('Option type must be projects, suppliers, po or pos.', 400);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Local Advance Purchase options error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load Local Advance Purchase options.' : $error->getMessage(),
    ], $status);
}
