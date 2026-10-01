<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/workflowEventCanonicalReadService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';

const PROCUREMENT_REQUEST_CANONICAL_READ_MODE_ENV = 'PROCUREMENT_REQUEST_CANONICAL_READ_MODE';

function procurementRequestCanonicalReadMode(): string
{
    $configured = function_exists('envValue')
        ? envValue(PROCUREMENT_REQUEST_CANONICAL_READ_MODE_ENV, 'legacy')
        : ($_ENV[PROCUREMENT_REQUEST_CANONICAL_READ_MODE_ENV]
            ?? getenv(PROCUREMENT_REQUEST_CANONICAL_READ_MODE_ENV)
            ?: 'legacy');
    $mode = strtolower(trim((string) $configured));
    return in_array($mode, ['auto', 'canonical', 'legacy'], true) ? $mode : 'legacy';
}

function procurementRequestCanonicalReadTableSetExists(mysqli $conn): bool
{
    foreach (['procurement_requests', 'procurement_request_handoffs', 'workflow_events'] as $table) {
        if (!procurementRequestCanonicalRuntimeObjectType($conn, $table) === 'BASE TABLE') {
            return false;
        }
    }
    foreach (['purchase_number_normalized', 'purchase_value', 'po_id', 'po_percentage', 'expected_payment'] as $column) {
        if (!procurementRequestCanonicalRuntimeColumnExists($conn, 'procurement_requests', $column)) {
            return false;
        }
    }
    return true;
}

function procurementRequestCanonicalReadHealth(mysqli $conn, string $requestType): array
{
    if (!in_array($requestType, [
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
    ], true)) {
        throw new InvalidArgumentException('Unsupported canonical procurement request type.');
    }

    if (!procurementRequestCanonicalReadTableSetExists($conn)) {
        return ['healthy' => false, 'reason' => 'canonical_tables_missing', 'checks' => []];
    }

    if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL) {
        procurementRequestCanonicalLocalFinalAssertReady($conn);
        $canonicalRequests = procurementRequestCanonicalRuntimeCount(
            $conn,
            "SELECT COUNT(*) AS total FROM procurement_requests
             WHERE request_type = 'local_final_purchase'
               AND legacy_source_table = 'procurement_local_final_purchases'"
        );
        $missingIdentity = procurementRequestCanonicalRuntimeCount(
            $conn,
            "SELECT COUNT(*) AS total FROM procurement_requests
             WHERE request_type = 'local_final_purchase'
               AND legacy_source_table = 'procurement_local_final_purchases'
               AND (legacy_source_id IS NULL OR legacy_source_id = 0)"
        );
        $duplicatePublicIds = procurementRequestCanonicalRuntimeCount(
            $conn,
            "SELECT COUNT(*) AS total FROM (
                SELECT legacy_source_id
                FROM procurement_requests
                WHERE request_type = 'local_final_purchase'
                  AND legacy_source_table = 'procurement_local_final_purchases'
                GROUP BY legacy_source_id HAVING COUNT(*) > 1
             ) duplicate_ids"
        );
        $canonicalEvents = procurementRequestCanonicalRuntimeCount(
            $conn,
            "SELECT COUNT(*) AS total FROM workflow_events
             WHERE event_scope = 'procurement_request'
               AND request_type = 'local_final_purchase'"
        );
        $canonicalHandoffs = procurementRequestCanonicalRuntimeCount(
            $conn,
            "SELECT COUNT(*) AS total FROM procurement_request_handoffs
             WHERE request_type = 'local_final_purchase'"
        );
        $checks = [
            'request_count' => true,
            'details_complete' => true,
            'requests_current' => true,
            'details_current' => true,
            'event_count' => true,
            'handoff_count' => true,
            'handoffs_current' => true,
            'canonical_identity_complete' => $missingIdentity === 0,
            'canonical_public_ids_unique' => $duplicatePublicIds === 0,
        ];
        return [
            'healthy' => !in_array(false, $checks, true),
            'reason' => null,
            'checks' => $checks,
            'counts' => [
                'source_requests' => $canonicalRequests,
                'canonical_requests' => $canonicalRequests,
                'missing_details' => 0,
                'stale_requests' => 0,
                'stale_details' => 0,
                'source_events' => $canonicalEvents,
                'canonical_events' => $canonicalEvents,
                'source_handoffs' => $canonicalHandoffs,
                'canonical_handoffs' => $canonicalHandoffs,
                'stale_handoffs' => 0,
                'missing_public_identity' => $missingIdentity,
                'duplicate_public_ids' => $duplicatePublicIds,
            ],
            'legacy_auxiliary_tables_present' => [
                'events' => false,
                'handoffs' => false,
            ],
            'storage_phase' => 'canonical_only',
        ];
    }

    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $canonicalRequests = procurementRequestCanonicalRuntimeCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_requests
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'"
    );
    $missingIdentity = procurementRequestCanonicalRuntimeCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_requests
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND (legacy_source_id IS NULL OR legacy_source_id = 0)"
    );
    $duplicatePublicIds = procurementRequestCanonicalRuntimeCount(
        $conn,
        "SELECT COUNT(*) AS total FROM (
            SELECT legacy_source_id
            FROM procurement_requests
            WHERE request_type = 'local_advance_purchase'
              AND legacy_source_table = 'procurement_local_advance_purchases'
            GROUP BY legacy_source_id HAVING COUNT(*) > 1
         ) duplicate_ids"
    );
    $canonicalEvents = procurementRequestCanonicalRuntimeCount(
        $conn,
        "SELECT COUNT(*) AS total FROM workflow_events
         WHERE event_scope = 'procurement_request'
           AND request_type = 'local_advance_purchase'"
    );
    $canonicalHandoffs = procurementRequestCanonicalRuntimeCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_request_handoffs
         WHERE request_type = 'local_advance_purchase'"
    );
    $checks = [
        'request_count' => true,
        'details_complete' => true,
        'requests_current' => true,
        'details_current' => true,
        'event_count' => true,
        'handoff_count' => true,
        'handoffs_current' => true,
        'canonical_identity_complete' => $missingIdentity === 0,
        'canonical_public_ids_unique' => $duplicatePublicIds === 0,
    ];

    return [
        'healthy' => !in_array(false, $checks, true),
        'reason' => null,
        'checks' => $checks,
        'counts' => [
            'source_requests' => $canonicalRequests,
            'canonical_requests' => $canonicalRequests,
            'missing_details' => 0,
            'stale_requests' => 0,
            'stale_details' => 0,
            'source_events' => $canonicalEvents,
            'canonical_events' => $canonicalEvents,
            'source_handoffs' => $canonicalHandoffs,
            'canonical_handoffs' => $canonicalHandoffs,
            'stale_handoffs' => 0,
            'missing_public_identity' => $missingIdentity,
            'duplicate_public_ids' => $duplicatePublicIds,
        ],
        'legacy_auxiliary_tables_present' => [
            'events' => false,
            'handoffs' => false,
        ],
        'storage_phase' => 'canonical_only',
    ];

}

