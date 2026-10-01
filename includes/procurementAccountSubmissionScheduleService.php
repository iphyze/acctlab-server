<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementSupplierActivityReportService.php';

const PROCUREMENT_ACCOUNT_SUBMISSION_SCHEDULE_SCOPES = [
    'local_final' => ['request_type' => 'local_final_purchase', 'label' => 'Local Final'],
    'local_advance' => ['request_type' => 'local_advance_purchase', 'label' => 'Local Advance'],
    'fx_final' => ['request_type' => 'fx_final_purchase', 'label' => 'FX Final'],
    'fx_advance' => ['request_type' => 'fx_advance_purchase', 'label' => 'FX Advance'],
];

function procurementAccountSubmissionScheduleAssertStorage(mysqli $conn): void
{
    $requestColumns = [
        'id', 'legacy_source_id', 'request_type', 'request_number', 'po_id', 'po_revision_id',
        'po_number', 'purchase_number', 'grn_ref', 'project_id', 'project_code', 'project_name',
        'supplier_id', 'supplier_name', 'invoice_number', 'currency', 'contact_person', 'phone_number',
        'po_value', 'purchase_value', 'po_percentage', 'expected_payment', 'account_expected_payment',
        'remark', 'po_status', 'payment_status', 'approval_status', 'handoff_status', 'handoff_revision',
        'account_request_type', 'account_request_id', 'date_received', 'created_by', 'created_at', 'deleted_at',
    ];
    if (!procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $requestColumns)) {
        throw new RuntimeException('Account Submission Schedule storage is incomplete.', 500);
    }

    $poColumns = [
        'id', 'request_scope', 'currency', 'po_number', 'project_id', 'project_code', 'project_name',
        'supplier_id', 'supplier_name', 'purchase_value', 'po_value', 'po_status', 'created_at',
    ];
    foreach (['procurement_local_advance_pos', 'procurement_local_advance_po_revisions'] as $table) {
        if (!procurementRequestCanonicalRuntimeColumnsReady($conn, $table, $poColumns)) {
            throw new RuntimeException('Advance PO schedule storage is incomplete.', 500);
        }
    }
}

function procurementAccountSubmissionScheduleVisibleScopes(array $user): array
{
    return procurementSupplierActivityReportVisibleScopes($user);
}

function procurementAccountSubmissionSchedulePrepareFilters(array $query, array $visibleScopes): array
{
    $dateFrom = procurementSupplierActivityReportValidateDate($query['date_from'] ?? null, 'Date From');
    $dateTo = procurementSupplierActivityReportValidateDate($query['date_to'] ?? null, 'Date To');
    if ($dateFrom === null || $dateTo === null) {
        throw new RuntimeException('Date From and Date To are required for an Account Submission Schedule.', 400);
    }
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('Date From cannot be later than Date To.', 400);
    }

    $scope = strtolower(trim((string) ($query['scope'] ?? 'all')));
    if ($scope === '') {
        $scope = 'all';
    }
    if ($scope !== 'all' && !isset(PROCUREMENT_ACCOUNT_SUBMISSION_SCHEDULE_SCOPES[$scope])) {
        throw new RuntimeException('Invalid schedule scope.', 400);
    }
    if ($scope !== 'all' && !in_array($scope, $visibleScopes, true)) {
        throw new RuntimeException('You are not permitted to view the selected schedule scope.', 403);
    }

    $selectedScopes = $scope === 'all' ? $visibleScopes : [$scope];
    $requestTypes = array_map(
        static fn(string $key): string => (string) PROCUREMENT_SUPPLIER_ACTIVITY_SCOPE_PERMISSIONS[$key]['request_type'],
        $selectedScopes
    );

    $currency = strtoupper(trim((string) ($query['currency'] ?? 'ALL')));
    if ($currency === '') {
        $currency = 'ALL';
    }
    if ($currency !== 'ALL' && !preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Invalid Currency filter.', 400);
    }

    $poStatuses = procurementSupplierActivityReportParseMultiValue($query['status'] ?? []);
    $paymentStatuses = procurementSupplierActivityReportValidateSelection(
        procurementSupplierActivityReportParseMultiValue($query['payment_status'] ?? []),
        PROCUREMENT_SUPPLIER_ACTIVITY_PAYMENT_STATUSES,
        'Payment Status'
    );

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'scope' => $scope,
        'selected_scopes' => $selectedScopes,
        'request_types' => $requestTypes,
        'supplier_id' => max(0, (int) ($query['supplier_id'] ?? 0)),
        'currency' => $currency,
        'po_statuses' => array_values(array_unique(array_map(static fn($value): string => trim((string) $value), $poStatuses))),
        'payment_statuses' => $paymentStatuses,
        'search' => trim((string) ($query['search'] ?? '')),
    ];
}

