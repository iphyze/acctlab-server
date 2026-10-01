<?php

declare(strict_types=1);

const PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE = 'local_final_purchase';
const PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_SOURCE = 'procurement_local_final_purchases';

// Backward-compatible request-type aliases retained by the current ProcureDesk
// runtime routes/services after historical consolidation assets were removed.
// Keep these central so Local Final and Local Advance reads/writes share the
// same canonical request-type values without depending on retired files.
if (!defined('PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL')) {
    define('PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL', PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE);
}
if (!defined('PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE')) {
    define('PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE', 'local_advance_purchase');
}


// Account-side handoff destinations. Local Final/Advance continue using their
// existing destinations by default; Compass is an explicit opt-in destination
// resolved from the selected supplier during the approval handoff.
const PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER = 'supplier_fund_request';
const PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE = 'advance_payment_request';
const PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS = 'compass_fund_request';

function procurementRequestCanonicalDefaultLocalAccountRequestType(string $requestType): ?string
{
    return match ($requestType) {
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL => PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE => PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE,
        default => null,
    };
}

function procurementRequestCanonicalRuntimeObjectType(mysqli $conn, string $name): ?string
{
    $stmt = $conn->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return is_string($type) ? strtoupper($type) : null;
}

function procurementRequestCanonicalRuntimeColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function procurementRequestCanonicalRuntimeObjectExists(mysqli $conn, string $name): bool
{
    return procurementRequestCanonicalRuntimeObjectType($conn, $name) !== null;
}

function procurementRequestCanonicalRuntimeCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc() ?: [];
    return (int) ($row['total'] ?? 0);
}

/**
 * Fast healthy-schema probe for hot read paths.
 *
 * Preparing a zero-row SELECT lets MySQL validate the table and every required
 * column in one operation. information_schema is only consulted by the existing
 * diagnostic fallback when this probe fails.
 */
function procurementRequestCanonicalRuntimeColumnsReady(
    mysqli $conn,
    string $table,
    array $columns
): bool {
    static $ready = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $columns = array_values(array_unique(array_map('strval', $columns)));
    if ($columns === []) {
        return false;
    }
    foreach ($columns as $column) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
    }

    $key = spl_object_id($conn) . ':' . $table . ':' . hash('sha256', implode('|', $columns));
    if (($ready[$key] ?? false) === true) {
        return true;
    }

    try {
        $select = implode(', ', array_map(
            static fn(string $column): string => "`{$column}`",
            $columns
        ));
        $stmt = $conn->prepare("SELECT {$select} FROM `{$table}` LIMIT 0");
        if (!$stmt) {
            return false;
        }
        $stmt->execute();
        $stmt->close();
        $ready[$key] = true;
        return true;
    } catch (Throwable) {
        return false;
    }
}

function procurementRequestCanonicalLocalFinalAssertReady(mysqli $conn): void
{
    $required = [
        'request_type', 'legacy_source_table', 'legacy_source_id', 'purchase_number',
        'purchase_number_normalized', 'grn_ref', 'material_type', 'project_id', 'project_code',
        'project_name', 'supplier_id', 'supplier_name', 'supplier_ledger', 'wht_status', 'wht_rate',
        'wht_amount', 'account_wht_override_status', 'account_wht_override_rate',
        'account_wht_override_amount', 'account_payable_amount', 'account_wht_adjustment_reason',
        'account_wht_adjusted_by', 'account_wht_adjusted_at', 'invoice_number', 'invoice_date',
        'purchase_date', 'purchase_subtotal', 'purchase_discount', 'purchase_other_charges',
        'purchase_vat_status', 'purchase_vat_rate', 'purchase_vat_amount', 'purchase_value',
        'po_subtotal', 'po_discount', 'po_other_charges', 'po_vat_status', 'po_vat_rate',
        'po_vat_amount', 'po_value', 'remark', 'po_status', 'payment_status',
        'payment_status_source', 'payment_status_updated_at', 'account_amount_paid',
        'account_processing_method', 'account_processing_reference', 'account_processing_started_at',
        'account_expected_completion_at', 'account_completion_mode', 'account_confirmation_status',
        'account_payment_reference', 'account_paid_at', 'account_payment_remarks',
        'account_payment_batch_id', 'approval_status', 'handoff_status', 'handoff_revision',
        'account_request_type', 'account_request_id', 'previous_account_request_id', 'approved_by', 'approved_at',
        'approval_reversed_by', 'approval_reversed_at', 'retrieved_by', 'retrieved_at',
        'retrieval_reason', 'retrieval_source', 'created_by', 'created_at', 'updated_by',
        'updated_at', 'deleted_by', 'deleted_at', 'version',
    ];
    if (procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $required)) {
        return;
    }
    if (procurementRequestCanonicalRuntimeObjectType($conn, 'procurement_requests') !== 'BASE TABLE') {
        throw new RuntimeException('Canonical Local Final Purchase storage is unavailable.', 500);
    }
    $missing = array_values(array_filter(
        $required,
        static fn(string $column): bool => !procurementRequestCanonicalRuntimeColumnExists(
            $conn,
            'procurement_requests',
            $column
        )
    ));
    if ($missing !== []) {
        throw new RuntimeException(
            'Canonical Local Final Purchase storage is incomplete: ' . implode(', ', $missing),
            500
        );
    }
}