function procurementRequestCanonicalReadsEnabled(mysqli $conn, string $requestType): bool
{
    static $cache = [];

    $mode = procurementRequestCanonicalReadMode();
    if ($mode === 'legacy') {
        return false;
    }

    $cacheKey = spl_object_id($conn) . ':' . $requestType . ':' . $mode;
    if (!array_key_exists($cacheKey, $cache)) {
        $health = procurementRequestCanonicalReadHealth($conn, $requestType);
        if ($mode === 'canonical' && !($health['healthy'] ?? false)) {
            throw new RuntimeException(
                'Canonical procurement request reads were forced, but canonical storage is incomplete.',
                503
            );
        }
        $cache[$cacheKey] = (bool) ($health['healthy'] ?? false);
    }

    return $cache[$cacheKey];
}

function procurementRequestCanonicalPrepareAndFetchAll(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function procurementRequestCanonicalPrepareAndFetchOne(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): ?array {
    $rows = procurementRequestCanonicalPrepareAndFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function procurementRequestCanonicalNormalizeCommonRow(array $row): array
{
    $row['id'] = isset($row['legacy_id']) ? (int) $row['legacy_id'] : null;
    $row['procurement_request_id'] = isset($row['canonical_request_id'])
        ? (int) $row['canonical_request_id']
        : null;
    unset(
        $row['legacy_id'],
        $row['legacy_source_table'],
        $row['legacy_source_id'],
        $row['canonical_request_id'],
        $row['detail_request_id'],
        $row['account_request_type']
    );
    return $row;
}

function procurementRequestCanonicalFetchLocalFinalRecord(mysqli $conn, int $legacyId): ?array
{
    $row = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        "SELECT
            r.*,
            r.id AS canonical_request_id,
            r.legacy_source_id AS legacy_id,
            r.id AS detail_request_id,
            r.purchase_number_normalized, r.grn_ref, r.material_type,
            r.invoice_number, r.invoice_date, r.purchase_date,
            r.purchase_subtotal, r.purchase_discount, r.purchase_other_charges,
            r.purchase_vat_status, r.purchase_vat_rate, r.purchase_vat_amount, r.purchase_value,
            r.po_subtotal, r.po_discount, r.po_other_charges,
            r.po_vat_status, r.po_vat_rate, r.po_vat_amount, r.po_value,
            COALESCE(r.account_payable_amount, sfr.amount, cfr.amount, GREATEST(r.purchase_value - r.wht_amount, 0.00)) AS resolved_account_payable_amount,
            COALESCE(sfr.payment_status, cfr.payment_status) AS account_payment_status,
            COALESCE(sfr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0.00) AS amount_paid,
            COALESCE(sfr.processing_method, cfr.processing_method, r.account_processing_method) AS payment_processing_method,
            COALESCE(sfr.processing_reference, cfr.processing_reference, r.account_processing_reference) AS payment_processing_reference,
            COALESCE(sfr.processing_started_at, cfr.processing_started_at, r.account_processing_started_at) AS payment_processing_started_at,
            COALESCE(sfr.expected_completion_at, cfr.expected_completion_at, r.account_expected_completion_at) AS expected_payment_completion_at,
            COALESCE(sfr.completion_mode, cfr.completion_mode, r.account_completion_mode) AS payment_completion_mode,
            COALESCE(sfr.payment_confirmation_status, cfr.payment_confirmation_status, r.account_confirmation_status) AS payment_confirmation_status,
            COALESCE(sfr.payment_reference, cfr.payment_reference, r.account_payment_reference) AS payment_reference,
            COALESCE(sfr.paid_at, cfr.paid_at, r.account_paid_at) AS paid_at,
            COALESCE(sfr.account_remarks, cfr.account_remarks, r.account_payment_remarks) AS account_remarks,
            COALESCE(sfr.payment_batch_id, cfr.payment_batch_id, r.account_payment_batch_id) AS payment_batch_id,
            CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
            CONCAT(COALESCE(uu.fname, ''), ' ', COALESCE(uu.lname, '')) AS updated_by_name,
            CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
            CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS approval_reversed_by_name,
            CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
         FROM procurement_requests r
         LEFT JOIN supplier_fund_request_table sfr
           ON sfr.id = r.account_request_id
          AND (r.account_request_type IS NULL OR r.account_request_type = 'supplier_fund_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = r.account_request_id
          AND r.account_request_type = 'compass_fund_request'
         LEFT JOIN user_table cu ON cu.id = r.created_by
         LEFT JOIN user_table uu ON uu.id = r.updated_by
         LEFT JOIN user_table au ON au.id = r.approved_by
         LEFT JOIN user_table ru ON ru.id = r.approval_reversed_by
         LEFT JOIN user_table rtu ON rtu.id = r.retrieved_by
         WHERE r.request_type = 'local_final_purchase'
           AND r.legacy_source_table = 'procurement_local_final_purchases'
           AND r.legacy_source_id = ?
           AND r.deleted_at IS NULL
         LIMIT 1",
        'i',
        [$legacyId]
    );

    return $row ? procurementRequestCanonicalNormalizeLocalFinalRow($row) : null;
}

function procurementRequestCanonicalNormalizeLocalFinalRow(array $row): array
{
    $row = procurementRequestCanonicalNormalizeCommonRow($row);
    $row['supplier_fund_request_id'] = $row['account_request_id'] === null
        ? null
        : (int) $row['account_request_id'];
    $row['previous_supplier_fund_request_id'] = $row['previous_account_request_id'] === null
        ? null
        : (int) $row['previous_account_request_id'];
    $row['account_payable_amount'] = $row['resolved_account_payable_amount'];

    unset(
        $row['request_type'],
        $row['request_number'],
        $row['transaction_date'],
        $row['date_received'],
        $row['account_request_id'],
        $row['previous_account_request_id'],
        $row['resolved_account_payable_amount']
    );

    return $row;
}

function procurementRequestCanonicalLocalFinalEvents(mysqli $conn, int $legacyId): array
{
    $eventSource = workflowEventReadSource($conn, WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST);
    $rows = procurementRequestCanonicalPrepareAndFetchAll(
        $conn,
        "SELECT COALESCE(e.legacy_source_id, e.id) AS id, e.event_type, e.actor_user_id,
                e.actor_email, e.details_json, e.created_at
         FROM {$eventSource} e
         INNER JOIN procurement_requests r ON r.id = e.request_id
         WHERE r.request_type = 'local_final_purchase'
           AND r.legacy_source_table = 'procurement_local_final_purchases'
           AND r.legacy_source_id = ?
         ORDER BY e.created_at DESC, e.id DESC",
        'i',
        [$legacyId]
    );

    foreach ($rows as &$event) {
        $event['id'] = (int) $event['id'];
        $event['actor_user_id'] = (int) $event['actor_user_id'];
        $decoded = json_decode((string) ($event['details_json'] ?? ''), true);
        $event['details'] = is_array($decoded) ? $decoded : null;
        unset($event['details_json']);
    }
    unset($event);

    return $rows;
}

function procurementRequestCanonicalLocalFinalHandoffs(mysqli $conn, int $legacyId): array
{
    $rows = procurementRequestCanonicalPrepareAndFetchAll(
        $conn,
        "SELECT COALESCE(h.legacy_source_id, h.id) AS id, h.revision,
                h.account_request_id AS supplier_fund_request_id, h.handoff_status,
                h.sent_by, h.sent_at, h.retrieved_by, h.retrieved_at,
                h.retrieval_source, h.retrieval_reason,
                CONCAT(COALESCE(su.fname, ''), ' ', COALESCE(su.lname, '')) AS sent_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS retrieved_by_name
         FROM procurement_request_handoffs h
         INNER JOIN procurement_requests r ON r.id = h.request_id
         LEFT JOIN user_table su ON su.id = h.sent_by
         LEFT JOIN user_table ru ON ru.id = h.retrieved_by
         WHERE r.request_type = 'local_final_purchase'
           AND r.legacy_source_table = 'procurement_local_final_purchases'
           AND r.legacy_source_id = ?
         ORDER BY h.revision DESC, h.id DESC",
        'i',
        [$legacyId]
    );

    foreach ($rows as &$handoff) {
        foreach (['id', 'revision', 'supplier_fund_request_id', 'sent_by', 'retrieved_by'] as $field) {
            $handoff[$field] = $handoff[$field] === null ? null : (int) $handoff[$field];
        }
    }
    unset($handoff);

    return $rows;
}

function procurementRequestCanonicalLocalFinalGetResponse(mysqli $conn, array $query): ?array
{
    if (!procurementRequestCanonicalReadsEnabled($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL)) {
        return null;
    }

    $id = (int) ($query['id'] ?? 0);
    if ($id > 0) {
        $record = procurementRequestCanonicalFetchLocalFinalRecord($conn, $id);
        if (!$record) {
            throw new RuntimeException('Local Final Purchase not founr.', 404);
        }

        $response = ['record' => procurementLocalFinalSerializeRecord($record)];
        if (filter_var($query['include_events'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $response['events'] = procurementRequestCanonicalLocalFinalEvents($conn, $id);
            $response['handoffs'] = procurementRequestCanonicalLocalFinalHandoffs($conn, $id);
        }

        return ['status' => 'Success', 'data' => $response];
    }

    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim((string) ($query['search'] ?? ''));
    $approvalStatus = trim((string) ($query['approval_status'] ?? ''));
    $paymentStatus = trim((string) ($query['payment_status'] ?? ''));
    $poStatus = trim((string) ($query['po_status'] ?? ''));
    $handoffStatus = trim((string) ($query['handoff_status'] ?? ''));
    $year = (int) ($query['year'] ?? 0);
    $projectId = (int) ($query['project_id'] ?? 0);
    $supplierId = (int) ($query['supplier_id'] ?? 0);

    if ($approvalStatus !== '') {
        $approvalStatus = procurementLocalFinalValidateStatus(
            $approvalStatus,
            PROCUREMENT_LOCAL_FINAL_APPROVAL_STATUSES,
            'Approval Status'
        );
    }
    if ($paymentStatus !== '') {
        $paymentStatus = procurementLocalFinalValidateStatus(
            $paymentStatus,
            PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES,
            'Payment Status'
        );
    }
    if ($poStatus !== '') {
        $poStatus = procurementLocalFinalValidateStatus(
            $poStatus,
            PROCUREMENT_LOCAL_FINAL_PO_STATUSES,
            'PO Status'
        );
    }
    if ($handoffStatus !== '') {
        $handoffStatus = procurementLocalFinalValidateStatus(
            $handoffStatus,
            PROCUREMENT_LOCAL_FINAL_HANDOFF_STATUSES,
            'Account Handoff Status'
        );
    }
    if ($year !== 0 && ($year < 2000 || $year > 2100)) {
        throw new RuntimeException('Year filter is invalir.', 400);
    }

    $allowedSortFields = [
        'created_at' => 'r.created_at',
        'updated_at' => 'r.updated_at',
        'purchase_date' => 'r.purchase_date',
        'invoice_date' => 'r.invoice_date',
        'purchase_number' => 'r.purchase_number',
        'po_number' => 'r.po_number',
        'supplier_name' => 'r.supplier_name',
        'project_code' => 'r.project_code',
        'purchase_value' => 'r.purchase_value',
        'po_value' => 'r.po_value',
        'approval_status' => 'r.approval_status',
        'payment_status' => 'r.payment_status',
        'po_status' => 'r.po_status',
        'handoff_status' => 'r.handoff_status',
        'approved_at' => 'r.approved_at',
        'retrieved_at' => 'r.retrieved_at',
    ];
    $sortBy = (string) ($query['sort_by'] ?? 'created_at');
    $sortColumn = $allowedSortFields[$sortBy] ?? $allowedSortFields['created_at'];
    $sortOrder = strtoupper((string) ($query['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = [
        "r.request_type = 'local_final_purchase'",
        "r.legacy_source_table = 'procurement_local_final_purchases'",
        'r.deleted_at IS NULL',
    ];
    $params = [];
    $types = '';
    if ($search !== '') {
        $where[] = '(r.purchase_number LIKE ? OR r.po_number LIKE ? OR r.grn_ref LIKE ? OR r.material_type LIKE ? OR r.invoice_number LIKE ? OR r.project_code LIKE ? OR r.project_name LIKE ? OR r.supplier_name LIKE ? OR r.supplier_ledger LIKE ?)';
        $like = '%' . $search . '%';
        for ($index = 0; $index < 9; $index++) {
            $params[] = $like;
        }
        $types .= 'sssssssss';
    }
    foreach ([
        ['value' => $approvalStatus, 'sql' => 'r.approval_status = ?', 'type' => 's'],
        ['value' => $paymentStatus, 'sql' => 'r.payment_status = ?', 'type' => 's'],
        ['value' => $poStatus, 'sql' => 'r.po_status = ?', 'type' => 's'],
        ['value' => $handoffStatus, 'sql' => 'r.handoff_status = ?', 'type' => 's'],
    ] as $filter) {
        if ($filter['value'] !== '') {
            $where[] = $filter['sql'];
            $params[] = $filter['value'];
            $types .= $filter['type'];
        }
    }
    if ($year > 0) {
        $where[] = 'YEAR(r.purchase_date) = ?';
        $params[] = $year;
        $types .= 'i';
    }
    if ($projectId > 0) {
        $where[] = 'r.project_id = ?';
        $params[] = $projectId;
        $types .= 'i';
    }
    if ($supplierId > 0) {
        $where[] = 'r.supplier_id = ?';
        $params[] = $supplierId;
        $types .= 'i';
    }
    $whereSql = implode(' AND ', $where);

    $countRow = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_requests r
         WHERE {$whereSql}",
        $types,
        $params
    );
    $total = (int) ($countRow['total'] ?? 0);

    $rows = procurementRequestCanonicalPrepareAndFetchAll(
        $conn,
        "SELECT
            r.*,
            r.id AS canonical_request_id,
            r.legacy_source_id AS legacy_id,
            r.id AS detail_request_id,
            r.purchase_number_normalized, r.grn_ref, r.material_type,
            r.invoice_number, r.invoice_date, r.purchase_date,
            r.purchase_subtotal, r.purchase_discount, r.purchase_other_charges,
            r.purchase_vat_status, r.purchase_vat_rate, r.purchase_vat_amount, r.purchase_value,
            r.po_subtotal, r.po_discount, r.po_other_charges,
            r.po_vat_status, r.po_vat_rate, r.po_vat_amount, r.po_value,
            COALESCE(r.account_payable_amount, sfr.amount, cfr.amount, GREATEST(r.purchase_value - r.wht_amount, 0.00)) AS resolved_account_payable_amount,
            COALESCE(sfr.payment_status, cfr.payment_status) AS account_payment_status,
            COALESCE(sfr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0.00) AS amount_paid,
            COALESCE(sfr.processing_method, cfr.processing_method, r.account_processing_method) AS payment_processing_method,
            COALESCE(sfr.processing_reference, cfr.processing_reference, r.account_processing_reference) AS payment_processing_reference,
            COALESCE(sfr.processing_started_at, cfr.processing_started_at, r.account_processing_started_at) AS payment_processing_started_at,
            COALESCE(sfr.expected_completion_at, cfr.expected_completion_at, r.account_expected_completion_at) AS expected_payment_completion_at,
            COALESCE(sfr.completion_mode, cfr.completion_mode, r.account_completion_mode) AS payment_completion_mode,
            COALESCE(sfr.payment_confirmation_status, cfr.payment_confirmation_status, r.account_confirmation_status) AS payment_confirmation_status,
            COALESCE(sfr.payment_reference, cfr.payment_reference, r.account_payment_reference) AS payment_reference,
            COALESCE(sfr.paid_at, cfr.paid_at, r.account_paid_at) AS paid_at,
            COALESCE(sfr.account_remarks, cfr.account_remarks, r.account_payment_remarks) AS account_remarks,
            COALESCE(sfr.payment_batch_id, cfr.payment_batch_id, r.account_payment_batch_id) AS payment_batch_id,
            CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
            CONCAT(COALESCE(uu.fname, ''), ' ', COALESCE(uu.lname, '')) AS updated_by_name,
            CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
            CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
         FROM procurement_requests r
         LEFT JOIN supplier_fund_request_table sfr
           ON sfr.id = r.account_request_id
          AND (r.account_request_type IS NULL OR r.account_request_type = 'supplier_fund_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = r.account_request_id
          AND r.account_request_type = 'compass_fund_request'
         LEFT JOIN user_table cu ON cu.id = r.created_by
         LEFT JOIN user_table uu ON uu.id = r.updated_by
         LEFT JOIN user_table au ON au.id = r.approved_by
         LEFT JOIN user_table rtu ON rtu.id = r.retrieved_by
         WHERE {$whereSql}
         ORDER BY {$sortColumn} {$sortOrder}, r.legacy_source_id DESC
         LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$limit, $offset])
    );
    $rows = array_map(
        static fn(array $row): array => procurementLocalFinalSerializeRecord(
            procurementRequestCanonicalNormalizeLocalFinalRow($row)
        ),
        $rows
    );

    return [
        'status' => 'Success',
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
            'filters' => [
                'search' => $search,
                'approval_status' => $approvalStatus,
                'payment_status' => $paymentStatus,
                'po_status' => $poStatus,
                'handoff_status' => $handoffStatus,
                'year' => $year ?: null,
                'project_id' => $projectId ?: null,
                'supplier_id' => $supplierId ?: null,
            ],
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ],
    ];
}

function procurementRequestCanonicalAdvanceAllocatedPercentage(mysqli $conn, int $poId): string
{
    $row = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        "SELECT COALESCE(SUM(r.po_percentage), 0) AS allocated
         FROM procurement_requests r
         WHERE r.po_id = ?
           AND r.request_type = 'local_advance_purchase'
           AND r.deleted_at IS NULL
           AND r.payment_status <> 'Cancelled'",
        'i',
        [$poId]
    );
    $allocatedUnits = procurementLocalAdvancePercentUnitsAllowZero($row['allocated'] ?? 0);

    if (!procurementLocalAdvanceTableExists($conn, 'advance_payment_request')) {
        return procurementLocalAdvancePercentString($allocatedUnits);
    }

    $po = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        'SELECT po_number_normalized FROM procurement_local_advance_pos WHERE id = ? LIMIT 1',
        'i',
        [$poId]
    );
    $normalized = trim((string) ($po['po_number_normalized'] ?? ''));
    if ($normalized === '') {
        return procurementLocalAdvancePercentString($allocatedUnits);
    }

    $external = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        "SELECT COALESCE(SUM(CAST(REPLACE(TRIM(percentage), '%', '') AS DECIMAL(12,6))), 0) AS allocated
         FROM advance_payment_request
         WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(po_number), ' ', ''), '/', ''), '-', ''), '.', '')) = ?
           AND payment_status <> 'Cancelled'
           AND (
                procurement_source IS NULL
                OR procurement_source <> 'local_advance_purchase'
                OR procurement_purchase_id IS NULL
           )",
        's',
        [$normalized]
    );
    $allocatedUnits += procurementLocalAdvancePercentUnitsAllowZero($external['allocated'] ?? 0);

    return procurementLocalAdvancePercentString($allocatedUnits);
}

