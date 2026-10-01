<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';

const PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS = [
    'local_final' => ['request_type' => 'local_final_purchase', 'permission' => 'payments.local_final.view', 'label' => 'Local Final'],
    'local_advance' => ['request_type' => 'local_advance_purchase', 'permission' => 'payments.local_advance.view', 'label' => 'Local Advance'],
    'fx_final' => ['request_type' => 'fx_final_purchase', 'permission' => 'payments.fx_final.view', 'label' => 'FX Final'],
    'fx_advance' => ['request_type' => 'fx_advance_purchase', 'permission' => 'payments.fx_advance.view', 'label' => 'FX Advance'],
];

const PROCUREMENT_SUPPLIER_ACTIVITY_WORKFLOW_STATUSES = [
    'Pending Approval',
    'Approved',
    'With Accounts',
    'Returned',
];

const PROCUREMENT_SUPPLIER_ACTIVITY_PAYMENT_STATUSES = [
    'Pending',
    'Processing',
    'Paid',
    'Failed',
    'Cancelled',
];

const PROCUREMENT_SUPPLIER_ACTIVITY_DATE_BASES = [
    'all',
    'request_date',
    'received_date',
    'payment_date',
];

function procurementSupplierActivityReportPermissionCodes(): array
{
    return array_values(array_unique(array_map(
        static fn(array $scope): string => (string) $scope['permission'],
        PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS
    )));
}

function procurementSupplierActivityReportVisibleScopes(array $user): array
{
    if ((string) ($user['role'] ?? '') === 'super_admin') {
        return array_keys(PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS);
    }

    $permissions = array_flip(array_map('strval', $user['permissions'] ?? []));
    $scopes = [];
    foreach (PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS as $key => $config) {
        if (isset($permissions[(string) $config['permission']])) {
            $scopes[] = $key;
        }
    }
    return $scopes;
}

function procurementSupplierActivityReportParseMultiValue(mixed $value): array
{
    $values = is_array($value) ? $value : [$value];
    $normalized = [];
    foreach ($values as $item) {
        foreach (explode(',', (string) $item) as $part) {
            $part = trim($part);
            if ($part === '' || strcasecmp($part, 'all') === 0) {
                continue;
            }
            $normalized[] = $part;
        }
    }
    return array_values(array_unique($normalized));
}

function procurementSupplierActivityReportValidateDate(?string $value, string $label): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        throw new RuntimeException($label . ' must use YYYY-MM-DD format.', 400);
    }
    return $date->format('Y-m-d');
}

function procurementSupplierActivityReportValidateSelection(array $values, array $allowed, string $label): array
{
    $lookup = array_flip(array_map('strtolower', $allowed));
    $result = [];
    foreach ($values as $value) {
        $key = strtolower(trim((string) $value));
        if (!isset($lookup[$key])) {
            throw new RuntimeException('Invalid ' . $label . ' filter: ' . $value, 400);
        }
        $result[] = $allowed[$lookup[$key]];
    }
    return array_values(array_unique($result));
}

function procurementSupplierActivityReportAssertStorage(mysqli $conn): void
{
    $requiredRequestColumns = [
        'id', 'legacy_source_id', 'request_type', 'request_number', 'po_id', 'po_revision_id',
        'po_number', 'purchase_number', 'invoice_number', 'project_id', 'project_code', 'project_name',
        'supplier_id', 'supplier_name', 'currency', 'purchase_date', 'transaction_date', 'date_received',
        'purchase_value', 'po_percentage', 'expected_payment', 'account_expected_payment',
        'account_payable_amount', 'wht_amount', 'account_wht_override_amount', 'account_amount_paid',
        'approval_status', 'handoff_status', 'payment_status', 'po_status', 'approved_at',
        'account_paid_at', 'payment_status_updated_at', 'created_by', 'created_at', 'updated_at', 'deleted_at',
    ];
    if (!procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $requiredRequestColumns)) {
        throw new RuntimeException('Supplier Activity Report storage is incomplete.', 500);
    }

    foreach (['procurement_local_advance_pos', 'procurement_local_advance_po_revisions'] as $table) {
        $required = [
            'id', 'po_number', 'project_id', 'project_code', 'project_name', 'supplier_id', 'supplier_name',
            'po_value', 'currency', 'po_status',
        ];
        if (!procurementRequestCanonicalRuntimeColumnsReady($conn, $table, $required)) {
            throw new RuntimeException('Advance PO reporting storage is incomplete.', 500);
        }
    }
}