function procurementAccountSubmissionScheduleBaseSql(array $requestTypes): array
{
    $placeholders = implode(', ', array_fill(0, count($requestTypes), '?'));
    $types = str_repeat('s', count($requestTypes));

    $sql = "SELECT
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
        COALESCE(NULLIF(TRIM(apr.po_number), ''), NULLIF(TRIM(ap.po_number), ''), NULLIF(TRIM(r.po_number), '')) AS po_number,
        NULLIF(TRIM(r.purchase_number), '') AS purchase_number,
        NULLIF(TRIM(r.grn_ref), '') AS grn_number,
        COALESCE(apr.project_id, ap.project_id, r.project_id) AS project_id,
        COALESCE(NULLIF(TRIM(apr.project_code), ''), NULLIF(TRIM(ap.project_code), ''), NULLIF(TRIM(r.project_code), '')) AS project_code,
        COALESCE(NULLIF(TRIM(apr.project_name), ''), NULLIF(TRIM(ap.project_name), ''), NULLIF(TRIM(r.project_name), '')) AS project_name,
        COALESCE(apr.supplier_id, ap.supplier_id, r.supplier_id) AS supplier_id,
        COALESCE(NULLIF(TRIM(apr.supplier_name), ''), NULLIF(TRIM(ap.supplier_name), ''), NULLIF(TRIM(r.supplier_name), '')) AS supplier_name,
        NULLIF(TRIM(r.invoice_number), '') AS invoice_number,
        CASE
            WHEN r.request_type IN ('local_final_purchase', 'local_advance_purchase') THEN 'NGN'
            ELSE UPPER(COALESCE(NULLIF(TRIM(r.currency), ''), NULLIF(TRIM(apr.currency), ''), NULLIF(TRIM(ap.currency), ''), 'UNKNOWN'))
        END AS currency,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN COALESCE(apr.po_value, ap.po_value, r.po_value, 0.00)
            ELSE COALESCE(r.po_value, 0.00)
        END AS po_value,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN COALESCE(
                apr.purchase_value,
                ap.purchase_value,
                r.purchase_value,
                ROUND(COALESCE(apr.po_value, ap.po_value, r.po_value, 0.00)
                    * CAST(REPLACE(COALESCE(NULLIF(r.po_percentage, ''), '0'), '%', '') AS DECIMAL(18,6)) / 100, 2)
            )
            ELSE COALESCE(r.purchase_value, 0.00)
        END AS purchase_value,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN CAST(REPLACE(COALESCE(NULLIF(r.po_percentage, ''), '0'), '%', '') AS DECIMAL(18,6))
            ELSE NULL
        END AS percentage,
        CASE
            WHEN r.request_type IN ('local_advance_purchase', 'fx_advance_purchase') THEN DATE(COALESCE(ap.created_at, apr.created_at, r.created_at))
            ELSE NULL
        END AS po_date,
        NULLIF(TRIM(r.contact_person), '') AS contact_person,
        NULLIF(TRIM(r.phone_number), '') AS phone_number,
        NULLIF(TRIM(r.remark), '') AS remark,
        COALESCE(NULLIF(TRIM(r.po_status), ''), NULLIF(TRIM(apr.po_status), ''), NULLIF(TRIM(ap.po_status), ''), 'Unclosed') AS status,
        COALESCE(NULLIF(TRIM(r.payment_status), ''), 'Pending') AS payment_status,
        COALESCE(NULLIF(TRIM(r.handoff_status), ''), 'Not Sent') AS account_status,
        DATE(COALESCE(r.date_received, r.approved_at)) AS sent_to_account_date,
        r.handoff_revision,
        r.account_request_type,
        r.account_request_id,
        TRIM(CONCAT(
            TRIM(COALESCE(u.fname, '')),
            CASE WHEN COALESCE(u.fname, '') <> '' AND COALESCE(u.lname, '') <> '' THEN ' ' ELSE '' END,
            TRIM(COALESCE(u.lname, ''))
        )) AS officer_name,
        r.created_at
    FROM procurement_requests r
    LEFT JOIN procurement_local_advance_pos ap
      ON ap.id = r.po_id
     AND ap.request_scope = r.request_type
     AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
    LEFT JOIN procurement_local_advance_po_revisions apr
      ON apr.id = r.po_revision_id
     AND apr.request_scope = r.request_type
     AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
    LEFT JOIN user_table u ON u.id = r.created_by
    WHERE r.deleted_at IS NULL
      AND COALESCE(r.date_received, r.approved_at) IS NOT NULL
      AND r.request_type IN ({$placeholders})";

    return ['sql' => $sql, 'types' => $types, 'params' => $requestTypes];
}