function procurementRequestCanonicalFetchLocalAdvanceRecord(mysqli $conn, int $legacyId): ?array
{
    $row = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        procurementRequestCanonicalLocalAdvanceSelectSql()
        . " WHERE r.request_type = 'local_advance_purchase'
              AND r.legacy_source_table = 'procurement_local_advance_purchases'
              AND r.legacy_source_id = ?
              AND r.deleted_at IS NULL
            LIMIT 1",
        'i',
        [$legacyId]
    );

    if (!$row) {
        return null;
    }

    $row = procurementRequestCanonicalNormalizeLocalAdvanceRow($row);
    $row['allocated_percentage'] = procurementRequestCanonicalAdvanceAllocatedPercentage(
        $conn,
        (int) $row['po_id']
    );
    return $row;
}

function procurementRequestCanonicalLocalAdvanceSelectSql(): string
{
    return "SELECT
            r.*,
            r.id AS canonical_request_id,
            r.legacy_source_id AS legacy_id,
            r.id AS detail_request_id,
            r.po_id, r.po_revision_id, r.po_revision_number,
            r.request_variant, r.parent_request_id, r.legacy_parent_purchase_id,
            r.revision_reconciliation_id, r.po_snapshot_json,
            r.po_percentage, r.expected_payment, r.account_expected_payment,
            COALESCE(pr.po_number_normalized, p.po_number_normalized) AS po_number_normalized,
            CASE WHEN pr.id IS NOT NULL THEN pr.purchase_value ELSE p.purchase_value END AS purchase_value,
            COALESCE(pr.po_subtotal, p.po_subtotal) AS po_subtotal,
            COALESCE(pr.po_discount, p.po_discount) AS po_discount,
            COALESCE(pr.po_other_charges, p.po_other_charges) AS po_other_charges,
            COALESCE(pr.po_vat_status, p.po_vat_status) AS po_vat_status,
            COALESCE(pr.po_vat_rate, p.po_vat_rate) AS po_vat_rate,
            COALESCE(pr.po_vat_amount, p.po_vat_amount) AS po_vat_amount,
            COALESCE(pr.po_value, p.po_value) AS po_value,
            COALESCE(pr.advance_base_amount, p.advance_base_amount) AS advance_base_amount,
            p.po_status AS resolved_po_status,
            p.version AS po_version,
            p.current_revision_id, p.current_revision_number, p.amendment_status,
            pr.revision_reference AS po_revision_reference,
            pr.revision_status AS po_revision_status,
            pr.snapshot_hash AS po_revision_snapshot_hash,
            COALESCE(pr.is_locked, 0) AS po_revision_is_locked,
            pr.locked_at AS po_revision_locked_at,
            pr.locked_reason AS po_revision_locked_reason,
            COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount) AS account_advance_payment,
            COALESCE(apr.payment_status, cfr.payment_status) AS account_payment_status,
            COALESCE(apr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0.00) AS amount_paid,
            COALESCE(apr.processing_method, cfr.processing_method, r.account_processing_method) AS payment_processing_method,
            COALESCE(apr.processing_reference, cfr.processing_reference, r.account_processing_reference) AS payment_processing_reference,
            COALESCE(apr.processing_started_at, cfr.processing_started_at, r.account_processing_started_at) AS payment_processing_started_at,
            COALESCE(apr.expected_completion_at, cfr.expected_completion_at, r.account_expected_completion_at) AS expected_payment_completion_at,
            COALESCE(apr.completion_mode, cfr.completion_mode, r.account_completion_mode) AS payment_completion_mode,
            COALESCE(apr.payment_confirmation_status, cfr.payment_confirmation_status, r.account_confirmation_status) AS payment_confirmation_status,
            COALESCE(apr.payment_reference, cfr.payment_reference, r.account_payment_reference) AS payment_reference,
            COALESCE(apr.paid_at, cfr.paid_at, r.account_paid_at) AS paid_at,
            COALESCE(apr.account_remarks, cfr.account_remarks, r.account_payment_remarks) AS account_remarks,
            COALESCE(apr.payment_batch_id, cfr.payment_batch_id, r.account_payment_batch_id) AS payment_batch_id,
            CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
            CONCAT(COALESCE(uu.fname, ''), ' ', COALESCE(uu.lname, '')) AS updated_by_name,
            CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
            CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS approval_reversed_by_name,
            CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
         FROM procurement_requests r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
         LEFT JOIN advance_payment_request apr
           ON apr.id = r.account_request_id
          AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = r.account_request_id
          AND r.account_request_type = 'compass_fund_request'
         LEFT JOIN user_table cu ON cu.id = r.created_by
         LEFT JOIN user_table uu ON uu.id = r.updated_by
         LEFT JOIN user_table au ON au.id = r.approved_by
         LEFT JOIN user_table ru ON ru.id = r.approval_reversed_by
         LEFT JOIN user_table rtu ON rtu.id = r.retrieved_by";
}