function procurementSupplierActivityReportBaseSql(array $requestTypes): array
{
    $placeholders = implode(', ', array_fill(0, count($requestTypes), '?'));
    $types = str_repeat('s', count($requestTypes));

    // Keep all financial calculations in this one canonical projection so the screen,
    // summaries, aging analysis and Excel export can never drift to different formulas.
    // Downstream semantics are equivalent to: report.payment_status = 'Cancelled' THEN 0.00 outstanding.
    $coreSql = "SELECT
        r.id AS canonical_id,
        COALESCE(NULLIF(r.legacy_source_id, 0), r.id) AS public_id,
        r.request_type,
        CASE r.request_type
            WHEN 'local_final_purchase' THEN 'Local Final'
            WHEN 'local_advance_purchase' THEN 'Local Advance'
            WHEN 'fx_final_purchase' THEN 'FX Final'
            WHEN 'fx_advance_purchase' THEN 'FX Advance'
            ELSE 'Purchase'
        END AS scope_label,
        r.request_number,
        r.purchase_number,
        COALESCE(NULLIF(TRIM(apr.po_number), ''), NULLIF(TRIM(ap.po_number), ''), NULLIF(TRIM(r.po_number), '')) AS po_number,
        r.invoice_number,
        COALESCE(apr.project_id, ap.project_id, r.project_id) AS project_id,
        COALESCE(NULLIF(TRIM(apr.project_code), ''), NULLIF(TRIM(ap.project_code), ''), NULLIF(TRIM(r.project_code), '')) AS project_code,
        COALESCE(NULLIF(TRIM(apr.project_name), ''), NULLIF(TRIM(ap.project_name), ''), NULLIF(TRIM(r.project_name), '')) AS project_name,
        COALESCE(apr.supplier_id, ap.supplier_id, r.supplier_id) AS supplier_id,
        COALESCE(NULLIF(TRIM(apr.supplier_name), ''), NULLIF(TRIM(ap.supplier_name), ''), NULLIF(TRIM(r.supplier_name), '')) AS supplier_name,
        CASE
            WHEN r.request_type IN ('local_final_purchase', 'local_advance_purchase') THEN 'NGN'
            ELSE UPPER(COALESCE(NULLIF(TRIM(r.currency), ''), NULLIF(TRIM(apr.currency), ''), NULLIF(TRIM(ap.currency), ''), 'UNKNOWN'))
        END AS currency,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN COALESCE(r.transaction_date, DATE(r.created_at))
            ELSE COALESCE(r.purchase_date, DATE(r.created_at))
        END AS request_date,
        COALESCE(r.date_received, DATE(r.approved_at)) AS received_date,
        CASE
            WHEN r.payment_status = 'Paid' THEN DATE(COALESCE(r.account_paid_at, r.payment_status_updated_at, r.updated_at))
            ELSE NULL
        END AS payment_date,
        COALESCE(
            r.date_received,
            DATE(r.approved_at),
            CASE
                WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN COALESCE(r.transaction_date, DATE(r.created_at))
                ELSE COALESCE(r.purchase_date, DATE(r.created_at))
            END
        ) AS aging_date,
        CASE
            WHEN r.handoff_status = 'Retrieved' THEN 'Returned'
            WHEN r.approval_status = 'Approved' AND r.handoff_status = 'In Account' THEN 'With Accounts'
            WHEN r.approval_status = 'Approved' THEN 'Approved'
            ELSE 'Pending Approval'
        END AS workflow_status,
        COALESCE(NULLIF(TRIM(r.payment_status), ''), 'Pending') AS payment_status,
        COALESCE(NULLIF(TRIM(r.handoff_status), ''), 'Not Sent') AS account_status,
        COALESCE(NULLIF(TRIM(r.po_status), ''), NULLIF(TRIM(ap.po_status), ''), 'Unclosed') AS po_status,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN CAST(REPLACE(COALESCE(NULLIF(r.po_percentage, ''), '0'), '%', '') AS DECIMAL(18,6))
            ELSE 100.000000
        END AS advance_percentage,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN
                ROUND(
                    COALESCE(apr.po_value, ap.po_value, 0.00)
                    * CAST(REPLACE(COALESCE(NULLIF(r.po_percentage, ''), '0'), '%', '') AS DECIMAL(18,6)) / 100,
                    2
                )
            ELSE COALESCE(r.purchase_value, 0.00)
        END AS procurement_value,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN
                COALESCE(r.account_expected_payment, r.expected_payment, 0.00)
            ELSE
                COALESCE(
                    r.account_payable_amount,
                    GREATEST(COALESCE(r.purchase_value, 0.00) - COALESCE(r.account_wht_override_amount, r.wht_amount, 0.00), 0.00)
                )
        END AS payable_amount,
        CASE
            WHEN r.payment_status = 'Paid' AND COALESCE(r.account_amount_paid, 0.00) <= 0 THEN
                CASE
                    WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN COALESCE(r.account_expected_payment, r.expected_payment, 0.00)
                    ELSE COALESCE(r.account_payable_amount, GREATEST(COALESCE(r.purchase_value, 0.00) - COALESCE(r.account_wht_override_amount, r.wht_amount, 0.00), 0.00))
                END
            ELSE COALESCE(r.account_amount_paid, 0.00)
        END AS raw_paid_amount,
        CONCAT(
            TRIM(COALESCE(u.fname, '')),
            CASE WHEN COALESCE(u.fname, '') <> '' AND COALESCE(u.lname, '') <> '' THEN ' ' ELSE '' END,
            TRIM(COALESCE(u.lname, ''))
        ) AS officer_name,
        r.created_at,
        r.approved_at,
        r.account_paid_at
    FROM procurement_requests r
    LEFT JOIN procurement_local_advance_pos ap
      ON ap.id = r.po_id
     AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
    LEFT JOIN procurement_local_advance_po_revisions apr
      ON apr.id = r.po_revision_id
     AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
    LEFT JOIN user_table u ON u.id = r.created_by
    WHERE r.deleted_at IS NULL
      AND r.request_type IN ({$placeholders})";

    $sql = "SELECT
        core.*,
        LEAST(GREATEST(core.raw_paid_amount, 0.00), GREATEST(core.payable_amount, 0.00)) AS paid_amount,
        CASE
            WHEN core.payment_status = 'Cancelled' THEN 0.00
            ELSE GREATEST(
                core.payable_amount - LEAST(GREATEST(core.raw_paid_amount, 0.00), GREATEST(core.payable_amount, 0.00)),
                0.00
            )
        END AS outstanding_amount,
        GREATEST(core.procurement_value - core.payable_amount, 0.00) AS wht_amount,
        GREATEST(DATEDIFF(CURDATE(), core.aging_date), 0) AS age_days
    FROM ({$coreSql}) core";

    return ['sql' => $sql, 'types' => $types, 'params' => $requestTypes];
}

