<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementRequestCanonicalRuntimeService.php';

function procurementDashboardFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the procurement dashboard summary.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function procurementDashboardFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare procurement dashboard data.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function procurementDashboardVisibleRequestTypes(array $user): array
{
    $permissionMap = [
        'local_final_purchase' => 'payments.local_final.view',
        'local_advance_purchase' => 'payments.local_advance.view',
        'fx_final_purchase' => 'payments.fx_final.view',
        'fx_advance_purchase' => 'payments.fx_advance.view',
    ];

    if ((string) ($user['role'] ?? '') === 'super_admin') {
        return array_keys($permissionMap);
    }

    $permissions = array_flip(array_map('strval', $user['permissions'] ?? []));
    return array_values(array_filter(
        array_keys($permissionMap),
        static fn(string $requestType): bool => isset($permissions[$permissionMap[$requestType]])
    ));
}

function procurementDashboardRequestLabel(string $requestType): string
{
    return match ($requestType) {
        'local_final_purchase' => 'Local Final',
        'local_advance_purchase' => 'Local Advance',
        'fx_final_purchase' => 'FX Final',
        'fx_advance_purchase' => 'FX Advance',
        default => 'Purchase',
    };
}

function procurementDashboardRequestMixDefaults(array $visibleTypes): array
{
    $rows = [];
    foreach ($visibleTypes as $requestType) {
        $rows[] = [
            'key' => $requestType,
            'label' => procurementDashboardRequestLabel($requestType),
            'created_this_month' => 0,
            'pending_approval' => 0,
            'with_accounts' => 0,
        ];
    }
    return $rows;
}

function procurementDashboardRequestRoute(string $requestType, int $publicId): ?string
{
    if ($publicId <= 0) {
        return null;
    }

    return match ($requestType) {
        'local_final_purchase' => "/payments/local/final/{$publicId}",
        'local_advance_purchase' => "/payments/local/advance/{$publicId}",
        'fx_final_purchase' => "/payments/foreign/final/{$publicId}",
        'fx_advance_purchase' => "/payments/foreign/advance/{$publicId}",
        default => null,
    };
}