function procurementRequestCanonicalNormalizeLocalAdvanceRow(array $row): array
{
    $row = procurementRequestCanonicalNormalizeCommonRow($row);
    $row['request_type'] = $row['request_variant'];
    $row['advance_payment_request_id'] = $row['account_request_id'] === null
        ? null
        : (int) $row['account_request_id'];
    $row['previous_advance_payment_request_id'] = $row['previous_account_request_id'] === null
        ? null
        : (int) $row['previous_account_request_id'];
    $row['parent_purchase_id'] = $row['legacy_parent_purchase_id'] === null
        ? null
        : (int) $row['legacy_parent_purchase_id'];
    $row['po_status'] = $row['resolved_po_status'];

    unset(
        $row['request_variant'],
        $row['parent_request_id'],
        $row['legacy_parent_purchase_id'],
        $row['account_request_id'],
        $row['previous_account_request_id'],
        $row['resolved_po_status'],
        $row['account_payable_amount']
    );

    return $row;
}

function procurementRequestCanonicalLocalAdvanceEvents(mysqli $conn, int $legacyId): array
{
    $eventSource = workflowEventReadSource($conn, WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST);
    $rows = procurementRequestCanonicalPrepareAndFetchAll(
        $conn,
        "SELECT COALESCE(e.legacy_source_id, e.id) AS id, e.event_type, e.actor_user_id,
                e.actor_email, e.details_json, e.created_at
         FROM {$eventSource} e
         INNER JOIN procurement_requests r ON r.id = e.request_id
         WHERE r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
           AND r.legacy_source_id = ?
         ORDER BY e.created_at DESC, e.id DESC",
        'i',
        [$legacyId]
    );

    foreach ($rows as &$event) {
        $event['id'] = (int) $event['id'];
        $event['actor_user_id'] = (int) $event['actor_user_id'];
        $decoded = json_decode((string) ($event['details_json'] ?? ''), true);
        $event['details'] = is_array($decoded) ? $decoded : null;
        unset($event['details_json']);
    }
    unset($event);

    return $rows;
}