function procurementSupplierActivityReportPrepareFilters(array $query, array $visibleScopes): array
{
    $scope = strtolower(trim((string) ($query['scope'] ?? 'all')));
    if ($scope === '') {
        $scope = 'all';
    }
    if ($scope !== 'all' && !isset(PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS[$scope])) {
        throw new RuntimeException('Invalid report scope.', 400);
    }
    if ($scope !== 'all' && !in_array($scope, $visibleScopes, true)) {
        throw new RuntimeException('You are not permitted to view the selected report scope.', 403);
    }

    $selectedScopes = $scope === 'all' ? $visibleScopes : [$scope];
    $requestTypes = array_map(
        static fn(string $key): string => (string) PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS[$key]['request_type'],
        $selectedScopes
    );

    $dateFrom = procurementSupplierActivityReportValidateDate($query['date_from'] ?? null, 'Date From');
    $dateTo = procurementSupplierActivityReportValidateDate($query['date_to'] ?? null, 'Date To');
    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        throw new RuntimeException('Date From cannot be later than Date To.', 400);
    }

    $dateBasis = strtolower(trim((string) ($query['date_basis'] ?? 'all')));
    if ($dateBasis === '') {
        $dateBasis = 'all';
    }
    if (!in_array($dateBasis, PROCUREMENT_SUPPLIER_ACTIVITY_DATE_BASES, true)) {
        throw new RuntimeException('Invalid Date Basis filter.', 400);
    }

    $statuses = procurementSupplierActivityReportValidateSelection(
        procurementSupplierActivityReportParseMultiValue($query['status'] ?? ($query['statuses'] ?? [])),
        PROCUREMENT_SUPPLIER_ACTIVITY_WORKFLOW_STATUSES,
        'Status'
    );
    $paymentStatuses = procurementSupplierActivityReportValidateSelection(
        procurementSupplierActivityReportParseMultiValue($query['payment_status'] ?? ($query['payment_statuses'] ?? [])),
        PROCUREMENT_SUPPLIER_ACTIVITY_PAYMENT_STATUSES,
        'Payment Status'
    );

    $currency = strtoupper(trim((string) ($query['currency'] ?? 'ALL')));
    if ($currency === '') {
        $currency = 'ALL';
    }
    if ($currency !== 'ALL' && !preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Invalid Currency filter.', 400);
    }

    return [
        'scope' => $scope,
        'selected_scopes' => $selectedScopes,
        'request_types' => $requestTypes,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'date_basis' => $dateBasis,
        'supplier_id' => max(0, (int) ($query['supplier_id'] ?? 0)),
        'statuses' => $statuses,
        'payment_statuses' => $paymentStatuses,
        'currency' => $currency,
        'search' => trim((string) ($query['search'] ?? '')),
    ];
}

