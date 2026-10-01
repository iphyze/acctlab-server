<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageRuntimeReadService.php';

const ACCOUNT_SUPPLIER_STATEMENT_STATUS_ALL = 'all';

function accountSupplierStatementBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $index => $value) {
        $params[$index] = $value;
        $refs[$index] = &$params[$index];
    }
    $stmt->bind_param($types, ...$refs);
}

function accountSupplierStatementSourceCoverage(): array
{
    return [
        'obligations' => [
            'supplier_fund_request_table' => 'Local Final Fund Requests',
            'advance_payment_request' => 'Local Advance Fund Requests',
            'fx_fund_request_table' => 'FX Final and FX Advance Fund Requests',
        ],
        'settlements' => [
            'account_payment_batches' => 'Canonical grouped payment operations',
            'account_payment_batch_items' => 'Canonical request allocations and paid amounts',
            'account_payment_artifacts' => 'Linked Bank Instruction, GAPS, Union Bank Schedule and FX instruction artifacts',
            'procurement_supplier_financial_adjustments' => 'Supplier recovery claims created by paid purchase revisions and cancellations',
            'procurement_supplier_financial_adjustment_credit_notes' => 'Supplier credit-note evidence and remaining reusable credit',
            'procurement_supplier_financial_adjustment_allocations' => 'Refund, recovery and supplier-credit offset audit trail',
        ],
        'reference_context' => [
            'suppliers_table' => 'Supplier master/ledger identity',
            'procurement_requests' => 'Canonical ProcureDesk request/source identifiers; not re-posted as duplicate obligations',
            'procurement_local_advance_pos' => 'Shared Local/FX Advance PO context; not re-posted as a second obligation',
            'procurement_local_advance_po_revisions' => 'PO revision/audit context for later drill-down',
        ],
        'legacy_payment_evidence' => [
            'payment_schedule_tab',
            'advance_payment_schedule_tab',
            'union_payment_schedule',
            'other_payment_schedule',
            'fx_instruction_letter_table',
        ],
        'unlinked_sources' => [
            'manual_fx_payments' => 'Manual FX instructions use the shared canonical processing tables but do not carry a reliable supplier_id. They are not name-matched into a supplier sub-ledger.',
        ],
        'policy' => 'Legacy schedules are exposed through canonical payment-artifact links when available. Unlinked historical schedules and Manual FX beneficiary names are not guessed/matched into the ledger because that could duplicate or misallocate supplier settlements.',
    ];
}

function accountSupplierStatementTypeMeta(string $requestType): array
{
    return match ($requestType) {
        'local_final_purchase' => [
            'label' => 'Local Final Fund Request',
            'scope' => 'Local',
            'route' => '/payments/fund-request/supplier',
        ],
        'local_advance_purchase' => [
            'label' => 'Local Advance Fund Request',
            'scope' => 'Local',
            'route' => '/payments/fund-request/advance',
        ],
        'fx_final_purchase' => [
            'label' => 'FX Final Fund Request',
            'scope' => 'FX',
            'route' => '/payments/fund-request/fx',
        ],
        'fx_advance_purchase' => [
            'label' => 'FX Advance Fund Request',
            'scope' => 'FX',
            'route' => '/payments/fund-request/fx',
        ],
        default => [
            'label' => 'Fund Request',
            'scope' => 'Unknown',
            'route' => '/payments/fund-request',
        ],
    };
}

function accountSupplierStatementDateExpression(string $alias, array $columns): string
{
    $parts = [];
    foreach ($columns as $column) {
        $parts[] = "STR_TO_DATE(NULLIF(TRIM({$alias}.{$column}), ''), '%Y-%m-%d')";
    }
    $parts[] = "{$alias}.created_at";
    return 'COALESCE(' . implode(', ', $parts) . ')';
}

/**
 * Canonical read-only supplier obligation projection.
 *
 * Monetary values stay in the original obligation currency. FX settlement
 * currency is carried separately so a payment made in another currency never
 * gets subtracted directly from the supplier balance in the request currency.
 */