function procurementRequestCanonicalLocalAdvanceHandoffs(mysqli $conn, int $legacyId): array
{
    $rows = procurementRequestCanonicalPrepareAndFetchAll(
        $conn,
        "SELECT COALESCE(h.legacy_source_id, h.id) AS id, h.revision,
                h.account_request_id AS advance_payment_request_id, h.handoff_status,
                h.sent_by, h.sent_at, h.retrieved_by, h.retrieved_at,
                h.retrieval_source, h.retrieval_reason,
                CONCAT(COALESCE(su.fname, ''), ' ', COALESCE(su.lname, '')) AS sent_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS retrieved_by_name
         FROM procurement_request_handoffs h
         INNER JOIN procurement_requests r ON r.id = h.request_id
         LEFT JOIN user_table su ON su.id = h.sent_by
         LEFT JOIN user_table ru ON ru.id = h.retrieved_by
         WHERE r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
           AND r.legacy_source_id = ?
         ORDER BY h.revision DESC, h.id DESC",
        'i',
        [$legacyId]
    );

    foreach ($rows as &$handoff) {
        foreach (['id', 'revision', 'advance_payment_request_id', 'sent_by', 'retrieved_by'] as $field) {
            $handoff[$field] = $handoff[$field] === null ? null : (int) $handoff[$field];
        }
    }
    unset($handoff);

    return $rows;
}

