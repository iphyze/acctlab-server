<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageCanonicalWriteService.php';
require_once __DIR__ . '/accountSupplierPaymentService.php';
require_once __DIR__ . '/accountAdvancePaymentService.php';
require_once __DIR__ . '/accountCompassFundRequestService.php';
require_once __DIR__ . '/fxFundRequestPaymentLifecycleService.php';
require_once __DIR__ . '/accountPaymentReminderService.php';
require_once __DIR__ . '/procurementSupplierFinancialAdjustmentService.php';

/**
 * Canonical, cross-workflow payment-processing workspace.
 *
 * The database deliberately remains normalized into one batch table and one
 * batch-item table. Local Final, Local Advance, FX Final and FX Advance are
 * separated only by request_type; no parallel Local/FX processing tables are
 * introduced here.
 */

function accountPaymentProcessingTypeMap(): array
{
    return [
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => [
            'label' => 'Local Final',
            'scope' => 'Local',
            'source_label' => 'Local Final Fund Request',
        ],
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => [
            'label' => 'Local Advance',
            'scope' => 'Local',
            'source_label' => 'Local Advance Fund Request',
        ],
        ACCOUNT_PAYMENT_TYPE_COMPASS => [
            'label' => 'Compass',
            'scope' => 'Local',
            'source_label' => 'Compass Fund Request',
        ],
        ACCOUNT_PAYMENT_TYPE_FX_FINAL => [
            'label' => 'FX Final',
            'scope' => 'FX',
            'source_label' => 'FX Final Fund Request',
        ],
        ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => [
            'label' => 'FX Advance',
            'scope' => 'FX',
            'source_label' => 'FX Advance Fund Request',
        ],
    ];
}

function accountPaymentProcessingAssertType(string $requestType): string
{
    $requestType = trim($requestType);
    if (!isset(accountPaymentProcessingTypeMap()[$requestType])) {
        throw new RuntimeException('Unsupported payment-processing request type.', 400);
    }
    return $requestType;
}

function accountPaymentProcessingCanonicalTypeFromFx(string $requestType): string
{
    return match (strtolower(trim($requestType))) {
        'final' => ACCOUNT_PAYMENT_TYPE_FX_FINAL,
        'advance' => ACCOUNT_PAYMENT_TYPE_FX_ADVANCE,
        default => throw new RuntimeException('FX Fund Request type must be Final or Advance.', 409),
    };
}

function accountPaymentProcessingBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $index => $value) {
        $params[$index] = $value;
        $refs[$index] = &$params[$index];
    }
    $stmt->bind_param($types, ...$refs);
}

/**
 * Keep payment-processing reads compatible with installations where the
 * optional Account-document migration has not yet been applied. Once the
 * table exists we expose one active-document count per canonical batch using
 * a pre-aggregated join, so item/register queries never multiply payment rows.
 */
function accountPaymentProcessingDocumentProjection(mysqli $conn): array
{
    static $cache = [];
    $key = spl_object_id($conn);
    if (!array_key_exists($key, $cache)) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'account_documents'
               AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute();
        $cache[$key] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
        $stmt->close();
    }

    if (!$cache[$key]) {
        return ['join' => '', 'select' => '0 AS document_count'];
    }

    return [
        'join' => "LEFT JOIN (
                    SELECT entity_id, COUNT(*) AS active_document_count
                    FROM account_documents
                    WHERE entity_type = 'payment_batch' AND status = 'ACTIVE'
                    GROUP BY entity_id
                 ) batch_docs ON batch_docs.entity_id = b.id",
        'select' => 'COALESCE(batch_docs.active_document_count, 0) AS document_count',
    ];
}

function accountPaymentProcessingLimit(array $query): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = (int) ($query['limit'] ?? 20);
    if (!in_array($limit, [10, 20, 50, 100], true)) {
        $limit = 20;
    }
    return [$page, $limit, ($page - 1) * $limit];
}

/**
 * A single normalized projection of the four request sources. Monetary values
 * are never converted here. Local rows are NGN; FX rows retain their payment
 * currency (or original currency before a payment currency is available).
 */