function procurementDashboardStatus(array $row): string
{
    $payment = trim((string) ($row['payment_status'] ?? ''));
    $approval = trim((string) ($row['approval_status'] ?? ''));
    $handoff = trim((string) ($row['handoff_status'] ?? ''));
    $poStatus = trim((string) ($row['po_status'] ?? ''));

    if (strcasecmp($payment, 'Cancelled') === 0 || strcasecmp($poStatus, 'Cancelled') === 0) {
        return 'Cancelled';
    }
    if (strcasecmp($payment, 'Failed') === 0) {
        return 'Failed';
    }
    if (strcasecmp($payment, 'Paid') === 0) {
        return 'Paid';
    }
    if (strcasecmp($handoff, 'Retrieved') === 0) {
        return 'Returned';
    }
    if (strcasecmp($approval, 'Unapproved') === 0) {
        return 'Pending approval';
    }
    if (strcasecmp($handoff, 'In Account') === 0) {
        return 'With accounts';
    }
    if (strcasecmp($approval, 'Approved') === 0) {
        return 'Approved';
    }

    return $payment !== '' ? $payment : 'Open';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    $user = procurementRequirePermission($conn, 'dashboard.view');
    $visibleTypes = procurementDashboardVisibleRequestTypes($user);

    $requiredColumns = [
        'id', 'request_type', 'request_number', 'legacy_source_id', 'po_id', 'po_revision_id',
        'po_number', 'purchase_number', 'currency', 'supplier_name', 'purchase_value',
        'expected_payment', 'approval_status',
        'handoff_status', 'payment_status', 'po_status', 'approved_at', 'retrieved_at',
        'account_paid_at', 'payment_status_updated_at', 'account_request_id', 'created_by',
        'created_at', 'updated_at', 'deleted_at',
    ];
    if (!procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $requiredColumns)) {
        throw new RuntimeException('Procurement dashboard storage is unavailable.', 500);
    }

    $now = new DateTimeImmutable('now');
    $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
    $nextWeekStart = $weekStart->modify('+7 days');
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $nextMonthStart = $monthStart->modify('+1 month');

    $zeroData = [
        'generated_at' => $now->format(DATE_ATOM),
        'metrics' => [
            'total_requests' => 0,
            'created_this_month' => 0,
            'pending_approval' => 0,
            'approved_this_week' => 0,
            'approved_this_month' => 0,
            'awaiting_accounts' => 0,
            'returned_total' => 0,
            'returned_this_week' => 0,
            'paid_this_week' => 0,
            'active_officers' => 0,
            'approved_total' => 0,
            'handed_to_accounts' => 0,
        ],
        'flow' => [
            'approval_rate' => 0.0,
            'handoff_rate' => 0.0,
            'awaiting_action' => 0,
            'completed_this_week' => 0,
        ],
        'pipeline' => [
            ['key' => 'created', 'label' => 'Created this month', 'value' => 0, 'detail' => 'New purchase requests', 'tone' => 'blue'],
            ['key' => 'pending', 'label' => 'Pending review', 'value' => 0, 'detail' => 'Awaiting approval', 'tone' => 'amber'],
            ['key' => 'approved', 'label' => 'Approved this month', 'value' => 0, 'detail' => 'Approved purchase requests', 'tone' => 'teal'],
            ['key' => 'accounts', 'label' => 'With accounts', 'value' => 0, 'detail' => 'Open accounting handoffs', 'tone' => 'violet'],
        ],
        'approval_pulse' => [
            'days' => array_map(static fn(string $day): array => ['day' => $day, 'value' => 0], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']),
            'average_hours' => null,
            'approved_count' => 0,
            'returned_count' => 0,
            'within_24h_rate' => 0.0,
        ],
        'request_mix' => procurementDashboardRequestMixDefaults($visibleTypes),
        'attention' => [
            'pending_over_48h' => 0,
            'accounts_over_7d' => 0,
            'failed_payments' => 0,
            'returned_open' => 0,
        ],
        'recent_requests' => [],
        'scope' => [
            'role' => (string) ($user['role'] ?? ''),
            'user_id' => (int) ($user['id'] ?? 0),
            'own_requests_only' => (string) ($user['role'] ?? '') === 'officer',
            'visible_request_types' => $visibleTypes,
        ],
    ];

    if ($visibleTypes === []) {
        jsonResponse(['status' => 'Success', 'data' => $zeroData]);
    }

    $typePlaceholders = implode(', ', array_fill(0, count($visibleTypes), '?'));
    $scopeSql = "r.deleted_at IS NULL AND r.request_type IN ({$typePlaceholders})";
    $scopeTypes = str_repeat('s', count($visibleTypes));
    $scopeParams = $visibleTypes;

    if ((string) ($user['role'] ?? '') === 'officer') {
        $scopeSql .= ' AND r.created_by = ?';
        $scopeTypes .= 'i';
        $scopeParams[] = (int) $user['id'];
    }

    $summarySql = "SELECT
        COUNT(*) AS total_requests,
        SUM(CASE WHEN r.created_at >= ? AND r.created_at < ? THEN 1 ELSE 0 END) AS created_this_month,
        SUM(CASE WHEN r.approval_status = 'Unapproved' AND r.handoff_status <> 'Retrieved' AND r.payment_status <> 'Cancelled' THEN 1 ELSE 0 END) AS pending_approval,
        SUM(CASE WHEN r.approval_status = 'Approved' AND r.approved_at >= ? AND r.approved_at < ? THEN 1 ELSE 0 END) AS approved_this_week,
        SUM(CASE WHEN r.approval_status = 'Approved' AND r.approved_at >= ? AND r.approved_at < ? THEN 1 ELSE 0 END) AS approved_this_month,
        SUM(CASE WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' AND r.payment_status NOT IN ('Paid', 'Cancelled') THEN 1 ELSE 0 END) AS awaiting_accounts,
        SUM(CASE WHEN r.handoff_status = 'Retrieved' THEN 1 ELSE 0 END) AS returned_total,
        SUM(CASE WHEN r.handoff_status = 'Retrieved' AND r.retrieved_at >= ? AND r.retrieved_at < ? THEN 1 ELSE 0 END) AS returned_this_week,
        SUM(CASE WHEN r.payment_status = 'Paid' AND COALESCE(r.account_paid_at, r.payment_status_updated_at, r.updated_at) >= ? AND COALESCE(r.account_paid_at, r.payment_status_updated_at, r.updated_at) < ? THEN 1 ELSE 0 END) AS paid_this_week,
        SUM(CASE WHEN r.approval_status = 'Unapproved' AND r.handoff_status <> 'Retrieved' AND r.payment_status <> 'Cancelled' AND r.created_at < ? THEN 1 ELSE 0 END) AS pending_over_48h,
        SUM(CASE WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' AND r.payment_status NOT IN ('Paid', 'Cancelled') AND COALESCE(r.approved_at, r.created_at) < ? THEN 1 ELSE 0 END) AS accounts_over_7d,
        SUM(CASE WHEN r.payment_status = 'Failed' THEN 1 ELSE 0 END) AS failed_payments,
        SUM(CASE WHEN r.handoff_status = 'Retrieved' AND r.payment_status <> 'Cancelled' THEN 1 ELSE 0 END) AS returned_open,
        SUM(CASE WHEN r.approval_status = 'Approved' THEN 1 ELSE 0 END) AS approved_total,
        SUM(CASE WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' THEN 1 ELSE 0 END) AS handed_to_accounts
      FROM procurement_requests r
      WHERE {$scopeSql}";

    $summaryDateParams = [
        $monthStart->format('Y-m-d H:i:s'), $nextMonthStart->format('Y-m-d H:i:s'),
        $weekStart->format('Y-m-d H:i:s'), $nextWeekStart->format('Y-m-d H:i:s'),
        $monthStart->format('Y-m-d H:i:s'), $nextMonthStart->format('Y-m-d H:i:s'),
        $weekStart->format('Y-m-d H:i:s'), $nextWeekStart->format('Y-m-d H:i:s'),
        $weekStart->format('Y-m-d H:i:s'), $nextWeekStart->format('Y-m-d H:i:s'),
        $now->modify('-48 hours')->format('Y-m-d H:i:s'),
        $now->modify('-7 days')->format('Y-m-d H:i:s'),
    ];
    $summary = procurementDashboardFetchOne(
        $conn,
        $summarySql,
        str_repeat('s', count($summaryDateParams)) . $scopeTypes,
        array_merge($summaryDateParams, $scopeParams)
    );

    $activeOfficers = 0;
    if ((string) ($user['role'] ?? '') === 'super_admin') {
        $activeOfficerRow = procurementDashboardFetchOne(
            $conn,
            "SELECT COUNT(*) AS total
             FROM procurement_user_access pua
             INNER JOIN user_table u ON u.id = pua.user_id
             WHERE pua.role = 'officer' AND pua.is_active = 1 AND u.status = 'Active'"
        );
        $activeOfficers = (int) ($activeOfficerRow['total'] ?? 0);
    }

    $pulseSql = "SELECT
        DATE(r.approved_at) AS approved_date,
        COUNT(*) AS approved_count,
        AVG(TIMESTAMPDIFF(SECOND, r.created_at, r.approved_at)) / 3600 AS average_hours,
        SUM(CASE WHEN TIMESTAMPDIFF(SECOND, r.created_at, r.approved_at) <= 86400 THEN 1 ELSE 0 END) AS within_24h_count
      FROM procurement_requests r
      WHERE {$scopeSql}
        AND r.approval_status = 'Approved'
        AND r.approved_at >= ? AND r.approved_at < ?
      GROUP BY DATE(r.approved_at)
      ORDER BY approved_date ASC";
    $pulseRows = procurementDashboardFetchAll(
        $conn,
        $pulseSql,
        $scopeTypes . 'ss',
        array_merge($scopeParams, [$weekStart->format('Y-m-d H:i:s'), $nextWeekStart->format('Y-m-d H:i:s')])
    );

    $pulseByDate = [];
    $pulseApprovalCount = 0;
    $pulseWithin24Count = 0;
    $weightedHours = 0.0;
    foreach ($pulseRows as $row) {
        $count = (int) ($row['approved_count'] ?? 0);
        $pulseByDate[(string) ($row['approved_date'] ?? '')] = $count;
        $pulseApprovalCount += $count;
        $pulseWithin24Count += (int) ($row['within_24h_count'] ?? 0);
        $weightedHours += ((float) ($row['average_hours'] ?? 0)) * $count;
    }

    $pulseDays = [];
    for ($offset = 0; $offset < 5; $offset++) {
        $date = $weekStart->modify("+{$offset} days");
        $pulseDays[] = [
            'day' => $date->format('D'),
            'value' => (int) ($pulseByDate[$date->format('Y-m-d')] ?? 0),
        ];
    }

    $mixSql = "SELECT
        r.request_type,
        SUM(CASE WHEN r.created_at >= ? AND r.created_at < ? THEN 1 ELSE 0 END) AS created_this_month,
        SUM(CASE WHEN r.approval_status = 'Unapproved' AND r.handoff_status <> 'Retrieved' AND r.payment_status <> 'Cancelled' THEN 1 ELSE 0 END) AS pending_approval,
        SUM(CASE WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' AND r.payment_status NOT IN ('Paid', 'Cancelled') THEN 1 ELSE 0 END) AS with_accounts
      FROM procurement_requests r
      WHERE {$scopeSql}
      GROUP BY r.request_type";
    $mixRows = procurementDashboardFetchAll(
        $conn,
        $mixSql,
        'ss' . $scopeTypes,
        array_merge([$monthStart->format('Y-m-d H:i:s'), $nextMonthStart->format('Y-m-d H:i:s')], $scopeParams)
    );
    $mixByType = [];
    foreach ($mixRows as $row) {
        $mixByType[(string) ($row['request_type'] ?? '')] = $row;
    }
    $requestMix = array_map(static function (string $requestType) use ($mixByType): array {
        $row = $mixByType[$requestType] ?? [];
        return [
            'key' => $requestType,
            'label' => procurementDashboardRequestLabel($requestType),
            'created_this_month' => (int) ($row['created_this_month'] ?? 0),
            'pending_approval' => (int) ($row['pending_approval'] ?? 0),
            'with_accounts' => (int) ($row['with_accounts'] ?? 0),
        ];
    }, $visibleTypes);

    // Advance purchases keep their commercial identity on the shared PO/revision rows.
    // Resolve those values here so the dashboard does not fall back to LAP/FXA request IDs
    // or "Supplier not recorded" when a valid PO supplier exists.
    $recentSql = "SELECT
        r.id AS canonical_id,
        r.legacy_source_id AS public_id,
        r.request_type,
        r.request_number,
        r.purchase_number,
        COALESCE(NULLIF(TRIM(apr.po_number), ''), NULLIF(TRIM(ap.po_number), ''), NULLIF(TRIM(r.po_number), '')) AS resolved_po_number,
        COALESCE(NULLIF(TRIM(apr.supplier_name), ''), NULLIF(TRIM(ap.supplier_name), ''), NULLIF(TRIM(r.supplier_name), '')) AS resolved_supplier_name,
        r.currency,
        r.purchase_value,
        r.expected_payment,
        r.approval_status,
        r.handoff_status,
        r.payment_status,
        r.po_status,
        r.created_at,
        CONCAT(TRIM(COALESCE(u.fname, '')), CASE WHEN COALESCE(u.fname, '') <> '' AND COALESCE(u.lname, '') <> '' THEN ' ' ELSE '' END, TRIM(COALESCE(u.lname, ''))) AS officer_name
      FROM procurement_requests r
      LEFT JOIN procurement_local_advance_pos ap
        ON ap.id = r.po_id
       AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
      LEFT JOIN procurement_local_advance_po_revisions apr
        ON apr.id = r.po_revision_id
       AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
      LEFT JOIN user_table u ON u.id = r.created_by
      WHERE {$scopeSql}
      ORDER BY r.created_at DESC, r.id DESC
      LIMIT 8";
    $recentRows = procurementDashboardFetchAll($conn, $recentSql, $scopeTypes, $scopeParams);

    $recentRequests = array_map(static function (array $row): array {
        $requestType = (string) ($row['request_type'] ?? '');
        $publicId = (int) ($row['public_id'] ?? 0);
        $isAdvance = in_array($requestType, ['local_advance_purchase', 'fx_advance_purchase'], true);
        $isLocal = in_array($requestType, ['local_final_purchase', 'local_advance_purchase'], true);
        $reference = trim((string) ($row['resolved_po_number'] ?? ''));
        if ($reference === '') {
            $reference = trim((string) ($row['purchase_number'] ?? ''));
        }
        if ($reference === '') {
            $reference = trim((string) ($row['request_number'] ?? ''));
        }
        if ($reference === '') {
            $reference = $publicId > 0 ? 'Request #' . $publicId : 'Procurement request';
        }

        return [
            'id' => $requestType . ':' . ($publicId > 0 ? $publicId : (int) ($row['canonical_id'] ?? 0)),
            'public_id' => $publicId,
            'request_type' => $requestType,
            'request_label' => procurementDashboardRequestLabel($requestType),
            'reference' => $reference,
            'vendor' => trim((string) ($row['resolved_supplier_name'] ?? '')) ?: 'Supplier not recorded',
            'officer' => trim((string) ($row['officer_name'] ?? '')) ?: 'Not recorded',
            'amount' => (float) ($isAdvance ? ($row['expected_payment'] ?? 0) : ($row['purchase_value'] ?? 0)),
            'currency' => $isLocal ? 'NGN' : (strtoupper(trim((string) ($row['currency'] ?? ''))) ?: 'UNKNOWN'),
            'submitted_at' => (string) ($row['created_at'] ?? ''),
            'status' => procurementDashboardStatus($row),
            'route' => procurementDashboardRequestRoute($requestType, $publicId),
        ];
    }, $recentRows);

    $totalRequests = (int) ($summary['total_requests'] ?? 0);
    $approvedTotal = (int) ($summary['approved_total'] ?? 0);
    $handedToAccounts = (int) ($summary['handed_to_accounts'] ?? 0);
    $approvalRate = $totalRequests > 0 ? round(($approvedTotal / $totalRequests) * 100, 2) : 0.0;
    $handoffRate = $approvedTotal > 0 ? round(($handedToAccounts / $approvedTotal) * 100, 2) : 0.0;

    $data = $zeroData;
    $data['metrics'] = [
        'total_requests' => $totalRequests,
        'created_this_month' => (int) ($summary['created_this_month'] ?? 0),
        'pending_approval' => (int) ($summary['pending_approval'] ?? 0),
        'approved_this_week' => (int) ($summary['approved_this_week'] ?? 0),
        'approved_this_month' => (int) ($summary['approved_this_month'] ?? 0),
        'awaiting_accounts' => (int) ($summary['awaiting_accounts'] ?? 0),
        'returned_total' => (int) ($summary['returned_total'] ?? 0),
        'returned_this_week' => (int) ($summary['returned_this_week'] ?? 0),
        'paid_this_week' => (int) ($summary['paid_this_week'] ?? 0),
        'active_officers' => $activeOfficers,
        'approved_total' => $approvedTotal,
        'handed_to_accounts' => $handedToAccounts,
    ];
    $data['flow'] = [
        'approval_rate' => $approvalRate,
        'handoff_rate' => $handoffRate,
        'awaiting_action' => (int) ($summary['pending_approval'] ?? 0),
        'completed_this_week' => (int) ($summary['paid_this_week'] ?? 0),
    ];
    $data['pipeline'] = [
        ['key' => 'created', 'label' => 'Created this month', 'value' => (int) ($summary['created_this_month'] ?? 0), 'detail' => 'New purchase requests', 'tone' => 'blue'],
        ['key' => 'pending', 'label' => 'Pending review', 'value' => (int) ($summary['pending_approval'] ?? 0), 'detail' => 'Awaiting approval', 'tone' => 'amber'],
        ['key' => 'approved', 'label' => 'Approved this month', 'value' => (int) ($summary['approved_this_month'] ?? 0), 'detail' => 'Approved purchase requests', 'tone' => 'teal'],
        ['key' => 'accounts', 'label' => 'With accounts', 'value' => (int) ($summary['awaiting_accounts'] ?? 0), 'detail' => 'Open accounting handoffs', 'tone' => 'violet'],
    ];
    $data['approval_pulse'] = [
        'days' => $pulseDays,
        'average_hours' => $pulseApprovalCount > 0 ? round($weightedHours / $pulseApprovalCount, 1) : null,
        'approved_count' => $pulseApprovalCount,
        'returned_count' => (int) ($summary['returned_this_week'] ?? 0),
        'within_24h_rate' => $pulseApprovalCount > 0 ? round(($pulseWithin24Count / $pulseApprovalCount) * 100, 2) : 0.0,
    ];
    $data['request_mix'] = $requestMix;
    $data['attention'] = [
        'pending_over_48h' => (int) ($summary['pending_over_48h'] ?? 0),
        'accounts_over_7d' => (int) ($summary['accounts_over_7d'] ?? 0),
        'failed_payments' => (int) ($summary['failed_payments'] ?? 0),
        'returned_open' => (int) ($summary['returned_open'] ?? 0),
    ];
    $data['recent_requests'] = $recentRequests;

    jsonResponse(['status' => 'Success', 'data' => $data]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('ProcureDesk dashboard overview error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load the procurement dashboard.' : $error->getMessage(),
    ], $status);
}