function procurementRequestCanonicalLocalFinalScopeSql(string $alias = ''): string
{
    $prefix = trim($alias) === '' ? '' : rtrim(trim($alias), '.') . '.';
    return $prefix . "request_type = '" . PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE . "'"
        . ' AND ' . $prefix . "legacy_source_table = '" . PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_SOURCE . "'";
}

/**
 * Canonical Local Final Purchase relation preserving the historical public-row
 * shape while reading only procurement_requests. This is intentionally SQL-only
 * and does not create a database VIEW.
 */
function procurementRequestCanonicalLocalFinalReadRelation(): string
{
    return "(
        SELECT
            legacy_source_id AS id,
            id AS canonical_request_id,
            id AS procurement_request_id,
            po_number,
            purchase_number,
            purchase_number_normalized,
            grn_ref,
            material_type,
            project_id,
            project_code,
            project_name,
            supplier_id,
            supplier_name,
            supplier_ledger,
            wht_status,
            wht_rate,
            wht_amount,
            account_wht_override_status,
            account_wht_override_rate,
            account_wht_override_amount,
            account_payable_amount,
            account_wht_adjustment_reason,
            account_wht_adjusted_by,
            account_wht_adjusted_at,
            invoice_number,
            invoice_date,
            purchase_date,
            purchase_subtotal,
            purchase_discount,
            purchase_other_charges,
            purchase_vat_status,
            purchase_vat_rate,
            purchase_vat_amount,
            purchase_value,
            po_subtotal,
            po_discount,
            po_other_charges,
            po_vat_status,
            po_vat_rate,
            po_vat_amount,
            po_value,
            remark,
            po_status,
            payment_status,
            payment_status_source,
            payment_status_updated_at,
            account_amount_paid,
            account_processing_method,
            account_processing_reference,
            account_processing_started_at,
            account_expected_completion_at,
            account_completion_mode,
            account_confirmation_status,
            account_payment_reference,
            account_paid_at,
            account_payment_remarks,
            account_payment_batch_id,
            approval_status,
            handoff_status,
            handoff_revision,
            account_request_type,
            account_request_id AS supplier_fund_request_id,
            previous_account_request_id AS previous_supplier_fund_request_id,
            approved_by,
            approved_at,
            approval_reversed_by,
            approval_reversed_at,
            retrieved_by,
            retrieved_at,
            retrieval_reason,
            retrieval_source,
            created_by,
            created_at,
            updated_by,
            updated_at,
            deleted_by,
            deleted_at,
            version
        FROM procurement_requests
        WHERE request_type = 'local_final_purchase'
          AND legacy_source_table = 'procurement_local_final_purchases'
    )";
}

const PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_TYPE = 'local_advance_purchase';
const PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_SOURCE = 'procurement_local_advance_purchases';

function procurementRequestCanonicalLocalAdvanceAssertReady(mysqli $conn): void
{
    $required = [
        'request_type', 'request_number', 'legacy_source_table', 'legacy_source_id',
        'po_id', 'po_revision_id', 'po_revision_number', 'request_variant',
        'legacy_parent_purchase_id', 'revision_reconciliation_id', 'po_snapshot_json',
        'purchase_number', 'po_percentage', 'expected_payment',
        'account_wht_override_status', 'account_wht_override_rate',
        'account_wht_override_amount', 'account_expected_payment',
        'account_wht_adjustment_reason', 'account_wht_adjusted_by', 'account_wht_adjusted_at',
        'remark', 'transaction_date', 'date_received', 'payment_status',
        'payment_status_source', 'payment_status_updated_at', 'approval_status',
        'handoff_status', 'handoff_revision', 'account_request_id', 'previous_account_request_id',
        'approved_by', 'approved_at', 'approval_reversed_by', 'approval_reversed_at',
        'retrieved_by', 'retrieved_at', 'retrieval_reason', 'retrieval_source',
        'account_amount_paid', 'account_processing_method', 'account_processing_reference',
        'account_processing_started_at', 'account_expected_completion_at', 'account_completion_mode',
        'account_confirmation_status', 'account_payment_reference', 'account_paid_at',
        'account_payment_remarks', 'account_payment_batch_id', 'created_by', 'created_at',
        'updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'version',
    ];
    if (procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $required)) {
        return;
    }
    if (procurementRequestCanonicalRuntimeObjectType($conn, 'procurement_requests') !== 'BASE TABLE') {
        throw new RuntimeException('Canonical Local Advance Purchase storage is unavailable.', 500);
    }
    $missing = array_values(array_filter(
        $required,
        static fn(string $column): bool => !procurementRequestCanonicalRuntimeColumnExists(
            $conn,
            'procurement_requests',
            $column
        )
    ));
    if ($missing !== []) {
        throw new RuntimeException(
            'Canonical Local Advance Purchase storage is incomplete: ' . implode(', ', $missing),
            500
        );
    }
}