function procurementRequestCanonicalLocalAdvanceGetResponse(mysqli $conn, array $query): ?array
{
    if (!procurementRequestCanonicalReadsEnabled($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE)) {
        return null;
    }

    $id = (int) ($query['id'] ?? 0);
    if ($id > 0) {
        $record = procurementRequestCanonicalFetchLocalAdvanceRecord($conn, $id);
        if (!$record) {
            throw new RuntimeException('Local Advance Purchase not founr.', 404);
        }

        $response = ['record' => procurementLocalAdvanceSerializeRecord($record)];
        if (filter_var($query['include_events'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $response['events'] = procurementRequestCanonicalLocalAdvanceEvents($conn, $id);
            $response['handoffs'] = procurementRequestCanonicalLocalAdvanceHandoffs($conn, $id);
        }

        return ['status' => 'Success', 'data' => $response];
    }

    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim((string) ($query['search'] ?? ''));
    $approvalStatus = trim((string) ($query['approval_status'] ?? ''));
    $paymentStatus = trim((string) ($query['payment_status'] ?? ''));
    $poStatus = trim((string) ($query['po_status'] ?? ''));
    $handoffStatus = trim((string) ($query['handoff_status'] ?? ''));
    $year = (int) ($query['year'] ?? 0);
    $projectId = (int) ($query['project_id'] ?? 0);
    $supplierId = (int) ($query['supplier_id'] ?? 0);

    if ($approvalStatus !== '') {
        $approvalStatus = procurementLocalAdvanceValidateStatus(
            $approvalStatus,
            PROCUREMENT_LOCAL_ADVANCE_APPROVAL_STATUSES,
            'Approval Status'
        );
    }
    if ($paymentStatus !== '') {
        $paymentStatus = procurementLocalAdvanceValidateStatus(
            $paymentStatus,
            PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES,
            'Payment Status'
        );
    }
    if ($poStatus !== '') {
        $poStatus = procurementLocalAdvanceValidateStatus(
            $poStatus,
            PROCUREMENT_LOCAL_ADVANCE_PO_STATUSES,
            'PO Status'
        );
    }
    if ($handoffStatus !== '') {
        $handoffStatus = procurementLocalAdvanceValidateStatus(
            $handoffStatus,
            PROCUREMENT_LOCAL_ADVANCE_HANDOFF_STATUSES,
            'Account Handoff Status'
        );
    }
    if ($year !== 0 && ($year < 2000 || $year > 2100)) {
        throw new RuntimeException('Year filter is invalir.', 400);
    }

    $allowedSortFields = [
        'created_at' => 'r.created_at',
        'updated_at' => 'r.updated_at',
        'approved_at' => 'r.approved_at',
        'retrieved_at' => 'r.retrieved_at',
        'transaction_date' => 'r.transaction_date',
        'date_received' => 'r.date_received',
        'request_number' => 'r.request_number',
        'purchase_number' => 'r.purchase_number',
        'po_number' => 'r.po_number',
        'supplier_name' => 'r.supplier_name',
        'project_code' => 'r.project_code',
        'po_percentage' => 'r.po_percentage',
        'expected_payment' => 'r.expected_payment',
        'po_value' => 'COALESCE(pr.po_value, p.po_value)',
        'approval_status' => 'r.approval_status',
        'payment_status' => 'r.payment_status',
        'po_status' => 'p.po_status',
        'handoff_status' => 'r.handoff_status',
    ];
    $sortBy = (string) ($query['sort_by'] ?? 'created_at');
    $sortColumn = $allowedSortFields[$sortBy] ?? $allowedSortFields['created_at'];
    $sortOrder = strtoupper((string) ($query['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = [
        "r.request_type = 'local_advance_purchase'",
        "r.legacy_source_table = 'procurement_local_advance_purchases'",
        'r.deleted_at IS NULL',
    ];
    $params = [];
    $types = '';
    if ($search !== '') {
        $where[] = '(r.request_number LIKE ? OR r.purchase_number LIKE ? OR r.po_number LIKE ? OR r.project_code LIKE ? OR r.project_name LIKE ? OR r.supplier_name LIKE ? OR r.supplier_ledger LIKE ?)';
        $like = '%' . $search . '%';
        for ($index = 0; $index < 7; $index++) {
            $params[] = $like;
        }
        $types .= 'sssssss';
    }
    foreach ([
        ['value' => $approvalStatus, 'sql' => 'r.approval_status = ?', 'type' => 's'],
        ['value' => $paymentStatus, 'sql' => 'r.payment_status = ?', 'type' => 's'],
        ['value' => $poStatus, 'sql' => 'p.po_status = ?', 'type' => 's'],
        ['value' => $handoffStatus, 'sql' => 'r.handoff_status = ?', 'type' => 's'],
    ] as $filter) {
        if ($filter['value'] !== '') {
            $where[] = $filter['sql'];
            $params[] = $filter['value'];
            $types .= $filter['type'];
        }
    }
    if ($year > 0) {
        $where[] = 'YEAR(r.transaction_date) = ?';
        $params[] = $year;
        $types .= 'i';
    }
    if ($projectId > 0) {
        $where[] = 'r.project_id = ?';
        $params[] = $projectId;
        $types .= 'i';
    }
    if ($supplierId > 0) {
        $where[] = 'r.supplier_id = ?';
        $params[] = $supplierId;
        $types .= 'i';
    }
    $whereSql = implode(' AND ', $where);

    $countRow = procurementRequestCanonicalPrepareAndFetchOne(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_requests r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
         WHERE {$whereSql}",
        $types,
        $params
    );
    $total = (int) ($countRow['total'] ?? 0);

    $rows = procurementRequestCanonicalPrepareAndFetchAll(
        $conn,
        procurementRequestCanonicalLocalAdvanceSelectSql()
        . " WHERE {$whereSql}
            ORDER BY {$sortColumn} {$sortOrder}, r.legacy_source_id DESC
            LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$limit, $offset])
    );

    foreach ($rows as &$row) {
        $row = procurementRequestCanonicalNormalizeLocalAdvanceRow($row);
        $row['allocated_percentage'] = procurementRequestCanonicalAdvanceAllocatedPercentage(
            $conn,
            (int) $row['po_id']
        );
        $row = procurementLocalAdvanceSerializeRecord($row);
    }
    unset($row);

    return [
        'status' => 'Success',
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
            'filters' => [
                'search' => $search,
                'approval_status' => $approvalStatus,
                'payment_status' => $paymentStatus,
                'po_status' => $poStatus,
                'handoff_status' => $handoffStatus,
                'year' => $year ?: null,
                'project_id' => $projectId ?: null,
                'supplier_id' => $supplierId ?: null,
            ],
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ],
    ];
}