function accountSupplierStatementRequestProjection(): string
{
    $localFinalDate = accountSupplierStatementDateExpression('sfr', ['date_received', 'purchase_date', 'invoice_date']);
    $localAdvanceDate = accountSupplierStatementDateExpression('apr', ['date_received']);

    return "(
        SELECT
            CONVERT('local_final_purchase' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            sfr.id AS request_id,
            sfr.supplier_id,
            CONVERT(sfr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS supplier_name,
            CONVERT(COALESCE(CAST(st.supplier_number AS CHAR), CAST(sfr.supplier_id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS supplier_ledger,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS settlement_currency,
            CAST(COALESCE(NULLIF(sfr.amount, ''), '0') AS DECIMAL(18,2)) AS obligation_amount,
            CAST(COALESCE(sfr.amount_paid, 0) AS DECIMAL(18,2)) AS request_amount_paid,
            {$localFinalDate} AS transaction_at,
            COALESCE(sfr.paid_at, sfr.payment_updated_at, sfr.created_at) AS fallback_payment_at,
            CONVERT(COALESCE(NULLIF(sfr.purchase_number, ''), NULLIF(sfr.invoice_number, ''), NULLIF(sfr.po_number, ''), CONCAT('LFR-', sfr.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT(COALESCE(sfr.purchase_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS purchase_number,
            CONVERT(COALESCE(sfr.po_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT(COALESCE(sfr.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(COALESCE(sfr.description, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
            CONVERT(COALESCE(sfr.project_code, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT(COALESCE(sfr.payment_status, 'Pending') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(sfr.payment_reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_payment_reference,
            CONVERT(COALESCE(sfr.processing_reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_processing_reference,
            sfr.payment_batch_id AS request_legacy_batch_id,
            CONVERT(COALESCE(sfr.procurement_source, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            sfr.procurement_purchase_id,
            NULL AS fx_instruction_letter_id,
            CAST(COALESCE(NULLIF(sfr.amount, ''), '0') AS DECIMAL(18,2)) AS settlement_amount_hint,
            CAST(1 AS DECIMAL(24,10)) AS exchange_rate_hint,
            sfr.created_at
        FROM supplier_fund_request_table sfr
        LEFT JOIN suppliers_table st ON st.id = sfr.supplier_id

        UNION ALL

        SELECT
            CONVERT('local_advance_purchase' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            apr.id AS request_id,
            apr.supplier_id,
            CONVERT(apr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS supplier_name,
            CONVERT(COALESCE(CAST(st.supplier_number AS CHAR), CAST(apr.supplier_id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS supplier_ledger,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS settlement_currency,
            CAST(COALESCE(NULLIF(apr.advance_payment, ''), '0') AS DECIMAL(18,2)) AS obligation_amount,
            CAST(COALESCE(apr.amount_paid, 0) AS DECIMAL(18,2)) AS request_amount_paid,
            {$localAdvanceDate} AS transaction_at,
            COALESCE(apr.paid_at, apr.payment_updated_at, apr.created_at) AS fallback_payment_at,
            CONVERT(COALESCE(NULLIF(apr.po_number, ''), CONCAT('LAR-', apr.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS purchase_number,
            CONVERT(COALESCE(apr.po_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(COALESCE(apr.note, 'Advance payment') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
            CONVERT(COALESCE(apr.site, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT(COALESCE(apr.payment_status, 'Pending') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(apr.payment_reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_payment_reference,
            CONVERT(COALESCE(apr.processing_reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_processing_reference,
            apr.payment_batch_id AS request_legacy_batch_id,
            CONVERT(COALESCE(apr.procurement_source, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            apr.procurement_purchase_id,
            NULL AS fx_instruction_letter_id,
            CAST(COALESCE(NULLIF(apr.advance_payment, ''), '0') AS DECIMAL(18,2)) AS settlement_amount_hint,
            CAST(1 AS DECIMAL(24,10)) AS exchange_rate_hint,
            apr.created_at
        FROM advance_payment_request apr
        LEFT JOIN suppliers_table st ON st.id = apr.supplier_id

        UNION ALL

        SELECT
            CONVERT(CASE WHEN fx.request_type = 'Final' THEN 'fx_final_purchase' ELSE 'fx_advance_purchase' END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_type,
            fx.id AS request_id,
            fx.suppliers_id AS supplier_id,
            CONVERT(fx.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS supplier_name,
            CONVERT(COALESCE(CAST(st.supplier_number AS CHAR), CAST(fx.suppliers_id AS CHAR)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS supplier_ledger,
            CONVERT(UPPER(COALESCE(NULLIF(TRIM(fx.currency), ''), 'FX')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency,
            CONVERT(UPPER(COALESCE(NULLIF(TRIM(fx.payment_currency), ''), NULLIF(TRIM(fx.currency), ''), 'FX')) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS settlement_currency,
            CAST(COALESCE(fx.payable_amount, 0) AS DECIMAL(18,2)) AS obligation_amount,
            CAST(CASE WHEN LOWER(TRIM(fx.payment_status)) = 'paid' THEN COALESCE(fx.payable_amount, 0) ELSE 0 END AS DECIMAL(18,2)) AS request_amount_paid,
            COALESCE(CAST(fx.date_received AS DATETIME), CAST(fx.purchase_date AS DATETIME), CAST(fx.invoice_date AS DATETIME), fx.created_at) AS transaction_at,
            COALESCE(fx.processed_at, fx.updated_at, fx.created_at) AS fallback_payment_at,
            CONVERT(COALESCE(NULLIF(fx.purchase_number, ''), NULLIF(fx.invoice_number, ''), NULLIF(fx.po_number, ''), CONCAT('FX-', fx.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS primary_reference,
            CONVERT(COALESCE(fx.purchase_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS purchase_number,
            CONVERT(COALESCE(fx.po_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS po_number,
            CONVERT(COALESCE(fx.invoice_number, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS invoice_number,
            CONVERT(COALESCE(fil.payment_purpose, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS description,
            CONVERT(COALESCE(fx.project_code, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS project_code,
            CONVERT(COALESCE(fx.payment_status, 'Pending') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status,
            CONVERT(COALESCE(fil.reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_payment_reference,
            CONVERT(COALESCE(fil.reference, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS request_processing_reference,
            NULL AS request_legacy_batch_id,
            CONVERT('AcctLab FX' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS procurement_source,
            NULL AS procurement_purchase_id,
            fx.fx_instruction_letter_id,
            CAST(COALESCE(fx.payment_amount, fx.payable_amount, 0) AS DECIMAL(18,2)) AS settlement_amount_hint,
            CAST(COALESCE(fx.exchange_rate, CASE WHEN COALESCE(fx.payable_amount, 0) > 0 THEN COALESCE(fx.payment_amount, fx.payable_amount, 0) / fx.payable_amount ELSE 1 END) AS DECIMAL(24,10)) AS exchange_rate_hint,
            fx.created_at
        FROM fx_fund_request_table fx
        LEFT JOIN suppliers_table st ON st.id = fx.suppliers_id
        LEFT JOIN fx_instruction_letter_table fil ON fil.id = fx.fx_instruction_letter_id
    )";
}

function accountSupplierStatementNormalizeDate(mixed $value, string $label): string
{
    $date = trim((string) $value);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($date === '' || !$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException("{$label} must use YYYY-MM-DD format.", 400);
    }
    return $date;
}

function accountSupplierStatementNormalizeCurrency(mixed $value): string
{
    $currency = strtoupper(trim((string) $value));
    if ($currency === '' || $currency === 'ALL') {
        return '';
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Currency filter must use a three-letter currency code.', 400);
    }
    return $currency;
}

function accountSupplierStatementNormalizeStatus(mixed $value): string
{
    $status = strtolower(trim((string) $value));
    if ($status === '' || $status === 'all') {
        return ACCOUNT_SUPPLIER_STATEMENT_STATUS_ALL;
    }

    $aliases = [
        'pending' => 'pending',
        'processing' => 'processing',
        'unconfirmed' => 'unconfirmed',
        'paid' => 'paid',
        'partially settled' => 'partially_settled',
        'partially_settled' => 'partially_settled',
        'partial' => 'partially_settled',
        'unpaid' => 'unpaid',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
    ];

    if (!isset($aliases[$status])) {
        throw new RuntimeException('Invalid Supplier Statement status filter.', 400);
    }
    return $aliases[$status];
}

function accountSupplierStatementRequestKey(array $row): string
{
    return (string) ($row['request_type'] ?? '') . ':' . (int) ($row['request_id'] ?? 0);
}

function accountSupplierStatementMoney(mixed $value): float
{
    return round((float) ($value ?? 0), 2);
}

function accountSupplierStatementDateOnly(mixed $value): string
{
    $text = trim((string) $value);
    return $text === '' ? '' : substr($text, 0, 10);
}

function accountSupplierStatementFetchSupplier(mysqli $conn, int $supplierId): array
{
    $stmt = $conn->prepare(
        'SELECT id, supplier_name, supplier_number, wht_status FROM suppliers_table WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $supplier = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$supplier) {
        throw new RuntimeException('Supplier was not found.', 404);
    }

    return [
        'id' => (int) $supplier['id'],
        'name' => (string) $supplier['supplier_name'],
        'ledger' => (string) $supplier['supplier_number'],
        'wht_status' => (string) ($supplier['wht_status'] ?? ''),
    ];
}

function accountSupplierStatementFetchRequests(
    mysqli $conn,
    int $supplierId,
    string $toDate,
    string $currency
): array {
    $projection = accountSupplierStatementRequestProjection();
    $where = ['r.supplier_id = ?', 'DATE(r.transaction_at) <= ?'];
    $params = [$supplierId, $toDate];
    $types = 'is';

    if ($currency !== '') {
        $where[] = 'r.currency = ?';
        $params[] = $currency;
        $types .= 's';
    }

    $sql = "SELECT r.* FROM {$projection} r WHERE " . implode(' AND ', $where) . ' ORDER BY r.transaction_at ASC, r.request_type ASC, r.request_id ASC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Supplier Statement obligation projection: ' . $conn->error, 500);
    }
    accountSupplierStatementBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['request_id'] = (int) $row['request_id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['obligation_amount'] = accountSupplierStatementMoney($row['obligation_amount'] ?? 0);
        $row['request_amount_paid'] = accountSupplierStatementMoney($row['request_amount_paid'] ?? 0);
        $row['settlement_amount_hint'] = accountSupplierStatementMoney($row['settlement_amount_hint'] ?? 0);
        $row['exchange_rate_hint'] = (float) ($row['exchange_rate_hint'] ?? 1);
        $row['request_legacy_batch_id'] = (int) ($row['request_legacy_batch_id'] ?? 0);
        $row['fx_instruction_letter_id'] = (int) ($row['fx_instruction_letter_id'] ?? 0);
        $row['procurement_purchase_id'] = (int) ($row['procurement_purchase_id'] ?? 0);
    }
    unset($row);
    return $rows;
}

function accountSupplierStatementFetchCanonicalPayments(
    mysqli $conn,
    int $supplierId,
    string $toDate,
    string $currency
): array {
    $projection = accountSupplierStatementRequestProjection();
    $where = [
        'r.supplier_id = ?',
        'DATE(r.transaction_at) <= ?',
        '(i.amount_paid > 0 OR COALESCE(i.supplier_credit_applied, 0) > 0)',
        'i.paid_at IS NOT NULL',
        "i.status NOT IN ('Cancelled','Failed')",
        'DATE(i.paid_at) <= ?',
        'i.request_type = b.request_type',
        'r.request_type = i.request_type',
        'r.request_id = i.request_id',
    ];
    $params = [$supplierId, $toDate, $toDate];
    $types = 'iss';

    if ($currency !== '') {
        $where[] = 'r.currency = ?';
        $params[] = $currency;
        $types .= 's';
    }

    $sql = "SELECT
                i.id AS canonical_item_id,
                i.batch_id AS canonical_batch_id,
                i.legacy_batch_id,
                i.request_type,
                i.request_id,
                i.amount AS settlement_amount,
                i.amount_paid AS settlement_amount_paid,
                COALESCE(i.gross_amount, i.amount) AS gross_amount,
                COALESCE(i.supplier_credit_applied, 0) AS supplier_credit_applied,
                i.status AS item_status,
                i.status_reason,
                i.paid_at,
                i.payment_reference,
                b.batch_reference,
                b.processing_method,
                b.processing_reference,
                b.completion_mode,
                b.status AS batch_status,
                b.created_at AS batch_created_at,
                b.completed_at AS batch_completed_at,
                r.currency,
                r.settlement_currency,
                r.obligation_amount,
                r.primary_reference,
                r.purchase_number,
                r.po_number,
                r.invoice_number,
                r.description,
                r.project_code,
                r.supplier_name,
                r.supplier_ledger,
                r.fx_instruction_letter_id,
                r.exchange_rate_hint
            FROM account_payment_batch_items i
            INNER JOIN account_payment_batches b ON b.id = i.batch_id
            INNER JOIN {$projection} r ON r.request_type = i.request_type AND r.request_id = i.request_id
            WHERE " . implode(' AND ', $where) . '
            ORDER BY i.paid_at ASC, i.id ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Supplier Statement settlement projection: ' . $conn->error, 500);
    }
    accountSupplierStatementBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['canonical_item_id'] = (int) $row['canonical_item_id'];
        $row['canonical_batch_id'] = (int) $row['canonical_batch_id'];
        $row['legacy_batch_id'] = (int) ($row['legacy_batch_id'] ?? 0);
        $row['request_id'] = (int) $row['request_id'];
        $row['settlement_amount'] = accountSupplierStatementMoney($row['settlement_amount'] ?? 0);
        $row['settlement_amount_paid'] = accountSupplierStatementMoney($row['settlement_amount_paid'] ?? 0);
        $row['gross_amount'] = accountSupplierStatementMoney($row['gross_amount'] ?? $row['settlement_amount'] ?? 0);
        $row['supplier_credit_applied'] = accountSupplierStatementMoney($row['supplier_credit_applied'] ?? 0);
        $row['obligation_amount'] = accountSupplierStatementMoney($row['obligation_amount'] ?? 0);
        $row['fx_instruction_letter_id'] = (int) ($row['fx_instruction_letter_id'] ?? 0);
        $row['exchange_rate_hint'] = (float) ($row['exchange_rate_hint'] ?? 1);
    }
    unset($row);
    return $rows;
}

function accountSupplierStatementFetchArtifacts(mysqli $conn, array $batchIds): array
{
    $batchIds = array_values(array_unique(array_filter(array_map('intval', $batchIds), static fn(int $id): bool => $id > 0)));
    if ($batchIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
    $types = str_repeat('i', count($batchIds));
    $stmt = $conn->prepare(
        "SELECT id, batch_id, legacy_batch_id, request_type, artifact_type, artifact_id,
                artifact_reference, artifact_route, request_ids_json, artifact_status, created_at
         FROM account_payment_artifacts
         WHERE batch_id IN ({$placeholders}) AND artifact_status = 'Active'
         ORDER BY batch_id ASC, id ASC"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Supplier Statement payment artifact query: ' . $conn->error, 500);
    }
    accountSupplierStatementBind($stmt, $types, $batchIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byBatch = [];
    foreach ($rows as $row) {
        $requestIds = json_decode((string) ($row['request_ids_json'] ?? ''), true);
        $byBatch[(int) $row['batch_id']][] = [
            'id' => (int) $row['id'],
            'type' => (string) $row['artifact_type'],
            'artifact_id' => (int) $row['artifact_id'],
            'reference' => (string) ($row['artifact_reference'] ?? ''),
            'route' => (string) ($row['artifact_route'] ?? ''),
            'request_ids' => is_array($requestIds) ? array_values(array_map('intval', $requestIds)) : [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $byBatch;
}

function accountSupplierStatementApplyCanonicalCredits(array $requestsByKey, array $paymentRows): array
{
    $creditsByRequest = [];
    $records = [];

    foreach ($paymentRows as $row) {
        $key = (string) $row['request_type'] . ':' . (int) $row['request_id'];
        $request = $requestsByKey[$key] ?? null;
        if (!$request) {
            continue;
        }

        $obligation = accountSupplierStatementMoney($request['obligation_amount'] ?? 0);
        $settlementAmount = accountSupplierStatementMoney($row['settlement_amount'] ?? 0);
        $settlementPaid = accountSupplierStatementMoney($row['settlement_amount_paid'] ?? 0);
        $supplierCreditApplied = accountSupplierStatementMoney($row['supplier_credit_applied'] ?? 0);
        $grossAmount = accountSupplierStatementMoney($row['gross_amount'] ?? 0);
        if ($obligation <= 0 || ($settlementPaid <= 0 && $supplierCreditApplied <= 0)) {
            continue;
        }

        $isLocalCreditOffset = in_array((string) $row['request_type'], ['local_final_purchase', 'local_advance_purchase'], true)
            && $supplierCreditApplied > 0;
        if ($isLocalCreditOffset) {
            $coverage = accountSupplierStatementMoney(min($obligation, $settlementPaid + $supplierCreditApplied));
            $statementCashCredit = accountSupplierStatementMoney(min($obligation, $settlementPaid));
        } else {
            $ratio = $settlementAmount > 0 ? min(1.0, max(0.0, $settlementPaid / $settlementAmount)) : 1.0;
            $coverage = accountSupplierStatementMoney($obligation * $ratio);
            $statementCashCredit = $coverage;
        }

        $credited = accountSupplierStatementMoney($creditsByRequest[$key] ?? 0);
        $coverage = accountSupplierStatementMoney(min(max(0.0, $obligation - $credited), $coverage));
        if ($coverage <= 0) {
            continue;
        }
        $creditsByRequest[$key] = accountSupplierStatementMoney($credited + $coverage);

        $records[] = [
            'kind' => 'canonical',
            'request_key' => $key,
            'request_type' => (string) $row['request_type'],
            'request_id' => (int) $row['request_id'],
            'currency' => (string) $row['currency'],
            'statement_credit' => $statementCashCredit,
            'settlement_coverage' => $coverage,
            'gross_amount' => $grossAmount > 0 ? $grossAmount : accountSupplierStatementMoney($statementCashCredit + $supplierCreditApplied),
            'supplier_credit_applied' => $supplierCreditApplied,
            'settlement_currency' => (string) $row['settlement_currency'],
            'settlement_amount' => $settlementPaid,
            'exchange_rate' => $statementCashCredit > 0 ? round($settlementPaid / $statementCashCredit, 10) : (float) ($row['exchange_rate_hint'] ?? 1),
            'paid_at' => (string) $row['paid_at'],
            'canonical_item_id' => (int) $row['canonical_item_id'],
            'canonical_batch_id' => (int) $row['canonical_batch_id'],
            'legacy_batch_id' => (int) $row['legacy_batch_id'],
            'batch_reference' => (string) ($row['batch_reference'] ?? ''),
            'processing_method' => (string) ($row['processing_method'] ?? ''),
            'processing_reference' => (string) ($row['processing_reference'] ?? ''),
            'payment_reference' => (string) ($row['payment_reference'] ?? ''),
            'completion_mode' => (string) ($row['completion_mode'] ?? ''),
            'item_status' => (string) ($row['item_status'] ?? ''),
            'primary_reference' => (string) ($row['primary_reference'] ?? ''),
            'purchase_number' => (string) ($row['purchase_number'] ?? ''),
            'po_number' => (string) ($row['po_number'] ?? ''),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'project_code' => (string) ($row['project_code'] ?? ''),
            'fx_instruction_letter_id' => (int) ($row['fx_instruction_letter_id'] ?? 0),
            'date_basis' => 'canonical_paid_at',
        ];
    }

    return [$creditsByRequest, $records];
}

function accountSupplierStatementAddLegacyFallbackCredits(
    array $requestsByKey,
    array $creditsByRequest,
    array $records,
    string $toDate
): array {
    foreach ($requestsByKey as $key => $request) {
        $sourceStatus = strtolower(trim((string) ($request['payment_status'] ?? '')));
        if ($sourceStatus !== 'paid') {
            continue;
        }

        $obligation = accountSupplierStatementMoney($request['obligation_amount'] ?? 0);
        $credited = accountSupplierStatementMoney($creditsByRequest[$key] ?? 0);
        if ($obligation <= 0 || $credited >= $obligation - 0.004) {
            continue;
        }

        $fallbackDate = (string) ($request['fallback_payment_at'] ?? '');
        if ($fallbackDate === '' || accountSupplierStatementDateOnly($fallbackDate) > $toDate) {
            continue;
        }

        $requestAmountPaid = accountSupplierStatementMoney($request['request_amount_paid'] ?? 0);
        if ($requestAmountPaid <= 0) {
            $requestAmountPaid = $obligation;
        }
        $credit = accountSupplierStatementMoney(min($obligation - $credited, $requestAmountPaid));
        if ($credit <= 0) {
            continue;
        }

        $creditsByRequest[$key] = accountSupplierStatementMoney($credited + $credit);
        $settlementAmount = accountSupplierStatementMoney($request['settlement_amount_hint'] ?? $credit);
        $records[] = [
            'kind' => 'legacy_fallback',
            'request_key' => $key,
            'request_type' => (string) $request['request_type'],
            'request_id' => (int) $request['request_id'],
            'currency' => (string) $request['currency'],
            'statement_credit' => $credit,
            'settlement_currency' => (string) ($request['settlement_currency'] ?? $request['currency']),
            'settlement_amount' => $settlementAmount > 0 ? $settlementAmount : $credit,
            'exchange_rate' => (float) ($request['exchange_rate_hint'] ?? 1),
            'paid_at' => $fallbackDate,
            'canonical_item_id' => 0,
            'canonical_batch_id' => 0,
            'legacy_batch_id' => (int) ($request['request_legacy_batch_id'] ?? 0),
            'batch_reference' => '',
            'processing_method' => '',
            'processing_reference' => (string) ($request['request_processing_reference'] ?? ''),
            'payment_reference' => (string) ($request['request_payment_reference'] ?? ''),
            'completion_mode' => '',
            'item_status' => 'Paid',
            'primary_reference' => (string) ($request['primary_reference'] ?? ''),
            'purchase_number' => (string) ($request['purchase_number'] ?? ''),
            'po_number' => (string) ($request['po_number'] ?? ''),
            'invoice_number' => (string) ($request['invoice_number'] ?? ''),
            'description' => (string) ($request['description'] ?? ''),
            'project_code' => (string) ($request['project_code'] ?? ''),
            'fx_instruction_letter_id' => (int) ($request['fx_instruction_letter_id'] ?? 0),
            'date_basis' => !empty($request['request_payment_reference']) || !empty($request['request_legacy_batch_id'])
                ? 'legacy_request_payment_metadata'
                : 'legacy_created_at_fallback',
        ];
    }

    usort($records, static function (array $left, array $right): int {
        $dateCompare = strcmp((string) ($left['paid_at'] ?? ''), (string) ($right['paid_at'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return ((int) ($left['canonical_item_id'] ?? 0)) <=> ((int) ($right['canonical_item_id'] ?? 0));
    });

    return [$creditsByRequest, $records];
}

function accountSupplierStatementAsOfStatus(array $request, float $credited): string
{
    $source = strtolower(trim((string) ($request['payment_status'] ?? '')));
    $obligation = accountSupplierStatementMoney($request['obligation_amount'] ?? 0);

    if ($source === 'cancelled' || $source === 'canceled') {
        return 'Cancelled';
    }
    if ($credited >= $obligation - 0.004 && $obligation > 0) {
        return 'Paid';
    }
    if ($credited > 0.004 && $credited < $obligation - 0.004) {
        return 'Partially Settled';
    }
    if ($source === 'processing') {
        return 'Processing';
    }
    if ($source === 'unconfirmed') {
        return 'Unconfirmed';
    }
    if ($source === 'failed') {
        return 'Failed';
    }
    return 'Pending';
}

function accountSupplierStatementStatusMatches(string $derivedStatus, string $filter): bool
{
    if ($filter === ACCOUNT_SUPPLIER_STATEMENT_STATUS_ALL) {
        return true;
    }

    $normalized = strtolower(str_replace(' ', '_', $derivedStatus));
    if ($filter === 'unpaid') {
        return in_array($normalized, ['pending', 'processing', 'unconfirmed', 'failed', 'partially_settled'], true);
    }
    return $normalized === $filter;
}

function accountSupplierStatementRequestSource(array $request): array
{
    $type = (string) $request['request_type'];
    $meta = accountSupplierStatementTypeMeta($type);
    $requestId = (int) $request['request_id'];
    return [
        'entity' => 'fund_request',
        'request_type' => $type,
        'request_id' => $requestId,
        'label' => $meta['label'],
        'scope' => $meta['scope'],
        'route' => $meta['route'] . '?request_id=' . $requestId,
        'procurement_purchase_id' => (int) ($request['procurement_purchase_id'] ?? 0),
        'procurement_source' => (string) ($request['procurement_source'] ?? ''),
    ];
}

function accountSupplierStatementBuildAllocation(array $record, array $request): array
{
    return [
        'request_type' => (string) $record['request_type'],
        'request_id' => (int) $record['request_id'],
        'reference' => (string) ($record['primary_reference'] ?? ''),
        'po_number' => (string) ($record['po_number'] ?? ''),
        'invoice_number' => (string) ($record['invoice_number'] ?? ''),
        'purchase_number' => (string) ($record['purchase_number'] ?? ''),
        'project_code' => (string) ($record['project_code'] ?? ''),
        'description' => (string) ($record['description'] ?? ''),
        'statement_currency' => (string) $record['currency'],
        'statement_credit_amount' => accountSupplierStatementMoney($record['statement_credit'] ?? 0),
        'gross_amount' => accountSupplierStatementMoney($record['gross_amount'] ?? $request['obligation_amount'] ?? 0),
        'supplier_credit_applied' => accountSupplierStatementMoney($record['supplier_credit_applied'] ?? 0),
        'cash_paid' => accountSupplierStatementMoney($record['settlement_amount'] ?? 0),
        'settlement_currency' => (string) ($record['settlement_currency'] ?? $record['currency']),
        'settlement_amount' => accountSupplierStatementMoney($record['settlement_amount'] ?? 0),
        'exchange_rate' => (float) ($record['exchange_rate'] ?? 1),
        'source' => accountSupplierStatementRequestSource($request),
    ];
}

function accountSupplierStatementGroupPayments(array $records, array $requestsByKey, array $artifactsByBatch): array
{
    $groups = [];
    foreach ($records as $record) {
        $request = $requestsByKey[(string) $record['request_key']] ?? null;
        if (!$request) {
            continue;
        }
        $currency = (string) $record['currency'];
        $batchId = (int) ($record['canonical_batch_id'] ?? 0);
        $groupKey = $batchId > 0
            ? 'batch:' . $batchId . ':' . $currency
            : 'legacy:' . (string) $record['request_key'] . ':' . accountSupplierStatementDateOnly($record['paid_at'] ?? '');

        if (!isset($groups[$groupKey])) {
            $paymentReference = trim((string) ($record['payment_reference'] ?? ''));
            $processingReference = trim((string) ($record['processing_reference'] ?? ''));
            $batchReference = trim((string) ($record['batch_reference'] ?? ''));
            $groups[$groupKey] = [
                'id' => $groupKey,
                'kind' => 'payment',
                'transaction_type' => $batchId > 0 ? 'Grouped Payment' : 'Legacy Payment',
                'transaction_at' => (string) $record['paid_at'],
                'date' => accountSupplierStatementDateOnly($record['paid_at'] ?? ''),
                'currency' => $currency,
                'reference' => $paymentReference !== '' ? $paymentReference : ($processingReference !== '' ? $processingReference : ($batchReference !== '' ? $batchReference : (string) ($record['primary_reference'] ?? 'Payment'))),
                'po_invoice' => '',
                'description' => $batchId > 0
                    ? trim(((string) ($record['processing_method'] ?? 'Payment')) . ' settlement')
                    : 'Historical supplier payment',
                'project' => '',
                'debit' => 0.0,
                'credit' => 0.0,
                'gross_settlement_amount' => 0.0,
                'supplier_credit_applied' => 0.0,
                'cash_payment_amount' => 0.0,
                'status' => 'Paid',
                'payment_batch_reference' => $batchReference,
                'processing_reference' => $processingReference,
                'canonical_batch_id' => $batchId,
                'legacy_batch_id' => (int) ($record['legacy_batch_id'] ?? 0),
                'payment_reference' => $paymentReference,
                'processing_method' => (string) ($record['processing_method'] ?? ''),
                'completion_mode' => (string) ($record['completion_mode'] ?? ''),
                'allocations' => [],
                'settlement_summary_by_currency' => [],
                'linked_documents' => $batchId > 0 ? ($artifactsByBatch[$batchId] ?? []) : [],
                'source' => [
                    'entity' => $batchId > 0 ? 'payment_batch' : 'legacy_payment',
                    'canonical_batch_id' => $batchId,
                    'legacy_batch_id' => (int) ($record['legacy_batch_id'] ?? 0),
                    'route' => $batchId > 0 ? '/payments/processing?batch_id=' . $batchId : accountSupplierStatementRequestSource($request)['route'],
                    'fx_instruction_letter_id' => (int) ($record['fx_instruction_letter_id'] ?? 0),
                ],
                'date_basis' => (string) ($record['date_basis'] ?? ''),
            ];
        }

        $groups[$groupKey]['credit'] = accountSupplierStatementMoney($groups[$groupKey]['credit'] + (float) $record['statement_credit']);
        $groups[$groupKey]['cash_payment_amount'] = accountSupplierStatementMoney(
            $groups[$groupKey]['cash_payment_amount'] + (float) ($record['settlement_amount'] ?? 0)
        );
        $groups[$groupKey]['supplier_credit_applied'] = accountSupplierStatementMoney(
            $groups[$groupKey]['supplier_credit_applied'] + (float) ($record['supplier_credit_applied'] ?? 0)
        );
        $groups[$groupKey]['gross_settlement_amount'] = accountSupplierStatementMoney(
            $groups[$groupKey]['gross_settlement_amount']
            + (float) ($record['gross_amount'] ?? ((float) ($record['statement_credit'] ?? 0) + (float) ($record['supplier_credit_applied'] ?? 0)))
        );
        $groups[$groupKey]['allocations'][] = accountSupplierStatementBuildAllocation($record, $request);

        $settlementCurrency = (string) ($record['settlement_currency'] ?? $currency);
        if (!isset($groups[$groupKey]['settlement_summary_by_currency'][$settlementCurrency])) {
            $groups[$groupKey]['settlement_summary_by_currency'][$settlementCurrency] = 0.0;
        }
        $groups[$groupKey]['settlement_summary_by_currency'][$settlementCurrency] = accountSupplierStatementMoney(
            $groups[$groupKey]['settlement_summary_by_currency'][$settlementCurrency] + (float) ($record['settlement_amount'] ?? 0)
        );
    }

    foreach ($groups as &$group) {
        $settlementRows = [];
        foreach ($group['settlement_summary_by_currency'] as $currency => $amount) {
            $settlementRows[] = ['currency' => $currency, 'amount' => accountSupplierStatementMoney($amount)];
        }
        $group['settlement_summary_by_currency'] = $settlementRows;
        if (count($group['allocations']) === 1) {
            $allocation = $group['allocations'][0];
            $group['po_invoice'] = trim(implode(' / ', array_filter([
                $allocation['po_number'] ?: null,
                $allocation['invoice_number'] ?: null,
            ])));
            $group['project'] = (string) ($allocation['project_code'] ?? '');
        } else {
            $group['po_invoice'] = count($group['allocations']) . ' linked transactions';
            $projects = array_values(array_unique(array_filter(array_map(
                static fn(array $allocation): string => trim((string) ($allocation['project_code'] ?? '')),
                $group['allocations']
            ))));
            $group['project'] = count($projects) === 1 ? $projects[0] : (count($projects) > 1 ? 'Multiple projects' : '');
        }
    }
    unset($group);

    return array_values($groups);
}

function accountSupplierStatementBuildObligationTransaction(
    array $request,
    string $derivedStatus,
    ?float $effectiveAmount = null
): array
{
    $cancelled = $derivedStatus === 'Cancelled';
    $amount = $effectiveAmount !== null
        ? accountSupplierStatementMoney($effectiveAmount)
        : ($cancelled ? 0.0 : accountSupplierStatementMoney($request['obligation_amount'] ?? 0));
    $meta = accountSupplierStatementTypeMeta((string) $request['request_type']);
    $poInvoice = trim(implode(' / ', array_filter([
        trim((string) ($request['po_number'] ?? '')) ?: null,
        trim((string) ($request['invoice_number'] ?? '')) ?: null,
    ])));

    return [
        'id' => 'obligation:' . accountSupplierStatementRequestKey($request),
        'kind' => 'obligation',
        'transaction_type' => $meta['label'],
        'transaction_at' => (string) $request['transaction_at'],
        'date' => accountSupplierStatementDateOnly($request['transaction_at'] ?? ''),
        'currency' => (string) $request['currency'],
        'reference' => (string) $request['primary_reference'],
        'po_invoice' => $poInvoice,
        'description' => (string) ($request['description'] ?? ''),
        'project' => (string) ($request['project_code'] ?? ''),
        'debit' => $amount,
        'credit' => 0.0,
        'original_amount' => accountSupplierStatementMoney($request['obligation_amount'] ?? 0),
        'status' => $derivedStatus,
        'payment_batch_reference' => '',
        'processing_reference' => (string) ($request['request_processing_reference'] ?? ''),
        'linked_documents' => [],
        'source' => accountSupplierStatementRequestSource($request),
    ];
}

function accountSupplierStatementTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function accountSupplierStatementFetchFinancialAdjustments(
    mysqli $conn,
    int $supplierId,
    string $toDate,
    string $currency
): array {
    $required = [
        'procurement_supplier_financial_adjustments',
        'procurement_supplier_financial_adjustment_credit_notes',
        'procurement_supplier_financial_adjustment_allocations',
    ];
    foreach ($required as $table) {
        if (!accountSupplierStatementTableExists($conn, $table)) {
            return [];
        }
    }

    $where = [
        'a.supplier_id = ?',
        "a.adjustment_direction = 'Recoverable'",
        "a.status <> 'Cancelled'",
        'DATE(a.created_at) <= ?',
    ];
    $params = [$supplierId, $toDate];
    $types = 'is';
    if ($currency !== '') {
        $where[] = 'a.currency = ?';
        $params[] = $currency;
        $types .= 's';
    }

    $stmt = $conn->prepare(
        'SELECT a.* FROM procurement_supplier_financial_adjustments a WHERE '
        . implode(' AND ', $where)
        . ' ORDER BY a.created_at ASC, a.id ASC'
    );
    accountSupplierStatementBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows === []) {
        return [];
    }

    $ids = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $idTypes = str_repeat('i', count($ids));

    $noteStmt = $conn->prepare(
        "SELECT id, adjustment_id, reference, issued_date, amount, available_amount, status, notes,
                created_by, created_at, cancelled_by, cancelled_at, cancellation_reason
         FROM procurement_supplier_financial_adjustment_credit_notes
         WHERE adjustment_id IN ({$placeholders}) AND DATE(created_at) <= ?
         ORDER BY created_at ASC, id ASC"
    );
    $noteParams = [...$ids, $toDate];
    accountSupplierStatementBind($noteStmt, $idTypes . 's', $noteParams);
    $noteStmt->execute();
    $notes = $noteStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $noteStmt->close();

    $allocationStmt = $conn->prepare(
        "SELECT id, adjustment_id, allocation_type, target_source_type, target_purchase_id,
                target_account_request_type, target_account_request_id, amount, reference, notes,
                created_by, created_at
         FROM procurement_supplier_financial_adjustment_allocations
         WHERE adjustment_id IN ({$placeholders}) AND DATE(created_at) <= ?
         ORDER BY created_at ASC, id ASC"
    );
    $allocationParams = [...$ids, $toDate];
    accountSupplierStatementBind($allocationStmt, $idTypes . 's', $allocationParams);
    $allocationStmt->execute();
    $allocations = $allocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocationStmt->close();

    $notesByAdjustment = [];
    foreach ($notes as $note) {
        $note['id'] = (int) $note['id'];
        $note['adjustment_id'] = (int) $note['adjustment_id'];
        $note['amount'] = accountSupplierStatementMoney($note['amount'] ?? 0);
        $note['available_amount'] = accountSupplierStatementMoney($note['available_amount'] ?? 0);
        $notesByAdjustment[$note['adjustment_id']][] = $note;
    }

    $allocationsByAdjustment = [];
    foreach ($allocations as $allocation) {
        $allocation['id'] = (int) $allocation['id'];
        $allocation['adjustment_id'] = (int) $allocation['adjustment_id'];
        $allocation['target_purchase_id'] = (int) ($allocation['target_purchase_id'] ?? 0);
        $allocation['target_account_request_id'] = (int) ($allocation['target_account_request_id'] ?? 0);
        $allocation['amount'] = accountSupplierStatementMoney($allocation['amount'] ?? 0);
        $allocationsByAdjustment[$allocation['adjustment_id']][] = $allocation;
    }

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['source_purchase_id'] = (int) ($row['source_purchase_id'] ?? 0);
        $row['source_po_id'] = (int) ($row['source_po_id'] ?? 0);
        $row['source_revision_id'] = (int) ($row['source_revision_id'] ?? 0);
        $row['source_revision_number'] = (int) ($row['source_revision_number'] ?? 0);
        $row['amount'] = accountSupplierStatementMoney($row['amount'] ?? 0);
        $row['outstanding_amount'] = accountSupplierStatementMoney($row['outstanding_amount'] ?? 0);
        $row['credit_notes'] = $notesByAdjustment[$row['id']] ?? [];
        $row['allocations'] = $allocationsByAdjustment[$row['id']] ?? [];
    }
    unset($row);
    return $rows;
}

function accountSupplierStatementDecodeSnapshot(mixed $json): array
{
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function accountSupplierStatementAdjustmentSource(array $adjustment): array
{
    return [
        'entity' => 'supplier_adjustment',
        'adjustment_id' => (int) ($adjustment['id'] ?? 0),
        'label' => 'Supplier Recovery',
        'route' => '/payments/supplier-adjustments?adjustment_id=' . (int) ($adjustment['id'] ?? 0),
        'source_type' => (string) ($adjustment['source_type'] ?? ''),
        'source_purchase_id' => (int) ($adjustment['source_purchase_id'] ?? 0),
        'source_revision_id' => (int) ($adjustment['source_revision_id'] ?? 0),
    ];
}

function accountSupplierStatementAdjustmentContext(array $adjustment): array
{
    $revised = accountSupplierStatementDecodeSnapshot($adjustment['revised_snapshot_json'] ?? null);
    $origin = accountSupplierStatementDecodeSnapshot($adjustment['origin_snapshot_json'] ?? null);
    $snapshot = $revised ?: $origin;
    $reference = trim((string) ($snapshot['po_number'] ?? ''))
        ?: trim((string) ($snapshot['purchase_number'] ?? ''))
        ?: ('ADJ-' . (int) ($adjustment['id'] ?? 0));
    $project = trim((string) ($snapshot['project_code'] ?? ''))
        ?: trim((string) ($snapshot['site'] ?? ''))
        ?: trim((string) ($snapshot['project'] ?? ''));
    return [
        'reference' => $reference,
        'project' => $project,
        'po_invoice' => trim((string) ($snapshot['po_number'] ?? '')),
    ];
}

function accountSupplierStatementBuildAdjustmentTransactions(array $adjustments): array
{
    $transactions = [];
    foreach ($adjustments as $adjustment) {
        $adjustmentId = (int) ($adjustment['id'] ?? 0);
        $currency = (string) ($adjustment['currency'] ?? 'NGN');
        $context = accountSupplierStatementAdjustmentContext($adjustment);
        $creditNotes = is_array($adjustment['credit_notes'] ?? null) ? $adjustment['credit_notes'] : [];
        $allocations = is_array($adjustment['allocations'] ?? null) ? $adjustment['allocations'] : [];
        $audit = [
            'adjustment_id' => $adjustmentId,
            'adjustment_kind' => (string) ($adjustment['adjustment_kind'] ?? ''),
            'original_amount' => accountSupplierStatementMoney($adjustment['amount'] ?? 0),
            'outstanding_amount' => accountSupplierStatementMoney($adjustment['outstanding_amount'] ?? 0),
            'credit_notes' => $creditNotes,
            'allocations' => $allocations,
        ];

        $transactions[] = [
            'id' => 'supplier-adjustment:' . $adjustmentId,
            'kind' => 'supplier_adjustment',
            'transaction_type' => 'Supplier Recovery · ' . (string) ($adjustment['adjustment_kind'] ?? 'Adjustment'),
            'transaction_at' => (string) ($adjustment['created_at'] ?? ''),
            'date' => accountSupplierStatementDateOnly($adjustment['created_at'] ?? ''),
            'currency' => $currency,
            'reference' => $context['reference'],
            'po_invoice' => $context['po_invoice'],
            'description' => (string) ($adjustment['reason'] ?? ''),
            'project' => $context['project'],
            'debit' => 0.0,
            'credit' => accountSupplierStatementMoney($adjustment['amount'] ?? 0),
            'status' => (string) ($adjustment['status'] ?? 'Open'),
            'payment_batch_reference' => '',
            'processing_reference' => '',
            'linked_documents' => [],
            'supplier_credit_applied' => 0.0,
            'audit' => $audit,
            'source' => accountSupplierStatementAdjustmentSource($adjustment),
        ];

        foreach ($creditNotes as $note) {
            $createdAt = (string) ($note['created_at'] ?? $adjustment['created_at'] ?? '');
            $transactions[] = [
                'id' => 'supplier-credit-note:' . (int) ($note['id'] ?? 0),
                'kind' => 'credit_note',
                'transaction_type' => 'Supplier Credit Note',
                'transaction_at' => $createdAt,
                'date' => accountSupplierStatementDateOnly($note['issued_date'] ?? $createdAt),
                'currency' => $currency,
                'reference' => (string) ($note['reference'] ?? ''),
                'po_invoice' => $context['po_invoice'],
                'description' => trim((string) ($note['notes'] ?? '')) ?: 'Supplier credit note registered against the recovery.',
                'project' => $context['project'],
                'debit' => 0.0,
                'credit' => 0.0,
                'status' => (string) ($note['status'] ?? 'Available'),
                'payment_batch_reference' => '',
                'processing_reference' => '',
                'linked_documents' => [],
                'credit_note_amount' => accountSupplierStatementMoney($note['amount'] ?? 0),
                'credit_available_amount' => accountSupplierStatementMoney($note['available_amount'] ?? 0),
                'supplier_credit_applied' => 0.0,
                'source' => accountSupplierStatementAdjustmentSource($adjustment),
            ];
            if (!empty($note['cancelled_at'])) {
                $transactions[] = [
                    'id' => 'supplier-credit-note-cancelled:' . (int) ($note['id'] ?? 0),
                    'kind' => 'credit_note_cancelled',
                    'transaction_type' => 'Credit Note Cancelled',
                    'transaction_at' => (string) $note['cancelled_at'],
                    'date' => accountSupplierStatementDateOnly($note['cancelled_at']),
                    'currency' => $currency,
                    'reference' => (string) ($note['reference'] ?? ''),
                    'po_invoice' => $context['po_invoice'],
                    'description' => trim((string) ($note['cancellation_reason'] ?? '')) ?: 'Unused supplier credit note cancelled.',
                    'project' => $context['project'],
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'status' => 'Cancelled',
                    'payment_batch_reference' => '',
                    'processing_reference' => '',
                    'linked_documents' => [],
                    'credit_note_amount' => accountSupplierStatementMoney($note['amount'] ?? 0),
                    'supplier_credit_applied' => 0.0,
                    'source' => accountSupplierStatementAdjustmentSource($adjustment),
                ];
            }
        }

        foreach ($allocations as $allocation) {
            $type = (string) ($allocation['allocation_type'] ?? 'Recovery');
            $isOffset = $type === 'Credit Offset';
            $amount = accountSupplierStatementMoney($allocation['amount'] ?? 0);
            $targetType = (string) ($allocation['target_account_request_type'] ?? $allocation['target_source_type'] ?? '');
            $targetId = (int) ($allocation['target_account_request_id'] ?? 0);
            $targetMeta = $targetType !== '' ? accountSupplierStatementTypeMeta($targetType) : null;
            $source = accountSupplierStatementAdjustmentSource($adjustment);
            if ($targetMeta && $targetId > 0) {
                $source['target_route'] = $targetMeta['route'] . '?request_id=' . $targetId;
                $source['target_label'] = $targetMeta['label'];
            }
            $transactions[] = [
                'id' => 'supplier-adjustment-allocation:' . (int) ($allocation['id'] ?? 0),
                'kind' => $isOffset ? 'credit_offset' : 'supplier_recovery',
                'transaction_type' => $isOffset ? 'Supplier Credit Applied' : ('Supplier ' . $type . ' Received'),
                'transaction_at' => (string) ($allocation['created_at'] ?? ''),
                'date' => accountSupplierStatementDateOnly($allocation['created_at'] ?? ''),
                'currency' => $currency,
                'reference' => trim((string) ($allocation['reference'] ?? '')) ?: $context['reference'],
                'po_invoice' => $context['po_invoice'],
                'description' => trim((string) ($allocation['notes'] ?? '')) ?: ($isOffset ? 'Supplier credit applied to a later payment.' : 'Supplier recovery received.'),
                'project' => $context['project'],
                'debit' => $isOffset ? 0.0 : $amount,
                'credit' => 0.0,
                'status' => $isOffset ? 'Applied' : 'Recovered',
                'payment_batch_reference' => '',
                'processing_reference' => '',
                'linked_documents' => [],
                'supplier_credit_applied' => $isOffset ? $amount : 0.0,
                'source' => $source,
            ];
        }
    }
    return $transactions;
}

function accountSupplierStatementBuildCurrencySections(
    array $requests,
    array $paymentRecords,
    array $creditsByRequest,
    array $artifactsByBatch,
    string $fromDate,
    string $toDate,
    string $statusFilter,
    array $financialAdjustments = []
): array {
    $requestsByKey = [];
    $derivedStatusByKey = [];
    $includedPurchaseIds = [];
    $preserveCancelledHistoryByKey = [];
    foreach ($requests as $request) {
        $key = accountSupplierStatementRequestKey($request);
        $derivedStatus = accountSupplierStatementAsOfStatus($request, (float) ($creditsByRequest[$key] ?? 0));
        if (!accountSupplierStatementStatusMatches($derivedStatus, $statusFilter)) {
            continue;
        }
        $requestsByKey[$key] = $request;
        $derivedStatusByKey[$key] = $derivedStatus;
        $purchaseId = (int) ($request['procurement_purchase_id'] ?? 0);
        if ($purchaseId > 0) {
            $includedPurchaseIds[$purchaseId] = true;
        }
        $preserveCancelledHistoryByKey[$key] = $derivedStatus === 'Cancelled'
            && accountSupplierStatementMoney($creditsByRequest[$key] ?? 0) > 0.004;
    }

    $filteredPayments = array_values(array_filter(
        $paymentRecords,
        static function (array $record) use ($requestsByKey, $derivedStatusByKey, $preserveCancelledHistoryByKey): bool {
            $key = (string) ($record['request_key'] ?? '');
            return isset($requestsByKey[$key])
                && (($derivedStatusByKey[$key] ?? '') !== 'Cancelled' || !empty($preserveCancelledHistoryByKey[$key]));
        }
    ));
    $paymentGroups = accountSupplierStatementGroupPayments($filteredPayments, $requestsByKey, $artifactsByBatch);
    $adjustmentTransactions = accountSupplierStatementBuildAdjustmentTransactions(array_values(array_filter(
        $financialAdjustments,
        static function (array $adjustment) use ($statusFilter, $includedPurchaseIds): bool {
            if ($statusFilter === ACCOUNT_SUPPLIER_STATEMENT_STATUS_ALL) {
                return true;
            }
            $purchaseId = (int) ($adjustment['source_purchase_id'] ?? 0);
            return $purchaseId > 0 && isset($includedPurchaseIds[$purchaseId]);
        }
    )));

    $sections = [];
    foreach ($requestsByKey as $key => $request) {
        $currency = (string) $request['currency'];
        if (!isset($sections[$currency])) {
            $sections[$currency] = [
                'currency' => $currency,
                'summary' => [
                    'opening_payable_balance' => 0.0,
                    'new_obligations' => 0.0,
                    'payments_settlements' => 0.0,
                    'supplier_recovery_adjustments' => 0.0,
                    'supplier_recoveries_received' => 0.0,
                    'supplier_credit_offsets_applied' => 0.0,
                    'outstanding_pending_payable' => 0.0,
                    'closing_payable_balance' => 0.0,
                    'net_supplier_payable_balance' => 0.0,
                    'net_supplier_credit_balance' => 0.0,
                    'unpaid_transaction_count' => 0,
                    'paid_transaction_count' => 0,
                    'partially_settled_transaction_count' => 0,
                ],
                'transactions' => [],
            ];
        }

        $derivedStatus = $derivedStatusByKey[$key];
        $cancelled = $derivedStatus === 'Cancelled';
        $preserveCancelledHistory = !empty($preserveCancelledHistoryByKey[$key]);
        $obligation = ($cancelled && !$preserveCancelledHistory)
            ? 0.0
            : accountSupplierStatementMoney($request['obligation_amount'] ?? 0);
        $credited = ($cancelled && !$preserveCancelledHistory)
            ? 0.0
            : accountSupplierStatementMoney(min($obligation, (float) ($creditsByRequest[$key] ?? 0)));
        $outstanding = accountSupplierStatementMoney(max(0.0, $obligation - $credited));
        $requestDate = accountSupplierStatementDateOnly($request['transaction_at'] ?? '');

        if ($requestDate < $fromDate) {
            $sections[$currency]['summary']['opening_payable_balance'] = accountSupplierStatementMoney(
                $sections[$currency]['summary']['opening_payable_balance'] + $obligation
            );
        } elseif ($requestDate <= $toDate) {
            $sections[$currency]['summary']['new_obligations'] = accountSupplierStatementMoney(
                $sections[$currency]['summary']['new_obligations'] + $obligation
            );
            $sections[$currency]['transactions'][] = accountSupplierStatementBuildObligationTransaction(
                $request,
                $derivedStatus,
                $obligation
            );
        }

        $sections[$currency]['summary']['outstanding_pending_payable'] = accountSupplierStatementMoney(
            $sections[$currency]['summary']['outstanding_pending_payable'] + $outstanding
        );
        if ($derivedStatus === 'Paid') {
            $sections[$currency]['summary']['paid_transaction_count']++;
        } elseif ($derivedStatus === 'Partially Settled') {
            $sections[$currency]['summary']['partially_settled_transaction_count']++;
            $sections[$currency]['summary']['unpaid_transaction_count']++;
        } elseif ($derivedStatus !== 'Cancelled') {
            $sections[$currency]['summary']['unpaid_transaction_count']++;
        }
    }

    foreach ($adjustmentTransactions as $transaction) {
        $currency = (string) ($transaction['currency'] ?? 'NGN');
        if (!isset($sections[$currency])) {
            $sections[$currency] = [
                'currency' => $currency,
                'summary' => [
                    'opening_payable_balance' => 0.0,
                    'new_obligations' => 0.0,
                    'payments_settlements' => 0.0,
                    'supplier_recovery_adjustments' => 0.0,
                    'supplier_recoveries_received' => 0.0,
                    'supplier_credit_offsets_applied' => 0.0,
                    'outstanding_pending_payable' => 0.0,
                    'closing_payable_balance' => 0.0,
                    'net_supplier_payable_balance' => 0.0,
                    'net_supplier_credit_balance' => 0.0,
                    'unpaid_transaction_count' => 0,
                    'paid_transaction_count' => 0,
                    'partially_settled_transaction_count' => 0,
                ],
                'transactions' => [],
            ];
        }
        $date = (string) ($transaction['date'] ?? '');
        $debit = accountSupplierStatementMoney($transaction['debit'] ?? 0);
        $credit = accountSupplierStatementMoney($transaction['credit'] ?? 0);
        $creditOffset = accountSupplierStatementMoney($transaction['supplier_credit_applied'] ?? 0);
        if ($date < $fromDate) {
            $sections[$currency]['summary']['opening_payable_balance'] = accountSupplierStatementMoney(
                $sections[$currency]['summary']['opening_payable_balance'] + $debit - $credit
            );
        } elseif ($date <= $toDate) {
            if (($transaction['kind'] ?? '') === 'supplier_adjustment') {
                $sections[$currency]['summary']['supplier_recovery_adjustments'] = accountSupplierStatementMoney(
                    $sections[$currency]['summary']['supplier_recovery_adjustments'] + $credit
                );
            } elseif (($transaction['kind'] ?? '') === 'supplier_recovery') {
                $sections[$currency]['summary']['supplier_recoveries_received'] = accountSupplierStatementMoney(
                    $sections[$currency]['summary']['supplier_recoveries_received'] + $debit
                );
            } elseif (($transaction['kind'] ?? '') === 'credit_offset') {
                $sections[$currency]['summary']['supplier_credit_offsets_applied'] = accountSupplierStatementMoney(
                    $sections[$currency]['summary']['supplier_credit_offsets_applied'] + $creditOffset
                );
            }
            $sections[$currency]['transactions'][] = $transaction;
        }
    }

    foreach ($filteredPayments as $record) {
        $currency = (string) $record['currency'];
        if (!isset($sections[$currency])) {
            continue;
        }
        $paidDate = accountSupplierStatementDateOnly($record['paid_at'] ?? '');
        $credit = accountSupplierStatementMoney($record['statement_credit'] ?? 0);
        if ($paidDate < $fromDate) {
            $sections[$currency]['summary']['opening_payable_balance'] = accountSupplierStatementMoney(
                $sections[$currency]['summary']['opening_payable_balance'] - $credit
            );
        } elseif ($paidDate <= $toDate) {
            $sections[$currency]['summary']['payments_settlements'] = accountSupplierStatementMoney(
                $sections[$currency]['summary']['payments_settlements'] + $credit
            );
        }
    }

    foreach ($paymentGroups as $group) {
        $currency = (string) $group['currency'];
        $date = (string) $group['date'];
        if (isset($sections[$currency]) && $date >= $fromDate && $date <= $toDate) {
            $sections[$currency]['transactions'][] = $group;
        }
    }

    foreach ($sections as &$section) {
        $summary = &$section['summary'];
        $summary['closing_payable_balance'] = accountSupplierStatementMoney(
            (float) $summary['opening_payable_balance']
            + (float) $summary['new_obligations']
            + (float) $summary['supplier_recoveries_received']
            - (float) $summary['payments_settlements']
            - (float) $summary['supplier_recovery_adjustments']
        );
        $summary['net_supplier_payable_balance'] = accountSupplierStatementMoney(max(0.0, (float) $summary['closing_payable_balance']));
        $summary['net_supplier_credit_balance'] = accountSupplierStatementMoney(max(0.0, -(float) $summary['closing_payable_balance']));

        usort($section['transactions'], static function (array $left, array $right): int {
            $dateCompare = strcmp((string) ($left['transaction_at'] ?? ''), (string) ($right['transaction_at'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            if (($left['kind'] ?? '') !== ($right['kind'] ?? '')) {
                return ($left['kind'] ?? '') === 'obligation' ? -1 : 1;
            }
            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        $running = accountSupplierStatementMoney($summary['opening_payable_balance']);
        foreach ($section['transactions'] as &$transaction) {
            $running = accountSupplierStatementMoney(
                $running + (float) ($transaction['debit'] ?? 0) - (float) ($transaction['credit'] ?? 0)
            );
            $transaction['running_balance'] = $running;
        }
        unset($transaction);
        unset($summary);
    }
    unset($section);

    ksort($sections);
    return array_values($sections);
}

function accountSupplierStatementReport(mysqli $conn, array $query): array
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $supplierId = (int) ($query['supplier_id'] ?? 0);
    if ($supplierId <= 0) {
        throw new RuntimeException('Supplier is required.', 400);
    }

    $fromDate = accountSupplierStatementNormalizeDate($query['from_date'] ?? '', 'From Date');
    $toDate = accountSupplierStatementNormalizeDate($query['to_date'] ?? '', 'To Date');
    if ($fromDate > $toDate) {
        throw new RuntimeException('From Date cannot be after To Date.', 400);
    }
    $currency = accountSupplierStatementNormalizeCurrency($query['currency'] ?? '');
    $status = accountSupplierStatementNormalizeStatus($query['status'] ?? 'all');

    $storage = accountPaymentStorageCanonicalRuntimeVerification($conn);
    if (($storage['healthy'] ?? false) !== true) {
        throw new RuntimeException('Canonical payment storage is incomplete. Supplier Statements require the verified shared payment batch architecture.', 503);
    }

    $supplier = accountSupplierStatementFetchSupplier($conn, $supplierId);
    $requests = accountSupplierStatementFetchRequests($conn, $supplierId, $toDate, $currency);
    $requestsByKey = [];
    foreach ($requests as $request) {
        $requestsByKey[accountSupplierStatementRequestKey($request)] = $request;
    }

    $canonicalPaymentRows = accountSupplierStatementFetchCanonicalPayments($conn, $supplierId, $toDate, $currency);
    [$creditsByRequest, $paymentRecords] = accountSupplierStatementApplyCanonicalCredits($requestsByKey, $canonicalPaymentRows);
    [$creditsByRequest, $paymentRecords] = accountSupplierStatementAddLegacyFallbackCredits(
        $requestsByKey,
        $creditsByRequest,
        $paymentRecords,
        $toDate
    );

    $artifactsByBatch = accountSupplierStatementFetchArtifacts(
        $conn,
        array_map(static fn(array $row): int => (int) ($row['canonical_batch_id'] ?? 0), $canonicalPaymentRows)
    );
    $financialAdjustments = accountSupplierStatementFetchFinancialAdjustments(
        $conn,
        $supplierId,
        $toDate,
        $currency
    );

    $sections = accountSupplierStatementBuildCurrencySections(
        $requests,
        $paymentRecords,
        $creditsByRequest,
        $artifactsByBatch,
        $fromDate,
        $toDate,
        $status,
        $financialAdjustments
    );

    return [
        'supplier' => $supplier,
        'period' => ['from_date' => $fromDate, 'to_date' => $toDate],
        'filters' => [
            'currency' => $currency !== '' ? $currency : 'all',
            'status' => $status,
        ],
        'currency_count' => count($sections),
        'currencies' => $sections,
        'source_coverage' => accountSupplierStatementSourceCoverage(),
        'notes' => [
            'currency_policy' => 'Every balance, debit, credit and running balance is calculated inside one obligation currency only.',
            'fx_settlement_policy' => 'When FX settlement currency differs from the obligation currency, the statement credit is allocated proportionally in the obligation currency while the actual settlement currency/amount remains visible in allocation details.',
            'legacy_history_policy' => 'Historical Paid requests without canonical batch items use existing request payment metadata as a fallback so old settled liabilities are not incorrectly left open. The response marks the fallback date basis for audit visibility.',
            'supplier_adjustment_policy' => 'Paid purchase revisions and cancellations remain visible as supplier recovery credits. Refunds reverse those credits as recovery receipts, while supplier-credit offsets are shown as non-posting audit events to avoid double-counting.',
        ],
    ];
}