function procurementSupplierActivityReportBuildWhere(array $filters): array
{
    $where = [];
    $types = '';
    $params = [];

    if ((int) $filters['supplier_id'] > 0) {
        $where[] = 'report.supplier_id = ?';
        $types .= 'i';
        $params[] = (int) $filters['supplier_id'];
    }

    if ((string) $filters['currency'] !== 'ALL') {
        $where[] = 'report.currency = ?';
        $types .= 's';
        $params[] = (string) $filters['currency'];
    }

    foreach ([
        ['values' => $filters['statuses'], 'column' => 'report.workflow_status'],
        ['values' => $filters['payment_statuses'], 'column' => 'report.payment_status'],
    ] as $selection) {
        $values = $selection['values'];
        if ($values === []) {
            continue;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $where[] = $selection['column'] . " IN ({$placeholders})";
        $types .= str_repeat('s', count($values));
        array_push($params, ...$values);
    }

    if ((string) $filters['search'] !== '') {
        $like = '%' . $filters['search'] . '%';
        $where[] = '(report.po_number LIKE ? OR report.purchase_number LIKE ? OR report.request_number LIKE ? OR report.invoice_number LIKE ? OR report.supplier_name LIKE ? OR report.project_code LIKE ? OR report.project_name LIKE ? OR report.officer_name LIKE ?)';
        $types .= 'ssssssss';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
        }
    }

    $from = $filters['date_from'];
    $to = $filters['date_to'];
    if ($from !== null || $to !== null) {
        $basisColumns = match ($filters['date_basis']) {
            'request_date' => ['report.request_date'],
            'received_date' => ['report.received_date'],
            'payment_date' => ['report.payment_date'],
            default => ['report.request_date', 'report.received_date', 'report.payment_date'],
        };
        $dateParts = [];
        foreach ($basisColumns as $column) {
            $parts = [];
            if ($from !== null) {
                $parts[] = "{$column} >= ?";
                $types .= 's';
                $params[] = $from;
            }
            if ($to !== null) {
                $parts[] = "{$column} <= ?";
                $types .= 's';
                $params[] = $to;
            }
            $dateParts[] = '(' . implode(' AND ', $parts) . ')';
        }
        $where[] = '(' . implode(' OR ', $dateParts) . ')';
    }

    return [
        'sql' => $where === [] ? '1 = 1' : implode(' AND ', $where),
        'types' => $types,
        'params' => $params,
    ];
}

function procurementSupplierActivityReportFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Supplier Activity Report data.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function procurementSupplierActivityReportFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = procurementSupplierActivityReportFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function procurementSupplierActivityReportMasterSuppliers(mysqli $conn, string $search = '', int $limit = 0): array
{
    if (!procurementRequestCanonicalRuntimeColumnsReady(
        $conn,
        'suppliers_table',
        ['id', 'supplier_name', 'supplier_number']
    )) {
        throw new RuntimeException('Supplier master storage is incomplete.', 500);
    }

    $sql = "SELECT id, supplier_name, supplier_number AS supplier_ledger
            FROM suppliers_table
            WHERE NULLIF(TRIM(supplier_name), '') IS NOT NULL";
    $types = '';
    $params = [];
    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (supplier_name LIKE ? OR CAST(supplier_number AS CHAR) LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $search . '%'];
        $types = 'ss';
    }
    $sql .= ' ORDER BY supplier_name ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ?';
        $params[] = $limit;
        $types .= 'i';
    }

    return procurementSupplierActivityReportFetchAll($conn, $sql, $types, $params);
}