function procurementAccountSubmissionScheduleBuildWhere(array $filters): array
{
    $where = ['schedule.sent_to_account_date >= ?', 'schedule.sent_to_account_date <= ?'];
    $types = 'ss';
    $params = [$filters['date_from'], $filters['date_to']];

    if ((int) $filters['supplier_id'] > 0) {
        $where[] = 'schedule.supplier_id = ?';
        $types .= 'i';
        $params[] = (int) $filters['supplier_id'];
    }
    if ((string) $filters['currency'] !== 'ALL') {
        $where[] = 'schedule.currency = ?';
        $types .= 's';
        $params[] = (string) $filters['currency'];
    }
    foreach ([
        ['values' => $filters['po_statuses'], 'column' => 'schedule.status'],
        ['values' => $filters['payment_statuses'], 'column' => 'schedule.payment_status'],
    ] as $selection) {
        if ($selection['values'] === []) {
            continue;
        }
        $placeholders = implode(', ', array_fill(0, count($selection['values']), '?'));
        $where[] = $selection['column'] . " IN ({$placeholders})";
        $types .= str_repeat('s', count($selection['values']));
        array_push($params, ...$selection['values']);
    }
    if ((string) $filters['search'] !== '') {
        $like = '%' . $filters['search'] . '%';
        $where[] = '(schedule.po_number LIKE ? OR schedule.purchase_number LIKE ? OR schedule.grn_number LIKE ? OR schedule.project_code LIKE ? OR schedule.project_name LIKE ? OR schedule.supplier_name LIKE ? OR schedule.invoice_number LIKE ?)';
        $types .= 'sssssss';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
        }
    }

    return ['sql' => implode(' AND ', $where), 'types' => $types, 'params' => $params];
}

function procurementAccountSubmissionScheduleRoute(string $requestType, int $publicId): ?string
{
    return procurementSupplierActivityReportRoute($requestType, $publicId);
}

function procurementAccountSubmissionScheduleSerializeRow(array $row): array
{
    $requestType = (string) ($row['request_type'] ?? '');
    $isAdvance = in_array($requestType, ['local_advance_purchase', 'fx_advance_purchase'], true);

    return [
        'id' => (int) ($row['public_id'] ?? 0),
        'canonical_id' => (int) ($row['canonical_id'] ?? 0),
        'request_type' => $requestType,
        'scope' => (string) ($row['scope_label'] ?? ''),
        'request_number' => trim((string) ($row['request_number'] ?? '')) ?: null,
        'po_number' => trim((string) ($row['po_number'] ?? '')) ?: null,
        'purchase_number' => trim((string) ($row['purchase_number'] ?? '')) ?: null,
        'grn_number' => trim((string) ($row['grn_number'] ?? '')) ?: null,
        'project_id' => ($row['project_id'] ?? null) === null ? null : (int) $row['project_id'],
        'project_code' => trim((string) ($row['project_code'] ?? '')) ?: null,
        'project_name' => trim((string) ($row['project_name'] ?? '')) ?: null,
        'site' => trim((string) ($row['project_name'] ?? '')) ?: trim((string) ($row['project_code'] ?? '')) ?: null,
        'supplier_id' => ($row['supplier_id'] ?? null) === null ? null : (int) $row['supplier_id'],
        'supplier_name' => trim((string) ($row['supplier_name'] ?? '')) ?: 'Supplier not recorded',
        'invoice_number' => trim((string) ($row['invoice_number'] ?? '')) ?: null,
        'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))) ?: 'UNKNOWN',
        'po_value' => round((float) ($row['po_value'] ?? 0), 2),
        'purchase_value' => round((float) ($row['purchase_value'] ?? 0), 2),
        'percentage' => $isAdvance ? round((float) ($row['percentage'] ?? 0), 6) : null,
        'po_date' => $isAdvance ? ($row['po_date'] ?? null) : null,
        'contact_person' => trim((string) ($row['contact_person'] ?? '')) ?: null,
        'phone_number' => trim((string) ($row['phone_number'] ?? '')) ?: null,
        'remark' => trim((string) ($row['remark'] ?? '')) ?: null,
        'status' => trim((string) ($row['status'] ?? '')) ?: 'Unclosed',
        'payment_status' => trim((string) ($row['payment_status'] ?? '')) ?: 'Pending',
        'account_status' => trim((string) ($row['account_status'] ?? '')) ?: 'Not Sent',
        'date_sent_to_account' => $row['sent_to_account_date'] ?? null,
        'officer' => trim((string) ($row['officer_name'] ?? '')) ?: 'Not recorded',
        'route' => procurementAccountSubmissionScheduleRoute($requestType, (int) ($row['public_id'] ?? 0)),
    ];
}

