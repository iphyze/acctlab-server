<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementLocalFinalPurchaseService.php';

function localFinalSummaryMoney(mixed $value): string
{
    return number_format((float) ($value ?? 0), 2, '.', '');
}

try {
    procurementEnsureAuthenticationTables($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequirePermission($conn, 'payments.local_final.view');

    procurementAssertLocalFinalPurchaseReadStorage($conn);

    $search = trim((string) ($_GET['search'] ?? ''));
    $year = (int) ($_GET['year'] ?? 0);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);

    if ($year !== 0 && ($year < 2000 || $year > 2100)) {
        throw new RuntimeException('Year filter is invalid.', 400);
    }

    $where = ['p.deleted_at IS NULL'];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(p.purchase_number LIKE ? OR p.po_number LIKE ? OR p.grn_ref LIKE ? OR p.invoice_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ? OR p.supplier_ledger LIKE ?)';
        $like = '%' . $search . '%';
        for ($index = 0; $index < 8; $index++) {
            $params[] = $like;
        }
        $types .= 'ssssssss';
    }

    if ($year > 0) {
        $where[] = 'p.purchase_date >= ? AND p.purchase_date < ?';
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
    $localFinalRelation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(p.purchase_value), 0.00) AS total_value,

            SUM(CASE WHEN p.approval_status = 'Unapproved' AND p.handoff_status <> 'Retrieved' THEN 1 ELSE 0 END) AS pending_approval_count,
            COALESCE(SUM(CASE WHEN p.approval_status = 'Unapproved' AND p.handoff_status <> 'Retrieved' THEN p.purchase_value ELSE 0.00 END), 0.00) AS pending_approval_value,

            SUM(CASE WHEN p.approval_status = 'Approved' AND p.handoff_status = 'In Account' THEN 1 ELSE 0 END) AS approved_count,
            COALESCE(SUM(CASE WHEN p.approval_status = 'Approved' AND p.handoff_status = 'In Account' THEN p.purchase_value ELSE 0.00 END), 0.00) AS approved_value,

            SUM(CASE WHEN p.handoff_status = 'Retrieved' THEN 1 ELSE 0 END) AS retrieved_count,
            COALESCE(SUM(CASE WHEN p.handoff_status = 'Retrieved' THEN p.purchase_value ELSE 0.00 END), 0.00) AS retrieved_value,

            SUM(CASE WHEN p.approval_status = 'Approved' AND p.handoff_status = 'In Account' AND p.payment_status IN ('Pending', 'Processing') THEN 1 ELSE 0 END) AS awaiting_payment_count,
            COALESCE(SUM(CASE WHEN p.approval_status = 'Approved' AND p.handoff_status = 'In Account' AND p.payment_status IN ('Pending', 'Processing') THEN p.purchase_value ELSE 0.00 END), 0.00) AS awaiting_payment_value,

            SUM(CASE WHEN p.payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' THEN p.purchase_value ELSE 0.00 END), 0.00) AS paid_value,
            COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' THEN p.account_amount_paid ELSE 0.00 END), 0.00) AS paid_actual_value,

            SUM(CASE WHEN p.po_status = 'Cancelled' OR p.payment_status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            COALESCE(SUM(CASE WHEN p.po_status = 'Cancelled' OR p.payment_status = 'Cancelled' THEN p.purchase_value ELSE 0.00 END), 0.00) AS cancelled_value
         FROM {$localFinalRelation} p
         WHERE $whereSql"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Local Final Purchase summary.', 500);
    }
    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $metric = static function (string $prefix) use ($row): array {
        return [
            'count' => (int) ($row[$prefix . '_count'] ?? 0),
            'value' => localFinalSummaryMoney($row[$prefix . '_value'] ?? 0),
        ];
    };

    jsonResponse([
        'status' => 'Success',
        'data' => [
            'scope' => [
                'search' => $search,
                'year' => $year > 0 ? $year : null,
                'project_id' => $projectId > 0 ? $projectId : null,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
            ],
            'total' => [
                'count' => (int) ($row['total_count'] ?? 0),
                'value' => localFinalSummaryMoney($row['total_value'] ?? 0),
            ],
            'pending_approval' => $metric('pending_approval'),
            'approved' => $metric('approved'),
            'retrieved' => $metric('retrieved'),
            'awaiting_payment' => $metric('awaiting_payment'),
            'paid' => [
                'count' => (int) ($row['paid_count'] ?? 0),
                'value' => localFinalSummaryMoney($row['paid_value'] ?? 0),
                'amount_paid' => localFinalSummaryMoney($row['paid_actual_value'] ?? 0),
            ],
            'cancelled' => $metric('cancelled'),
        ],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Local Final Purchase summary error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load the Local Final Purchase summary.' : $error->getMessage(),
    ], $status);
}