function procurementRequestCanonicalLocalAdvanceScopeSql(string $alias = ''): string
{
    $prefix = trim($alias) === '' ? '' : rtrim(trim($alias), '.') . '.';
    return $prefix . "request_type = '" . PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_TYPE . "'"
        . ' AND ' . $prefix . "legacy_source_table = '" . PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_SOURCE . "'";
}

/**
 * Canonical Local Advance Purchase relation preserving the historical public-row
 * shape while reading only procurement_requests. It intentionally creates no DB VIEW.
 */
function procurementRequestCanonicalLocalAdvanceReadRelation(): string
{
    return "(
        SELECT
            legacy_source_id AS id,
            id AS canonical_request_id,
            id AS procurement_request_id,
            request_number,
            po_id,
            po_revision_id,
            po_revision_number,
            request_variant AS request_type,
            legacy_parent_purchase_id AS parent_purchase_id,
            revision_reconciliation_id,
            po_snapshot_json,
            purchase_number,
            po_percentage,
            expected_payment,
            account_wht_override_status,
            account_wht_override_rate,
            account_wht_override_amount,
            account_expected_payment,
            account_wht_adjustment_reason,
            account_wht_adjusted_by,
            account_wht_adjusted_at,
            remark,
            transaction_date,
            date_received,
            payment_status,
            payment_status_source,
            payment_status_updated_at,
            approval_status,
            handoff_status,
            handoff_revision,
            account_request_type,
            account_request_id AS advance_payment_request_id,
            previous_account_request_id AS previous_advance_payment_request_id,
            approved_by,
            approved_at,
            approval_reversed_by,
            approval_reversed_at,
            retrieved_by,
            retrieved_at,
            retrieval_reason,
            retrieval_source,
            account_amount_paid,
            account_processing_method,
            account_processing_reference,
            account_processing_started_at,
            account_expected_completion_at,
            account_completion_mode,
            account_confirmation_status,
            account_payment_reference,
            account_paid_at,
            account_payment_remarks,
            account_payment_batch_id,
            created_by,
            created_at,
            updated_by,
            updated_at,
            deleted_by,
            deleted_at,
            version
        FROM procurement_requests
        WHERE request_type = 'local_advance_purchase'
          AND legacy_source_table = 'procurement_local_advance_purchases'
    )";
}

// Canonical-native Foreign/FX Final Purchase request type. Unlike the retired
// Local Final/Advance source tables, these rows are created directly in
// procurement_requests. legacy_source_table is retained only as a stable
// canonical namespace because the consolidated table still enforces the
// historical public-id uniqueness contract.
const PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE = 'fx_final_purchase';
const PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE = 'canonical_fx_final_purchase';
if (!defined('PROCUREMENT_REQUEST_TYPE_FX_FINAL')) {
    define('PROCUREMENT_REQUEST_TYPE_FX_FINAL', PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE);
}