function procurementAccountSubmissionSchedule(mysqli $conn, array $user, array $query): array
{
    $startedAt = hrtime(true);
    procurementAccountSubmissionScheduleAssertStorage($conn);

    $visibleScopes = procurementAccountSubmissionScheduleVisibleScopes($user);
    if ($visibleScopes === []) {
        throw new RuntimeException('You are not permitted to view procurement schedules.', 403);
    }
    $filters = procurementAccountSubmissionSchedulePrepareFilters($query, $visibleScopes);
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $base = procurementAccountSubmissionScheduleBaseSql($filters['request_types']);
    $filtered = procurementAccountSubmissionScheduleBuildWhere($filters);
    $types = $base['types'] . $filtered['types'];
    $params = array_merge($base['params'], $filtered['params']);
    $derived = '(' . $base['sql'] . ') schedule';

    $count = procurementSupplierActivityReportFetchOne(
        $conn,
        "SELECT COUNT(*) AS total FROM {$derived} WHERE {$filtered['sql']}",
        $types,
        $params
    );
    $total = (int) ($count['total'] ?? 0);

    $rows = procurementSupplierActivityReportFetchAll(
        $conn,
        "SELECT schedule.*
         FROM {$derived}
         WHERE {$filtered['sql']}
         ORDER BY schedule.sent_to_account_date ASC, schedule.scope_label ASC, schedule.po_number ASC, schedule.canonical_id ASC
         LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$limit, $offset])
    );

    $summaryRows = procurementSupplierActivityReportFetchAll(
        $conn,
        "SELECT schedule.scope_label, schedule.currency, COUNT(*) AS total
         FROM {$derived}
         WHERE {$filtered['sql']}
         GROUP BY schedule.scope_label, schedule.currency
         ORDER BY schedule.scope_label, schedule.currency",
        $types,
        $params
    );

    $scopeCounts = [];
    $currencyCounts = [];
    foreach ($summaryRows as $summary) {
        $scopeLabel = (string) ($summary['scope_label'] ?? 'Purchase');
        $currency = strtoupper((string) ($summary['currency'] ?? 'UNKNOWN'));
        $rowTotal = (int) ($summary['total'] ?? 0);
        $scopeCounts[$scopeLabel] = ($scopeCounts[$scopeLabel] ?? 0) + $rowTotal;
        $currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + $rowTotal;
    }

    $includeOptions = !in_array(
        strtolower(trim((string) ($query['include_options'] ?? '1'))),
        ['0', 'false', 'no'],
        true
    );
    $options = null;
    if ($includeOptions) {
        $visibleTypes = array_map(
            static fn(string $scope): string => (string) PROCUREMENT_ACCOUNT_SUBMISSION_SCHEDULE_SCOPES[$scope]['request_type'],
            $visibleScopes
        );
        $optionBase = procurementAccountSubmissionScheduleBaseSql($visibleTypes);
        $currencyRows = procurementSupplierActivityReportFetchAll(
            $conn,
            "SELECT schedule.currency
             FROM ({$optionBase['sql']}) schedule
             WHERE NULLIF(TRIM(schedule.currency), '') IS NOT NULL
             GROUP BY schedule.currency
             ORDER BY schedule.currency",
            $optionBase['types'],
            $optionBase['params']
        );

        $scopeOptions = [['value' => 'all', 'label' => 'All']];
        foreach ($visibleScopes as $scopeKey) {
            $scopeOptions[] = [
                'value' => $scopeKey,
                'label' => (string) PROCUREMENT_ACCOUNT_SUBMISSION_SCHEDULE_SCOPES[$scopeKey]['label'],
            ];
        }

        $options = [
            'suppliers' => [],
            'scopes' => $scopeOptions,
            'currencies' => array_merge(['All'], array_values(array_map(
                static fn(array $row): string => strtoupper((string) $row['currency']),
                $currencyRows
            ))),
            'statuses' => ['All', 'Unclosed', 'Closed'],
            'payment_statuses' => array_merge(['All'], PROCUREMENT_SUPPLIER_ACTIVITY_PAYMENT_STATUSES),
        ];
    }

    $selectedSupplierLabel = $filters['supplier_id'] > 0
        ? procurementSupplierActivityReportMasterSupplierLabel($conn, (int) $filters['supplier_id'])
        : null;

    return [
        'status' => 'Success',
        'data' => array_map('procurementAccountSubmissionScheduleSerializeRow', $rows),
        'summary' => [
            'total' => $total,
            'by_scope' => $scopeCounts,
            'by_currency' => $currencyCounts,
        ],
        'options' => $options,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'scope' => $filters['scope'],
            'supplier_id' => $filters['supplier_id'] ?: null,
            'supplier_label' => $selectedSupplierLabel,
            'currency' => $filters['currency'],
            'po_statuses' => $filters['po_statuses'],
            'payment_statuses' => $filters['payment_statuses'],
            'search' => $filters['search'],
            'query_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ],
    ];
}