function accountPaymentProcessingSourceProjection(): string
{
    // The Local request tables pre-date the canonical payment workspace and
    // may use a different utf8mb4 collation from the newer FX/canonical
    // tables. A UNION compares corresponding string expressions before the
    // request_type filter is applied, so normalize every textual projection to
    // one collation here. This keeps Local-only, FX-only and mixed workspace
    // reads safe without changing any source-table collation or schema.
    return "(
        SELECT
            CONVERT('local_final_purchase' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            sfr.id AS request_id,
            CONVERT(sfr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS counterparty,
            CONVERT(sfr.purchase_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT(sfr.po_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT(sfr.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(sfr.project_code USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CAST(COALESCE(NULLIF(sfr.amount, ''), '0') AS DECIMAL(18,2)) AS request_amount,
            CONVERT(sfr.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(sfr.procurement_source, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            sfr.procurement_purchase_id,
            NULL AS fx_instruction_letter_id
        FROM supplier_fund_request_table sfr

        UNION ALL

        SELECT
            CONVERT('local_advance_purchase' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            apr.id AS request_id,
            CONVERT(apr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS counterparty,
            CONVERT(apr.po_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT(apr.po_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(apr.site USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CAST(COALESCE(NULLIF(apr.advance_payment, ''), '0') AS DECIMAL(18,2)) AS request_amount,
            CONVERT(apr.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(apr.procurement_source, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            apr.procurement_purchase_id,
            NULL AS fx_instruction_letter_id
        FROM advance_payment_request apr

        UNION ALL

        SELECT
            CONVERT('compass_fund_request' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            cfr.id AS request_id,
            CONVERT(cfr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS counterparty,
            CONVERT(COALESCE(NULLIF(cfr.purchase_number, ''), NULLIF(cfr.po_number, ''), NULLIF(cfr.invoice_number, ''), CONCAT('COMPASS-', cfr.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT(COALESCE(cfr.po_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT(COALESCE(cfr.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(cfr.project_code USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CAST(COALESCE(cfr.amount, 0) AS DECIMAL(18,2)) AS request_amount,
            CONVERT(cfr.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(cfr.procurement_source, 'AcctLab Compass') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            cfr.procurement_purchase_id,
            NULL AS fx_instruction_letter_id
        FROM compass_fund_request_table cfr

        UNION ALL

        SELECT
            CONVERT(CASE WHEN fx.request_type = 'Final' THEN 'fx_final_purchase' ELSE 'fx_advance_purchase' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            fx.id AS request_id,
            CONVERT(fx.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS counterparty,
            CONVERT(COALESCE(NULLIF(fx.purchase_number, ''), NULLIF(fx.po_number, ''), CONCAT('FX-', fx.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT(COALESCE(fx.po_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT(COALESCE(fx.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(fx.project_code USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT(COALESCE(NULLIF(fx.payment_currency, ''), fx.currency, 'FX') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CAST(COALESCE(fx.payment_amount, fx.payable_amount, 0) AS DECIMAL(18,2)) AS request_amount,
            CONVERT(fx.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT('AcctLab FX' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            NULL AS procurement_purchase_id,
            fx.fx_instruction_letter_id
        FROM fx_fund_request_table fx
    )";
}

function accountPaymentProcessingScopeCondition(string $scope): array
{
    return match (strtolower(trim($scope))) {
        '', 'all' => [null, '', []],
        'local' => ["i.request_type IN ('local_final_purchase','local_advance_purchase','compass_fund_request')", '', []],
        'fx' => ["i.request_type IN ('fx_final_purchase','fx_advance_purchase')", '', []],
        default => throw new RuntimeException('Processing scope must be Local, FX or All.', 400),
    };
}

function accountPaymentProcessingBuildItemWhere(array $query): array
{
    $where = ['i.request_type = b.request_type', 'r.request_type = i.request_type', 'r.request_id = i.request_id'];
    $params = [];
    $types = '';

    [$scopeSql] = accountPaymentProcessingScopeCondition((string) ($query['scope'] ?? 'all'));
    if ($scopeSql !== null) {
        $where[] = $scopeSql;
    }

    $requestType = trim((string) ($query['request_type'] ?? ''));
    if ($requestType !== '' && strcasecmp($requestType, 'all') !== 0) {
        accountPaymentProcessingAssertType($requestType);
        $where[] = 'i.request_type = ?';
        $params[] = $requestType;
        $types .= 's';
    }

    $batchId = (int) ($query['batch_id'] ?? 0);
    if ($batchId > 0) {
        $where[] = 'b.id = ?';
        $params[] = $batchId;
        $types .= 'i';
    }

    // Legacy Local payment links carried the request-type scoped batch ID.
    // Resolve those against the canonical batch row without introducing a
    // second processing workspace or table.
    $legacyBatchId = (int) ($query['legacy_batch_id'] ?? 0);
    if ($legacyBatchId > 0) {
        if ($requestType === '' || strcasecmp($requestType, 'all') === 0) {
            throw new RuntimeException('Request type is required with a legacy payment batch ID.', 400);
        }
        $where[] = 'b.legacy_source_id = ?';
        $params[] = $legacyBatchId;
        $types .= 'i';
    }

    $status = trim((string) ($query['status'] ?? 'active'));
    if (strcasecmp($status, 'active') === 0) {
        $where[] = "i.status IN ('Processing','Awaiting Confirmation','Delayed')";
        $where[] = "r.payment_status IN ('Processing','Unconfirmed')";
    } elseif ($status !== '' && strcasecmp($status, 'all') !== 0) {
        $allowedStatuses = ['Processing', 'Awaiting Confirmation', 'Delayed', 'Paid', 'Failed', 'Cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Invalid processing status filter.', 400);
        }
        $where[] = 'i.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    $method = trim((string) ($query['processing_method'] ?? $query['method'] ?? ''));
    if ($method !== '' && strcasecmp($method, 'all') !== 0) {
        $where[] = 'b.processing_method = ?';
        $params[] = $method;
        $types .= 's';
    }

    $currency = strtoupper(trim((string) ($query['currency'] ?? '')));
    if ($currency !== '' && strcasecmp($currency, 'all') !== 0) {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Currency filter must use a three-letter currency code.', 400);
        }
        $where[] = 'r.currency = ?';
        $params[] = $currency;
        $types .= 's';
    }

    $search = trim((string) ($query['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(r.counterparty LIKE ? OR r.primary_reference LIKE ? OR r.po_number LIKE ? OR r.invoice_number LIKE ? OR r.project_code LIKE ? OR b.batch_reference LIKE ? OR b.processing_reference LIKE ?)';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    return [implode(' AND ', $where), $types, $params];
}

function accountPaymentProcessingEnrichRows(array $rows): array
{
    $types = accountPaymentProcessingTypeMap();
    foreach ($rows as &$row) {
        $meta = $types[(string) ($row['request_type'] ?? '')] ?? ['label' => 'Unknown', 'scope' => 'Unknown', 'source_label' => 'Fund Request'];
        $row['request_type_label'] = $meta['label'];
        $row['scope'] = $meta['scope'];
        $row['source_label'] = $meta['source_label'];
        $row['id'] = (int) ($row['id'] ?? 0); // canonical batch-item id
        $row['canonical_item_id'] = $row['id'];
        $row['legacy_item_id'] = (int) ($row['legacy_item_id'] ?? 0);
        $row['batch_id'] = (int) ($row['batch_id'] ?? 0); // canonical batch id
        $row['legacy_batch_id'] = (int) ($row['legacy_batch_id'] ?? 0);
        $row['document_count'] = (int) ($row['document_count'] ?? 0);
        $row['has_documents'] = $row['document_count'] > 0;
        $row['request_id'] = (int) ($row['request_id'] ?? 0);
        $row['can_update'] = in_array((string) ($row['status'] ?? ''), ['Processing', 'Awaiting Confirmation', 'Delayed'], true)
            && in_array((string) ($row['payment_status'] ?? ''), ['Processing', 'Unconfirmed'], true);
        $row['supports_delay'] = in_array((string) ($row['request_type'] ?? ''), [
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
            ACCOUNT_PAYMENT_TYPE_COMPASS,
        ], true);
    }
    unset($row);
    return $rows;
}

function accountPaymentProcessingListItems(mysqli $conn, array $query): array
{
    accountPaymentStorageCanonicalReadsEnabled($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    [$page, $limit, $offset] = accountPaymentProcessingLimit($query);
    [$whereSql, $types, $params] = accountPaymentProcessingBuildItemWhere($query);
    $source = accountPaymentProcessingSourceProjection();
    $documentProjection = accountPaymentProcessingDocumentProjection($conn);
    $from = "FROM account_payment_batch_items i
             INNER JOIN account_payment_batches b ON b.id = i.batch_id
             INNER JOIN {$source} r ON r.request_type = i.request_type AND r.request_id = i.request_id
             {$documentProjection['join']}";

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total {$from} WHERE {$whereSql}");
    accountPaymentProcessingBind($countStmt, $types, $params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $stmt = $conn->prepare(
        "SELECT
            i.id,
            i.legacy_source_id AS legacy_item_id,
            b.id AS batch_id,
            b.legacy_source_id AS legacy_batch_id,
            b.batch_reference,
            b.processing_method,
            b.processing_reference,
            b.completion_mode,
            b.status AS batch_status,
            b.processing_business_days,
            b.expected_completion_at AS batch_expected_completion_at,
            i.request_type,
            i.request_id,
            i.amount,
            i.amount_paid,
            i.status,
            i.status_reason,
            i.processing_started_at,
            i.expected_completion_at,
            i.paid_at,
            i.payment_reference,
            r.counterparty,
            r.primary_reference,
            r.po_number,
            r.invoice_number,
            r.project_code,
            r.currency,
            r.request_amount,
            r.payment_status,
            r.procurement_source,
            r.procurement_purchase_id,
            r.fx_instruction_letter_id,
            {$documentProjection['select']}
         {$from}
         WHERE {$whereSql}
         ORDER BY
            CASE WHEN i.status = 'Awaiting Confirmation' THEN 0 WHEN i.status = 'Delayed' THEN 1 WHEN i.status = 'Processing' THEN 2 ELSE 3 END,
            i.expected_completion_at ASC,
            i.id DESC
         LIMIT ? OFFSET ?"
    );
    $allParams = array_merge($params, [$limit, $offset]);
    accountPaymentProcessingBind($stmt, $types . 'ii', $allParams);
    $stmt->execute();
    $rows = accountPaymentProcessingEnrichRows($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    $summaryStmt = $conn->prepare(
        "SELECT r.currency,
                COUNT(*) AS total_count,
                SUM(CASE WHEN i.status = 'Processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN i.status = 'Awaiting Confirmation' THEN 1 ELSE 0 END) AS awaiting_confirmation_count,
                SUM(CASE WHEN i.status = 'Delayed' THEN 1 ELSE 0 END) AS delayed_count,
                SUM(CASE WHEN i.status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(i.amount) AS total_value,
                SUM(CASE WHEN i.status = 'Processing' THEN i.amount ELSE 0 END) AS processing_value,
                SUM(CASE WHEN i.status = 'Awaiting Confirmation' THEN i.amount ELSE 0 END) AS awaiting_confirmation_value,
                SUM(CASE WHEN i.status = 'Delayed' THEN i.amount ELSE 0 END) AS delayed_value,
                SUM(CASE WHEN i.status = 'Paid' THEN i.amount_paid ELSE 0 END) AS paid_value
         {$from}
         WHERE {$whereSql}
         GROUP BY r.currency
         ORDER BY r.currency"
    );
    accountPaymentProcessingBind($summaryStmt, $types, $params);
    $summaryStmt->execute();
    $currencySummary = $summaryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $summaryStmt->close();

    $typeStmt = $conn->prepare(
        "SELECT i.request_type, COUNT(*) AS total
         {$from}
         WHERE {$whereSql}
         GROUP BY i.request_type
         ORDER BY i.request_type"
    );
    accountPaymentProcessingBind($typeStmt, $types, $params);
    $typeStmt->execute();
    $typeCounts = $typeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $typeStmt->close();

    return [
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
        ],
        'summary_by_currency' => $currencySummary,
        'request_type_counts' => $typeCounts,
    ];
}

function accountPaymentProcessingListBatches(mysqli $conn, array $query): array
{
    [$page, $limit, $offset] = accountPaymentProcessingLimit($query);
    $where = ['1=1'];
    $params = [];
    $types = '';

    $scope = strtolower(trim((string) ($query['scope'] ?? 'all')));
    if ($scope === 'local') {
        $where[] = "b.request_type IN ('local_final_purchase','local_advance_purchase','compass_fund_request')";
    } elseif ($scope === 'fx') {
        $where[] = "b.request_type IN ('fx_final_purchase','fx_advance_purchase')";
    } elseif (!in_array($scope, ['', 'all'], true)) {
        throw new RuntimeException('Processing scope must be Local, FX or All.', 400);
    }

    $requestType = trim((string) ($query['request_type'] ?? ''));
    if ($requestType !== '' && strcasecmp($requestType, 'all') !== 0) {
        accountPaymentProcessingAssertType($requestType);
        $where[] = 'b.request_type = ?';
        $params[] = $requestType;
        $types .= 's';
    }

    $status = trim((string) ($query['status'] ?? ''));
    if ($status !== '' && strcasecmp($status, 'all') !== 0) {
        $where[] = 'b.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    $search = trim((string) ($query['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(b.batch_reference LIKE ? OR b.processing_reference LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    $whereSql = implode(' AND ', $where);
    $count = $conn->prepare("SELECT COUNT(*) AS total FROM account_payment_batches b WHERE {$whereSql}");
    accountPaymentProcessingBind($count, $types, $params);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $source = accountPaymentProcessingSourceProjection();
    $documentProjection = accountPaymentProcessingDocumentProjection($conn);
    $stmt = $conn->prepare(
        "SELECT b.id, b.legacy_source_id AS legacy_batch_id, b.request_type, b.batch_reference,
                b.processing_method, b.processing_reference, b.processing_business_days,
                b.expected_completion_at, b.completion_mode, b.status, b.item_count,
                b.total_amount, b.account_remarks, b.created_at, b.updated_at, b.completed_at,
                CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, '')) AS created_by_name,
                COALESCE(GROUP_CONCAT(DISTINCT r.currency ORDER BY r.currency SEPARATOR ' · '), 'NGN') AS currencies,
                SUM(CASE WHEN i.status IN ('Processing','Awaiting Confirmation','Delayed') THEN 1 ELSE 0 END) AS active_item_count,
                SUM(CASE WHEN i.status = 'Paid' THEN 1 ELSE 0 END) AS paid_item_count,
                {$documentProjection['select']}
         FROM account_payment_batches b
         LEFT JOIN user_table u ON u.id = b.created_by
         LEFT JOIN account_payment_batch_items i ON i.batch_id = b.id AND i.request_type = b.request_type
         LEFT JOIN {$source} r ON r.request_type = i.request_type AND r.request_id = i.request_id
         {$documentProjection['join']}
         WHERE {$whereSql}
         GROUP BY b.id
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT ? OFFSET ?"
    );
    accountPaymentProcessingBind($stmt, $types . 'ii', array_merge($params, [$limit, $offset]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $metaMap = accountPaymentProcessingTypeMap();
    foreach ($rows as &$row) {
        $meta = $metaMap[(string) ($row['request_type'] ?? '')] ?? ['label' => 'Unknown', 'scope' => 'Unknown'];
        $row['request_type_label'] = $meta['label'];
        $row['scope'] = $meta['scope'];
        $row['id'] = (int) $row['id'];
        $row['legacy_batch_id'] = (int) $row['legacy_batch_id'];
        $row['document_count'] = (int) ($row['document_count'] ?? 0);
        $row['has_documents'] = $row['document_count'] > 0;
    }
    unset($row);

    return [
        'data' => $rows,
        'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
    ];
}

function accountPaymentProcessingResolveCanonicalBatchId(mysqli $conn, int $legacyBatchId, string $requestType): int
{
    if ($legacyBatchId <= 0) {
        throw new RuntimeException('Legacy payment batch ID is required.', 400);
    }
    $requestType = accountPaymentProcessingAssertType($requestType);
    $stmt = $conn->prepare(
        'SELECT id FROM account_payment_batches WHERE request_type = ? AND legacy_source_id = ? LIMIT 1'
    );
    $stmt->bind_param('si', $requestType, $legacyBatchId);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($id <= 0) {
        throw new RuntimeException('Payment batch not found.', 404);
    }
    return $id;
}

function accountPaymentProcessingGetBatch(mysqli $conn, int $batchId): array
{
    if ($batchId <= 0) {
        throw new RuntimeException('Payment batch ID is required.', 400);
    }
    $documentProjection = accountPaymentProcessingDocumentProjection($conn);
    $batchStmt = $conn->prepare(
        "SELECT b.*, b.id AS canonical_batch_id, b.legacy_source_id AS legacy_batch_id,
                CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, '')) AS created_by_name,
                {$documentProjection['select']}
         FROM account_payment_batches b
         LEFT JOIN user_table u ON u.id = b.created_by
         {$documentProjection['join']}
         WHERE b.id = ? LIMIT 1"
    );
    $batchStmt->bind_param('i', $batchId);
    $batchStmt->execute();
    $batch = $batchStmt->get_result()->fetch_assoc();
    $batchStmt->close();
    if (!$batch) {
        throw new RuntimeException('Payment batch not found.', 404);
    }
    $items = accountPaymentProcessingListItems($conn, [
        'page' => 1,
        'limit' => 100,
        'batch_id' => $batchId,
        'status' => 'all',
    ]);
    $batch['items'] = $items['data'];
    $batch['summary_by_currency'] = $items['summary_by_currency'];
    $meta = accountPaymentProcessingTypeMap()[(string) $batch['request_type']] ?? ['label' => 'Unknown', 'scope' => 'Unknown'];
    $batch['request_type_label'] = $meta['label'];
    $batch['scope'] = $meta['scope'];
    $batch['document_count'] = (int) ($batch['document_count'] ?? 0);
    $batch['has_documents'] = $batch['document_count'] > 0;
    return $batch;
}

function accountPaymentProcessingGenerateFxBatchReference(string $requestType, int $instructionId): string
{
    $prefix = $requestType === ACCOUNT_PAYMENT_TYPE_FX_FINAL ? 'FXF' : 'FXA';
    return sprintf('%s-PROC-%06d', $prefix, $instructionId);
}

/**
 * Persist FX processing into the same canonical batch/item tables already used
 * by Local processing. FX completion metadata is stored on these same canonical
 * rows; request_type keeps Final and Advance isolated without parallel tables.
 */
function accountPaymentProcessingCreateFxBatches(
    mysqli $conn,
    array $requests,
    array $lines,
    int $instructionId,
    string $instructionReference,
    int $actorId,
    array $completion = []
): array {
    if ($instructionId < 0 || $requests === [] || $lines === []) {
        throw new RuntimeException('FX canonical processing batch context is incomplete.', 409);
    }

    $completionMode = trim((string) ($completion['completion_mode'] ?? 'Notify'));
    if (!in_array($completionMode, ['Notify', 'Immediate', 'Automatic'], true)) {
        throw new RuntimeException('FX completion mode must be Notify, Immediate or Automatic.', 400);
    }
    $isImmediate = $completionMode === 'Immediate';
    $businessDays = $isImmediate ? 0 : (int) ($completion['processing_business_days'] ?? 0);
    if (!$isImmediate && ($businessDays < 1 || $businessDays > 5)) {
        throw new RuntimeException('FX processing period must be between 1 and 5 business days.', 400);
    }
    $expectedCompletionAt = trim((string) ($completion['expected_completion_at'] ?? ''));
    if ($expectedCompletionAt === '') {
        throw new RuntimeException('FX expected completion timestamp is required.', 409);
    }

    $requestById = [];
    foreach ($requests as $request) {
        $requestById[(int) $request['id']] = $request;
    }

    $groups = [];
    foreach ($lines as $line) {
        $requestId = (int) ($line['request_id'] ?? 0);
        $request = $requestById[$requestId] ?? null;
        if (!$request) {
            throw new RuntimeException("FX Fund Request #{$requestId} is missing from canonical batch creation.", 409);
        }
        $type = accountPaymentProcessingCanonicalTypeFromFx((string) ($request['request_type'] ?? ''));
        $groups[$type][] = [
            'request_id' => $requestId,
            'amount' => round((float) ($line['payment_amount'] ?? $request['payment_amount'] ?? $request['payable_amount'] ?? 0), 2),
        ];
    }

    $now = date('Y-m-d H:i:s');
    $created = [];
    foreach ($groups as $requestType => $items) {
        $total = round(array_sum(array_map(static fn(array $item): float => (float) $item['amount'], $items)), 2);
        $batchReference = $instructionId > 0
            ? accountPaymentProcessingGenerateFxBatchReference($requestType, $instructionId)
            : sprintf('%s-CREDIT-%06d-%s', $requestType === ACCOUNT_PAYMENT_TYPE_FX_FINAL ? 'FXF' : 'FXA', (int) ($items[0]['request_id'] ?? 0), date('YmdHis'));
        $legacyBatchId = accountPaymentStorageCreateBatch($conn, $requestType, [
            'batch_reference' => $batchReference,
            'processing_method' => $instructionId > 0 ? 'FX Instruction' : 'Supplier Credit Offset',
            'processing_reference' => $instructionReference,
            'processing_business_days' => $businessDays,
            'expected_completion_at' => $expectedCompletionAt,
            'completion_mode' => $completionMode,
            'status' => $isImmediate ? 'Completed' : 'Processing',
            'item_count' => count($items),
            'total_amount' => $total,
            'account_remarks' => $instructionId > 0
                ? 'FX payment processing tracked in the shared canonical batch workspace.'
                : 'FX request settled entirely by same-supplier, same-currency supplier credit.',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'completed_at' => $isImmediate ? $now : null,
        ]);
        $canonicalBatchId = accountPaymentStorageCanonicalBatchId($conn, $requestType, $legacyBatchId);
        $requestIds = [];
        foreach ($items as $item) {
            $requestIds[] = (int) $item['request_id'];
            accountPaymentStorageCreateItem($conn, $requestType, $legacyBatchId, [
                'request_id' => (int) $item['request_id'],
                'amount' => (float) $item['amount'],
                'amount_paid' => $isImmediate ? (float) $item['amount'] : 0.0,
                'status' => $isImmediate ? 'Paid' : 'Processing',
                'status_reason' => $isImmediate ? 'Completed immediately when the FX instruction was created.' : null,
                'processing_started_at' => $now,
                'expected_completion_at' => $expectedCompletionAt,
                'paid_at' => $isImmediate ? $now : null,
                'payment_reference' => $isImmediate ? $instructionReference : null,
                'updated_by' => $actorId,
            ]);
        }
        if ($instructionId > 0) {
            accountPaymentStorageUpsertArtifact($conn, $requestType, $legacyBatchId, [
                'artifact_type' => 'fx_instruction',
                'artifact_id' => $instructionId,
                'artifact_reference' => $instructionReference,
                'artifact_route' => '/payments/fx-payments/print/' . $instructionId,
                'request_ids_json' => json_encode($requestIds, JSON_UNESCAPED_SLASHES),
                'created_by' => $actorId,
            ]);
        }
        $created[] = [
            'id' => $canonicalBatchId,
            'legacy_batch_id' => $legacyBatchId,
            'request_type' => $requestType,
            'batch_reference' => $batchReference,
            'item_count' => count($items),
            'total_amount' => $total,
            'completion_mode' => $completionMode,
            'processing_business_days' => $businessDays,
            'expected_completion_at' => $expectedCompletionAt,
            'status' => $isImmediate ? 'Completed' : 'Processing',
        ];
    }

    return $created;
}

function accountPaymentProcessingUpdateCanonicalBatchStatus(mysqli $conn, int $canonicalBatchId, int $actorId): void
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'Processing') AS processing_count,
                SUM(status = 'Awaiting Confirmation') AS awaiting_count,
                SUM(status = 'Delayed') AS delayed_count,
                SUM(status = 'Paid') AS paid_count,
                SUM(status IN ('Failed','Cancelled')) AS exception_count
         FROM account_payment_batch_items WHERE batch_id = ?"
    );
    $stmt->bind_param('i', $canonicalBatchId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $total = (int) ($summary['total'] ?? 0);
    $active = (int) ($summary['processing_count'] ?? 0) + (int) ($summary['awaiting_count'] ?? 0) + (int) ($summary['delayed_count'] ?? 0);
    $paid = (int) ($summary['paid_count'] ?? 0);
    $exceptions = (int) ($summary['exception_count'] ?? 0);

    if ((int) ($summary['awaiting_count'] ?? 0) > 0) {
        $status = 'Awaiting Confirmation';
        $completedAt = null;
    } elseif ($active > 0) {
        $status = 'Processing';
        $completedAt = null;
    } elseif ($total > 0 && $paid === $total) {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    } elseif ($exceptions > 0 || $total > 0) {
        $status = 'Completed With Exceptions';
        $completedAt = date('Y-m-d H:i:s');
    } else {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    }

    $update = $conn->prepare('UPDATE account_payment_batches SET status = ?, updated_by = ?, completed_at = ? WHERE id = ?');
    $update->bind_param('sisi', $status, $actorId, $completedAt, $canonicalBatchId);
    $update->execute();
    $update->close();
}

function accountPaymentProcessingExpandFxSharedInstructionRequests(mysqli $conn, array $requestIds): array
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn(int $id): bool => $id > 0)));
    if ($requestIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));
    $stmt = $conn->prepare(
        "SELECT DISTINCT sibling.id
         FROM fx_fund_request_table selected
         INNER JOIN fx_fund_request_table sibling
           ON sibling.fx_instruction_letter_id = selected.fx_instruction_letter_id
         WHERE selected.id IN ({$placeholders})
           AND selected.fx_instruction_letter_id IS NOT NULL"
    );
    accountPaymentProcessingBind($stmt, $types, $requestIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $expanded = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    sort($expanded, SORT_NUMERIC);
    return $expanded;
}

function accountPaymentProcessingApplyFxPaid(
    mysqli $conn,
    array $requestIds,
    string $paymentReference,
    array $actor,
    bool $manageTransaction = true
): array {
    $expanded = accountPaymentProcessingExpandFxSharedInstructionRequests($conn, $requestIds);
    if ($expanded === []) {
        $expanded = array_values(array_unique(array_map('intval', $requestIds)));
    }
    $expanded = array_values(array_filter($expanded, static fn(int $id): bool => $id > 0));
    if ($expanded === []) {
        throw new RuntimeException('No eligible FX processing requests were selected.', 400);
    }

    if ($manageTransaction) {
        $conn->begin_transaction();
    }
    try {
        $placeholders = implode(',', array_fill(0, count($expanded), '?'));
        $types = str_repeat('i', count($expanded));
        $batchStmt = $conn->prepare(
            "SELECT DISTINCT batch_id
             FROM account_payment_batch_items
             WHERE request_type IN ('fx_final_purchase','fx_advance_purchase')
               AND request_id IN ({$placeholders})"
        );
        accountPaymentProcessingBind($batchStmt, $types, $expanded);
        $batchStmt->execute();
        $batchIds = array_map(
            static fn(array $row): int => (int) $row['batch_id'],
            $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC)
        );
        $batchStmt->close();

        // Set the canonical item to Paid before synchronizing ProcureDesk so
        // the FX sync sees account_confirmation_status = Confirmed, not the
        // previous Processing/Awaiting Confirmation state.
        $set = "status = 'Paid', amount_paid = amount, paid_at = NOW(),
                status_reason = 'Payment confirmed through the shared processing workspace.',
                updated_by = ?";
        $params = array_merge([(int) $actor['id']], $expanded);
        $updateTypes = 'i' . $types;
        if ($paymentReference !== '') {
            $set .= ', payment_reference = ?';
            $params = array_merge([(int) $actor['id'], $paymentReference], $expanded);
            $updateTypes = 'is' . $types;
        }
        $update = $conn->prepare(
            "UPDATE account_payment_batch_items
             SET {$set}
             WHERE request_type IN ('fx_final_purchase','fx_advance_purchase')
               AND request_id IN ({$placeholders})
               AND status IN ('Processing','Awaiting Confirmation','Delayed','Failed')"
        );
        accountPaymentProcessingBind($update, $updateTypes, $params);
        $update->execute();
        $updatedItems = $update->affected_rows;
        $update->close();

        $result = fxFundRequestLifecycleApplyRequestStatus(
            $conn,
            $expanded,
            'Paid',
            (int) $actor['id'],
            (string) $actor['email'],
            ''
        );

        $typeStmt = $conn->prepare('SELECT id, request_type FROM fx_fund_request_table WHERE id = ? LIMIT 1');
        foreach ($expanded as $requestId) {
            $typeStmt->bind_param('i', $requestId);
            $typeStmt->execute();
            $fxType = (string) ($typeStmt->get_result()->fetch_assoc()['request_type'] ?? '');
            if ($fxType === '') continue;
            procurementSupplierFinalizeCreditReservations(
                $conn,
                accountPaymentProcessingCanonicalTypeFromFx($fxType),
                $requestId,
                (int) $actor['id'],
                null,
                $paymentReference !== '' ? $paymentReference : 'FX supplier credit offset'
            );
        }
        $typeStmt->close();

        foreach (array_values(array_unique($batchIds)) as $batchId) {
            accountPaymentProcessingUpdateCanonicalBatchStatus($conn, $batchId, (int) $actor['id']);
        }

        // Stop any active Notify reminders immediately after a manual or
        // automatic Paid confirmation. The reminder worker remains a second
        // idempotent safety net if this update is skipped by an older runtime.
        $reminderTypes = ['FX Final', 'FX Advance'];
        $reminderPlaceholders = implode(',', array_fill(0, count($expanded), '?'));
        $reminderTypePlaceholders = implode(',', array_fill(0, count($reminderTypes), '?'));
        $reminderParams = array_merge($reminderTypes, $expanded);
        $reminderBindTypes = str_repeat('s', count($reminderTypes)) . str_repeat('i', count($expanded));
        $reminderUpdate = $conn->prepare(
            "UPDATE account_payment_reminders
             SET lifecycle_status = 'Completed',
                 completed_at = COALESCE(completed_at, NOW()),
                 completion_reason = COALESCE(completion_reason, 'Payment was confirmed as Paid.'),
                 completed_by = ?, updated_by = ?,
                 lease_token = NULL, lease_expires_at = NULL
             WHERE request_type IN ({$reminderTypePlaceholders})
               AND request_id IN ({$reminderPlaceholders})
               AND lifecycle_status NOT IN ('Completed','Cancelled')"
        );
        $reminderParams = array_merge([(int) $actor['id'], (int) $actor['id']], $reminderParams);
        $reminderBindTypes = 'ii' . $reminderBindTypes;
        accountPaymentProcessingBind($reminderUpdate, $reminderBindTypes, $reminderParams);
        $reminderUpdate->execute();
        $reminderUpdate->close();

        if ($manageTransaction) {
            $conn->commit();
        }
        return ['request_ids' => $expanded, 'updated_items' => $updatedItems, 'lifecycle' => $result];
    } catch (Throwable $error) {
        if ($manageTransaction) {
            $conn->rollback();
        }
        throw $error;
    }
}

function accountPaymentProcessingFxReminderType(string $canonicalType): string
{
    return $canonicalType === ACCOUNT_PAYMENT_TYPE_FX_ADVANCE ? 'FX Advance' : 'FX Final';
}

function accountPaymentProcessingSyncFxRequestAfterItemChange(
    mysqli $conn,
    int $requestId,
    string $eventType
): void {
    procurementFxFinalSyncFundRequestToProcurement($conn, $requestId, 0, 'system', $eventType);
    procurementFxAdvanceSyncFundRequestToProcurement($conn, $requestId, 0, 'system', $eventType);
}

function accountPaymentProcessingFxDueInstructions(mysqli $conn, string $now): array
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT artifact.artifact_id AS instruction_id
         FROM account_payment_artifacts artifact
         INNER JOIN account_payment_batches batch
           ON batch.id = artifact.batch_id
          AND batch.request_type = artifact.request_type
         WHERE artifact.artifact_type = 'fx_instruction'
           AND artifact.artifact_status = 'Active'
           AND artifact.request_type IN ('fx_final_purchase','fx_advance_purchase')
           AND batch.status = 'Processing'
           AND batch.completion_mode IN ('Notify','Automatic')
           AND batch.expected_completion_at IS NOT NULL
           AND batch.expected_completion_at <= ?
         ORDER BY artifact.artifact_id ASC"
    );
    $stmt->bind_param('s', $now);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_values(array_unique(array_filter(
        array_map(static fn(array $row): int => (int) ($row['instruction_id'] ?? 0), $rows),
        static fn(int $id): bool => $id > 0
    )));
}

function accountPaymentProcessingLockFxInstructionContext(mysqli $conn, int $instructionId): array
{
    $instruction = $conn->prepare(
        'SELECT id, reference, payment_status
         FROM fx_instruction_letter_table
         WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $instruction->bind_param('i', $instructionId);
    $instruction->execute();
    $instructionRow = $instruction->get_result()->fetch_assoc() ?: null;
    $instruction->close();
    if ($instructionRow === null) {
        throw new RuntimeException("FX Instruction #{$instructionId} no longer exists.", 404);
    }

    $items = $conn->prepare(
        "SELECT
             i.id AS canonical_item_id,
             i.legacy_source_id AS batch_item_id,
             i.request_type,
             i.request_id,
             i.status AS item_status,
             i.amount,
             i.expected_completion_at AS item_expected_completion_at,
             b.id AS canonical_batch_id,
             b.legacy_source_id AS batch_id,
             b.batch_reference,
             b.processing_method,
             b.processing_reference,
             b.completion_mode,
             b.status AS batch_status,
             b.expected_completion_at AS batch_expected_completion_at,
             fx.request_type AS fx_request_type,
             fx.payment_status,
             fx.po_number,
             fx.suppliers_name,
             fx.fx_instruction_letter_id
         FROM account_payment_artifacts artifact
         INNER JOIN account_payment_batches b
           ON b.id = artifact.batch_id
          AND b.request_type = artifact.request_type
         INNER JOIN account_payment_batch_items i
           ON i.batch_id = b.id
          AND i.request_type = b.request_type
         INNER JOIN fx_fund_request_table fx
           ON fx.id = i.request_id
          AND fx.fx_instruction_letter_id = ?
          AND (
                (i.request_type = 'fx_final_purchase' AND fx.request_type = 'Final')
             OR (i.request_type = 'fx_advance_purchase' AND fx.request_type = 'Advance')
          )
         WHERE artifact.artifact_type = 'fx_instruction'
           AND artifact.artifact_id = ?
           AND artifact.artifact_status = 'Active'
           AND artifact.request_type IN ('fx_final_purchase','fx_advance_purchase')
         ORDER BY i.id ASC
         FOR UPDATE"
    );
    $items->bind_param('ii', $instructionId, $instructionId);
    $items->execute();
    $rows = $items->get_result()->fetch_all(MYSQLI_ASSOC);
    $items->close();
    if ($rows === []) {
        throw new RuntimeException("FX Instruction #{$instructionId} has no canonical processing items.", 409);
    }

    return ['instruction' => $instructionRow, 'items' => $rows];
}

function accountPaymentProcessingProcessDueFxBatches(mysqli $conn): array
{
    accountPaymentReminderEnsureStorage($conn);
    $systemActor = ['id' => 0, 'email' => 'system'];
    $now = date('Y-m-d H:i:s');
    $instructionIds = accountPaymentProcessingFxDueInstructions($conn, $now);
    $result = [
        'review_instructions' => [],
        'automatic_instructions' => [],
        'review_batches' => [],
        'automatic_batches' => [],
        'reconciled_paid_instructions' => [],
        'skipped' => [],
    ];

    foreach ($instructionIds as $instructionId) {
        $conn->begin_transaction();
        try {
            $context = accountPaymentProcessingLockFxInstructionContext($conn, $instructionId);
            $instruction = $context['instruction'];
            $items = $context['items'];

            $activeItems = array_values(array_filter(
                $items,
                static fn(array $item): bool => in_array(
                    (string) ($item['item_status'] ?? ''),
                    ['Processing', 'Delayed', 'Awaiting Confirmation'],
                    true
                )
            ));
            if ($activeItems === []) {
                foreach (array_values(array_unique(array_map(
                    static fn(array $item): int => (int) $item['canonical_batch_id'],
                    $items
                ))) as $batchId) {
                    accountPaymentProcessingUpdateCanonicalBatchStatus($conn, $batchId, 0);
                }
                $conn->commit();
                continue;
            }

            $modes = array_values(array_unique(array_map(
                static fn(array $item): string => trim((string) ($item['completion_mode'] ?? '')),
                $activeItems
            )));
            if (count($modes) !== 1 || !in_array($modes[0], ['Notify', 'Automatic'], true)) {
                throw new RuntimeException(
                    "FX Instruction #{$instructionId} has inconsistent completion modes across its shared processing batches.",
                    409
                );
            }
            $completionMode = $modes[0];

            $requestIds = array_values(array_unique(array_map(
                static fn(array $item): int => (int) $item['request_id'],
                $activeItems
            )));
            $batchIds = array_values(array_unique(array_map(
                static fn(array $item): int => (int) $item['canonical_batch_id'],
                $activeItems
            )));

            $instructionStatus = trim((string) ($instruction['payment_status'] ?? ''));
            if (strcasecmp($instructionStatus, 'Paid') === 0) {
                // Reconcile an externally-paid instruction into the canonical
                // item/batch state and ProcureDesk without generating reminders.
                accountPaymentProcessingApplyFxPaid(
                    $conn,
                    $requestIds,
                    trim((string) ($instruction['reference'] ?? '')),
                    $systemActor,
                    false
                );
                $result['reconciled_paid_instructions'][] = $instructionId;
                $conn->commit();
                continue;
            }
            if (strcasecmp($instructionStatus, 'Pending') !== 0) {
                throw new RuntimeException(
                    "FX Instruction #{$instructionId} is {$instructionStatus}; due processing only acts while the instruction is Pending.",
                    409
                );
            }

            $notProcessing = array_values(array_filter(
                $activeItems,
                static fn(array $item): bool => strcasecmp((string) ($item['payment_status'] ?? ''), 'Processing') !== 0
            ));
            if ($notProcessing !== []) {
                throw new RuntimeException(
                    "FX Instruction #{$instructionId} contains a Fund Request that is no longer Processing.",
                    409
                );
            }

            if ($completionMode === 'Automatic') {
                accountPaymentProcessingApplyFxPaid(
                    $conn,
                    $requestIds,
                    trim((string) ($instruction['reference'] ?? '')),
                    $systemActor,
                    false
                );

                accountNotificationPublishDetailed($conn, [
                    'type' => 'fx_payment_batch_auto_completed',
                    'action_key' => 'fx_payment_batch_auto_completed',
                    'category' => 'fx_payment_processing',
                    'severity' => 'success',
                    'title' => 'FX payment automatically completed',
                    'message' => sprintf(
                        'FX Instruction #%d reached its expected completion time and %d linked request(s) were marked Paid automatically.',
                        $instructionId,
                        count($requestIds)
                    ),
                    'actor' => $systemActor,
                    'entity_type' => 'fx_instruction',
                    'entity_id' => (string) $instructionId,
                    'route' => '/payments/payment-processing?scope=FX&status=Paid',
                    'roles' => ['Admin', 'Super_Admin'],
                    'department' => 'account',
                    'payload' => [
                        'instruction_id' => $instructionId,
                        'request_ids' => $requestIds,
                        'batch_ids' => $batchIds,
                        'completion_mode' => 'Automatic',
                        'route' => '/payments/payment-processing?scope=FX&status=Paid',
                    ],
                    'dedupe_key' => 'fx-payment-auto-completed-' . $instructionId,
                ]);

                $result['automatic_instructions'][] = $instructionId;
                $result['automatic_batches'] = array_values(array_unique(array_merge(
                    $result['automatic_batches'],
                    $batchIds
                )));
                $conn->commit();
                continue;
            }

            $reminderIds = [];
            $dueRequestIds = [];
            foreach ($activeItems as $item) {
                $itemDueAt = trim((string) (
                    $item['item_expected_completion_at']
                    ?? $item['batch_expected_completion_at']
                    ?? ''
                ));
                if ($itemDueAt !== '' && $itemDueAt > $now) {
                    continue;
                }

                $canonicalItemId = (int) $item['canonical_item_id'];
                $requestId = (int) $item['request_id'];
                $canonicalType = (string) $item['request_type'];
                $update = $conn->prepare(
                    "UPDATE account_payment_batch_items
                     SET status = 'Awaiting Confirmation',
                         status_reason = 'Expected FX completion time reached; Account confirmation is required.',
                         updated_by = 0
                     WHERE id = ?
                       AND request_id = ?
                       AND BINARY request_type = BINARY ?
                       AND status IN ('Processing','Delayed')"
                );
                $update->bind_param('iis', $canonicalItemId, $requestId, $canonicalType);
                $update->execute();
                $update->close();

                accountPaymentProcessingSyncFxRequestAfterItemChange(
                    $conn,
                    $requestId,
                    'account_payment_confirmation_due'
                );

                $reminderType = accountPaymentProcessingFxReminderType($canonicalType);
                $reminderIds[] = accountPaymentReminderSchedule(
                    $conn,
                    $reminderType,
                    $requestId,
                    (int) $item['batch_id'],
                    (int) $item['batch_item_id'],
                    $itemDueAt !== '' ? $itemDueAt : $now,
                    [
                        'batch_reference' => $item['batch_reference'] ?? null,
                        'canonical_batch_id' => (int) $item['canonical_batch_id'],
                        'processing_method' => $item['processing_method'] ?? null,
                        'processing_reference' => $item['processing_reference'] ?? null,
                        'fx_instruction_letter_id' => $instructionId,
                        'source' => 'fx_payment_batch_due',
                    ],
                    $systemActor
                );
                accountPaymentReminderRecordSourceEvent(
                    $conn,
                    $reminderType,
                    $requestId,
                    (int) $item['batch_id'],
                    'payment_confirmation_due',
                    $systemActor,
                    [
                        'fx_instruction_letter_id' => $instructionId,
                        'expected_completion_at' => $itemDueAt !== '' ? $itemDueAt : null,
                        'canonical_batch_id' => (int) $item['canonical_batch_id'],
                    ]
                );
                $dueRequestIds[] = $requestId;
            }

            foreach ($batchIds as $batchId) {
                accountPaymentProcessingUpdateCanonicalBatchStatus($conn, $batchId, 0);
            }

            if ($dueRequestIds !== []) {
                $primaryBatchId = $batchIds[0] ?? 0;
                $route = '/payments/payment-processing?scope=FX&status=Awaiting%20Confirmation';
                if ($primaryBatchId > 0) {
                    $route .= '&batch_id=' . $primaryBatchId;
                }
                $delivery = accountNotificationPublishDetailed($conn, [
                    'type' => 'fx_payment_batch_confirmation_due',
                    'action_key' => 'fx_payment_batch_confirmation_due',
                    'category' => 'fx_payment_processing',
                    'severity' => 'warning',
                    'title' => 'FX payments ready for confirmation',
                    'message' => sprintf(
                        'FX Instruction #%d reached its expected completion time. Confirm the %d eligible request(s) that should be marked Paid.',
                        $instructionId,
                        count(array_unique($dueRequestIds))
                    ),
                    'actor' => $systemActor,
                    'entity_type' => 'fx_instruction',
                    'entity_id' => (string) $instructionId,
                    'route' => $route,
                    'roles' => ['Admin', 'Super_Admin'],
                    'department' => 'account',
                    'payload' => [
                        'instruction_id' => $instructionId,
                        'request_ids' => array_values(array_unique($dueRequestIds)),
                        'reminder_ids' => array_values(array_unique($reminderIds)),
                        'batch_ids' => $batchIds,
                        'completion_mode' => 'Notify',
                        'route' => $route,
                    ],
                    'dedupe_key' => 'fx-payment-confirmation-due-' . $instructionId,
                ]);
                accountPaymentReminderInitialDelivery(
                    $conn,
                    $reminderIds,
                    (int) ($delivery['resolved'] ?? 0),
                    $systemActor
                );

                $result['review_instructions'][] = $instructionId;
                $result['review_batches'] = array_values(array_unique(array_merge(
                    $result['review_batches'],
                    $batchIds
                )));
            }

            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            // Automatic failures remain Processing and due, so the next
            // scheduler cycle retries safely. Notify delivery failures are
            // handled by account_payment_reminders with exponential backoff.
            $result['skipped'][] = [
                'instruction_id' => $instructionId,
                'reason' => $error->getMessage(),
                'retryable' => true,
            ];
        }
    }

    return $result;
}

function accountPaymentProcessingAction(mysqli $conn, array $data, array $actor): array
{
    $action = strtolower(trim((string) ($data['action'] ?? '')));
    if (!in_array($action, ['paid', 'delayed', 'failed', 'cancelled'], true)) {
        throw new RuntimeException('Payment-processing action is invalid.', 400);
    }

    $canonicalItemIds = array_values(array_unique(array_filter(
        array_map('intval', is_array($data['item_ids'] ?? null) ? $data['item_ids'] : []),
        static fn(int $id): bool => $id > 0
    )));
    $batchId = (int) ($data['batch_id'] ?? 0);
    if ($canonicalItemIds === [] && $batchId <= 0) {
        throw new RuntimeException('Select at least one processing transaction or a batch.', 400);
    }

    $where = [];
    $params = [];
    $types = '';
    if ($batchId > 0) {
        $where[] = 'i.batch_id = ?';
        $params[] = $batchId;
        $types .= 'i';
    }
    if ($canonicalItemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($canonicalItemIds), '?'));
        $where[] = "i.id IN ({$placeholders})";
        $params = array_merge($params, $canonicalItemIds);
        $types .= str_repeat('i', count($canonicalItemIds));
    }
    $whereSql = implode(' AND ', $where);
    $stmt = $conn->prepare(
        "SELECT i.id, i.legacy_source_id, i.request_type, i.request_id, i.status, i.batch_id
         FROM account_payment_batch_items i
         WHERE {$whereSql}
         ORDER BY i.request_type, i.id"
    );
    accountPaymentProcessingBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows === []) {
        throw new RuntimeException('No matching processing transactions were found.', 404);
    }

    $groups = [];
    foreach ($rows as $row) {
        $groups[(string) $row['request_type']][] = $row;
    }

    $result = ['successful' => [], 'failed' => [], 'groups' => []];
    foreach ($groups as $requestType => $groupRows) {
        if ($requestType === ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL) {
            $legacyIds = array_map(static fn(array $row): int => (int) $row['legacy_source_id'], $groupRows);
            $groupResult = accountSupplierMarkItems($conn, $legacyIds, $action, $data, $actor);
            $result['groups'][$requestType] = $groupResult;
            $result['successful'] = array_merge($result['successful'], $groupResult['successful'] ?? []);
            $result['failed'] = array_merge($result['failed'], $groupResult['failed'] ?? []);
            continue;
        }
        if ($requestType === ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE) {
            $legacyIds = array_map(static fn(array $row): int => (int) $row['legacy_source_id'], $groupRows);
            $groupResult = accountAdvanceMarkItems($conn, $legacyIds, $action, $data, $actor);
            $result['groups'][$requestType] = $groupResult;
            $result['successful'] = array_merge($result['successful'], $groupResult['successful'] ?? []);
            $result['failed'] = array_merge($result['failed'], $groupResult['failed'] ?? []);
            continue;
        }
        if ($requestType === ACCOUNT_PAYMENT_TYPE_COMPASS) {
            $legacyIds = array_map(static fn(array $row): int => (int) $row['legacy_source_id'], $groupRows);
            $groupResult = accountCompassMarkPaymentItems($conn, $legacyIds, $action, $data, $actor);
            $result['groups'][$requestType] = $groupResult;
            $result['successful'] = array_merge($result['successful'], $groupResult['successful'] ?? []);
            $result['failed'] = array_merge($result['failed'], $groupResult['failed'] ?? []);
            continue;
        }
        if (in_array($requestType, [ACCOUNT_PAYMENT_TYPE_FX_FINAL, ACCOUNT_PAYMENT_TYPE_FX_ADVANCE], true)) {
            if ($action !== 'paid') {
                $result['failed'][] = [
                    'request_type' => $requestType,
                    'reason' => 'FX processing currently supports batch Paid confirmation only. Delay/failure parity is handled in the next FX completion-mode batch.',
                ];
                continue;
            }
            $requestIds = array_map(static fn(array $row): int => (int) $row['request_id'], $groupRows);
            $groupResult = accountPaymentProcessingApplyFxPaid(
                $conn,
                $requestIds,
                trim((string) ($data['payment_reference'] ?? '')),
                $actor
            );
            $result['groups'][$requestType] = $groupResult;
            $result['successful'] = array_merge($result['successful'], $groupResult['request_ids'] ?? []);
            continue;
        }
        $result['failed'][] = ['request_type' => $requestType, 'reason' => 'Unsupported processing request type.'];
    }

    return $result;
}