function procurementRequestCanonicalFxFinalAssertReady(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $required = [
        'request_type', 'request_number', 'legacy_source_table', 'legacy_source_id',
        'currency', 'po_number', 'purchase_number', 'purchase_number_normalized',
        'grn_ref', 'material_type', 'project_id', 'project_code', 'project_name',
        'supplier_id', 'supplier_name', 'supplier_ledger', 'wht_status', 'wht_rate',
        'wht_amount', 'invoice_number', 'invoice_date', 'purchase_date',
        'purchase_subtotal', 'purchase_discount', 'purchase_other_charges',
        'purchase_vat_status', 'purchase_vat_rate', 'purchase_vat_amount',
        'purchase_value', 'po_subtotal', 'po_discount', 'po_other_charges',
        'po_vat_status', 'po_vat_rate', 'po_vat_amount', 'po_value', 'remark',
        'po_status', 'payment_status', 'payment_status_source',
        'payment_status_updated_at', 'approval_status', 'handoff_status',
        'handoff_revision', 'account_request_type', 'account_request_id',
        'previous_account_request_id', 'approved_by', 'approved_at',
        'approval_reversed_by', 'approval_reversed_at', 'retrieved_by',
        'retrieved_at', 'retrieval_reason', 'retrieval_source',
        'account_amount_paid', 'account_processing_method',
        'account_processing_reference', 'account_processing_started_at',
        'account_expected_completion_at', 'account_completion_mode',
        'account_confirmation_status', 'account_payment_reference',
        'account_paid_at', 'account_payment_remarks', 'account_payment_batch_id',
        'created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_by',
        'deleted_at', 'version',
    ];

    if (procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $required)) {
        return;
    }
    if (procurementRequestCanonicalRuntimeObjectType($conn, 'procurement_requests') !== 'BASE TABLE') {
        throw new RuntimeException('Canonical FX Final Purchase storage is unavailable.', 500);
    }
    $missing = array_values(array_filter(
        $required,
        static fn(string $column): bool => !procurementRequestCanonicalRuntimeColumnExists(
            $conn,
            'procurement_requests',
            $column
        )
    ));
    if ($missing !== []) {
        throw new RuntimeException(
            'Canonical FX Final Purchase storage is incomplete: ' . implode(', ', $missing),
            500
        );
    }
}

function procurementRequestCanonicalFxFinalScopeSql(string $alias = ''): string
{
    $prefix = trim($alias) === '' ? '' : rtrim(trim($alias), '.') . '.';
    return $prefix . "request_type = '" . PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE . "'"
        . ' AND ' . $prefix . "legacy_source_table = '" . PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE . "'";
}

function procurementRequestCanonicalFxFinalReadRelation(): string
{
    return "(
        SELECT
            legacy_source_id AS id,
            id AS canonical_request_id,
            currency,
            po_number,
            purchase_number,
            purchase_number_normalized,
            grn_ref,
            material_type,
            project_id,
            project_code,
            project_name,
            supplier_id,
            supplier_name,
            supplier_ledger,
            wht_status,
            wht_rate,
            wht_amount,
            account_wht_override_status,
            account_wht_override_rate,
            account_wht_override_amount,
            account_payable_amount,
            account_wht_adjustment_reason,
            account_wht_adjusted_by,
            account_wht_adjusted_at,
            invoice_number,
            invoice_date,
            purchase_date,
            purchase_subtotal,
            purchase_discount,
            purchase_other_charges,
            purchase_vat_status,
            purchase_vat_rate,
            purchase_vat_amount,
            purchase_value,
            po_subtotal,
            po_discount,
            po_other_charges,
            po_vat_status,
            po_vat_rate,
            po_vat_amount,
            po_value,
            remark,
            po_status,
            payment_status,
            payment_status_source,
            payment_status_updated_at,
            account_amount_paid,
            account_processing_method,
            account_processing_reference,
            account_processing_started_at,
            account_expected_completion_at,
            account_completion_mode,
            account_confirmation_status,
            account_payment_reference,
            account_paid_at,
            account_payment_remarks,
            account_payment_batch_id,
            approval_status,
            handoff_status,
            handoff_revision,
            account_request_type,
            account_request_id AS fx_fund_request_id,
            previous_account_request_id AS previous_fx_fund_request_id,
            approved_by,
            approved_at,
            approval_reversed_by,
            approval_reversed_at,
            retrieved_by,
            retrieved_at,
            retrieval_reason,
            retrieval_source,
            created_by,
            created_at,
            updated_by,
            updated_at,
            deleted_by,
            deleted_at,
            version
        FROM procurement_requests
        WHERE request_type = 'fx_final_purchase'
          AND legacy_source_table = 'canonical_fx_final_purchase'
    )";
}


// Canonical-native Foreign/FX Advance Purchase. The purchase/request row lives
// in procurement_requests; the existing Local Advance PO/revision tables are
// reused as shared advance-PO infrastructure and are separated by request_scope.
const PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE = 'fx_advance_purchase';
const PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE = 'canonical_fx_advance_purchase';
if (!defined('PROCUREMENT_REQUEST_TYPE_FX_ADVANCE')) {
    define('PROCUREMENT_REQUEST_TYPE_FX_ADVANCE', PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE);
}