function procurementSupplierActivityReportMasterSupplierLabel(mysqli $conn, int $supplierId): ?string
{
    if ($supplierId <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT supplier_name FROM suppliers_table WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare supplier lookup.', 500);
    }
    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $label = trim((string) ($row['supplier_name'] ?? ''));
    return $label !== '' ? $label : null;
}

function procurementSupplierActivityReportRoute(string $requestType, int $publicId): ?string
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

function procurementSupplierActivityReportSerializeRow(array $row): array
{
    // These figures come from the canonical projection used by the summary/export too.
    $payable = round(max(0.0, (float) ($row['payable_amount'] ?? 0)), 2);
    $paid = round(max(0.0, (float) ($row['paid_amount'] ?? 0)), 2);
    $outstanding = round(max(0.0, (float) ($row['outstanding_amount'] ?? 0)), 2);

    return [
        'id' => (int) ($row['public_id'] ?? 0),
        'canonical_id' => (int) ($row['canonical_id'] ?? 0),
        'request_type' => (string) ($row['request_type'] ?? ''),
        'scope' => (string) ($row['scope_label'] ?? ''),
        'request_number' => (string) ($row['request_number'] ?? ''),
        'po_number' => trim((string) ($row['po_number'] ?? '')) ?: null,
        'purchase_number' => trim((string) ($row['purchase_number'] ?? '')) ?: null,
        'invoice_number' => trim((string) ($row['invoice_number'] ?? '')) ?: null,
        'supplier_id' => ($row['supplier_id'] ?? null) === null ? null : (int) $row['supplier_id'],
        'supplier_name' => trim((string) ($row['supplier_name'] ?? '')) ?: 'Supplier not recorded',
        'project_id' => ($row['project_id'] ?? null) === null ? null : (int) $row['project_id'],
        'project_code' => trim((string) ($row['project_code'] ?? '')) ?: null,
        'project_name' => trim((string) ($row['project_name'] ?? '')) ?: null,
        'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))) ?: 'UNKNOWN',
        'request_date' => $row['request_date'] ?? null,
        'received_date' => $row['received_date'] ?? null,
        'payment_date' => $row['payment_date'] ?? null,
        'aging_date' => $row['aging_date'] ?? null,
        'age_days' => max(0, (int) ($row['age_days'] ?? 0)),
        'procurement_value' => round((float) ($row['procurement_value'] ?? 0), 2),
        'payable_amount' => $payable,
        'paid_amount' => $paid,
        'outstanding_amount' => $outstanding,
        'wht_amount' => round(max(0.0, (float) ($row['wht_amount'] ?? 0)), 2),
        'advance_percentage' => in_array((string) ($row['request_type'] ?? ''), ['local_advance_purchase', 'fx_advance_purchase'], true)
            ? round((float) ($row['advance_percentage'] ?? 0), 6)
            : null,
        'status' => (string) ($row['workflow_status'] ?? ''),
        'payment_status' => (string) ($row['payment_status'] ?? ''),
        'account_status' => (string) ($row['account_status'] ?? ''),
        'po_status' => (string) ($row['po_status'] ?? ''),
        'officer' => trim((string) ($row['officer_name'] ?? '')) ?: 'Not recorded',
        'route' => procurementSupplierActivityReportRoute((string) ($row['request_type'] ?? ''), (int) ($row['public_id'] ?? 0)),
    ];
}

