<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';

function localAdvanceSummaryMoney(mixed $value): string
{
    return number_format((float) ($value ?? 0), 2, '.', '');
}

try {
    procurementEnsureAuthenticationTables($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequirePermission($conn, 'payments.local_advance.view');

    procurementAssertLocalAdvancePurchaseReadStorage($conn);

    $search = trim((string) ($_GET['search'] ?? ''));
    $year = (int) ($_GET['year'] ?? 0);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);

    if ($year !== 0 && ($year < 2000 || $year > 2100)) {
        throw new RuntimeException('Year filter is invalid.', 400);
    }

    $where = ['r.deleted_at IS NULL'];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(r.request_number LIKE ? OR r.purchase_number LIKE ? OR p.po_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ? OR p.supplier_ledger LIKE ?)';
        $like = '%' . $search . '%';
        for ($index = 0; $index < 7; $index++) {
            $params[] = $like;
        }
        $types .= 'sssssss';
    }

    if ($year > 0) {
        $where[] = 'r.transaction_date >= ? AND r.transaction_date < ?';
        $params[] = sprintf('%04d-01-01', $year);
        $params[] = sprintf('%04d-01-01', $year + 1);
        $types .= 'ss';
    }
    if ($projectId > 0) {
        $where[] = 'p.project_id = ?';
        $params[] = $projectId;
        $types .= 'i';
    }
    if ($supplierId > 0) {
        $where[] = 'p.supplier_id = ?';
        $params[] = $supplierId;
        $types .= 'i';
    }

    $whereSql = implode(' AND ', $where);
    $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_requests,
            COUNT(DISTINCT r.po_id) AS total_pos,
            COALESCE(SUM(r.expected_payment), 0.00) AS total_expected_payment,

            COALESCE(SUM(CASE WHEN r.approval_status = 'Unapproved' THEN 1 ELSE 0 END), 0) AS unapproved_count,
            COALESCE(SUM(CASE WHEN r.approval_status = 'Approved' THEN 1 ELSE 0 END), 0) AS approved_count,
            COALESCE(SUM(CASE WHEN r.payment_status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN r.payment_status = 'Processing' THEN 1 ELSE 0 END), 0) AS processing_count,
            COALESCE(SUM(CASE WHEN r.payment_status = 'Paid' THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN r.payment_status = 'Failed' THEN 1 ELSE 0 END), 0) AS failed_count,
            COALESCE(SUM(CASE WHEN r.payment_status = 'Cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count,

            COALESCE(SUM(CASE WHEN r.approval_status = 'Unapproved' AND r.handoff_status = 'Not Sent' THEN 1 ELSE 0 END), 0) AS pending_approval_count,
            COALESCE(SUM(CASE WHEN r.approval_status = 'Unapproved' AND r.handoff_status = 'Not Sent' THEN r.expected_payment ELSE 0.00 END), 0.00) AS pending_approval_value,

            COALESCE(SUM(CASE WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' THEN 1 ELSE 0 END), 0) AS approval_workspace_approved_count,
            COALESCE(SUM(CASE WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' THEN r.expected_payment ELSE 0.00 END), 0.00) AS approval_workspace_approved_value,

            COALESCE(SUM(CASE WHEN r.handoff_status = 'Retrieved' THEN 1 ELSE 0 END), 0) AS retrieved_count,
            COALESCE(SUM(CASE WHEN r.handoff_status = 'Retrieved' THEN r.expected_payment ELSE 0.00 END), 0.00) AS retrieved_value
         FROM {$localAdvanceRelation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE $whereSql"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Local Advance Purchase summary.', 500);
    }
    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ([
        'total_requests', 'total_pos', 'unapproved_count', 'approved_count', 'pending_count',
        'processing_count', 'paid_count', 'failed_count', 'cancelled_count',
    ] as $field) {
        $summary[$field] = (int) ($summary[$field] ?? 0);
    }
    $summary['total_expected_payment'] = localAdvanceSummaryMoney($summary['total_expected_payment'] ?? 0);

    $metric = static function (string $countField, string $valueField) use ($summary): array {
        return [
            'count' => (int) ($summary[$countField] ?? 0),
            'value' => localAdvanceSummaryMoney($summary[$valueField] ?? 0),
        ];
    };

    $poStatuses = $conn->prepare(
        "SELECT p.po_status, COUNT(DISTINCT p.id) AS total
         FROM {$localAdvanceRelation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE $whereSql
         GROUP BY p.po_status ORDER BY p.po_status ASC"
    );
    if (!$poStatuses) {
        throw new RuntimeException('Unable to prepare the Local Advance Purchase PO summary.', 500);
    }
    if ($params !== []) {
        $poStatuses->bind_param($types, ...$params);
    }
    $poStatuses->execute();
    $statusRows = $poStatuses->get_result()->fetch_all(MYSQLI_ASSOC);
    $poStatuses->close();
    foreach ($statusRows as &$row) {
        $row['total'] = (int) $row['total'];
    }
    unset($row);

    jsonResponse([
        'status' => 'Success',
        'data' => [
            'summary' => $summary,
            'pending_approval' => $metric('pending_approval_count', 'pending_approval_value'),
            'approved' => $metric('approval_workspace_approved_count', 'approval_workspace_approved_value'),
            'retrieved' => $metric('retrieved_count', 'retrieved_value'),
            'po_statuses' => $statusRows,
            'filters' => [
                'search' => $search,
                'year' => $year ?: null,
                'project_id' => $projectId ?: null,
                'supplier_id' => $supplierId ?: null,
            ],
        ],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Local Advance Purchase summary error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load the Local Advance Purchase summary.' : $error->getMessage(),
    ], $status);
}