function procurementRequestCanonicalFxAdvanceAssertReady(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $required = [
        'request_type', 'request_number', 'legacy_source_table', 'legacy_source_id',
        'currency', 'po_id', 'po_revision_id', 'po_revision_number', 'request_variant',
        'legacy_parent_purchase_id', 'revision_reconciliation_id', 'po_snapshot_json',
        'purchase_number', 'po_percentage', 'expected_payment', 'remark',
        'transaction_date', 'date_received', 'payment_status', 'payment_status_source',
        'payment_status_updated_at', 'approval_status', 'handoff_status', 'handoff_revision',
        'account_request_type', 'account_request_id', 'previous_account_request_id',
        'approved_by', 'approved_at', 'approval_reversed_by', 'approval_reversed_at',
        'retrieved_by', 'retrieved_at', 'retrieval_reason', 'retrieval_source',
        'account_amount_paid', 'account_processing_method', 'account_processing_reference',
        'account_processing_started_at', 'account_expected_completion_at', 'account_completion_mode',
        'account_confirmation_status', 'account_payment_reference', 'account_paid_at',
        'account_payment_remarks', 'account_payment_batch_id', 'created_by', 'created_at',
        'updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'version',
    ];
    if (!procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $required)) {
        if (procurementRequestCanonicalRuntimeObjectType($conn, 'procurement_requests') !== 'BASE TABLE') {
            throw new RuntimeException('Canonical FX Advance Purchase storage is unavailable.', 500);
        }
        $missing = array_values(array_filter(
            $required,
            static fn(string $column): bool => !procurementRequestCanonicalRuntimeColumnExists(
                $conn,
                'procurement_requests',
                $column
            )
        ));
        if ($missing !== []) {
            throw new RuntimeException(
                'Canonical FX Advance Purchase storage is incomplete: ' . implode(', ', $missing),
                500
            );
        }
    }

    foreach (['procurement_local_advance_pos', 'procurement_local_advance_po_revisions', 'procurement_local_advance_po_revision_reconciliations'] as $table) {
        if (procurementRequestCanonicalRuntimeColumnsReady($conn, $table, ['request_scope', 'currency'])) {
            continue;
        }
        if (procurementRequestCanonicalRuntimeObjectType($conn, $table) !== 'BASE TABLE') {
            throw new RuntimeException('Shared Advance PO storage is incomplete: ' . $table, 500);
        }
        foreach (['request_scope', 'currency'] as $column) {
            if (!procurementRequestCanonicalRuntimeColumnExists($conn, $table, $column)) {
                throw new RuntimeException('Shared Advance PO storage is missing ' . $table . '.' . $column, 500);
            }
        }
    }
}

function procurementRequestCanonicalFxAdvanceScopeSql(string $alias = ''): string
{
    $prefix = trim($alias) === '' ? '' : rtrim(trim($alias), '.') . '.';
    return $prefix . "request_type = '" . PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE . "'"
        . ' AND ' . $prefix . "legacy_source_table = '" . PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE . "'";
}

function procurementRequestCanonicalFxAdvanceReadRelation(): string
{
    return "(
        SELECT
            legacy_source_id AS id,
            id AS canonical_request_id,
            request_number,
            currency,
            po_id,
            po_revision_id,
            po_revision_number,
            request_variant AS request_type,
            legacy_parent_purchase_id AS parent_purchase_id,
            revision_reconciliation_id,
            po_snapshot_json,
            purchase_number,
            po_percentage,
            expected_payment,
            account_wht_override_status,
            account_wht_override_rate,
            account_wht_override_amount,
            account_expected_payment,
            account_wht_adjustment_reason,
            account_wht_adjusted_by,
            account_wht_adjusted_at,
            remark,
            transaction_date,
            date_received,
            payment_status,
            payment_status_source,
            payment_status_updated_at,
            approval_status,
            handoff_status,
            handoff_revision,
            account_request_type,
            account_request_id AS fx_fund_request_id,
            previous_account_request_id AS previous_fx_fund_request_id,
            approved_by,
            approved_at,
            approval_reversed_by,
            approval_reversed_at,
            retrieved_by,
            retrieved_at,
            retrieval_reason,
            retrieval_source,
            account_amount_paid,
            account_processing_method,
            account_processing_reference,
            account_processing_started_at,
            account_expected_completion_at,
            account_completion_mode,
            account_confirmation_status,
            account_payment_reference,
            account_paid_at,
            account_payment_remarks,
            account_payment_batch_id,
            created_by,
            created_at,
            updated_by,
            updated_at,
            deleted_by,
            deleted_at,
            version
        FROM procurement_requests
        WHERE request_type = 'fx_advance_purchase'
          AND legacy_source_table = 'canonical_fx_advance_purchase'
    )";
}