function procurementSupplierActivityReport(mysqli $conn, array $user, array $query): array
{
    $startedAt = hrtime(true);
    $includeOptionsValue = strtolower(trim((string) ($query['include_options'] ?? '1')));
    $includeOptions = !in_array($includeOptionsValue, ['0', 'false', 'no'], true);

    procurementSupplierActivityReportAssertStorage($conn);
    $visibleScopes = procurementSupplierActivityReportVisibleScopes($user);
    if ($visibleScopes === []) {
        throw new RuntimeException('You are not permitted to view procurement reports.', 403);
    }

    $filters = procurementSupplierActivityReportPrepareFilters($query, $visibleScopes);
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $base = procurementSupplierActivityReportBaseSql($filters['request_types']);
    $filtered = procurementSupplierActivityReportBuildWhere($filters);
    $baseTypes = $base['types'] . $filtered['types'];
    $baseParams = array_merge($base['params'], $filtered['params']);
    $derived = '(' . $base['sql'] . ') report';

    $countRow = procurementSupplierActivityReportFetchOne(
        $conn,
        "SELECT COUNT(*) AS total FROM {$derived} WHERE {$filtered['sql']}",
        $baseTypes,
        $baseParams
    );
    $total = (int) ($countRow['total'] ?? 0);

    $listSql = "SELECT report.*
                FROM {$derived}
                WHERE {$filtered['sql']}
                ORDER BY report.request_date DESC, report.created_at DESC, report.canonical_id DESC
                LIMIT ? OFFSET ?";
    $listParams = array_merge($baseParams, [$limit, $offset]);
    $rows = procurementSupplierActivityReportFetchAll($conn, $listSql, $baseTypes . 'ii', $listParams);
    $serializedRows = array_map('procurementSupplierActivityReportSerializeRow', $rows);

    $summaryRows = procurementSupplierActivityReportFetchAll(
        $conn,
        "SELECT
            report.currency,
            COUNT(*) AS request_count,
            SUM(CASE WHEN report.workflow_status IN ('Approved', 'With Accounts') THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN report.workflow_status = 'With Accounts' THEN 1 ELSE 0 END) AS with_accounts_count,
            SUM(CASE WHEN report.workflow_status = 'Pending Approval' THEN 1 ELSE 0 END) AS pending_approval_count,
            SUM(CASE WHEN report.workflow_status = 'Returned' THEN 1 ELSE 0 END) AS returned_count,
            SUM(CASE WHEN report.payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN report.outstanding_amount > 0.00 THEN 1 ELSE 0 END) AS outstanding_count,
            SUM(report.procurement_value) AS procurement_value,
            SUM(report.payable_amount) AS payable_amount,
            SUM(report.paid_amount) AS paid_amount,
            SUM(report.outstanding_amount) AS outstanding_amount,
            SUM(report.wht_amount) AS wht_amount,
            SUM(CASE WHEN report.outstanding_amount > 0.00 AND report.age_days <= 30 THEN report.outstanding_amount ELSE 0.00 END) AS aging_0_30,
            SUM(CASE WHEN report.outstanding_amount > 0.00 AND report.age_days BETWEEN 31 AND 60 THEN report.outstanding_amount ELSE 0.00 END) AS aging_31_60,
            SUM(CASE WHEN report.outstanding_amount > 0.00 AND report.age_days BETWEEN 61 AND 90 THEN report.outstanding_amount ELSE 0.00 END) AS aging_61_90,
            SUM(CASE WHEN report.outstanding_amount > 0.00 AND report.age_days > 90 THEN report.outstanding_amount ELSE 0.00 END) AS aging_90_plus
         FROM {$derived}
         WHERE {$filtered['sql']}
         GROUP BY report.currency
         ORDER BY report.currency",
        $baseTypes,
        $baseParams
    );

    $currencies = [];
    $countTotals = [
        'total_requests' => 0,
        'approved' => 0,
        'pending_approval' => 0,
        'with_accounts' => 0,
        'returned' => 0,
        'paid' => 0,
        'outstanding' => 0,
    ];
    foreach ($summaryRows as $summary) {
        $currency = strtoupper(trim((string) ($summary['currency'] ?? ''))) ?: 'UNKNOWN';
        $outstandingAmount = round(max(0.0, (float) ($summary['outstanding_amount'] ?? 0)), 2);
        $requestCount = (int) ($summary['request_count'] ?? 0);
        $paidCount = (int) ($summary['paid_count'] ?? 0);
        $currencies[] = [
            'currency' => $currency,
            'request_count' => $requestCount,
            'procurement_value' => round((float) ($summary['procurement_value'] ?? 0), 2),
            'payable' => round((float) ($summary['payable_amount'] ?? 0), 2),
            'paid' => round((float) ($summary['paid_amount'] ?? 0), 2),
            'outstanding' => $outstandingAmount,
            'wht' => round((float) ($summary['wht_amount'] ?? 0), 2),
            'aging' => [
                '0_30' => round(max(0.0, (float) ($summary['aging_0_30'] ?? 0)), 2),
                '31_60' => round(max(0.0, (float) ($summary['aging_31_60'] ?? 0)), 2),
                '61_90' => round(max(0.0, (float) ($summary['aging_61_90'] ?? 0)), 2),
                '90_plus' => round(max(0.0, (float) ($summary['aging_90_plus'] ?? 0)), 2),
            ],
        ];
        $countTotals['total_requests'] += $requestCount;
        $countTotals['approved'] += (int) ($summary['approved_count'] ?? 0);
        $countTotals['pending_approval'] += (int) ($summary['pending_approval_count'] ?? 0);
        $countTotals['with_accounts'] += (int) ($summary['with_accounts_count'] ?? 0);
        $countTotals['returned'] += (int) ($summary['returned_count'] ?? 0);
        $countTotals['paid'] += $paidCount;
        $countTotals['outstanding'] += (int) ($summary['outstanding_count'] ?? 0);
    }

    $optionsPayload = null;
    if ($includeOptions) {
        // Supplier options come from the canonical supplier master so the report can be
        // prepared for any supplier on the system, including suppliers with no prior
        // ProcureDesk transaction. Currency options remain permission-scoped to the
        // purchase workflows the current user can see.
        // The frontend requests these options once, then skips the option queries while
        // paging or changing report filters.
        $visibleTypes = array_map(
            static fn(string $scope): string => (string) PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS[$scope]['request_type'],
            $visibleScopes
        );
        $optionBase = procurementSupplierActivityReportBaseSql($visibleTypes);
        $currencyRows = procurementSupplierActivityReportFetchAll(
            $conn,
            "SELECT report.currency
             FROM ({$optionBase['sql']}) report
             WHERE NULLIF(TRIM(report.currency), '') IS NOT NULL
             GROUP BY report.currency
             ORDER BY report.currency",
            $optionBase['types'],
            $optionBase['params']
        );

        $scopeOptions = [['value' => 'all', 'label' => 'All']];
        foreach ($visibleScopes as $scopeKey) {
            $scopeOptions[] = [
                'value' => $scopeKey,
                'label' => (string) PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS[$scopeKey]['label'],
            ];
        }

        $optionsPayload = [
            'suppliers' => [],
            'scopes' => $scopeOptions,
            'statuses' => array_merge(['All'], PROCUREMENT_SUPPLIER_ACTIVITY_WORKFLOW_STATUSES),
            'payment_statuses' => array_merge(['All'], PROCUREMENT_SUPPLIER_ACTIVITY_PAYMENT_STATUSES),
            'currencies' => array_merge(['All'], array_values(array_map(
                static fn(array $row): string => strtoupper((string) $row['currency']),
                $currencyRows
            ))),
            'date_bases' => [
                ['value' => 'all', 'label' => 'All'],
                ['value' => 'request_date', 'label' => 'Request Date'],
                ['value' => 'received_date', 'label' => 'Received Date'],
                ['value' => 'payment_date', 'label' => 'Payment Date'],
            ],
        ];
    }

    $selectedSupplierLabel = $filters['supplier_id'] > 0
        ? procurementSupplierActivityReportMasterSupplierLabel($conn, (int) $filters['supplier_id'])
        : null;

    return [
        'status' => 'Success',
        'data' => $serializedRows,
        'summary' => [
            'counts' => $countTotals,
            'currencies' => $currencies,
        ],
        'options' => $optionsPayload,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
            'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'query_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'options_included' => $includeOptions,
            'supplier_label' => $selectedSupplierLabel,
            'filters' => [
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'date_basis' => $filters['date_basis'],
                'supplier_id' => $filters['supplier_id'] ?: null,
                'scope' => $filters['scope'],
                'status' => $filters['statuses'],
                'payment_status' => $filters['payment_statuses'],
                'currency' => $filters['currency'],
                'search' => $filters['search'],
            ],
            'visible_scopes' => $visibleScopes,
        ],
    ];
}
