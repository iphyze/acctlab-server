<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/procurementNotificationService.php';
require_once __DIR__ . '/procurementLocalAdvancePoAuditService.php';
require_once __DIR__ . '/procurementRequestCanonicalSyncService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/procurementCompassHandoffService.php';
require_once __DIR__ . '/advancePoReconciliationCanonicalRuntimeService.php';
require_once __DIR__ . '/procurementSupplierFinancialAdjustmentService.php';
require_once __DIR__ . '/procurementPurchaseReplacementService.php';

const PROCUREMENT_LOCAL_ADVANCE_PO_STATUSES = ['Closed', 'Unclosed', 'Partially Closed', 'Cancelled'];
const PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES = ['Pending', 'Processing', 'Paid', 'Failed', 'Cancelled'];
const PROCUREMENT_LOCAL_ADVANCE_APPROVAL_STATUSES = ['Unapproved', 'Approved'];
const PROCUREMENT_LOCAL_ADVANCE_HANDOFF_STATUSES = ['Not Sent', 'In Account', 'Retrieved'];
const PROCUREMENT_LOCAL_ADVANCE_PO_REVISION_STATUSES = ['Current', 'Draft', 'Pending Approval', 'Approved', 'Superseded', 'Rejected', 'Cancelled'];
const PROCUREMENT_LOCAL_ADVANCE_PO_REVISION_REQUEST_TYPES = ['Original', 'Supplementary', 'Recovery'];
const PROCUREMENT_LOCAL_ADVANCE_AMENDMENT_TYPES = ['Commercial', 'Value', 'Tax', 'Project', 'Scope', 'Supplier'];
const PROCUREMENT_LOCAL_ADVANCE_RECONCILIATION_DIRECTIONS = ['Increase', 'Decrease', 'No Change'];
const PROCUREMENT_LOCAL_ADVANCE_RECONCILIATION_STATUSES = ['Pending Supplementary Payment', 'Recovery Required', 'Resolved', 'Cancelled'];
const PROCUREMENT_LOCAL_ADVANCE_RECOVERY_RESOLUTION_TYPES = ['Supplier Credit', 'Refund', 'Recovery', 'Offset Against Future Payment'];
const PROCUREMENT_LOCAL_ADVANCE_MAX_BATCH = 100;
const PROCUREMENT_LOCAL_ADVANCE_PERCENT_SCALE = 1000000;
const PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX = 100000000;
const PROCUREMENT_ADVANCE_PO_SCOPE_LOCAL = 'local_advance_purchase';
const PROCUREMENT_ADVANCE_PO_SCOPE_FX = 'fx_advance_purchase';

function procurementLocalAdvanceStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function procurementLocalAdvanceUpper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function procurementLocalAdvanceSubstring(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function procurementLocalAdvanceTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($conn) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}

function procurementLocalAdvanceTableType(mysqli $conn, string $table): ?string
{
    $stmt = $conn->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return is_string($type) ? strtoupper($type) : null;
}

function procurementLocalAdvanceEnsureColumn(
    mysqli $conn,
    string $table,
    string $column,
    string $definition
): void {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();

    if (!$exists && procurementLocalAdvanceTableType($conn, $table) === 'VIEW') {
        throw new RuntimeException('Local Advance Purchase compatibility view is incomplete: ' . $column, 500);
    }
    if (!$exists && !$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        throw new RuntimeException('Unable to update Local Advance Purchase storage.', 500);
    }
}

function procurementLocalAdvanceEnsureIndex(
    mysqli $conn,
    string $table,
    string $index,
    string $definition
): void {
    if (procurementLocalAdvanceTableType($conn, $table) === 'VIEW') {
        return;
    }
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();

    if (!$exists && !$conn->query("ALTER TABLE `$table` ADD $definition")) {
        throw new RuntimeException('Unable to add the Local Advance Purchase Account index.', 500);
    }
}

function procurementAssertLocalAdvancePurchaseReadStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);

    $poColumns = [
        'id', 'request_scope', 'currency', 'po_number', 'po_number_normalized',
        'project_id', 'project_code', 'project_name', 'supplier_id', 'supplier_name',
        'supplier_ledger', 'wht_status', 'wht_rate', 'wht_amount', 'purchase_value',
        'po_subtotal', 'po_discount', 'po_other_charges', 'po_vat_status',
        'po_vat_rate', 'po_vat_amount', 'po_value', 'advance_base_amount',
        'po_status', 'version', 'current_revision_id', 'current_revision_number',
        'amendment_status',
    ];
    $revisionColumns = [
        'id', 'po_number', 'po_number_normalized', 'project_id', 'project_code',
        'project_name', 'supplier_id', 'supplier_name', 'supplier_ledger',
        'wht_status', 'wht_rate', 'wht_amount', 'purchase_value', 'po_subtotal',
        'po_discount', 'po_other_charges', 'po_vat_status', 'po_vat_rate',
        'po_vat_amount', 'po_value', 'advance_base_amount', 'revision_reference',
        'revision_status', 'snapshot_hash', 'is_locked', 'locked_at', 'locked_reason',
    ];
    $accountColumns = [
        'id', 'advance_payment', 'payment_status', 'amount_paid', 'processing_method',
        'processing_reference', 'processing_started_at', 'expected_completion_at',
        'completion_mode', 'payment_confirmation_status', 'payment_reference', 'paid_at',
        'account_remarks', 'payment_batch_id', 'procurement_source', 'procurement_purchase_id',
    ];

    $ready = procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_local_advance_pos', $poColumns)
        && procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_local_advance_po_revisions', $revisionColumns)
        && procurementRequestCanonicalRuntimeColumnsReady($conn, 'advance_payment_request', $accountColumns);

    if (!$ready) {
        procurementLocalAdvanceEnsureStorage($conn);
        return;
    }

    procurementCompassAssertStorageReady($conn);
}

function procurementLocalAdvanceEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    procurementSupplierAdjustmentEnsureStorage($conn);

    $queries = [
        "CREATE TABLE IF NOT EXISTS procurement_local_advance_pos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(120) NOT NULL,
            po_number_normalized VARCHAR(120) NOT NULL,
            project_id INT NOT NULL,
            project_code VARCHAR(255) NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            supplier_id INT NOT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            supplier_ledger VARCHAR(120) NOT NULL,
            wht_status VARCHAR(40) NOT NULL,
            wht_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
            wht_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            purchase_value DECIMAL(18,2) NULL,
            po_subtotal DECIMAL(18,2) NOT NULL,
            po_discount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_vat_status VARCHAR(10) NOT NULL,
            po_vat_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
            po_vat_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_value DECIMAL(18,2) NOT NULL,
            advance_base_amount DECIMAL(18,2) NOT NULL,
            po_status VARCHAR(30) NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            current_revision_id BIGINT UNSIGNED NULL,
            current_revision_number INT UNSIGNED NOT NULL DEFAULT 1,
            amendment_status VARCHAR(30) NOT NULL DEFAULT 'None',
            UNIQUE KEY uq_local_advance_po_number (po_number_normalized),
            INDEX idx_local_advance_po_status (po_status, updated_at),
            INDEX idx_local_advance_po_project (project_id, project_code),
            INDEX idx_local_advance_po_supplier (supplier_id, supplier_ledger)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_local_advance_po_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_id BIGINT UNSIGNED NOT NULL,
            revision_number INT UNSIGNED NOT NULL,
            revision_reference VARCHAR(160) NOT NULL,
            previous_revision_id BIGINT UNSIGNED NULL,
            revision_status VARCHAR(30) NOT NULL DEFAULT 'Current',
            amendment_type VARCHAR(40) NOT NULL DEFAULT 'Initial',
            amendment_reason TEXT NULL,
            po_number VARCHAR(120) NOT NULL,
            po_number_normalized VARCHAR(120) NOT NULL,
            project_id INT NOT NULL,
            project_code VARCHAR(255) NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            supplier_id INT NOT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            supplier_ledger VARCHAR(120) NOT NULL,
            wht_status VARCHAR(40) NOT NULL,
            wht_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
            wht_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            purchase_value DECIMAL(18,2) NULL,
            po_subtotal DECIMAL(18,2) NOT NULL,
            po_discount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_vat_status VARCHAR(10) NOT NULL,
            po_vat_rate DECIMAL(8,6) NOT NULL DEFAULT 0.000000,
            po_vat_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_value DECIMAL(18,2) NOT NULL,
            advance_base_amount DECIMAL(18,2) NOT NULL,
            po_status VARCHAR(30) NOT NULL,
            commercial_snapshot_json LONGTEXT NOT NULL,
            change_summary_json LONGTEXT NULL,
            snapshot_hash CHAR(64) NOT NULL,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            locked_at DATETIME NULL,
            locked_reason VARCHAR(120) NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_by INT NULL,
            submitted_at DATETIME NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            rejection_reason TEXT NULL,
            cancelled_by INT NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason TEXT NULL,
            superseded_at DATETIME NULL,
            updated_by INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_local_advance_po_revision (po_id, revision_number),
            UNIQUE KEY uq_local_advance_po_revision_ref (revision_reference),
            INDEX idx_local_advance_po_revision_status (po_id, revision_status, created_at),
            INDEX idx_local_advance_po_revision_previous (previous_revision_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_local_advance_po_revision_reconciliations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_id BIGINT UNSIGNED NOT NULL,
            revision_id BIGINT UNSIGNED NOT NULL,
            previous_revision_id BIGINT UNSIGNED NULL,
            previous_po_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            revised_po_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            po_value_delta DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            previous_advance_base_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            revised_advance_base_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            advance_base_delta DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            allocated_percentage_at_revision DECIMAL(9,6) NOT NULL DEFAULT 0.000000,
            previous_committed_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            revised_committed_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            total_paid_at_revision DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            total_processing_at_revision DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            total_pending_at_revision DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            pending_reallocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            supplementary_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            supplementary_amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            recovery_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            reconciliation_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            reconciliation_direction VARCHAR(20) NOT NULL DEFAULT 'No Change',
            reconciliation_status VARCHAR(40) NOT NULL DEFAULT 'Pending',
            resolution_type VARCHAR(40) NULL,
            supplementary_purchase_id BIGINT UNSIGNED NULL,
            supplementary_advance_payment_request_id INT NULL,
            account_reconciliation_id BIGINT UNSIGNED NULL,
            account_sync_status VARCHAR(40) NOT NULL DEFAULT 'Pending',
            account_synced_by INT NULL,
            account_synced_at DATETIME NULL,
            account_sync_error TEXT NULL,
            recovery_reference VARCHAR(160) NULL,
            resolution_notes TEXT NULL,
            allocation_snapshot_json LONGTEXT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_by INT NULL,
            resolved_at DATETIME NULL,
            updated_by INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_local_advance_revision_reconciliation (revision_id),
            UNIQUE KEY uq_local_advance_account_reconciliation_id (account_reconciliation_id),
            INDEX idx_local_advance_reconciliation_po (po_id, reconciliation_status),
            INDEX idx_local_advance_reconciliation_direction (reconciliation_direction, reconciliation_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS account_advance_po_reconciliation_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_reconciliation_id BIGINT UNSIGNED NOT NULL,
            procurement_purchase_id BIGINT UNSIGNED NOT NULL,
            advance_payment_request_id INT NULL,
            request_type VARCHAR(30) NOT NULL DEFAULT 'Original',
            allocation_action VARCHAR(40) NOT NULL,
            payment_status VARCHAR(30) NOT NULL,
            exact_po_revision_id BIGINT UNSIGNED NULL,
            exact_po_revision_number INT UNSIGNED NULL,
            current_po_revision_id BIGINT UNSIGNED NOT NULL,
            current_po_revision_number INT UNSIGNED NOT NULL,
            previous_expected_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            revised_expected_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            amount_processing DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            amount_pending DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            recovery_allocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_account_advance_po_allocation
                (account_reconciliation_id, procurement_purchase_id, allocation_action),
            INDEX idx_account_advance_po_allocation_request (advance_payment_request_id),
            INDEX idx_account_advance_po_allocation_purchase
                (procurement_purchase_id, current_po_revision_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        if (!$conn->query($query)) {
            throw new RuntimeException(
                'Local Advance Purchase storage could not be initialized. Apply database/procurement_local_advance_purchase_migration.sql.',
                500
            );
        }
    }

    if (!procurementLocalAdvanceTableExists($conn, 'advance_payment_request')) {
        throw new RuntimeException('The AcctLab Advance Fund Request table could not be found.', 500);
    }
    procurementCompassAssertStorageReady($conn);

    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_pos', 'current_revision_id', "BIGINT UNSIGNED NULL AFTER `version`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_pos', 'current_revision_number', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `current_revision_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_pos', 'amendment_status', "VARCHAR(30) NOT NULL DEFAULT 'None' AFTER `current_revision_number`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_pos', 'request_scope', "VARCHAR(40) NOT NULL DEFAULT 'local_advance_purchase' AFTER `id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_pos', 'currency', "CHAR(3) NOT NULL DEFAULT 'NGN' AFTER `request_scope`");

    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'request_scope', "VARCHAR(40) NOT NULL DEFAULT 'local_advance_purchase' AFTER `po_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'currency', "CHAR(3) NOT NULL DEFAULT 'NGN' AFTER `request_scope`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'submitted_by', "INT NULL AFTER `created_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'submitted_at', "DATETIME NULL AFTER `submitted_by`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'rejected_by', "INT NULL AFTER `approved_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'rejected_at', "DATETIME NULL AFTER `rejected_by`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'rejection_reason', "TEXT NULL AFTER `rejected_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'cancelled_by', "INT NULL AFTER `rejection_reason`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'cancelled_at', "DATETIME NULL AFTER `cancelled_by`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revisions', 'cancellation_reason', "TEXT NULL AFTER `cancelled_at`");

    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'request_scope', "VARCHAR(40) NOT NULL DEFAULT 'local_advance_purchase' AFTER `po_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'currency', "CHAR(3) NOT NULL DEFAULT 'NGN' AFTER `request_scope`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'allocated_percentage_at_revision', "DECIMAL(9,6) NOT NULL DEFAULT 0.000000 AFTER `advance_base_delta`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'previous_committed_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `allocated_percentage_at_revision`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'revised_committed_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `previous_committed_amount`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'total_pending_at_revision', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_processing_at_revision`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'pending_reallocated_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_pending_at_revision`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'supplementary_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `pending_reallocated_amount`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'supplementary_amount_paid', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `supplementary_amount`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'recovery_amount', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `supplementary_amount_paid`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'supplementary_advance_payment_request_id', "INT NULL AFTER `supplementary_purchase_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'account_reconciliation_id', "BIGINT UNSIGNED NULL AFTER `supplementary_advance_payment_request_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'account_sync_status', "VARCHAR(40) NOT NULL DEFAULT 'Pending' AFTER `account_reconciliation_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'account_synced_by', "INT NULL AFTER `account_sync_status`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'account_synced_at', "DATETIME NULL AFTER `account_synced_by`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'account_sync_error', "TEXT NULL AFTER `account_synced_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'procurement_local_advance_po_revision_reconciliations', 'allocation_snapshot_json', "LONGTEXT NULL AFTER `resolution_notes`");
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'procurement_local_advance_po_revision_reconciliations',
        'uq_local_advance_account_reconciliation_id',
        'UNIQUE KEY `uq_local_advance_account_reconciliation_id` (`account_reconciliation_id`)'
    );


    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_status', "VARCHAR(40) NULL AFTER `vat_status`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_rate', "DECIMAL(8,6) NOT NULL DEFAULT 0.000000 AFTER `wht_status`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht', "VARCHAR(255) NOT NULL DEFAULT '0.00' AFTER `wht_rate`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'vat_rate', "DECIMAL(8,6) NOT NULL DEFAULT 0.000000 AFTER `vat`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_source', "VARCHAR(40) NULL AFTER `payment_status`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_purchase_id', "BIGINT UNSIGNED NULL AFTER `procurement_source`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_root_po_id', "BIGINT UNSIGNED NULL AFTER `procurement_purchase_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_request_type', "VARCHAR(30) NOT NULL DEFAULT 'Original' AFTER `procurement_root_po_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_parent_purchase_id', "BIGINT UNSIGNED NULL AFTER `procurement_request_type`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_reconciliation_id', "BIGINT UNSIGNED NULL AFTER `procurement_parent_purchase_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_revision', "INT UNSIGNED NULL AFTER `procurement_reconciliation_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_po_revision_id', "BIGINT UNSIGNED NULL AFTER `procurement_revision`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_po_revision_number', "INT UNSIGNED NULL AFTER `procurement_po_revision_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_po_snapshot_json', "LONGTEXT NULL AFTER `procurement_po_revision_number`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_current_po_revision_id', "BIGINT UNSIGNED NULL AFTER `procurement_po_snapshot_json`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'procurement_current_po_revision_number', "INT UNSIGNED NULL AFTER `procurement_current_po_revision_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'processing_method', "VARCHAR(30) NULL AFTER `procurement_revision`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'processing_reference', "VARCHAR(160) NULL AFTER `processing_method`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'processing_started_at', "DATETIME NULL AFTER `processing_reference`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'processing_business_days', "TINYINT UNSIGNED NULL AFTER `processing_started_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'expected_completion_at', "DATETIME NULL AFTER `processing_business_days`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'completion_mode', "VARCHAR(30) NULL AFTER `expected_completion_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'payment_confirmation_status', "VARCHAR(40) NOT NULL DEFAULT 'Not Scheduled' AFTER `completion_mode`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'amount_paid', "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `payment_confirmation_status`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'paid_at', "DATETIME NULL AFTER `amount_paid`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'payment_reference', "VARCHAR(160) NULL AFTER `paid_at`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'account_remarks', "TEXT NULL AFTER `payment_reference`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'payment_batch_id', "BIGINT UNSIGNED NULL AFTER `account_remarks`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'payment_updated_by', "INT NULL AFTER `payment_batch_id`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'payment_updated_at', "DATETIME NULL AFTER `payment_updated_by`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_override_status', "VARCHAR(40) NULL AFTER `wht`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_override_rate', "DECIMAL(8,6) NULL AFTER `wht_override_status`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_override_amount', "DECIMAL(18,2) NULL AFTER `wht_override_rate`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_override_reason', "TEXT NULL AFTER `wht_override_amount`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_override_by', "INT NULL AFTER `wht_override_reason`");
    procurementLocalAdvanceEnsureColumn($conn, 'advance_payment_request', 'wht_override_at', "DATETIME NULL AFTER `wht_override_by`");
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'advance_payment_request',
        'idx_advance_procurement_po_revision',
        'INDEX `idx_advance_procurement_po_revision` (`procurement_po_revision_id`, `procurement_po_revision_number`)'
    );
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'advance_payment_request',
        'idx_advance_procurement_current_po_revision',
        'INDEX `idx_advance_procurement_current_po_revision` (`procurement_current_po_revision_id`, `procurement_current_po_revision_number`)'
    );
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'advance_payment_request',
        'idx_advance_procurement_reconciliation',
        'INDEX `idx_advance_procurement_reconciliation` (`procurement_reconciliation_id`, `procurement_request_type`)'
    );
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'procurement_local_advance_po_revisions',
        'idx_local_advance_revision_review',
        'INDEX `idx_local_advance_revision_review` (`revision_status`, `submitted_at`, `po_id`)'
    );
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'procurement_local_advance_po_revision_reconciliations',
        'idx_local_advance_reconciliation_revision_status',
        'INDEX `idx_local_advance_reconciliation_revision_status` (`revision_id`, `reconciliation_status`)'
    );

    procurementLocalAdvanceEnsurePoRevisionBackfill($conn);

    procurementLocalAdvanceEnsureIndex(
        $conn,
        'advance_payment_request',
        'uq_advance_procurement_purchase',
        'UNIQUE KEY `uq_advance_procurement_purchase` (`procurement_source`, `procurement_purchase_id`)'
    );
    procurementLocalAdvanceEnsureIndex(
        $conn,
        'advance_payment_request',
        'idx_advance_procurement_status',
        'INDEX `idx_advance_procurement_status` (`procurement_source`, `payment_status`, `procurement_purchase_id`)'
    );

    $advanceScope = procurementRequestCanonicalLocalAdvanceScopeSql();
    $conn->query(
        "UPDATE procurement_requests
         SET handoff_status = CASE
             WHEN account_request_id IS NOT NULL AND approval_status = 'Approved' THEN 'In Account'
             WHEN handoff_status IS NULL OR TRIM(handoff_status) = '' THEN 'Not Sent'
             ELSE handoff_status
         END,
         handoff_revision = CASE
             WHEN account_request_id IS NOT NULL AND handoff_revision = 0 THEN 1
             ELSE handoff_revision
         END
         WHERE {$advanceScope}"
    );
    $conn->query(
        "UPDATE advance_payment_request apr
         INNER JOIN procurement_requests r
            ON r.account_request_id = apr.id
           AND r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         SET apr.procurement_source = 'local_advance_purchase',
             apr.procurement_purchase_id = r.legacy_source_id,
             apr.procurement_root_po_id = r.po_id,
             apr.procurement_request_type = r.request_variant,
             apr.procurement_parent_purchase_id = r.legacy_parent_purchase_id,
             apr.procurement_reconciliation_id = r.revision_reconciliation_id,
             apr.procurement_revision = GREATEST(r.handoff_revision, 1),
             apr.procurement_po_revision_id = r.po_revision_id,
             apr.procurement_po_revision_number = r.po_revision_number,
             apr.procurement_po_snapshot_json = COALESCE(r.po_snapshot_json, pr.commercial_snapshot_json),
             apr.procurement_current_po_revision_id = p.current_revision_id,
             apr.procurement_current_po_revision_number = p.current_revision_number
         WHERE r.deleted_at IS NULL
           AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')"
    );
    $conn->query(
        "UPDATE compass_fund_request_table cfr
         INNER JOIN procurement_requests r
            ON r.account_request_id = cfr.id
           AND r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
           AND r.account_request_type = 'compass_fund_request'
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         SET cfr.procurement_source = 'local_advance_purchase',
             cfr.procurement_purchase_id = r.legacy_source_id,
             cfr.procurement_root_po_id = r.po_id,
             cfr.procurement_request_type = r.request_variant,
             cfr.procurement_parent_purchase_id = r.legacy_parent_purchase_id,
             cfr.procurement_reconciliation_id = r.revision_reconciliation_id,
             cfr.procurement_revision = GREATEST(r.handoff_revision, 1),
             cfr.procurement_po_revision_id = r.po_revision_id,
             cfr.procurement_po_revision_number = r.po_revision_number,
             cfr.procurement_po_snapshot_json = COALESCE(r.po_snapshot_json, pr.commercial_snapshot_json),
             cfr.procurement_current_po_revision_id = p.current_revision_id,
             cfr.procurement_current_po_revision_number = p.current_revision_number
         WHERE r.deleted_at IS NULL"
    );

    $ensured = true;
}

function procurementLocalAdvanceNormalizePoNumber(string $value): array
{
    $display = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($display === '') {
        throw new RuntimeException('PO Number is required.', 400);
    }
    if (procurementLocalAdvanceStringLength($display) > 120) {
        throw new RuntimeException('PO Number must not exceed 120 characters.', 400);
    }

    $normalized = procurementLocalAdvanceUpper(preg_replace('/[^[:alnum:]]+/u', '', $display) ?? '');
    if ($normalized === '') {
        throw new RuntimeException('PO Number is invalid.', 400);
    }
    return [$display, $normalized];
}

function procurementLocalAdvanceRequiredText(array $data, string $field, string $label, int $maxLength = 255): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException($label . ' is required.', 400);
    }
    if (procurementLocalAdvanceStringLength($value) > $maxLength) {
        throw new RuntimeException($label . ' must not exceed ' . $maxLength . ' characters.', 400);
    }
    return $value;
}

function procurementLocalAdvanceOptionalText(array $data, string $field, int $maxLength = 4000): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if (procurementLocalAdvanceStringLength($value) > $maxLength) {
        throw new RuntimeException(ucwords(str_replace('_', ' ', $field)) . ' is too long.', 400);
    }
    return $value;
}

function procurementLocalAdvanceMoneyToCents(mixed $value, string $label, bool $allowZero = true): int
{
    if (is_int($value)) {
        $raw = (string) $value;
    } elseif (is_float($value)) {
        $raw = number_format($value, 4, '.', '');
    } else {
        $raw = trim(str_replace([',', '₦'], '', (string) $value));
    }

    if ($raw === '' || !preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        throw new RuntimeException($label . ' must be a valid non-negative number.', 400);
    }

    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    if (strlen($whole) > 14) {
        throw new RuntimeException($label . ' is too large.', 400);
    }
    $fraction = preg_replace('/\D/', '', $fraction) ?? '';
    $firstTwo = str_pad(substr($fraction, 0, 2), 2, '0');
    $cents = ((int) $whole * 100) + (int) $firstTwo;
    if (isset($fraction[2]) && (int) $fraction[2] >= 5) {
        $cents++;
    }
    if (!$allowZero && $cents <= 0) {
        throw new RuntimeException($label . ' must be greater than zero.', 400);
    }
    return $cents;
}

function procurementLocalAdvanceOptionalMoneyToCents(mixed $value, string $label): ?int
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    return procurementLocalAdvanceMoneyToCents($value, $label);
}

function procurementLocalAdvanceCents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function procurementLocalAdvancePercentUnits(mixed $value): int
{
    $raw = trim(str_replace(['%', ','], '', (string) $value));
    if ($raw === '' || !preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        throw new RuntimeException('PO Percentage must be a valid number.', 400);
    }

    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $fraction = preg_replace('/\D/', '', $fraction) ?? '';
    $firstSix = str_pad(substr($fraction, 0, 6), 6, '0');
    $units = ((int) $whole * PROCUREMENT_LOCAL_ADVANCE_PERCENT_SCALE) + (int) $firstSix;
    if (isset($fraction[6]) && (int) $fraction[6] >= 5) {
        $units++;
    }
    if ($units <= 0 || $units > PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX) {
        throw new RuntimeException('PO Percentage must be greater than 0 and not exceed 100.', 400);
    }
    return $units;
}

function procurementLocalAdvancePercentString(int $units): string
{
    return number_format($units / PROCUREMENT_LOCAL_ADVANCE_PERCENT_SCALE, 6, '.', '');
}

function procurementLocalAdvanceRateString(int $basisPoints): string
{
    return number_format($basisPoints / 10000, 6, '.', '');
}

function procurementLocalAdvanceRateAmount(int $baseCents, int $basisPoints): int
{
    return intdiv(($baseCents * $basisPoints) + 5000, 10000);
}

function procurementLocalAdvanceMultiplyDivideRounded(int $amount, int $multiplier, int $divisor): int
{
    $quotient = intdiv($amount, $divisor);
    $remainder = $amount % $divisor;
    return ($quotient * $multiplier) + intdiv(($remainder * $multiplier) + intdiv($divisor, 2), $divisor);
}

function procurementLocalAdvanceVatStatus(mixed $value): array
{
    if (is_bool($value)) {
        return $value ? ['Yes', 750] : ['No', 0];
    }
    $status = strtolower(trim((string) $value));
    if (in_array($status, ['yes', 'yes - 7.5%', '7.5%', '7.50%', '1', 'true'], true)) {
        return ['Yes', 750];
    }
    if (in_array($status, ['no', 'no - 0.00%', '0', '0.0', '0.00%', 'false'], true)) {
        return ['No', 0];
    }
    throw new RuntimeException('PO VAT Status must be Yes or No.', 400);
}

function procurementLocalAdvanceWhtBasisPoints(string $whtStatus): int
{
    if (!preg_match('/(\d+(?:\.\d+)?)/', $whtStatus, $matches)) {
        return 0;
    }
    $percent = (float) $matches[1];
    if (abs($percent - 2.0) < 0.001) {
        return 200;
    }
    if (abs($percent - 5.0) < 0.001) {
        return 500;
    }
    return 0;
}

function procurementLocalAdvanceResolveProject(mysqli $conn, array $data): array
{
    $projectId = (int) ($data['project_id'] ?? 0);
    $projectCode = trim((string) ($data['project_code'] ?? ''));

    if ($projectId > 0) {
        $stmt = $conn->prepare('SELECT id, code, location FROM location_table WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $projectId);
    } elseif ($projectCode !== '') {
        $stmt = $conn->prepare('SELECT id, code, location FROM location_table WHERE code = ? LIMIT 1');
        $stmt->bind_param('s', $projectCode);
    } else {
        throw new RuntimeException('Project Code is required.', 400);
    }
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$project) {
        throw new RuntimeException('The selected project could not be found.', 404);
    }

    return [
        'id' => (int) $project['id'],
        'code' => trim((string) $project['code']),
        'name' => trim((string) $project['location']),
    ];
}

function procurementLocalAdvanceResolveSupplier(mysqli $conn, array $data): array
{
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $supplierLedger = trim((string) ($data['supplier_ledger'] ?? ''));
    $supplier = null;

    if ($supplierId > 0) {
        $stmt = $conn->prepare(
            'SELECT id, supplier_name, supplier_number, wht_status FROM suppliers_table WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $supplierId);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$supplier) {
            $stmt = $conn->prepare(
                'SELECT id, supplier_name, supplier_number, wht_status FROM suppliers_table WHERE supplier_number = ? LIMIT 1'
            );
            $stmt->bind_param('i', $supplierId);
            $stmt->execute();
            $supplier = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif ($supplierLedger !== '' && ctype_digit($supplierLedger)) {
        $ledger = (int) $supplierLedger;
        $stmt = $conn->prepare(
            'SELECT id, supplier_name, supplier_number, wht_status FROM suppliers_table WHERE supplier_number = ? LIMIT 1'
        );
        $stmt->bind_param('i', $ledger);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        throw new RuntimeException("Supplier's Name is required.", 400);
    }

    if (!$supplier) {
        throw new RuntimeException('The selected supplier could not be found.', 404);
    }

    $whtStatus = trim((string) ($supplier['wht_status'] ?? '0.00%')) ?: '0.00%';
    return [
        'id' => (int) $supplier['id'],
        'name' => trim((string) $supplier['supplier_name']),
        'ledger' => (string) $supplier['supplier_number'],
        'wht_status' => $whtStatus,
        'wht_basis_points' => procurementLocalAdvanceWhtBasisPoints($whtStatus),
    ];
}

function procurementLocalAdvanceValidateStatus(mixed $value, array $allowed, string $label): string
{
    $candidate = trim((string) $value);
    foreach ($allowed as $status) {
        if (strcasecmp($candidate, $status) === 0) {
            return $status;
        }
    }
    throw new RuntimeException($label . ' is invalid.', 400);
}

function procurementLocalAdvanceBuildPayload(mysqli $conn, array $data): array
{
    [$poNumber, $poNumberNormalized] = procurementLocalAdvanceNormalizePoNumber(
        (string) ($data['po_number'] ?? '')
    );
    $project = procurementLocalAdvanceResolveProject($conn, $data);
    $supplier = procurementLocalAdvanceResolveSupplier($conn, $data);

    $poSubtotal = procurementLocalAdvanceMoneyToCents($data['po_subtotal'] ?? null, 'PO Subtotal', false);
    $poDiscount = procurementLocalAdvanceMoneyToCents($data['po_discount'] ?? null, 'PO Discount');
    $poOtherCharges = procurementLocalAdvanceMoneyToCents($data['po_other_charges'] ?? null, 'PO Other Charges');
    if ($poDiscount > $poSubtotal) {
        throw new RuntimeException('PO Discount cannot be greater than PO Subtotal.', 400);
    }

    [$poVatStatus, $poVatBasisPoints] = procurementLocalAdvanceVatStatus($data['po_vat_status'] ?? null);
    $poNet = $poSubtotal - $poDiscount;
    $poVatAmount = procurementLocalAdvanceRateAmount($poNet, $poVatBasisPoints);

    // WHT is only applicable when VAT is charged. The supplier's configured WHT
    // remains the source of truth, but it becomes an effective 0% for non-VAT POs.
    $effectiveWhtBasisPoints = $poVatBasisPoints > 0
        ? (int) $supplier['wht_basis_points']
        : 0;
    $effectiveWhtStatus = $effectiveWhtBasisPoints > 0
        ? (string) $supplier['wht_status']
        : '0.00%';
    $whtAmount = procurementLocalAdvanceRateAmount($poNet, $effectiveWhtBasisPoints);
    $poValue = $poNet + $poOtherCharges + $poVatAmount;
    $advanceBase = $poNet + $poVatAmount - $whtAmount + $poOtherCharges;
    if ($advanceBase < 0) {
        throw new RuntimeException('Expected Payment base cannot be negative.', 400);
    }

    $percentageUnits = procurementLocalAdvancePercentUnits($data['po_percentage'] ?? $data['percentage'] ?? null);
    $expectedPayment = procurementLocalAdvanceMultiplyDivideRounded(
        $advanceBase,
        $percentageUnits,
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
    );
    $purchaseValue = procurementLocalAdvanceOptionalMoneyToCents($data['purchase_value'] ?? null, 'Purchase Value');

    $purchaseNumber = procurementLocalAdvanceOptionalText($data, 'purchase_number', 120);
    $remark = procurementLocalAdvanceOptionalText($data, 'remark');
    $poStatus = procurementLocalAdvanceValidateStatus(
        $data['po_status'] ?? '',
        PROCUREMENT_LOCAL_ADVANCE_PO_STATUSES,
        'PO Status'
    );

    return [
        'po_number' => $poNumber,
        'po_number_normalized' => $poNumberNormalized,
        'project_id' => $project['id'],
        'project_code' => $project['code'],
        'project_name' => $project['name'],
        'supplier_id' => $supplier['id'],
        'supplier_name' => $supplier['name'],
        'supplier_ledger' => $supplier['ledger'],
        'wht_status' => $effectiveWhtStatus,
        'wht_rate' => procurementLocalAdvanceRateString($effectiveWhtBasisPoints),
        'wht_amount' => procurementLocalAdvanceCents($whtAmount),
        'purchase_value' => $purchaseValue === null ? null : procurementLocalAdvanceCents($purchaseValue),
        'po_subtotal' => procurementLocalAdvanceCents($poSubtotal),
        'po_discount' => procurementLocalAdvanceCents($poDiscount),
        'po_other_charges' => procurementLocalAdvanceCents($poOtherCharges),
        'po_vat_status' => $poVatStatus,
        'po_vat_rate' => procurementLocalAdvanceRateString($poVatBasisPoints),
        'po_vat_amount' => procurementLocalAdvanceCents($poVatAmount),
        'po_value' => procurementLocalAdvanceCents($poValue),
        'advance_base_amount' => procurementLocalAdvanceCents($advanceBase),
        'po_status' => $poStatus,
        'purchase_number' => $purchaseNumber === '' ? null : $purchaseNumber,
        'po_percentage' => procurementLocalAdvancePercentString($percentageUnits),
        'percentage_units' => $percentageUnits,
        'expected_payment' => procurementLocalAdvanceCents($expectedPayment),
        'remark' => $remark,
    ];
}

function procurementLocalAdvancePoRevisionReference(string $poNumber, int $revisionNumber): string
{
    $reference = trim($poNumber) . '#REV-' . max(1, $revisionNumber);
    return procurementLocalAdvanceSubstring($reference, 160);
}

function procurementLocalAdvancePoCommercialSnapshot(array $po): array
{
    return [
        'request_scope' => (string) ($po['request_scope'] ?? PROCUREMENT_ADVANCE_PO_SCOPE_LOCAL),
        'currency' => (string) ($po['currency'] ?? 'NGN'),
        'po_number' => (string) ($po['po_number'] ?? ''),
        'po_number_normalized' => (string) ($po['po_number_normalized'] ?? ''),
        'project_id' => (int) ($po['project_id'] ?? 0),
        'project_code' => (string) ($po['project_code'] ?? ''),
        'project_name' => (string) ($po['project_name'] ?? ''),
        'supplier_id' => (int) ($po['supplier_id'] ?? 0),
        'supplier_name' => (string) ($po['supplier_name'] ?? ''),
        'supplier_ledger' => (string) ($po['supplier_ledger'] ?? ''),
        'wht_status' => (string) ($po['wht_status'] ?? '0.00%'),
        'wht_rate' => (string) ($po['wht_rate'] ?? '0.000000'),
        'wht_amount' => (string) ($po['wht_amount'] ?? '0.00'),
        'purchase_value' => $po['purchase_value'] ?? null,
        'po_subtotal' => (string) ($po['po_subtotal'] ?? '0.00'),
        'po_discount' => (string) ($po['po_discount'] ?? '0.00'),
        'po_other_charges' => (string) ($po['po_other_charges'] ?? '0.00'),
        'po_vat_status' => (string) ($po['po_vat_status'] ?? 'No'),
        'po_vat_rate' => (string) ($po['po_vat_rate'] ?? '0.000000'),
        'po_vat_amount' => (string) ($po['po_vat_amount'] ?? '0.00'),
        'po_value' => (string) ($po['po_value'] ?? '0.00'),
        'advance_base_amount' => (string) ($po['advance_base_amount'] ?? '0.00'),
        'po_status' => (string) ($po['po_status'] ?? 'Unclosed'),
    ];
}

function procurementLocalAdvancePoCommercialSnapshotJson(array $po): string
{
    return (string) json_encode(
        procurementLocalAdvancePoCommercialSnapshot($po),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function procurementLocalAdvancePoCommercialSnapshotHash(array $po): string
{
    return hash('sha256', procurementLocalAdvancePoCommercialSnapshotJson($po));
}

function procurementLocalAdvanceFetchPoRevision(
    mysqli $conn,
    int $revisionId,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions WHERE id = ? LIMIT 1$lock"
    );
    $stmt->bind_param('i', $revisionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementLocalAdvanceFetchPoRevisionByNumber(
    mysqli $conn,
    int $poId,
    int $revisionNumber,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions
         WHERE po_id = ? AND revision_number = ? LIMIT 1$lock"
    );
    $stmt->bind_param('ii', $poId, $revisionNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementLocalAdvanceEnsureInitialPoRevision(
    mysqli $conn,
    int $poId,
    ?int $actorId = null
): array {
    $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The Local Advance PO could not be found for revision initialization.', 409);
    }

    $existing = procurementLocalAdvanceFetchPoRevisionByNumber($conn, $poId, 1, true);
    if (!$existing) {
        $revisionReference = procurementLocalAdvancePoRevisionReference((string) $po['po_number'], 1);
        $snapshotJson = procurementLocalAdvancePoCommercialSnapshotJson($po);
        $snapshotHash = hash('sha256', $snapshotJson);
        $effectiveActorId = $actorId ?? (int) ($po['updated_by'] ?? $po['created_by'] ?? 0);

        $insert = $conn->prepare(
            "INSERT INTO procurement_local_advance_po_revisions
                (po_id, request_scope, currency, revision_number, revision_reference, previous_revision_id,
                 revision_status, amendment_type, amendment_reason,
                 po_number, po_number_normalized, project_id, project_code, project_name,
                 supplier_id, supplier_name, supplier_ledger, wht_status, wht_rate, wht_amount,
                 purchase_value, po_subtotal, po_discount, po_other_charges, po_vat_status,
                 po_vat_rate, po_vat_amount, po_value, advance_base_amount, po_status,
                 commercial_snapshot_json, snapshot_hash, is_locked,
                 created_by, created_at, updated_by, updated_at)
             SELECT p.id, p.request_scope, p.currency, 1, ?, NULL, 'Current', 'Initial', NULL,
                    p.po_number, p.po_number_normalized, p.project_id, p.project_code, p.project_name,
                    p.supplier_id, p.supplier_name, p.supplier_ledger, p.wht_status, p.wht_rate, p.wht_amount,
                    p.purchase_value, p.po_subtotal, p.po_discount, p.po_other_charges, p.po_vat_status,
                    p.po_vat_rate, p.po_vat_amount, p.po_value, p.advance_base_amount, p.po_status,
                    ?, ?, 0, ?, p.created_at, ?, p.updated_at
             FROM procurement_local_advance_pos p
             WHERE p.id = ?
             ON DUPLICATE KEY UPDATE revision_reference = VALUES(revision_reference)"
        );
        $insert->bind_param(
            'sssiii',
            $revisionReference,
            $snapshotJson,
            $snapshotHash,
            $effectiveActorId,
            $effectiveActorId,
            $poId
        );
        $insert->execute();
        $insert->close();
        $existing = procurementLocalAdvanceFetchPoRevisionByNumber($conn, $poId, 1, true);
    }

    if (!$existing) {
        throw new RuntimeException('The initial Local Advance PO revision could not be initialized.', 500);
    }

    $revisionId = (int) $existing['id'];
    $revisionNumber = (int) $existing['revision_number'];
    $update = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET current_revision_id = CASE
                 WHEN current_revision_id IS NULL OR current_revision_id = 0 THEN ?
                 ELSE current_revision_id
             END,
             current_revision_number = CASE
                 WHEN current_revision_id IS NULL OR current_revision_id = 0 THEN ?
                 ELSE current_revision_number
             END
         WHERE id = ?"
    );
    $update->bind_param('iii', $revisionId, $revisionNumber, $poId);
    $update->execute();
    $update->close();

    return $existing;
}

function procurementLocalAdvanceEnsurePoRevisionBackfill(mysqli $conn): void
{
    $rows = $conn->query(
        "SELECT p.id
         FROM procurement_local_advance_pos p
         LEFT JOIN procurement_local_advance_po_revisions r
           ON r.po_id = p.id AND r.revision_number = 1
         WHERE p.request_scope = 'local_advance_purchase'
           AND (r.id IS NULL OR p.current_revision_id IS NULL OR p.current_revision_id = 0)"
    );
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            procurementLocalAdvanceEnsureInitialPoRevision($conn, (int) $row['id']);
        }
        $rows->free();
    }

    $conn->query(
        "UPDATE procurement_requests purchase
         INNER JOIN procurement_local_advance_po_revisions revision
           ON revision.po_id = purchase.po_id AND revision.revision_number = 1
         SET purchase.po_revision_id = revision.id,
             purchase.po_revision_number = revision.revision_number,
             purchase.po_snapshot_json = COALESCE(purchase.po_snapshot_json, revision.commercial_snapshot_json)
         WHERE purchase.request_type = 'local_advance_purchase'
           AND purchase.legacy_source_table = 'procurement_local_advance_purchases'
           AND (purchase.po_revision_id IS NULL OR purchase.po_revision_id = 0)"
    );
    $conn->query(
        "UPDATE procurement_local_advance_po_revisions revision
         INNER JOIN procurement_requests purchase
           ON purchase.po_revision_id = revision.id
          AND purchase.request_type = 'local_advance_purchase'
          AND purchase.legacy_source_table = 'procurement_local_advance_purchases'
         SET revision.is_locked = 1,
             revision.locked_at = COALESCE(revision.locked_at, purchase.account_processing_started_at, purchase.account_paid_at, purchase.payment_status_updated_at, NOW()),
             revision.locked_reason = COALESCE(revision.locked_reason, 'Account payment activity recorded')
         WHERE purchase.deleted_at IS NULL
           AND (purchase.payment_status IN ('Processing', 'Paid') OR purchase.account_amount_paid > 0)"
    );
}

function procurementLocalAdvanceResolveCurrentPoRevision(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): array {
    $po = procurementLocalAdvanceFetchPo($conn, $poId, $forUpdate);
    if (!$po) {
        throw new RuntimeException('The linked Local Advance PO could not be found.', 409);
    }

    $revisionId = (int) ($po['current_revision_id'] ?? 0);
    $revision = $revisionId > 0
        ? procurementLocalAdvanceFetchPoRevision($conn, $revisionId, $forUpdate)
        : null;
    if (!$revision || (int) $revision['po_id'] !== $poId) {
        $revision = procurementLocalAdvanceEnsureInitialPoRevision(
            $conn,
            $poId,
            (int) ($po['updated_by'] ?? $po['created_by'] ?? 0)
        );
    }
    return $revision;
}

function procurementLocalAdvanceAssertPoRevisionEditable(mysqli $conn, int $poId): array
{
    $revision = procurementLocalAdvanceResolveCurrentPoRevision($conn, $poId, true);
    if ((int) ($revision['is_locked'] ?? 0) === 1) {
        throw new RuntimeException(
            'This PO revision is locked because Account has started or completed payment. Create a PO amendment instead of editing it directly.',
            409
        );
    }
    return $revision;
}

function procurementLocalAdvanceSyncCurrentPoRevisionFromPo(
    mysqli $conn,
    int $poId,
    int $actorId
): array {
    $revision = procurementLocalAdvanceAssertPoRevisionEditable($conn, $poId);
    $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The Local Advance PO could not be found.', 409);
    }

    $snapshotJson = procurementLocalAdvancePoCommercialSnapshotJson($po);
    $snapshotHash = hash('sha256', $snapshotJson);
    $revisionReference = procurementLocalAdvancePoRevisionReference(
        (string) $po['po_number'],
        (int) $revision['revision_number']
    );
    $revisionId = (int) $revision['id'];

    $update = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions revision
         INNER JOIN procurement_local_advance_pos po ON po.id = revision.po_id
         SET revision.revision_reference = ?,
             revision.po_number = po.po_number,
             revision.po_number_normalized = po.po_number_normalized,
             revision.project_id = po.project_id,
             revision.project_code = po.project_code,
             revision.project_name = po.project_name,
             revision.supplier_id = po.supplier_id,
             revision.supplier_name = po.supplier_name,
             revision.supplier_ledger = po.supplier_ledger,
             revision.wht_status = po.wht_status,
             revision.wht_rate = po.wht_rate,
             revision.wht_amount = po.wht_amount,
             revision.purchase_value = po.purchase_value,
             revision.po_subtotal = po.po_subtotal,
             revision.po_discount = po.po_discount,
             revision.po_other_charges = po.po_other_charges,
             revision.po_vat_status = po.po_vat_status,
             revision.po_vat_rate = po.po_vat_rate,
             revision.po_vat_amount = po.po_vat_amount,
             revision.po_value = po.po_value,
             revision.advance_base_amount = po.advance_base_amount,
             revision.po_status = po.po_status,
             revision.commercial_snapshot_json = ?,
             revision.snapshot_hash = ?,
             revision.updated_by = ?,
             revision.updated_at = NOW(),
             revision.version = revision.version + 1
         WHERE revision.id = ? AND revision.is_locked = 0"
    );
    $update->bind_param('sssii', $revisionReference, $snapshotJson, $snapshotHash, $actorId, $revisionId);
    $update->execute();
    $update->close();

    return procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true) ?? $revision;
}

function procurementLocalAdvanceLockPoRevision(
    mysqli $conn,
    int $revisionId,
    string $reason = 'Account handoff created'
): array {
    $revision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The linked PO revision could not be found.', 409);
    }

    $reason = procurementLocalAdvanceSubstring(trim($reason), 120);
    if ($reason === '') {
        $reason = 'Account handoff created';
    }

    $update = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET is_locked = 1,
             locked_at = COALESCE(locked_at, NOW()),
             locked_reason = COALESCE(locked_reason, ?),
             updated_at = NOW()
         WHERE id = ?"
    );
    $update->bind_param('si', $reason, $revisionId);
    $update->execute();
    $update->close();

    return procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true) ?? $revision;
}

function procurementLocalAdvanceLockCurrentPoRevision(
    mysqli $conn,
    int $poId,
    string $reason = 'Account handoff created'
): array {
    $revision = procurementLocalAdvanceResolveCurrentPoRevision($conn, $poId, true);
    return procurementLocalAdvanceLockPoRevision($conn, (int) $revision['id'], $reason);
}

function procurementLocalAdvanceFetchPoByNormalized(mysqli $conn, string $normalized, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_pos WHERE request_scope = 'local_advance_purchase' AND po_number_normalized = ? LIMIT 1$lock"
    );
    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementLocalAdvanceFetchPo(mysqli $conn, int $poId, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare("SELECT * FROM procurement_local_advance_pos WHERE request_scope = 'local_advance_purchase' AND id = ? LIMIT 1$lock");
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementLocalAdvanceCreatePo(mysqli $conn, array $payload, int $actorId): array
{
    $stmt = $conn->prepare(
        "INSERT INTO procurement_local_advance_pos
            (request_scope, currency, po_number, po_number_normalized, project_id, project_code, project_name,
             supplier_id, supplier_name, supplier_ledger, wht_status, wht_rate, wht_amount,
             purchase_value, po_subtotal, po_discount, po_other_charges, po_vat_status,
             po_vat_rate, po_vat_amount, po_value, advance_base_amount, po_status,
             created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $params = [
        PROCUREMENT_ADVANCE_PO_SCOPE_LOCAL, 'NGN',
        $payload['po_number'], $payload['po_number_normalized'], $payload['project_id'],
        $payload['project_code'], $payload['project_name'], $payload['supplier_id'],
        $payload['supplier_name'], $payload['supplier_ledger'], $payload['wht_status'],
        $payload['wht_rate'], $payload['wht_amount'], $payload['purchase_value'],
        $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'],
        $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'],
        $payload['po_value'], $payload['advance_base_amount'], $payload['po_status'],
        $actorId, $actorId,
    ];
    $types = 'ssssississsssssssssssssii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $poId = (int) $conn->insert_id;
    $created = $stmt->affected_rows === 1;
    $stmt->close();

    $revision = $created
        ? procurementLocalAdvanceEnsureInitialPoRevision($conn, $poId, $actorId)
        : procurementLocalAdvanceResolveCurrentPoRevision($conn, $poId, true);
    return [
        'id' => $poId,
        'created' => $created,
        'revision_id' => (int) $revision['id'],
        'revision_number' => (int) $revision['revision_number'],
        'revision_snapshot_json' => (string) $revision['commercial_snapshot_json'],
    ];
}

function procurementLocalAdvanceCommercialComparison(array $po, array $payload): array
{
    $fields = [
        'project_id', 'project_code', 'project_name', 'supplier_id', 'supplier_name',
        'supplier_ledger', 'wht_status', 'wht_rate', 'wht_amount', 'purchase_value',
        'po_subtotal', 'po_discount', 'po_other_charges', 'po_vat_status', 'po_vat_rate',
        'po_vat_amount', 'po_value', 'advance_base_amount',
    ];
    $differences = [];
    foreach ($fields as $field) {
        $existing = $po[$field] ?? null;
        $incoming = $payload[$field] ?? null;
        if ($existing === null && $incoming === null) {
            continue;
        }
        if ((string) $existing !== (string) $incoming) {
            $differences[] = $field;
        }
    }
    return $differences;
}

function procurementLocalAdvanceAssertExistingPoCompatible(array $po, array $payload): void
{
    if ((string) $po['po_status'] !== (string) $payload['po_status']) {
        throw new RuntimeException(
            'This PO already exists with status ' . $po['po_status'] . '. Use the PO status action to change it.',
            409
        );
    }
    $differences = procurementLocalAdvanceCommercialComparison($po, $payload);
    if ($differences !== []) {
        throw new RuntimeException(
            'This PO already exists with different project, supplier, tax or value details. Reuse the existing PO details.',
            409
        );
    }
}

function procurementLocalAdvanceAssertPoOpen(array $po): void
{
    if (in_array((string) ($po['po_status'] ?? ''), ['Closed', 'Cancelled'], true)) {
        throw new RuntimeException('A Closed or Cancelled PO cannot receive another advance request.', 409);
    }
}

/**
 * Batch equivalent of procurementLocalAdvanceAllocatedUnits() for register reads.
 *
 * PO allocation is a ProcureDesk procurement concept and must be derived only
 * from canonical Local Advance Purchase requests for the PO. Downstream/manual
 * AcctLab Advance Fund Requests are intentionally excluded so they cannot
 * inflate the ProcureDesk cumulative percentage simply because they reuse the
 * same PO number. This matches the FX Advance allocation model.
 *
 * @param array<int> $poIds
 * @return array<int,int> map of PO id => allocated percentage units
 */
function procurementLocalAdvanceAllocatedUnitsForPoIds(mysqli $conn, array $poIds): array
{
    $poIds = array_values(array_unique(array_filter(
        array_map(static fn(mixed $value): int => (int) $value, $poIds),
        static fn(int $value): bool => $value > 0
    )));
    if ($poIds === []) {
        return [];
    }

    $allocatedByPo = array_fill_keys($poIds, 0);
    $placeholders = implode(',', array_fill(0, count($poIds), '?'));
    $idTypes = str_repeat('i', count($poIds));
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();

    $stmt = $conn->prepare(
        "SELECT source.po_id, COALESCE(SUM(source.po_percentage), 0) AS allocated
         FROM {$relation} source
         WHERE source.po_id IN ({$placeholders})
           AND source.deleted_at IS NULL
           AND source.payment_status <> 'Cancelled'
         GROUP BY source.po_id"
    );
    $stmt->bind_param($idTypes, ...$poIds);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $poId = (int) ($row['po_id'] ?? 0);
        if ($poId > 0 && array_key_exists($poId, $allocatedByPo)) {
            $allocatedByPo[$poId] = procurementLocalAdvancePercentUnitsAllowZero(
                $row['allocated'] ?? 0
            );
        }
    }
    $stmt->close();

    return $allocatedByPo;
}

function procurementLocalAdvanceAllocatedUnits(mysqli $conn, int $poId, ?int $excludePurchaseId = null): int
{
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    if ($excludePurchaseId !== null) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(po_percentage), 0) AS allocated
             FROM {$relation} source
             WHERE po_id = ? AND id <> ? AND deleted_at IS NULL AND payment_status <> 'Cancelled'"
        );
        $stmt->bind_param('ii', $poId, $excludePurchaseId);
    } else {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(po_percentage), 0) AS allocated
             FROM {$relation} source
             WHERE po_id = ? AND deleted_at IS NULL AND payment_status <> 'Cancelled'"
        );
        $stmt->bind_param('i', $poId);
    }
    $stmt->execute();
    $allocated = (string) ($stmt->get_result()->fetch_assoc()['allocated'] ?? '0');
    $stmt->close();

    return procurementLocalAdvancePercentUnitsAllowZero($allocated);
}

function procurementLocalAdvancePercentUnitsAllowZero(mixed $value): int
{
    $raw = trim(str_replace(['%', ','], '', (string) $value));
    if ($raw === '' || !preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        return 0;
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $fraction = preg_replace('/\D/', '', $fraction) ?? '';
    return ((int) $whole * PROCUREMENT_LOCAL_ADVANCE_PERCENT_SCALE)
        + (int) str_pad(substr($fraction, 0, 6), 6, '0');
}

function procurementLocalAdvanceAssertAllocationAvailable(
    mysqli $conn,
    int $poId,
    int $requestedUnits,
    ?int $excludePurchaseId = null
): array {
    $allocatedUnits = procurementLocalAdvanceAllocatedUnits($conn, $poId, $excludePurchaseId);
    $totalUnits = $allocatedUnits + $requestedUnits;
    if ($totalUnits > PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX) {
        $availableUnits = max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $allocatedUnits);
        throw new RuntimeException(
            'The requested percentage exceeds the available ' . rtrim(rtrim(procurementLocalAdvancePercentString($availableUnits), '0'), '.') . '% for this PO.',
            409
        );
    }
    return [
        'allocated_units' => $allocatedUnits,
        'requested_units' => $requestedUnits,
        'total_units' => $totalUnits,
        'available_units' => PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $totalUnits,
    ];
}

function procurementLocalAdvanceCountOtherActiveRequests(mysqli $conn, int $poId, int $excludeId): int
{
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM {$relation} source
         WHERE po_id = ? AND id <> ? AND deleted_at IS NULL"
    );
    $stmt->bind_param('ii', $poId, $excludeId);
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function procurementLocalAdvanceUpdatePoCommercials(
    mysqli $conn,
    int $poId,
    array $payload,
    int $actorId
): void {
    procurementLocalAdvanceAssertPoRevisionEditable($conn, $poId);

    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_pos SET
            po_number = ?, po_number_normalized = ?, project_id = ?, project_code = ?, project_name = ?,
            supplier_id = ?, supplier_name = ?, supplier_ledger = ?, wht_status = ?, wht_rate = ?,
            wht_amount = ?, purchase_value = ?, po_subtotal = ?, po_discount = ?, po_other_charges = ?,
            po_vat_status = ?, po_vat_rate = ?, po_vat_amount = ?, po_value = ?, advance_base_amount = ?,
            updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ?"
    );
    $params = [
        $payload['po_number'], $payload['po_number_normalized'], $payload['project_id'],
        $payload['project_code'], $payload['project_name'], $payload['supplier_id'],
        $payload['supplier_name'], $payload['supplier_ledger'], $payload['wht_status'],
        $payload['wht_rate'], $payload['wht_amount'], $payload['purchase_value'],
        $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'],
        $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'],
        $payload['po_value'], $payload['advance_base_amount'], $actorId, $poId,
    ];
    $types = 'ssississssssssssssssii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    procurementLocalAdvanceSyncCurrentPoRevisionFromPo($conn, $poId, $actorId);
}

function procurementLocalAdvanceAmendmentType(mixed $value): string
{
    $candidate = trim((string) $value);
    if ($candidate === '') {
        return 'Commercial';
    }
    return procurementLocalAdvanceValidateStatus(
        $candidate,
        PROCUREMENT_LOCAL_ADVANCE_AMENDMENT_TYPES,
        'Amendment Type'
    );
}

function procurementLocalAdvanceBuildAmendmentPayload(
    mysqli $conn,
    array $currentRevision,
    array $data
): array {
    if (array_key_exists('po_number', $data) && trim((string) $data['po_number']) !== '') {
        [, $incomingNormalized] = procurementLocalAdvanceNormalizePoNumber((string) $data['po_number']);
        if ($incomingNormalized !== (string) $currentRevision['po_number_normalized']) {
            throw new RuntimeException(
                'The PO Number cannot be changed by amendment. Cancel or reverse the old PO and create a new PO instead.',
                409
            );
        }
    }

    $merged = [
        'po_number' => (string) $currentRevision['po_number'],
        'project_id' => array_key_exists('project_id', $data)
            ? (int) $data['project_id']
            : (int) $currentRevision['project_id'],
        'supplier_id' => array_key_exists('supplier_id', $data)
            ? (int) $data['supplier_id']
            : (int) $currentRevision['supplier_id'],
        'po_subtotal' => $data['po_subtotal'] ?? $currentRevision['po_subtotal'],
        'po_discount' => $data['po_discount'] ?? $currentRevision['po_discount'],
        'po_other_charges' => $data['po_other_charges'] ?? $currentRevision['po_other_charges'],
        'po_vat_status' => $data['po_vat_status'] ?? $currentRevision['po_vat_status'],
        'purchase_value' => array_key_exists('purchase_value', $data)
            ? $data['purchase_value']
            : $currentRevision['purchase_value'],
        'po_percentage' => '100.000000',
        'po_status' => (string) $currentRevision['po_status'],
    ];

    if (array_key_exists('project_code', $data) && trim((string) $data['project_code']) !== '') {
        $merged['project_code'] = trim((string) $data['project_code']);
        if (!array_key_exists('project_id', $data)) {
            unset($merged['project_id']);
        }
    }
    if (array_key_exists('supplier_ledger', $data) && trim((string) $data['supplier_ledger']) !== '') {
        $merged['supplier_ledger'] = trim((string) $data['supplier_ledger']);
        if (!array_key_exists('supplier_id', $data)) {
            unset($merged['supplier_id']);
        }
    }

    return procurementLocalAdvanceBuildPayload($conn, $merged);
}

function procurementLocalAdvanceRevisionSupplierChanged(array $previous, array $revised): bool
{
    return (int) ($previous['supplier_id'] ?? 0) !== (int) ($revised['supplier_id'] ?? 0)
        || trim((string) ($previous['supplier_ledger'] ?? '')) !== trim((string) ($revised['supplier_ledger'] ?? ''));
}

function procurementLocalAdvanceAmendmentChangeSummary(array $previous, array $proposed): array
{
    $labels = [
        'project_id' => 'Project',
        'project_code' => 'Project Code',
        'project_name' => 'Project Name',
        'supplier_id' => 'Supplier',
        'supplier_name' => 'Supplier Name',
        'supplier_ledger' => 'Supplier Ledger',
        'wht_status' => 'WHT Status',
        'wht_rate' => 'WHT Rate',
        'wht_amount' => 'WHT Amount',
        'purchase_value' => 'Purchase Value',
        'po_subtotal' => 'PO Subtotal',
        'po_discount' => 'PO Discount',
        'po_other_charges' => 'PO Other Charges',
        'po_vat_status' => 'VAT Status',
        'po_vat_rate' => 'VAT Rate',
        'po_vat_amount' => 'VAT Amount',
        'po_value' => 'PO Value',
        'advance_base_amount' => 'Expected Payment Base',
    ];
    $changes = [];
    foreach ($labels as $field => $label) {
        $oldValue = $previous[$field] ?? null;
        $newValue = $proposed[$field] ?? null;
        if ($oldValue === null && $newValue === null) {
            continue;
        }
        if ((string) $oldValue === (string) $newValue) {
            continue;
        }
        $changes[$field] = [
            'label' => $label,
            'previous' => $oldValue,
            'new' => $newValue,
        ];
    }
    return $changes;
}

function procurementLocalAdvanceFetchPendingAmendment(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions
         WHERE po_id = ? AND revision_status IN ('Draft', 'Pending Approval')
         ORDER BY revision_number DESC, id DESC LIMIT 1$lock"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementLocalAdvancePoPaymentSummary(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): array {
    if ($forUpdate) {
        $lockStmt = $conn->prepare(
            "SELECT r.id, r.account_request_id
             FROM procurement_requests r
             WHERE r.request_type = 'local_advance_purchase'
               AND r.legacy_source_table = 'procurement_local_advance_purchases'
               AND r.po_id = ? AND r.deleted_at IS NULL
             FOR UPDATE"
        );
        $lockStmt->bind_param('i', $poId);
        $lockStmt->execute();
        $lockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lockStmt->close();
    }

    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN r.request_type = 'Original' AND r.payment_status <> 'Cancelled'
                    THEN r.po_percentage ELSE 0 END), 0) AS allocated_percentage,
            COALESCE(SUM(CASE
                WHEN r.payment_status <> 'Cancelled'
                    THEN COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount)
                ELSE 0 END), 0) AS previous_committed_amount,
            COALESCE(SUM(CASE
                WHEN COALESCE(apr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0) > 0
                    THEN GREATEST(COALESCE(apr.amount_paid, cfr.amount_paid, 0), COALESCE(r.account_amount_paid, 0))
                WHEN (COALESCE(apr.payment_status, cfr.payment_status) = 'Paid'
                      OR (apr.payment_status IS NULL AND cfr.payment_status IS NULL AND r.payment_status = 'Paid'))
                    THEN COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount)
                ELSE 0 END), 0) AS total_paid,
            COALESCE(SUM(CASE
                WHEN (COALESCE(apr.payment_status, cfr.payment_status) = 'Processing'
                      OR (apr.payment_status IS NULL AND cfr.payment_status IS NULL AND r.payment_status = 'Processing'))
                    THEN GREATEST(
                        COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount)
                        - GREATEST(COALESCE(apr.amount_paid, cfr.amount_paid, 0), COALESCE(r.account_amount_paid, 0)),
                        0
                    )
                ELSE 0 END), 0) AS total_processing,
            COALESCE(SUM(CASE
                WHEN (COALESCE(apr.payment_status, cfr.payment_status) = 'Pending'
                      OR (apr.payment_status IS NULL AND cfr.payment_status IS NULL AND r.payment_status = 'Pending'))
                    THEN COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount)
                ELSE 0 END), 0) AS total_pending,
            SUM(CASE
                WHEN (COALESCE(apr.payment_status, cfr.payment_status) IN ('Processing', 'Paid')
                      OR (apr.payment_status IS NULL AND cfr.payment_status IS NULL
                          AND r.payment_status IN ('Processing', 'Paid')))
                    OR COALESCE(apr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0) > 0
                    OR apr.processing_started_at IS NOT NULL
                    OR cfr.processing_started_at IS NOT NULL
                    OR r.account_processing_started_at IS NOT NULL
                    OR apr.paid_at IS NOT NULL
                    OR cfr.paid_at IS NOT NULL
                    OR r.account_paid_at IS NOT NULL
                    OR apr.payment_batch_id IS NOT NULL
                    OR cfr.payment_batch_id IS NOT NULL
                    OR r.account_payment_batch_id IS NOT NULL
                THEN 1 ELSE 0 END) AS payment_activity_count,
            SUM(CASE
                WHEN r.approval_status = 'Unapproved' AND r.payment_status <> 'Cancelled'
                THEN 1 ELSE 0 END) AS unapproved_count
         FROM {$relation} r
         LEFT JOIN advance_payment_request apr
           ON apr.id = r.advance_payment_request_id
          AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = r.advance_payment_request_id
          AND r.account_request_type = 'compass_fund_request'
         WHERE r.po_id = ? AND r.deleted_at IS NULL"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'allocated_percentage' => procurementLocalAdvancePercentString(
            procurementLocalAdvancePercentUnitsAllowZero($row['allocated_percentage'] ?? 0)
        ),
        'allocated_percentage_units' => procurementLocalAdvancePercentUnitsAllowZero(
            $row['allocated_percentage'] ?? 0
        ),
        'previous_committed_amount' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents(
                $row['previous_committed_amount'] ?? 0,
                'Previous Committed Amount'
            )
        ),
        'total_paid' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['total_paid'] ?? 0, 'Total Paid')
        ),
        'total_processing' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['total_processing'] ?? 0, 'Total Processing')
        ),
        'total_pending' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['total_pending'] ?? 0, 'Total Pending')
        ),
        'payment_activity_count' => (int) ($row['payment_activity_count'] ?? 0),
        'unapproved_count' => (int) ($row['unapproved_count'] ?? 0),
    ];
}

function procurementLocalAdvanceRevisedCommittedCents(
    mysqli $conn,
    int $poId,
    array $revision
): int {
    $netCents = procurementLocalAdvanceMoneyToCents($revision['po_subtotal'], 'Revised PO Subtotal')
        - procurementLocalAdvanceMoneyToCents($revision['po_discount'], 'Revised PO Discount');
    $vatCents = procurementLocalAdvanceMoneyToCents($revision['po_vat_amount'], 'Revised PO VAT');
    $otherChargesCents = procurementLocalAdvanceMoneyToCents(
        $revision['po_other_charges'],
        'Revised PO Other Charges'
    );
    $vatIsCharged = strcasecmp((string) ($revision['po_vat_status'] ?? 'No'), 'Yes') === 0
        && $vatCents > 0;
    $defaultWhtBasisPoints = $vatIsCharged
        ? procurementLocalAdvanceWhtBasisPoints((string) ($revision['wht_status'] ?? '0.00%'))
        : 0;

    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT po_percentage, account_wht_override_status
         FROM {$relation} source
         WHERE po_id = ? AND request_type = 'Original'
           AND deleted_at IS NULL AND payment_status <> 'Cancelled'
         ORDER BY id ASC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $totalCents = 0;
    foreach ($rows as $row) {
        $overrideStatus = trim((string) ($row['account_wht_override_status'] ?? ''));
        $basisPoints = $vatIsCharged && $overrideStatus !== ''
            ? procurementLocalAdvanceWhtBasisPoints($overrideStatus)
            : $defaultWhtBasisPoints;
        $whtCents = procurementLocalAdvanceRateAmount($netCents, $basisPoints);
        $requestBaseCents = $netCents + $vatCents - $whtCents + $otherChargesCents;
        if ($requestBaseCents < 0) {
            throw new RuntimeException('The revised request amount cannot be negative.', 409);
        }
        $percentageUnits = procurementLocalAdvancePercentUnitsAllowZero($row['po_percentage'] ?? 0);
        $totalCents += procurementLocalAdvanceMultiplyDivideRounded(
            $requestBaseCents,
            $percentageUnits,
            PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
        );
    }

    return $totalCents;
}

function procurementLocalAdvanceCreatePoAmendment(
    mysqli $conn,
    int $poId,
    array $data,
    array $actor
): array {
    if ($poId <= 0) {
        throw new RuntimeException('A valid Local Advance PO ID is required.', 400);
    }
    $reason = trim((string) ($data['reason'] ?? $data['amendment_reason'] ?? ''));
    if ($reason === '') {
        throw new RuntimeException('An amendment reason is required.', 400);
    }
    $amendmentType = procurementLocalAdvanceAmendmentType($data['amendment_type'] ?? 'Commercial');
    $actorId = (int) ($actor['id'] ?? 0);

    $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The Local Advance PO could not be found.', 404);
    }
    if ((string) ($po['po_status'] ?? '') === 'Cancelled') {
        throw new RuntimeException('A Cancelled PO cannot be amended.', 409);
    }
    $currentRevision = procurementLocalAdvanceResolveCurrentPoRevision($conn, $poId, true);
    if ((int) ($currentRevision['is_locked'] ?? 0) !== 1) {
        throw new RuntimeException(
            'This PO has no locked payment revision. Use the normal Local Advance Purchase edit flow instead.',
            409
        );
    }
    if (procurementLocalAdvanceFetchPendingAmendment($conn, $poId, true)) {
        throw new RuntimeException('This PO already has an amendment awaiting review.', 409);
    }

    $paymentSummary = procurementLocalAdvancePoPaymentSummary($conn, $poId, true);
    if ((int) $paymentSummary['payment_activity_count'] <= 0) {
        throw new RuntimeException(
            'No Account payment activity has been recorded for this PO. Retrieve and edit the original request instead.',
            409
        );
    }
    if ((int) $paymentSummary['unapproved_count'] > 0) {
        throw new RuntimeException(
            'Resolve or remove all unapproved requests on this PO before submitting an amendment.',
            409
        );
    }

    $payload = procurementLocalAdvanceBuildAmendmentPayload($conn, $currentRevision, $data);
    $changes = procurementLocalAdvanceAmendmentChangeSummary($currentRevision, $payload);
    if ($changes === []) {
        throw new RuntimeException('The amendment does not contain any commercial change.', 409);
    }

    $revisionNumber = max(
        (int) ($po['current_revision_number'] ?? 1),
        (int) ($currentRevision['revision_number'] ?? 1)
    ) + 1;
    $revisionReference = procurementLocalAdvancePoRevisionReference(
        (string) $currentRevision['po_number'],
        $revisionNumber
    );
    $snapshotJson = procurementLocalAdvancePoCommercialSnapshotJson($payload);
    $changeSummaryJson = (string) json_encode(
        $changes,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    $snapshotHash = hash('sha256', $snapshotJson);
    $previousRevisionId = (int) $currentRevision['id'];

    $stmt = $conn->prepare(
        "INSERT INTO procurement_local_advance_po_revisions
            (po_id, revision_number, revision_reference, previous_revision_id,
             revision_status, amendment_type, amendment_reason,
             po_number, po_number_normalized, project_id, project_code, project_name,
             supplier_id, supplier_name, supplier_ledger, wht_status, wht_rate, wht_amount,
             purchase_value, po_subtotal, po_discount, po_other_charges, po_vat_status,
             po_vat_rate, po_vat_amount, po_value, advance_base_amount, po_status,
             commercial_snapshot_json, change_summary_json, snapshot_hash, is_locked,
             created_by, submitted_by, submitted_at, updated_by)
         VALUES (?, ?, ?, ?, 'Pending Approval', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), ?)"
    );
    $params = [
        $poId, $revisionNumber, $revisionReference, $previousRevisionId,
        $amendmentType, $reason,
        $payload['po_number'], $payload['po_number_normalized'], $payload['project_id'],
        $payload['project_code'], $payload['project_name'], $payload['supplier_id'],
        $payload['supplier_name'], $payload['supplier_ledger'], $payload['wht_status'],
        $payload['wht_rate'], $payload['wht_amount'], $payload['purchase_value'],
        $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'],
        $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'],
        $payload['po_value'], $payload['advance_base_amount'], $payload['po_status'],
        $snapshotJson, $changeSummaryJson, $snapshotHash,
        $actorId, $actorId, $actorId,
    ];
    $types = 'iisi' . str_repeat('s', 4) . 'i' . str_repeat('s', 2) . 'i'
        . str_repeat('s', 18) . 'iii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $revisionId = (int) $conn->insert_id;
    $stmt->close();

    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Pending Approval', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ?"
    );
    $updatePo->bind_param('ii', $actorId, $poId);
    $updatePo->execute();
    $updatePo->close();

    $freshRevision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    procurementLocalAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        null,
        'amendment_submitted',
        $actor,
        [
            'reason' => $reason,
            'amendment_type' => $amendmentType,
            'change_summary' => $changes,
            'previous_revision_id' => $previousRevisionId,
        ]
    );

    return $freshRevision;
}

function procurementLocalAdvanceFetchReconciliation(
    mysqli $conn,
    int $reconciliationId,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revision_reconciliations
         WHERE id = ? LIMIT 1$lock"
    );
    $stmt->bind_param('i', $reconciliationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}


function procurementLocalAdvanceRevisionRequestFinancials(
    array $revision,
    array $purchase
): array {
    $netCents = procurementLocalAdvanceMoneyToCents(
        $revision['po_subtotal'] ?? 0,
        'Revised PO Subtotal'
    ) - procurementLocalAdvanceMoneyToCents(
        $revision['po_discount'] ?? 0,
        'Revised PO Discount'
    );
    $vatCents = procurementLocalAdvanceMoneyToCents(
        $revision['po_vat_amount'] ?? 0,
        'Revised PO VAT'
    );
    $otherChargesCents = procurementLocalAdvanceMoneyToCents(
        $revision['po_other_charges'] ?? 0,
        'Revised PO Other Charges'
    );
    $hasVat = strcasecmp((string) ($revision['po_vat_status'] ?? 'No'), 'Yes') === 0
        && $vatCents > 0;

    $overrideStatus = trim((string) ($purchase['account_wht_override_status'] ?? ''));
    $whtStatus = $hasVat
        ? ($overrideStatus !== '' ? $overrideStatus : (string) ($revision['wht_status'] ?? '0.00%'))
        : '0.00%';
    $whtBasisPoints = $hasVat ? procurementLocalAdvanceWhtBasisPoints($whtStatus) : 0;
    $whtCents = procurementLocalAdvanceRateAmount($netCents, $whtBasisPoints);
    $amountPayableCents = $netCents + $vatCents - $whtCents;
    if ($amountPayableCents < 0) {
        throw new RuntimeException('The revised Advance Fund Request amount cannot be negative.', 409);
    }

    $requestBaseCents = $amountPayableCents + $otherChargesCents;
    $percentageUnits = procurementLocalAdvancePercentUnitsAllowZero(
        $purchase['po_percentage'] ?? $purchase['percentage'] ?? 0
    );
    $expectedCents = procurementLocalAdvanceMultiplyDivideRounded(
        $requestBaseCents,
        $percentageUnits,
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
    );

    return [
        'po_percentage' => procurementLocalAdvancePercentString($percentageUnits),
        'po_percentage_units' => $percentageUnits,
        'net_amount' => procurementLocalAdvanceCents($netCents),
        'vat' => procurementLocalAdvanceCents($vatCents),
        'vat_rate' => $hasVat ? (string) ($revision['po_vat_rate'] ?? '0.000000') : '0.000000',
        'vat_status' => procurementLocalAdvanceLegacyVatStatus(array_merge($revision, $purchase)),
        'wht_status' => $whtStatus,
        'wht_rate' => number_format($whtBasisPoints / 10000, 6, '.', ''),
        'wht' => procurementLocalAdvanceCents($whtCents),
        'amount_payable' => procurementLocalAdvanceCents($amountPayableCents),
        'other_charges' => procurementLocalAdvanceCents($otherChargesCents),
        'expected_payment' => procurementLocalAdvanceCents($expectedCents),
        'expected_payment_cents' => $expectedCents,
    ];
}

function procurementLocalAdvanceAllocationPaymentStatus(array $row): string
{
    $status = trim((string) ($row['account_payment_status'] ?? $row['payment_status'] ?? 'Pending'));
    return $status === 'Unconfirmed' ? 'Processing' : ($status !== '' ? $status : 'Pending');
}

function procurementLocalAdvanceAllocationIsLocked(array $row): bool
{
    $status = procurementLocalAdvanceAllocationPaymentStatus($row);
    return in_array($status, ['Processing', 'Paid'], true)
        || (float) ($row['account_amount_paid_effective'] ?? 0) > 0
        || !empty($row['account_processing_started_at_effective'])
        || !empty($row['account_paid_at_effective'])
        || !empty($row['account_payment_batch_id_effective']);
}

function procurementLocalAdvanceFetchAccountAllocationRows(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): array {
    if ($forUpdate) {
        $lockStmt = $conn->prepare(
            "SELECT id FROM procurement_requests
             WHERE request_type = 'local_advance_purchase'
               AND legacy_source_table = 'procurement_local_advance_purchases'
               AND po_id = ? AND deleted_at IS NULL
             FOR UPDATE"
        );
        $lockStmt->bind_param('i', $poId);
        $lockStmt->execute();
        $lockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lockStmt->close();
    }
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT purchase.*,
                purchase.advance_payment_request_id AS linked_advance_payment_request_id,
                COALESCE(apr.payment_status, cfr.payment_status) AS account_payment_status,
                COALESCE(
                    purchase.account_expected_payment,
                    purchase.expected_payment,
                    apr.advance_payment,
                    cfr.amount
                ) AS account_advance_payment,
                COALESCE(apr.amount_paid, cfr.amount_paid) AS account_amount_paid_request,
                COALESCE(apr.processing_started_at, cfr.processing_started_at)
                    AS account_processing_started_at_request,
                COALESCE(apr.paid_at, cfr.paid_at) AS account_paid_at_request,
                COALESCE(apr.payment_batch_id, cfr.payment_batch_id) AS account_payment_batch_id_request,
                COALESCE(apr.amount_paid, cfr.amount_paid, purchase.account_amount_paid, 0.00)
                    AS account_amount_paid_effective,
                COALESCE(apr.processing_started_at, cfr.processing_started_at, purchase.account_processing_started_at)
                    AS account_processing_started_at_effective,
                COALESCE(apr.paid_at, cfr.paid_at, purchase.account_paid_at)
                    AS account_paid_at_effective,
                COALESCE(apr.payment_batch_id, cfr.payment_batch_id, purchase.account_payment_batch_id)
                    AS account_payment_batch_id_effective,
                COALESCE(
                    purchase.account_expected_payment,
                    purchase.expected_payment,
                    apr.advance_payment,
                    cfr.amount
                ) AS committed_amount,
                exact_revision.supplier_id AS exact_revision_supplier_id,
                exact_revision.supplier_name AS exact_revision_supplier_name,
                exact_revision.supplier_ledger AS exact_revision_supplier_ledger,
                EXISTS(
                    SELECT 1
                    FROM procurement_supplier_financial_adjustments supplier_adjustment
                    WHERE supplier_adjustment.source_type = 'local_advance_purchase'
                      AND supplier_adjustment.source_purchase_id = purchase.id
                      AND supplier_adjustment.adjustment_kind = 'Supplier Change'
                      AND supplier_adjustment.adjustment_direction = 'Recoverable'
                      AND supplier_adjustment.status <> 'Cancelled'
                ) AS supplier_change_recovery_recorded
         FROM {$relation} purchase
         LEFT JOIN procurement_local_advance_po_revisions exact_revision
           ON exact_revision.id = purchase.po_revision_id
         LEFT JOIN advance_payment_request apr
           ON apr.id = purchase.advance_payment_request_id
          AND (purchase.account_request_type IS NULL OR purchase.account_request_type = 'advance_payment_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = purchase.advance_payment_request_id
          AND purchase.account_request_type = 'compass_fund_request'
         WHERE purchase.po_id = ? AND purchase.deleted_at IS NULL
           AND purchase.payment_status <> 'Cancelled'
         ORDER BY CASE purchase.request_type
                    WHEN 'Original' THEN 1
                    WHEN 'Supplementary' THEN 2
                    ELSE 3
                  END,
                  purchase.id ASC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function procurementLocalAdvanceUpdatePendingAllocationToRevision(
    mysqli $conn,
    array $row,
    array $revision,
    array $financials,
    int $actorId
): void {
    $purchaseId = (int) $row['id'];
    $requestId = (int) ($row['linked_advance_payment_request_id'] ?? 0);
    $revisionId = (int) $revision['id'];
    $revisionNumber = (int) $revision['revision_number'];
    $snapshotJson = (string) $revision['commercial_snapshot_json'];
    $expectedPayment = (string) $financials['expected_payment'];
    $overrideStatus = trim((string) ($row['account_wht_override_status'] ?? ''));
    $accountExpectedPayment = $overrideStatus !== '' ? $expectedPayment : null;

    $purchaseUpdate = $conn->prepare(
        "UPDATE procurement_requests
         SET po_revision_id = ?, po_revision_number = ?, po_snapshot_json = ?,
             expected_payment = ?, account_expected_payment = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ? AND deleted_at IS NULL"
    );
    $purchaseUpdate->bind_param(
        'iisssii',
        $revisionId,
        $revisionNumber,
        $snapshotJson,
        $expectedPayment,
        $accountExpectedPayment,
        $actorId,
        $purchaseId
    );
    $purchaseUpdate->execute();
    if ($purchaseUpdate->affected_rows !== 1) {
        $purchaseUpdate->close();
        throw new RuntimeException('A pending PO payment allocation changed during amendment synchronization.', 409);
    }
    $purchaseUpdate->close();

    if ($requestId <= 0) {
        return;
    }

    $supplierName = (string) $revision['supplier_name'];
    $supplierLedger = (int) $revision['supplier_ledger'];
    $projectCode = (string) $revision['project_code'];
    $poNumber = (string) $revision['po_number'];
    $percentage = (string) $financials['po_percentage'];
    $amount = (string) $revision['po_subtotal'];
    $discount = (string) $revision['po_discount'];
    $netAmount = (string) $financials['net_amount'];
    $vat = (string) $financials['vat'];
    $vatRate = (string) $financials['vat_rate'];
    $vatStatus = (string) $financials['vat_status'];
    $whtStatus = (string) $financials['wht_status'];
    $whtRate = (string) $financials['wht_rate'];
    $wht = (string) $financials['wht'];
    $amountPayable = (string) $financials['amount_payable'];
    $otherCharges = (string) $financials['other_charges'];
    $rootPoId = (int) $revision['po_id'];
    $accountRequestType = trim((string) ($row['account_request_type'] ?? ''))
        ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE;

    if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
        $supplierId = (int) $revision['supplier_id'];
        $vatPolicy = (string) $financials['vat_status'];
        $compassVatStatus = (strcasecmp((string) ($revision['po_vat_status'] ?? 'No'), 'Yes') === 0
            && procurementLocalAdvanceMoneyToCents($vat, 'Revised PO VAT') > 0)
            ? 'Yes'
            : 'No';
        $requestUpdate = $conn->prepare(
            "UPDATE compass_fund_request_table
             SET suppliers_name = ?, supplier_id = ?, project_code = ?, po_number = ?,
                 percentage = ?, net_value = ?, discount = ?, vat_policy = ?, vat = ?,
                 vat_rate = ?, vat_status = ?, wht = ?, wht_status = ?, wht_rate = ?,
                 other_charges = ?, amount = ?, procurement_root_po_id = ?,
                 procurement_request_type = 'Original', procurement_parent_purchase_id = NULL,
                 procurement_reconciliation_id = NULL,
                 procurement_po_revision_id = ?, procurement_po_revision_number = ?,
                 procurement_po_snapshot_json = ?, procurement_current_po_revision_id = ?,
                 procurement_current_po_revision_number = ?, payment_updated_by = ?,
                 payment_updated_at = NOW()
             WHERE id = ? AND payment_status = 'Pending'
               AND COALESCE(amount_paid, 0) = 0
               AND processing_started_at IS NULL
               AND paid_at IS NULL
               AND payment_batch_id IS NULL"
        );
        $requestUpdate->bind_param(
            'sissssssssssssssiiisiiii',
            $supplierName,
            $supplierId,
            $projectCode,
            $poNumber,
            $percentage,
            $amount,
            $discount,
            $vatPolicy,
            $vat,
            $vatRate,
            $compassVatStatus,
            $wht,
            $whtStatus,
            $whtRate,
            $otherCharges,
            $expectedPayment,
            $rootPoId,
            $revisionId,
            $revisionNumber,
            $snapshotJson,
            $revisionId,
            $revisionNumber,
            $actorId,
            $requestId
        );
    } else {
        $requestUpdate = $conn->prepare(
        "UPDATE advance_payment_request
         SET suppliers_name = ?, supplier_id = ?, site = ?, po_number = ?,
             percentage = ?, amount = ?, discount = ?, net_amount = ?,
             vat = ?, vat_rate = ?, vat_status = ?, wht_status = ?, wht_rate = ?, wht = ?,
             amount_payable = ?, other_charges = ?, advance_payment = ?,
             procurement_root_po_id = ?, procurement_request_type = 'Original',
             procurement_parent_purchase_id = NULL,
             procurement_reconciliation_id = NULL,
             procurement_po_revision_id = ?, procurement_po_revision_number = ?,
             procurement_po_snapshot_json = ?,
             procurement_current_po_revision_id = ?,
             procurement_current_po_revision_number = ?,
             payment_updated_by = ?, payment_updated_at = NOW(), updated_at = NOW()
         WHERE id = ? AND payment_status = 'Pending'
           AND COALESCE(amount_paid, 0) = 0
           AND processing_started_at IS NULL
           AND paid_at IS NULL
           AND payment_batch_id IS NULL"
    );
    $requestUpdate->bind_param(
        'sisssssssssssssssiiisiiii',
        $supplierName,
        $supplierLedger,
        $projectCode,
        $poNumber,
        $percentage,
        $amount,
        $discount,
        $netAmount,
        $vat,
        $vatRate,
        $vatStatus,
        $whtStatus,
        $whtRate,
        $wht,
        $amountPayable,
        $otherCharges,
        $expectedPayment,
        $rootPoId,
        $revisionId,
        $revisionNumber,
        $snapshotJson,
        $revisionId,
        $revisionNumber,
        $actorId,
        $requestId
    );
    }
    $requestUpdate->execute();
    if ($requestUpdate->affected_rows !== 1) {
        $requestUpdate->close();
        throw new RuntimeException('The pending AcctLab request started processing before amendment synchronization completed.', 409);
    }
    $requestUpdate->close();

    $handoffUpdate = $conn->prepare(
        "UPDATE procurement_request_handoffs h
         INNER JOIN procurement_requests r ON r.id = h.request_id
         SET h.po_revision_id = ?, h.po_revision_number = ?, h.po_snapshot_json = ?
         WHERE r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
           AND r.legacy_source_id = ?
           AND h.account_request_type = ?
           AND h.account_request_id = ?"
    );
    $handoffUpdate->bind_param(
        'iisisi',
        $revisionId,
        $revisionNumber,
        $snapshotJson,
        $purchaseId,
        $accountRequestType,
        $requestId
    );
    $handoffUpdate->execute();
    $handoffUpdate->close();
}

function procurementLocalAdvanceCancelUnprocessedSupplementary(
    mysqli $conn,
    array $row,
    int $currentReconciliationId,
    int $actorId
): void {
    $purchaseId = (int) $row['id'];
    $requestId = (int) ($row['linked_advance_payment_request_id'] ?? 0);
    $purchaseUpdate = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = 'Cancelled', payment_status_source = 'procuredesk',
             payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(),
             version = version + 1
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ? AND payment_status = 'Pending' AND deleted_at IS NULL"
    );
    $purchaseUpdate->bind_param('ii', $actorId, $purchaseId);
    $purchaseUpdate->execute();
    if ($purchaseUpdate->affected_rows !== 1) {
        $purchaseUpdate->close();
        throw new RuntimeException('A prior supplementary request changed during amendment synchronization.', 409);
    }
    $purchaseUpdate->close();

    if ($requestId > 0) {
        $accountRequestType = trim((string) ($row['account_request_type'] ?? ''))
            ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE;
        $accountRequestTable = procurementLocalAdvanceAccountRequestTable($accountRequestType);
        $updatedAtSql = $accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE
            ? ', updated_at = NOW()'
            : '';
        $requestUpdate = $conn->prepare(
            "UPDATE `{$accountRequestTable}`
             SET payment_status = 'Cancelled',
                 payment_confirmation_status = 'Not Scheduled',
                 account_remarks = CONCAT_WS(' | ', NULLIF(account_remarks, ''),
                     'Superseded by a later approved PO revision'),
                 payment_updated_by = ?, payment_updated_at = NOW(){$updatedAtSql}
             WHERE id = ? AND payment_status = 'Pending'
               AND COALESCE(amount_paid, 0) = 0
               AND processing_started_at IS NULL
               AND paid_at IS NULL
               AND payment_batch_id IS NULL"
        );
        $requestUpdate->bind_param('ii', $actorId, $requestId);
        $requestUpdate->execute();
        if ($requestUpdate->affected_rows !== 1) {
            $requestUpdate->close();
            throw new RuntimeException('A prior supplementary AcctLab request started processing before it could be superseded.', 409);
        }
        $requestUpdate->close();
    }

    $priorReconciliationId = (int) ($row['revision_reconciliation_id'] ?? 0);
    if ($priorReconciliationId > 0 && $priorReconciliationId !== $currentReconciliationId) {
        $reason = 'Superseded by a later approved PO revision.';
        $reconciliationUpdate = $conn->prepare(
            "UPDATE procurement_local_advance_po_revision_reconciliations
             SET reconciliation_status = 'Cancelled', resolution_type = 'Superseded',
                 resolution_notes = ?, resolved_by = ?, resolved_at = NOW(),
                 updated_by = ?, updated_at = NOW()
             WHERE id = ? AND reconciliation_status = 'Pending Supplementary Payment'"
        );
        $reconciliationUpdate->bind_param(
            'siii',
            $reason,
            $actorId,
            $actorId,
            $priorReconciliationId
        );
        $reconciliationUpdate->execute();
        $reconciliationUpdate->close();

    }
}

function procurementLocalAdvanceCreateSupplementaryPurchaseAndRequest(
    mysqli $conn,
    array $revision,
    int $reconciliationId,
    int $supplementaryCents,
    ?int $parentPurchaseId,
    array $actor
): array {
    if ($supplementaryCents <= 0) {
        throw new RuntimeException('A positive supplementary amount is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $poId = (int) $revision['po_id'];
    $revisionId = (int) $revision['id'];
    $revisionNumber = (int) $revision['revision_number'];
    $snapshotJson = (string) $revision['commercial_snapshot_json'];
    $baseCents = max(
        1,
        procurementLocalAdvanceMoneyToCents(
            $revision['advance_base_amount'],
            'Revised Expected Payment Base'
        )
    );
    $percentageUnits = min(
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX,
        max(
            1,
            procurementLocalAdvanceMultiplyDivideRounded(
                $supplementaryCents,
                PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX,
                $baseCents
            )
        )
    );
    $percentage = procurementLocalAdvancePercentString($percentageUnits);
    $expectedPayment = procurementLocalAdvanceCents($supplementaryCents);
    $purchaseNumber = procurementLocalAdvanceSubstring(
        trim((string) $revision['po_number']) . '/REV-' . $revisionNumber . '-SUPP',
        120
    );
    $remark = 'Supplementary payment created from approved PO amendment '
        . (string) $revision['revision_reference'];

    $purchaseId = procurementRequestCanonicalNextLegacyId(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE
    );
    $requestNumber = 'LAP-SUP-' . date('Y') . '-' . str_pad((string) $purchaseId, 7, '0', STR_PAD_LEFT);
    $canonicalType = PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_TYPE;
    $canonicalSource = PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_SOURCE;
    $requestVariant = 'Supplementary';
    $insert = $conn->prepare(
        "INSERT INTO procurement_requests
            (request_type, request_number, legacy_source_table, legacy_source_id,
             po_id, po_revision_id, po_revision_number, request_variant,
             legacy_parent_purchase_id, revision_reconciliation_id, po_snapshot_json,
             purchase_number, po_percentage, expected_payment, remark,
             transaction_date, date_received, payment_status, payment_status_source,
             payment_status_updated_at, approval_status, handoff_status, handoff_revision,
             account_request_type, approved_by, approved_at, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 CURDATE(), CURDATE(), 'Pending', 'procuredesk', NOW(),
                 'Approved', 'In Account', 1, NULL, ?, NOW(), ?, ?)"
    );
    $insert->bind_param(
        'sssiiiisiisssssiii',
        $canonicalType,
        $requestNumber,
        $canonicalSource,
        $purchaseId,
        $poId,
        $revisionId,
        $revisionNumber,
        $requestVariant,
        $parentPurchaseId,
        $reconciliationId,
        $snapshotJson,
        $purchaseNumber,
        $percentage,
        $expectedPayment,
        $remark,
        $actorId,
        $actorId,
        $actorId
    );
    $insert->execute();
    $insert->close();
    if ($purchaseId <= 0) {
        throw new RuntimeException('The supplementary Local Advance Purchase could not be created.', 500);
    }

    procurementRequestCanonicalSyncRequest(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        $purchaseId
    );

    $purchase = procurementLocalAdvanceFetchRecord($conn, $purchaseId, true);
    if (!$purchase) {
        throw new RuntimeException('The supplementary Local Advance Purchase could not be loaded.', 500);
    }
    $accountRequestType = procurementCompassResolveLocalAccountRequestType(
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        $purchase['supplier_id'] ?? null,
        $purchase['supplier_name'] ?? null
    );
    $handoffRequest = procurementLocalAdvanceCreateAccountFundRequest(
        $conn,
        $purchase,
        1,
        $accountRequestType,
        $expectedPayment
    );
    $requestId = (int) $handoffRequest['id'];
    procurementLocalAdvanceCreateHandoff(
        $conn,
        $purchaseId,
        1,
        $accountRequestType,
        $requestId,
        $actorId
    );

    $linkUpdate = $conn->prepare(
        "UPDATE procurement_requests
         SET account_request_type = ?, account_request_id = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ?"
    );
    $linkUpdate->bind_param('siii', $accountRequestType, $requestId, $actorId, $purchaseId);
    $linkUpdate->execute();
    $linkUpdate->close();

    return [
        'purchase_id' => $purchaseId,
        'advance_payment_request_id' => $requestId,
        'account_request_type' => $accountRequestType,
        'request_number' => $requestNumber,
        'amount' => $expectedPayment,
        'percentage' => $percentage,
    ];
}

function procurementLocalAdvanceUpsertAccountReconciliation(
    mysqli $conn,
    array $reconciliation,
    array $revision,
    array $sync,
    array $actor
): int {
    $actorId = (int) ($actor['id'] ?? 0);
    $procurementReconciliationId = (int) $reconciliation['id'];
    $direction = (string) $sync['direction'];
    $status = (string) $sync['status'];
    $previousCommitted = (string) $reconciliation['previous_committed_amount'];
    $revisedCommitted = (string) $reconciliation['revised_committed_amount'];
    $totalPaid = (string) $reconciliation['total_paid_at_revision'];
    $totalProcessing = (string) $reconciliation['total_processing_at_revision'];
    $totalPending = (string) $reconciliation['total_pending_at_revision'];
    $pendingReallocated = procurementLocalAdvanceCents(
        (int) $sync['pending_reallocated_cents']
    );
    $supplementaryAmount = procurementLocalAdvanceCents(
        (int) $sync['supplementary_cents']
    );
    $recoveryAmount = procurementLocalAdvanceCents((int) $sync['recovery_cents']);
    $supplementaryPurchaseId = (int) ($sync['supplementary_purchase_id'] ?? 0);
    $supplementaryRequestId = (int) (
        $sync['supplementary_advance_payment_request_id'] ?? 0
    );
    $resolutionType = $sync['resolution_type'];
    $resolutionNotes = $sync['resolution_notes'];
    $snapshotJson = (string) json_encode(
        $sync['allocations'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    $resolvedBy = $status === 'Resolved' ? $actorId : null;
    $resolvedAt = $status === 'Resolved' ? date('Y-m-d H:i:s') : null;
    $accountId = advancePoReconciliationAllocateAccountId(
        $conn,
        $procurementReconciliationId
    );

    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET reconciliation_direction = ?, reconciliation_status = ?,
             previous_committed_amount = ?, revised_committed_amount = ?,
             total_paid_at_revision = ?, total_processing_at_revision = ?,
             total_pending_at_revision = ?, pending_reallocated_amount = ?,
             supplementary_amount = ?, recovery_amount = ?,
             supplementary_purchase_id = NULLIF(?, 0),
             supplementary_advance_payment_request_id = NULLIF(?, 0),
             resolution_type = ?, resolution_notes = ?,
             allocation_snapshot_json = ?, account_reconciliation_id = ?,
             account_sync_status = 'Synced', account_synced_by = ?,
             account_synced_at = NOW(), account_sync_error = NULL,
             resolved_by = ?, resolved_at = ?,
             updated_by = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param(
        'ssssssssssiisssiiisii',
        $direction,
        $status,
        $previousCommitted,
        $revisedCommitted,
        $totalPaid,
        $totalProcessing,
        $totalPending,
        $pendingReallocated,
        $supplementaryAmount,
        $recoveryAmount,
        $supplementaryPurchaseId,
        $supplementaryRequestId,
        $resolutionType,
        $resolutionNotes,
        $snapshotJson,
        $accountId,
        $actorId,
        $resolvedBy,
        $resolvedAt,
        $actorId,
        $procurementReconciliationId
    );
    $stmt->execute();
    $stmt->close();

    return $accountId;
}


function procurementLocalAdvancePersistAccountAllocations(
    mysqli $conn,
    int $accountReconciliationId,
    array $allocations
): void {
    $delete = $conn->prepare(
        'DELETE FROM account_advance_po_reconciliation_allocations
         WHERE account_reconciliation_id = ?'
    );
    $delete->bind_param('i', $accountReconciliationId);
    $delete->execute();
    $delete->close();

    $insert = $conn->prepare(
        "INSERT INTO account_advance_po_reconciliation_allocations
            (account_reconciliation_id, procurement_purchase_id,
             advance_payment_request_id, request_type, allocation_action,
             payment_status, exact_po_revision_id, exact_po_revision_number,
             current_po_revision_id, current_po_revision_number,
             previous_expected_amount, revised_expected_amount,
             amount_paid, amount_processing, amount_pending,
             recovery_allocated_amount)
         VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0),
                 ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($allocations as $allocation) {
        $requestId = (int) ($allocation['advance_payment_request_id'] ?? 0);
        $exactRevisionId = (int) ($allocation['exact_po_revision_id'] ?? 0);
        $exactRevisionNumber = (int) ($allocation['exact_po_revision_number'] ?? 0);
        $insert->bind_param(
            'iiisssiiiissssss',
            $accountReconciliationId,
            $allocation['procurement_purchase_id'],
            $requestId,
            $allocation['request_type'],
            $allocation['allocation_action'],
            $allocation['payment_status'],
            $exactRevisionId,
            $exactRevisionNumber,
            $allocation['current_po_revision_id'],
            $allocation['current_po_revision_number'],
            $allocation['previous_expected_amount'],
            $allocation['revised_expected_amount'],
            $allocation['amount_paid'],
            $allocation['amount_processing'],
            $allocation['amount_pending'],
            $allocation['recovery_allocated_amount']
        );
        $insert->execute();
    }
    $insert->close();
}

function procurementLocalAdvanceSupersedePriorRecoveryReconciliations(
    mysqli $conn,
    int $poId,
    int $currentReconciliationId,
    int $actorId
): void {
    $reason = 'Superseded by the cumulative reconciliation for a later approved PO revision.';
    $stmt = $conn->prepare(
        "SELECT id FROM procurement_local_advance_po_revision_reconciliations
         WHERE po_id = ? AND id <> ?
           AND reconciliation_status = 'Recovery Required'
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $poId, $currentReconciliationId);
    $stmt->execute();
    $ids = array_map(
        static fn(array $row): int => (int) $row['id'],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $stmt->close();
    if ($ids === []) {
        return;
    }

    $procurementUpdate = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET reconciliation_status = 'Cancelled', resolution_type = 'Superseded',
             resolution_notes = ?, resolved_by = ?, resolved_at = NOW(),
             updated_by = ?, updated_at = NOW()
         WHERE id = ? AND reconciliation_status = 'Recovery Required'"
    );
    foreach ($ids as $reconciliationId) {
        $procurementUpdate->bind_param(
            'siii',
            $reason,
            $actorId,
            $actorId,
            $reconciliationId
        );
        $procurementUpdate->execute();
    }
    $procurementUpdate->close();
}


function procurementLocalAdvanceCreateSupplierChangeAdjustments(
    mysqli $conn,
    array $previousRevision,
    array $revision,
    array $rows,
    ?array $supplementary,
    array $actor
): array {
    if (!procurementLocalAdvanceRevisionSupplierChanged($previousRevision, $revision)) {
        return [];
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $previousSupplierId = (int) ($previousRevision['supplier_id'] ?? 0);
    $newSupplierId = (int) ($revision['supplier_id'] ?? 0);
    if ($actorId <= 0 || $previousSupplierId <= 0 || $newSupplierId <= 0) {
        throw new RuntimeException('Supplier-change adjustment identity is incomplete.', 409);
    }

    $reason = 'Supplier changed from '
        . trim((string) ($previousRevision['supplier_name'] ?? 'previous supplier'))
        . ' to '
        . trim((string) ($revision['supplier_name'] ?? 'revised supplier'))
        . ' on PO '
        . trim((string) ($revision['po_number'] ?? ''))
        . '. '
        . trim((string) ($revision['amendment_reason'] ?? 'Approved PO amendment.'));

    $originSnapshot = procurementLocalAdvancePoCommercialSnapshot($previousRevision);
    $revisedSnapshot = procurementLocalAdvancePoCommercialSnapshot($revision);
    $adjustments = [];

    foreach ($rows as $row) {
        $exactSupplierId = (int) ($row['exact_revision_supplier_id'] ?? 0);
        if ($exactSupplierId !== $previousSupplierId) {
            continue;
        }

        $paidCents = procurementLocalAdvanceMoneyToCents(
            $row['account_amount_paid_effective'] ?? 0,
            'Supplier Change Amount Paid'
        );
        if ($paidCents <= 0 && procurementLocalAdvanceAllocationPaymentStatus($row) === 'Paid') {
            $paidCents = procurementLocalAdvanceMoneyToCents(
                $row['committed_amount'] ?? 0,
                'Supplier Change Paid Commitment'
            );
        }
        if ($paidCents <= 0) {
            continue;
        }

        $adjustments[] = procurementSupplierAdjustmentCreate($conn, [
            'source_type' => 'local_advance_purchase',
            'source_purchase_id' => (int) $row['id'],
            'source_po_id' => (int) $revision['po_id'],
            'source_revision_id' => (int) $revision['id'],
            'source_revision_number' => (int) $revision['revision_number'],
            'adjustment_kind' => 'Supplier Change',
            'adjustment_direction' => 'Recoverable',
            'supplier_id' => $previousSupplierId,
            'supplier_name' => (string) $previousRevision['supplier_name'],
            'supplier_ledger' => (string) $previousRevision['supplier_ledger'],
            'currency' => 'NGN',
            'amount' => procurementLocalAdvanceCents($paidCents),
            'reason' => $reason,
            'origin_snapshot' => $originSnapshot,
            'revised_snapshot' => $revisedSnapshot,
        ], $actorId);
    }

    $supplementaryPurchaseId = (int) ($supplementary['purchase_id'] ?? 0);
    $supplementaryAmount = trim((string) ($supplementary['amount'] ?? '0.00'));
    if ($supplementaryPurchaseId > 0
        && procurementLocalAdvanceMoneyToCents($supplementaryAmount, 'Supplier Change Payable', true) > 0) {
        $adjustments[] = procurementSupplierAdjustmentCreate($conn, [
            'source_type' => 'local_advance_purchase',
            'source_purchase_id' => $supplementaryPurchaseId,
            'source_po_id' => (int) $revision['po_id'],
            'source_revision_id' => (int) $revision['id'],
            'source_revision_number' => (int) $revision['revision_number'],
            'adjustment_kind' => 'Supplier Change',
            'adjustment_direction' => 'Payable',
            'supplier_id' => $newSupplierId,
            'supplier_name' => (string) $revision['supplier_name'],
            'supplier_ledger' => (string) $revision['supplier_ledger'],
            'currency' => 'NGN',
            'amount' => $supplementaryAmount,
            'reason' => $reason,
            'origin_snapshot' => $originSnapshot,
            'revised_snapshot' => $revisedSnapshot,
        ], $actorId);
    }

    return $adjustments;
}


function procurementLocalAdvanceCreateValueDecreaseAdjustments(
    mysqli $conn,
    array $previousRevision,
    array $revision,
    array $allocations,
    array $actor
): array {
    if (procurementLocalAdvanceRevisionSupplierChanged($previousRevision, $revision)) {
        return [];
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $supplierId = (int) ($revision['supplier_id'] ?? 0);
    if ($actorId <= 0 || $supplierId <= 0) {
        throw new RuntimeException('Value-decrease recovery identity is incomplete.', 409);
    }

    $originSnapshot = procurementLocalAdvancePoCommercialSnapshot($previousRevision);
    $revisedSnapshot = procurementLocalAdvancePoCommercialSnapshot($revision);
    $reason = 'PO ' . trim((string) ($revision['po_number'] ?? ''))
        . ' value decreased on Revision ' . (int) ($revision['revision_number'] ?? 0)
        . '. ' . trim((string) ($revision['amendment_reason'] ?? 'Approved PO amendment.'));
    $adjustments = [];

    foreach ($allocations as $allocation) {
        if ((string) ($allocation['allocation_action'] ?? '') !== 'Preserved') {
            continue;
        }
        $recoveryCents = procurementLocalAdvanceMoneyToCents(
            $allocation['recovery_allocated_amount'] ?? 0,
            'Value Decrease Recovery',
            true
        );
        if ($recoveryCents <= 0) {
            continue;
        }

        $purchaseId = (int) ($allocation['procurement_purchase_id'] ?? 0);
        if ($purchaseId <= 0) {
            continue;
        }

        $adjustments[] = procurementSupplierAdjustmentCreate($conn, [
            'source_type' => 'local_advance_purchase',
            'source_purchase_id' => $purchaseId,
            'source_po_id' => (int) ($revision['po_id'] ?? 0),
            'source_revision_id' => (int) ($revision['id'] ?? 0),
            'source_revision_number' => (int) ($revision['revision_number'] ?? 0),
            'adjustment_kind' => 'Value Decrease',
            'adjustment_direction' => 'Recoverable',
            'supplier_id' => $supplierId,
            'supplier_name' => (string) ($revision['supplier_name'] ?? ''),
            'supplier_ledger' => (string) ($revision['supplier_ledger'] ?? ''),
            'currency' => (string) ($revision['currency'] ?? 'NGN'),
            'amount' => procurementLocalAdvanceCents($recoveryCents),
            'reason' => $reason,
            'origin_snapshot' => $originSnapshot,
            'revised_snapshot' => $revisedSnapshot,
        ], $actorId);
    }

    return $adjustments;
}


function procurementLocalAdvanceSynchronizeApprovedAmendmentToAccount(
    mysqli $conn,
    int $reconciliationId,
    array $actor
): array {
    $reconciliation = procurementLocalAdvanceFetchReconciliation($conn, $reconciliationId, true);
    if (!$reconciliation) {
        throw new RuntimeException('The approved PO reconciliation could not be found.', 409);
    }
    $revision = procurementLocalAdvanceFetchPoRevision(
        $conn,
        (int) $reconciliation['revision_id'],
        true
    );
    if (!$revision || (string) $revision['revision_status'] !== 'Current') {
        throw new RuntimeException('The approved PO revision is not current for Account synchronization.', 409);
    }
    $previousRevision = procurementLocalAdvanceFetchPoRevision(
        $conn,
        (int) ($reconciliation['previous_revision_id'] ?? 0),
        true
    );
    if (!$previousRevision) {
        throw new RuntimeException('The previous PO revision could not be found for Account synchronization.', 409);
    }
    $supplierChanged = procurementLocalAdvanceRevisionSupplierChanged($previousRevision, $revision);
    $currentSupplierId = (int) ($revision['supplier_id'] ?? 0);

    $actorId = (int) ($actor['id'] ?? 0);
    procurementLocalAdvanceSupersedePriorRecoveryReconciliations(
        $conn,
        (int) $reconciliation['po_id'],
        $reconciliationId,
        $actorId
    );
    $rows = procurementLocalAdvanceFetchAccountAllocationRows(
        $conn,
        (int) $reconciliation['po_id'],
        true
    );
    $targetCommittedCents = 0;
    $allocatedAfterCents = 0;
    $pendingReallocatedCents = 0;
    $allocations = [];
    $parentPurchaseId = null;

    foreach ($rows as $row) {
        $requestType = (string) ($row['request_type'] ?? 'Original');
        $purchaseId = (int) $row['id'];
        if ($parentPurchaseId === null && $requestType === 'Original') {
            $parentPurchaseId = $purchaseId;
        }

        $previousCents = procurementLocalAdvanceMoneyToCents(
            $row['committed_amount'] ?? 0,
            'Committed Advance Fund Request'
        );
        $paymentStatus = procurementLocalAdvanceAllocationPaymentStatus($row);
        $amountPaidCents = procurementLocalAdvanceMoneyToCents(
            $row['account_amount_paid_effective'] ?? 0,
            'Amount Paid'
        );
        if ($amountPaidCents <= 0 && $paymentStatus === 'Paid') {
            $amountPaidCents = $previousCents;
        }
        $amountProcessingCents = $paymentStatus === 'Processing'
            ? max(0, $previousCents - $amountPaidCents)
            : 0;
        $amountPendingCents = $paymentStatus === 'Pending' ? $previousCents : 0;
        $isLocked = procurementLocalAdvanceAllocationIsLocked($row);
        $exactSupplierId = (int) ($row['exact_revision_supplier_id'] ?? 0);
        $hasPriorSupplierRecovery = (int) ($row['supplier_change_recovery_recorded'] ?? 0) === 1;
        $belongsToCurrentSupplier = $exactSupplierId <= 0
            || ($exactSupplierId === $currentSupplierId && !$hasPriorSupplierRecovery);
        $allocationAction = 'Preserved';
        $revisedCents = $previousCents;

        if ($requestType === 'Original') {
            $financials = procurementLocalAdvanceRevisionRequestFinancials($revision, $row);
            $revisedCents = (int) $financials['expected_payment_cents'];
            $targetCommittedCents += $revisedCents;
            if (!$isLocked && $paymentStatus === 'Pending') {
                procurementLocalAdvanceUpdatePendingAllocationToRevision(
                    $conn,
                    $row,
                    $revision,
                    $financials,
                    $actorId
                );
                $allocationAction = 'Reallocated';
                $allocatedAfterCents += $revisedCents;
                $pendingReallocatedCents += $revisedCents - $previousCents;
                $amountPendingCents = $revisedCents;
            } elseif ($belongsToCurrentSupplier) {
                $allocatedAfterCents += $previousCents;
            } else {
                $allocationAction = $supplierChanged && $exactSupplierId === (int) $previousRevision['supplier_id']
                    ? 'Supplier Replaced'
                    : 'Historical Supplier';
                $revisedCents = 0;
            }
        } elseif ($requestType === 'Supplementary') {
            if (!$isLocked && $paymentStatus === 'Pending') {
                procurementLocalAdvanceCancelUnprocessedSupplementary(
                    $conn,
                    $row,
                    $reconciliationId,
                    $actorId
                );
                $allocationAction = 'Superseded';
                $revisedCents = 0;
                $amountPendingCents = 0;
            } elseif ($belongsToCurrentSupplier) {
                $allocatedAfterCents += $previousCents;
            } else {
                $allocationAction = $supplierChanged && $exactSupplierId === (int) $previousRevision['supplier_id']
                    ? 'Supplier Replaced'
                    : 'Historical Supplier';
                $revisedCents = 0;
            }
        } elseif ($belongsToCurrentSupplier) {
            $allocatedAfterCents += $previousCents;
        } else {
            $allocationAction = 'Historical Supplier';
            $revisedCents = 0;
        }

        $allocations[] = [
            'procurement_purchase_id' => $purchaseId,
            'advance_payment_request_id' => (int) ($row['linked_advance_payment_request_id'] ?? 0),
            'request_type' => $requestType,
            'allocation_action' => $allocationAction,
            'payment_status' => $paymentStatus,
            'exact_po_revision_id' => (int) ($row['po_revision_id'] ?? 0),
            'exact_po_revision_number' => (int) ($row['po_revision_number'] ?? 0),
            'current_po_revision_id' => (int) $revision['id'],
            'current_po_revision_number' => (int) $revision['revision_number'],
            'previous_expected_amount' => procurementLocalAdvanceCents($previousCents),
            'revised_expected_amount' => procurementLocalAdvanceCents($revisedCents),
            'amount_paid' => procurementLocalAdvanceCents($amountPaidCents),
            'amount_processing' => procurementLocalAdvanceCents($amountProcessingCents),
            'amount_pending' => procurementLocalAdvanceCents($amountPendingCents),
            'recovery_allocated_amount' => '0.00',
        ];
    }

    $adjustmentCents = $targetCommittedCents - $allocatedAfterCents;
    $direction = $adjustmentCents > 0 ? 'Increase' : ($adjustmentCents < 0 ? 'Decrease' : 'No Change');
    $status = $direction === 'Increase'
        ? 'Pending Supplementary Payment'
        : ($direction === 'Decrease' ? 'Recovery Required' : 'Resolved');
    $supplementaryCents = max(0, $adjustmentCents);
    $recoveryCents = max(0, -$adjustmentCents);
    $supplementary = null;

    if ($supplementaryCents > 0) {
        $supplementary = procurementLocalAdvanceCreateSupplementaryPurchaseAndRequest(
            $conn,
            $revision,
            $reconciliationId,
            $supplementaryCents,
            $parentPurchaseId,
            $actor
        );
        $allocations[] = [
            'procurement_purchase_id' => (int) $supplementary['purchase_id'],
            'advance_payment_request_id' => (int) $supplementary['advance_payment_request_id'],
            'request_type' => 'Supplementary',
            'allocation_action' => 'Supplementary',
            'payment_status' => 'Pending',
            'exact_po_revision_id' => (int) $revision['id'],
            'exact_po_revision_number' => (int) $revision['revision_number'],
            'current_po_revision_id' => (int) $revision['id'],
            'current_po_revision_number' => (int) $revision['revision_number'],
            'previous_expected_amount' => '0.00',
            'revised_expected_amount' => (string) $supplementary['amount'],
            'amount_paid' => '0.00',
            'amount_processing' => '0.00',
            'amount_pending' => (string) $supplementary['amount'],
            'recovery_allocated_amount' => '0.00',
        ];
    }

    $supplierAdjustments = procurementLocalAdvanceCreateSupplierChangeAdjustments(
        $conn,
        $previousRevision,
        $revision,
        $rows,
        $supplementary,
        $actor
    );

    if ($recoveryCents > 0) {
        $remainingRecovery = $recoveryCents;
        foreach ($allocations as &$allocation) {
            if ($remainingRecovery <= 0 || $allocation['allocation_action'] !== 'Preserved') {
                continue;
            }
            $paidCents = procurementLocalAdvanceMoneyToCents(
                $allocation['amount_paid'],
                'Recovery Paid Allocation'
            );
            $processingCents = procurementLocalAdvanceMoneyToCents(
                $allocation['amount_processing'],
                'Recovery Processing Allocation'
            );
            $exposureCents = $paidCents + $processingCents;
            if ($exposureCents <= 0) {
                continue;
            }
            $allocatedRecovery = min($remainingRecovery, $exposureCents);
            $allocation['recovery_allocated_amount'] = procurementLocalAdvanceCents(
                $allocatedRecovery
            );
            $remainingRecovery -= $allocatedRecovery;
        }
        unset($allocation);
        if ($remainingRecovery > 0) {
            throw new RuntimeException(
                'The recovery amount could not be allocated to paid or processing Account requests.',
                409
            );
        }
    }

    if ($recoveryCents > 0 && !$supplierChanged) {
        $supplierAdjustments = array_merge(
            $supplierAdjustments,
            procurementLocalAdvanceCreateValueDecreaseAdjustments(
                $conn,
                $previousRevision,
                $revision,
                $allocations,
                $actor
            )
        );
    }

    $resolutionType = $status === 'Resolved'
        ? ($pendingReallocatedCents !== 0
            ? 'Pending Requests Reallocated'
            : 'No Financial Adjustment')
        : null;
    $resolutionNotes = $status === 'Resolved'
        ? ($pendingReallocatedCents !== 0
            ? 'Unprocessed Account requests were recalculated against the approved PO revision.'
            : 'The approved amendment requires no additional payment or recovery.')
        : null;

    $supplementaryPurchaseId = $supplementary['purchase_id'] ?? null;
    $supplementaryRequestId = $supplementary['advance_payment_request_id'] ?? null;
    $reconciliationAmount = procurementLocalAdvanceCents(abs($adjustmentCents));
    $resolvedBy = $status === 'Resolved' ? $actorId : null;
    $resolvedAt = $status === 'Resolved' ? date('Y-m-d H:i:s') : null;

    $reconciliationUpdate = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET reconciliation_direction = ?, reconciliation_status = ?,
             reconciliation_amount = ?, resolution_type = ?,
             supplementary_purchase_id = NULLIF(?, 0),
             supplementary_advance_payment_request_id = NULLIF(?, 0),
             account_sync_status = 'Synced', account_synced_by = ?,
             account_synced_at = NOW(), account_sync_error = NULL,
             resolution_notes = ?, resolved_by = ?, resolved_at = ?,
             updated_by = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $supplementaryPurchaseValue = (int) ($supplementaryPurchaseId ?? 0);
    $supplementaryRequestValue = (int) ($supplementaryRequestId ?? 0);
    $reconciliationUpdate->bind_param(
        'ssssiiisisii',
        $direction,
        $status,
        $reconciliationAmount,
        $resolutionType,
        $supplementaryPurchaseValue,
        $supplementaryRequestValue,
        $actorId,
        $resolutionNotes,
        $resolvedBy,
        $resolvedAt,
        $actorId,
        $reconciliationId
    );
    $reconciliationUpdate->execute();
    $reconciliationUpdate->close();

    $currentRevisionUpdate = $conn->prepare(
        "UPDATE advance_payment_request apr
         INNER JOIN procurement_requests purchase
            ON purchase.account_request_id = apr.id
           AND purchase.request_type = 'local_advance_purchase'
           AND purchase.legacy_source_table = 'procurement_local_advance_purchases'
           AND (purchase.account_request_type IS NULL OR purchase.account_request_type = 'advance_payment_request')
         SET apr.procurement_root_po_id = purchase.po_id,
             apr.procurement_request_type = purchase.request_variant,
             apr.procurement_parent_purchase_id = purchase.legacy_parent_purchase_id,
             apr.procurement_reconciliation_id = purchase.revision_reconciliation_id,
             apr.procurement_current_po_revision_id = ?,
             apr.procurement_current_po_revision_number = ?
         WHERE purchase.po_id = ? AND purchase.deleted_at IS NULL"
    );
    $revisionId = (int) $revision['id'];
    $revisionNumber = (int) $revision['revision_number'];
    $poId = (int) $revision['po_id'];
    $currentRevisionUpdate->bind_param('iii', $revisionId, $revisionNumber, $poId);
    $currentRevisionUpdate->execute();
    $currentRevisionUpdate->close();

    $compassCurrentRevisionUpdate = $conn->prepare(
        "UPDATE compass_fund_request_table cfr
         INNER JOIN procurement_requests purchase
            ON purchase.account_request_id = cfr.id
           AND purchase.request_type = 'local_advance_purchase'
           AND purchase.legacy_source_table = 'procurement_local_advance_purchases'
           AND purchase.account_request_type = 'compass_fund_request'
         SET cfr.procurement_root_po_id = purchase.po_id,
             cfr.procurement_request_type = purchase.request_variant,
             cfr.procurement_parent_purchase_id = purchase.legacy_parent_purchase_id,
             cfr.procurement_reconciliation_id = purchase.revision_reconciliation_id,
             cfr.procurement_current_po_revision_id = ?,
             cfr.procurement_current_po_revision_number = ?
         WHERE purchase.po_id = ? AND purchase.deleted_at IS NULL"
    );
    $compassCurrentRevisionUpdate->bind_param('iii', $revisionId, $revisionNumber, $poId);
    $compassCurrentRevisionUpdate->execute();
    $compassCurrentRevisionUpdate->close();

    $freshReconciliation = procurementLocalAdvanceFetchReconciliation(
        $conn,
        $reconciliationId,
        true
    );
    if (!$freshReconciliation) {
        throw new RuntimeException('The synchronized PO reconciliation could not be reloaded.', 500);
    }

    $sync = [
        'direction' => $direction,
        'status' => $status,
        'pending_reallocated_cents' => $pendingReallocatedCents,
        'supplementary_cents' => $supplementaryCents,
        'recovery_cents' => $recoveryCents,
        'supplementary_purchase_id' => $supplementaryPurchaseId,
        'supplementary_advance_payment_request_id' => $supplementaryRequestId,
        'resolution_type' => $resolutionType,
        'resolution_notes' => $resolutionNotes,
        'supplier_changed' => $supplierChanged,
        'supplier_adjustments' => $supplierAdjustments,
        'allocations' => $allocations,
    ];
    $accountReconciliationId = procurementLocalAdvanceUpsertAccountReconciliation(
        $conn,
        $freshReconciliation,
        $revision,
        $sync,
        $actor
    );
    procurementLocalAdvancePersistAccountAllocations(
        $conn,
        $accountReconciliationId,
        $allocations
    );

    $linkUpdate = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET account_reconciliation_id = ?, updated_by = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $linkUpdate->bind_param('iii', $accountReconciliationId, $actorId, $reconciliationId);
    $linkUpdate->execute();
    $linkUpdate->close();

    return [
        'account_reconciliation_id' => $accountReconciliationId,
        'direction' => $direction,
        'status' => $status,
        'supplementary_purchase_id' => $supplementaryPurchaseId,
        'supplementary_advance_payment_request_id' => $supplementaryRequestId,
        'supplementary_amount' => procurementLocalAdvanceCents($supplementaryCents),
        'recovery_amount' => procurementLocalAdvanceCents($recoveryCents),
        'pending_reallocated_amount' => procurementLocalAdvanceCents(
            $pendingReallocatedCents
        ),
        'supplier_changed' => $supplierChanged,
        'supplier_adjustments' => $supplierAdjustments,
        'allocations' => $allocations,
    ];
}

function procurementLocalAdvanceRefreshAccountReconciliationAfterPendingWhtAdjustment(
    mysqli $conn,
    int $purchaseId,
    string $newExpectedAmount,
    int $actorId
): void {
    if ($purchaseId <= 0) {
        return;
    }

    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT purchase.request_type, purchase.revision_reconciliation_id,
                allocation.id AS allocation_id,
                allocation.account_reconciliation_id,
                allocation.revised_expected_amount,
                account_reconciliation.id AS procurement_reconciliation_id,
                account_reconciliation.reconciliation_status
         FROM {$relation} purchase
         INNER JOIN account_advance_po_reconciliation_allocations allocation
            ON allocation.procurement_purchase_id = purchase.id
         INNER JOIN procurement_local_advance_po_revision_reconciliations
            account_reconciliation
            ON account_reconciliation.account_reconciliation_id =
               allocation.account_reconciliation_id
         WHERE purchase.id = ? AND purchase.deleted_at IS NULL
           AND account_reconciliation.reconciliation_status IN
               ('Pending Supplementary Payment', 'Recovery Required')
         ORDER BY account_reconciliation.updated_at DESC,
                  account_reconciliation.account_reconciliation_id DESC
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return;
    }

    $oldExpectedCents = procurementLocalAdvanceMoneyToCents(
        $row['revised_expected_amount'] ?? 0,
        'Previous Reconciled Advance Payment'
    );
    $newExpectedCents = procurementLocalAdvanceMoneyToCents(
        $newExpectedAmount,
        'Adjusted Reconciled Advance Payment'
    );
    $deltaCents = $newExpectedCents - $oldExpectedCents;
    if ($deltaCents === 0) {
        return;
    }

    $newExpected = procurementLocalAdvanceCents($newExpectedCents);
    $allocationId = (int) $row['allocation_id'];
    $accountReconciliationId = (int) $row['account_reconciliation_id'];
    $procurementReconciliationId = (int) $row['procurement_reconciliation_id'];
    $requestType = (string) ($row['request_type'] ?? 'Original');

    $allocationUpdate = $conn->prepare(
        "UPDATE account_advance_po_reconciliation_allocations
         SET revised_expected_amount = ?, amount_pending = ?
         WHERE id = ?"
    );
    $allocationUpdate->bind_param('ssi', $newExpected, $newExpected, $allocationId);
    $allocationUpdate->execute();
    if ($allocationUpdate->affected_rows !== 1) {
        $allocationUpdate->close();
        throw new RuntimeException(
            'The PO reconciliation allocation changed before the WHT update completed.',
            409
        );
    }
    $allocationUpdate->close();

    if ($requestType === 'Supplementary') {
        $purchaseReconciliationId = (int) ($row['revision_reconciliation_id'] ?? 0);
        if ($purchaseReconciliationId <= 0
            || $purchaseReconciliationId !== $procurementReconciliationId) {
            throw new RuntimeException(
                'The supplementary request is not linked to its active PO reconciliation.',
                409
            );
        }

        $procurementUpdate = $conn->prepare(
            "UPDATE procurement_local_advance_po_revision_reconciliations
             SET reconciliation_amount = ?, supplementary_amount = ?,
                 account_sync_status = 'Synced',
                 account_synced_by = ?, account_synced_at = NOW(),
                 account_sync_error = NULL, updated_by = ?, updated_at = NOW()
             WHERE id = ? AND reconciliation_status = 'Pending Supplementary Payment'"
        );
        $procurementUpdate->bind_param(
            'ssiii',
            $newExpected,
            $newExpected,
            $actorId,
            $actorId,
            $procurementReconciliationId
        );
        $procurementUpdate->execute();
        if ($procurementUpdate->affected_rows !== 1) {
            $procurementUpdate->close();
            throw new RuntimeException(
                'The supplementary PO reconciliation changed before the WHT update completed.',
                409
            );
        }
        $procurementUpdate->close();
    } else {
        $delta = procurementLocalAdvanceCents($deltaCents);
        $procurementUpdate = $conn->prepare(
            "UPDATE procurement_local_advance_po_revision_reconciliations
             SET revised_committed_amount = revised_committed_amount + ?,
                 pending_reallocated_amount = pending_reallocated_amount + ?,
                 account_sync_status = 'Synced', account_synced_by = ?,
                 account_synced_at = NOW(), account_sync_error = NULL,
                 updated_by = ?, updated_at = NOW()
             WHERE id = ? AND reconciliation_status IN
                 ('Pending Supplementary Payment', 'Recovery Required')"
        );
        $procurementUpdate->bind_param(
            'ssiii',
            $delta,
            $delta,
            $actorId,
            $actorId,
            $procurementReconciliationId
        );
        $procurementUpdate->execute();
        if ($procurementUpdate->affected_rows !== 1) {
            $procurementUpdate->close();
            throw new RuntimeException(
                'The active PO reconciliation changed before the WHT update completed.',
                409
            );
        }
        $procurementUpdate->close();
    }

    $snapshotStmt = $conn->prepare(
        "SELECT procurement_purchase_id, advance_payment_request_id,
                request_type, allocation_action, payment_status,
                exact_po_revision_id, exact_po_revision_number,
                current_po_revision_id, current_po_revision_number,
                previous_expected_amount, revised_expected_amount,
                amount_paid, amount_processing, amount_pending,
                recovery_allocated_amount
         FROM account_advance_po_reconciliation_allocations
         WHERE account_reconciliation_id = ?
         ORDER BY id ASC"
    );
    $snapshotStmt->bind_param('i', $accountReconciliationId);
    $snapshotStmt->execute();
    $allocations = $snapshotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $snapshotStmt->close();
    $snapshotJson = (string) json_encode(
        $allocations,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );

    $snapshotUpdate = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET allocation_snapshot_json = ?, updated_by = ?, updated_at = NOW()
         WHERE account_reconciliation_id = ?"
    );
    $snapshotUpdate->bind_param(
        'sii',
        $snapshotJson,
        $actorId,
        $accountReconciliationId
    );
    $snapshotUpdate->execute();
    $snapshotUpdate->close();
}


function procurementLocalAdvanceRefreshSupplementaryReconciliation(
    mysqli $conn,
    int $purchaseId,
    int $actorId
): void {
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT reconciliation.*,
                COALESCE(apr.payment_status, cfr.payment_status) AS account_payment_status,
                COALESCE(apr.amount_paid, cfr.amount_paid, 0.00) AS amount_paid,
                COALESCE(
                    purchase.account_expected_payment,
                    purchase.expected_payment,
                    apr.advance_payment,
                    cfr.amount
                ) AS advance_payment
         FROM procurement_local_advance_po_revision_reconciliations reconciliation
         INNER JOIN {$relation} purchase
            ON purchase.id = reconciliation.supplementary_purchase_id
         LEFT JOIN advance_payment_request apr
            ON apr.id = purchase.advance_payment_request_id
           AND (purchase.account_request_type IS NULL OR purchase.account_request_type = 'advance_payment_request')
         LEFT JOIN compass_fund_request_table cfr
            ON cfr.id = purchase.advance_payment_request_id
           AND purchase.account_request_type = 'compass_fund_request'
         WHERE purchase.id = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return;
    }

    $amountCents = procurementLocalAdvanceMoneyToCents(
        $row['reconciliation_amount'] ?? 0,
        'Supplementary Reconciliation Amount'
    );
    $paidCents = procurementLocalAdvanceMoneyToCents(
        $row['amount_paid'] ?? 0,
        'Supplementary Amount Paid'
    );
    $status = trim((string) ($row['account_payment_status'] ?? 'Pending'));
    $isResolved = $status === 'Paid' && $paidCents >= $amountCents;
    $paidAmount = procurementLocalAdvanceCents($paidCents);
    $reconciliationId = (int) $row['id'];

    if ($isResolved) {
        $resolutionType = 'Supplementary Payment';
        $notes = 'The supplementary Advance Fund Request has been paid in AcctLab.';
        $update = $conn->prepare(
            "UPDATE procurement_local_advance_po_revision_reconciliations
             SET reconciliation_status = 'Resolved', resolution_type = ?,
                 resolution_notes = ?, supplementary_amount_paid = ?,
                 resolved_by = ?, resolved_at = NOW(),
                 updated_by = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $update->bind_param(
            'sssiii',
            $resolutionType,
            $notes,
            $paidAmount,
            $actorId,
            $actorId,
            $reconciliationId
        );
        $update->execute();
        $update->close();

        $poStatus = $conn->prepare(
            "UPDATE procurement_local_advance_pos
             SET amendment_status = 'Resolved', updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE id = ?"
        );
        $poId = (int) $row['po_id'];
        $poStatus->bind_param('ii', $actorId, $poId);
        $poStatus->execute();
        $poStatus->close();
    } else {
        $update = $conn->prepare(
            "UPDATE procurement_local_advance_po_revision_reconciliations
             SET reconciliation_status = 'Pending Supplementary Payment',
                 resolution_type = NULL, resolution_notes = NULL,
                 supplementary_amount_paid = ?,
                 resolved_by = NULL, resolved_at = NULL,
                 updated_by = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $update->bind_param('sii', $paidAmount, $actorId, $reconciliationId);
        $update->execute();
        $update->close();

        $poStatus = $conn->prepare(
            "UPDATE procurement_local_advance_pos
             SET amendment_status = 'Supplementary Required', updated_by = ?,
                 updated_at = NOW(), version = version + 1
             WHERE id = ? AND amendment_status = 'Resolved'"
        );
        $poId = (int) $row['po_id'];
        $poStatus->bind_param('ii', $actorId, $poId);
        $poStatus->execute();
        $poStatus->close();
    }
}


function procurementLocalAdvanceListAccountPoReconciliations(
    mysqli $conn,
    array $filters = []
): array {
    $where = ['reconciliation.account_reconciliation_id IS NOT NULL'];
    $params = [];
    $types = '';

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'reconciliation.reconciliation_status = ?';
        $params[] = $status;
        $types .= 's';
    }
    $poId = (int) ($filters['po_id'] ?? 0);
    if ($poId > 0) {
        $where[] = 'reconciliation.po_id = ?';
        $params[] = $poId;
        $types .= 'i';
    }
    $requestId = (int) ($filters['advance_payment_request_id'] ?? 0);
    if ($requestId > 0) {
        $where[] = "(reconciliation.supplementary_advance_payment_request_id = ?
            OR EXISTS (
                SELECT 1
                FROM account_advance_po_reconciliation_allocations request_allocation
                WHERE request_allocation.account_reconciliation_id =
                      reconciliation.account_reconciliation_id
                  AND request_allocation.advance_payment_request_id = ?
            ))";
        $params[] = $requestId;
        $params[] = $requestId;
        $types .= 'ii';
    }

    $sql = "SELECT
                reconciliation.account_reconciliation_id AS id,
                reconciliation.id AS procurement_reconciliation_id,
                reconciliation.po_id,
                revision.po_number,
                reconciliation.revision_id AS po_revision_id,
                revision.revision_number AS po_revision_number,
                reconciliation.previous_revision_id AS previous_po_revision_id,
                reconciliation.reconciliation_direction,
                reconciliation.reconciliation_status,
                reconciliation.previous_committed_amount,
                reconciliation.revised_committed_amount,
                reconciliation.total_paid_at_revision,
                reconciliation.total_processing_at_revision,
                reconciliation.total_pending_at_revision,
                reconciliation.pending_reallocated_amount,
                reconciliation.supplementary_amount,
                reconciliation.supplementary_amount_paid,
                reconciliation.recovery_amount,
                reconciliation.supplementary_purchase_id,
                reconciliation.supplementary_advance_payment_request_id,
                reconciliation.resolution_type,
                reconciliation.recovery_reference AS resolution_reference,
                reconciliation.resolution_notes,
                reconciliation.allocation_snapshot_json,
                reconciliation.account_synced_by AS synced_by,
                reconciliation.account_synced_at AS synced_at,
                reconciliation.resolved_by,
                reconciliation.resolved_at,
                reconciliation.updated_by,
                reconciliation.updated_at
            FROM procurement_local_advance_po_revision_reconciliations reconciliation
            INNER JOIN procurement_local_advance_po_revisions revision
               ON revision.id = reconciliation.revision_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY reconciliation.updated_at DESC,
                     reconciliation.account_reconciliation_id DESC";
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($records === []) {
        return [];
    }
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $records);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $allocationTypes = str_repeat('i', count($ids));
    $allocationStmt = $conn->prepare(
        "SELECT * FROM account_advance_po_reconciliation_allocations
         WHERE account_reconciliation_id IN ($placeholders)
         ORDER BY account_reconciliation_id, id"
    );
    $allocationStmt->bind_param($allocationTypes, ...$ids);
    $allocationStmt->execute();
    $allocations = $allocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocationStmt->close();

    $grouped = [];
    foreach ($allocations as $allocation) {
        $grouped[(int) $allocation['account_reconciliation_id']][] = $allocation;
    }
    $eventGroups = procurementLocalAdvanceAccountWorkflowEvents($conn, $ids);
    foreach ($records as &$record) {
        $record['allocations'] = $grouped[(int) $record['id']] ?? [];
        $record['events'] = $eventGroups[(int) $record['id']] ?? [];
        $snapshot = json_decode((string) ($record['allocation_snapshot_json'] ?? ''), true);
        $record['allocation_snapshot'] = is_array($snapshot) ? $snapshot : null;
        unset($record['allocation_snapshot_json']);
    }
    unset($record);
    return $records;
}


function procurementLocalAdvanceApprovePoAmendment(
    mysqli $conn,
    int $revisionId,
    array $actor
): array {
    if ($revisionId <= 0) {
        throw new RuntimeException('A valid PO amendment revision is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $revision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The PO amendment could not be found.', 404);
    }
    if ((string) $revision['revision_status'] !== 'Pending Approval') {
        throw new RuntimeException('Only a pending PO amendment can be approved.', 409);
    }

    $poId = (int) $revision['po_id'];
    $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The linked Local Advance PO could not be found.', 409);
    }
    $previousRevisionId = (int) ($revision['previous_revision_id'] ?? 0);
    if ($previousRevisionId <= 0 || $previousRevisionId !== (int) ($po['current_revision_id'] ?? 0)) {
        throw new RuntimeException(
            'The PO changed after this amendment was submitted. Cancel it and create a new amendment.',
            409
        );
    }
    $previousRevision = procurementLocalAdvanceFetchPoRevision($conn, $previousRevisionId, true);
    if (!$previousRevision || (string) $previousRevision['revision_status'] !== 'Current') {
        throw new RuntimeException('The previous PO revision is no longer current.', 409);
    }

    $paymentSummary = procurementLocalAdvancePoPaymentSummary($conn, $poId, true);
    $supplierChanged = procurementLocalAdvanceRevisionSupplierChanged($previousRevision, $revision);
    if ($supplierChanged
        && procurementLocalAdvanceMoneyToCents(
            $paymentSummary['total_processing'] ?? 0,
            'Processing Supplier Payment'
        ) > 0) {
        throw new RuntimeException(
            'Complete or cancel the current Account processing payment before approving a supplier change.',
            409
        );
    }
    $allocatedUnits = (int) $paymentSummary['allocated_percentage_units'];
    $revisedBaseCents = procurementLocalAdvanceMoneyToCents(
        $revision['advance_base_amount'],
        'Revised Expected Payment Base'
    );
    $revisedCommittedCents = procurementLocalAdvanceRevisedCommittedCents($conn, $poId, $revision);
    $previousCommittedCents = procurementLocalAdvanceMoneyToCents(
        $paymentSummary['previous_committed_amount'],
        'Previous Committed Amount'
    );
    $deltaCents = $revisedCommittedCents - $previousCommittedCents;
    $direction = $deltaCents > 0 ? 'Increase' : ($deltaCents < 0 ? 'Decrease' : 'No Change');
    $reconciliationStatus = $direction === 'Increase'
        ? 'Pending Supplementary Payment'
        : ($direction === 'Decrease' ? 'Recovery Required' : 'Resolved');
    $reconciliationAmount = procurementLocalAdvanceCents(abs($deltaCents));
    $resolutionType = $direction === 'No Change' ? 'No Financial Adjustment' : null;

    $insert = $conn->prepare(
        "INSERT INTO procurement_local_advance_po_revision_reconciliations
            (po_id, revision_id, previous_revision_id,
             previous_po_value, revised_po_value, po_value_delta,
             previous_advance_base_amount, revised_advance_base_amount, advance_base_delta,
             allocated_percentage_at_revision, previous_committed_amount, revised_committed_amount,
             total_paid_at_revision, total_processing_at_revision, total_pending_at_revision,
             reconciliation_amount, reconciliation_direction, reconciliation_status,
             resolution_type, created_by, resolved_by, resolved_at, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $previousPoCents = procurementLocalAdvanceMoneyToCents($previousRevision['po_value'], 'Previous PO Value');
    $revisedPoCents = procurementLocalAdvanceMoneyToCents($revision['po_value'], 'Revised PO Value');
    $previousBaseCents = procurementLocalAdvanceMoneyToCents(
        $previousRevision['advance_base_amount'],
        'Previous Expected Payment Base'
    );
    $params = [
        $poId, $revisionId, $previousRevisionId,
        procurementLocalAdvanceCents($previousPoCents),
        procurementLocalAdvanceCents($revisedPoCents),
        procurementLocalAdvanceCents($revisedPoCents - $previousPoCents),
        procurementLocalAdvanceCents($previousBaseCents),
        procurementLocalAdvanceCents($revisedBaseCents),
        procurementLocalAdvanceCents($revisedBaseCents - $previousBaseCents),
        $paymentSummary['allocated_percentage'],
        procurementLocalAdvanceCents($previousCommittedCents),
        procurementLocalAdvanceCents($revisedCommittedCents),
        $paymentSummary['total_paid'],
        $paymentSummary['total_processing'],
        $paymentSummary['total_pending'],
        $reconciliationAmount,
        $direction,
        $reconciliationStatus,
        $resolutionType,
        $actorId,
        $direction === 'No Change' ? $actorId : null,
        $direction === 'No Change' ? date('Y-m-d H:i:s') : null,
        $actorId,
    ];
    $types = 'iii' . str_repeat('s', 16) . 'iisi';
    $insert->bind_param($types, ...$params);
    $insert->execute();
    $reconciliationId = (int) $conn->insert_id;
    $insert->close();

    $supersede = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Superseded', superseded_at = NOW(), updated_by = ?, updated_at = NOW(),
             version = version + 1
         WHERE id = ? AND revision_status = 'Current'"
    );
    $supersede->bind_param('ii', $actorId, $previousRevisionId);
    $supersede->execute();
    if ($supersede->affected_rows !== 1) {
        $supersede->close();
        throw new RuntimeException('The current PO revision changed before approval completed.', 409);
    }
    $supersede->close();

    $approve = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Current', approved_by = ?, approved_at = NOW(),
             is_locked = 1, locked_at = COALESCE(locked_at, NOW()),
             locked_reason = COALESCE(locked_reason, 'Approved amendment to paid PO'),
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND revision_status = 'Pending Approval'"
    );
    $approve->bind_param('iii', $actorId, $actorId, $revisionId);
    $approve->execute();
    if ($approve->affected_rows !== 1) {
        $approve->close();
        throw new RuntimeException('The PO amendment changed before approval completed.', 409);
    }
    $approve->close();

    $amendmentStatus = $direction === 'Increase'
        ? 'Supplementary Required'
        : ($direction === 'Decrease' ? 'Recovery Required' : 'Approved');
    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos p
         INNER JOIN procurement_local_advance_po_revisions revision ON revision.id = ?
         SET p.po_number = revision.po_number,
             p.po_number_normalized = revision.po_number_normalized,
             p.project_id = revision.project_id,
             p.project_code = revision.project_code,
             p.project_name = revision.project_name,
             p.supplier_id = revision.supplier_id,
             p.supplier_name = revision.supplier_name,
             p.supplier_ledger = revision.supplier_ledger,
             p.wht_status = revision.wht_status,
             p.wht_rate = revision.wht_rate,
             p.wht_amount = revision.wht_amount,
             p.purchase_value = revision.purchase_value,
             p.po_subtotal = revision.po_subtotal,
             p.po_discount = revision.po_discount,
             p.po_other_charges = revision.po_other_charges,
             p.po_vat_status = revision.po_vat_status,
             p.po_vat_rate = revision.po_vat_rate,
             p.po_vat_amount = revision.po_vat_amount,
             p.po_value = revision.po_value,
             p.advance_base_amount = revision.advance_base_amount,
             p.po_status = revision.po_status,
             p.current_revision_id = revision.id,
             p.current_revision_number = revision.revision_number,
             p.amendment_status = ?,
             p.updated_by = ?, p.updated_at = NOW(), p.version = p.version + 1
         WHERE p.id = ?"
    );
    $updatePo->bind_param('isii', $revisionId, $amendmentStatus, $actorId, $poId);
    $updatePo->execute();
    if ($updatePo->affected_rows !== 1) {
        $updatePo->close();
        throw new RuntimeException('The amended PO could not be activated.', 409);
    }
    $updatePo->close();

    $accountSync = procurementLocalAdvanceSynchronizeApprovedAmendmentToAccount(
        $conn,
        $reconciliationId,
        $actor
    );
    $amendmentStatus = (string) $accountSync['status'] === 'Pending Supplementary Payment'
        ? 'Supplementary Required'
        : ((string) $accountSync['status'] === 'Recovery Required'
            ? 'Recovery Required'
            : 'Approved');
    $syncStatusUpdate = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = ?, updated_by = ?, updated_at = NOW(),
             version = version + 1
         WHERE id = ?"
    );
    $syncStatusUpdate->bind_param('sii', $amendmentStatus, $actorId, $poId);
    $syncStatusUpdate->execute();
    $syncStatusUpdate->close();

    $freshRevision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    $freshReconciliation = procurementLocalAdvanceFetchReconciliation(
        $conn,
        $reconciliationId,
        true
    ) ?? [];
    $eventDetails = [
        'reason' => (string) ($freshRevision['amendment_reason'] ?? ''),
        'amendment_type' => (string) ($freshRevision['amendment_type'] ?? ''),
        'previous_po_value' => (string) ($freshReconciliation['previous_po_value'] ?? '0.00'),
        'revised_po_value' => (string) ($freshReconciliation['revised_po_value'] ?? '0.00'),
        'previous_committed_amount' => (string) ($freshReconciliation['previous_committed_amount'] ?? '0.00'),
        'revised_committed_amount' => (string) ($freshReconciliation['revised_committed_amount'] ?? '0.00'),
        'total_paid_at_revision' => (string) ($freshReconciliation['total_paid_at_revision'] ?? '0.00'),
        'total_processing_at_revision' => (string) ($freshReconciliation['total_processing_at_revision'] ?? '0.00'),
        'total_pending_at_revision' => (string) ($freshReconciliation['total_pending_at_revision'] ?? '0.00'),
        'reconciliation_direction' => (string) ($freshReconciliation['reconciliation_direction'] ?? 'No Change'),
        'reconciliation_status' => (string) ($freshReconciliation['reconciliation_status'] ?? 'Resolved'),
        'reconciliation_amount' => (string) ($freshReconciliation['reconciliation_amount'] ?? '0.00'),
        'supplementary_purchase_id' => (int) ($freshReconciliation['supplementary_purchase_id'] ?? 0),
        'supplementary_advance_payment_request_id' => (int) ($freshReconciliation['supplementary_advance_payment_request_id'] ?? 0),
        'account_reconciliation_id' => (int) ($freshReconciliation['account_reconciliation_id'] ?? 0),
        'supplier_changed' => $supplierChanged,
        'supplier_adjustments' => $accountSync['supplier_adjustments'] ?? [],
        'account_sync' => $accountSync,
    ];
    procurementLocalAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        $reconciliationId,
        'amendment_approved',
        $actor,
        $eventDetails
    );
    procurementLocalAdvanceRecordAccountPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        $reconciliationId,
        'po_amendment_synchronized',
        $actor,
        $eventDetails
    );

    return [
        'revision' => $freshRevision,
        'reconciliation' => $freshReconciliation,
        'account_sync' => $accountSync,
    ];
}

function procurementLocalAdvanceRejectPoAmendment(
    mysqli $conn,
    int $revisionId,
    string $reason,
    array $actor
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('An amendment rejection reason is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $revision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The PO amendment could not be found.', 404);
    }
    if ((string) $revision['revision_status'] !== 'Pending Approval') {
        throw new RuntimeException('Only a pending PO amendment can be rejected.', 409);
    }

    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Rejected', rejected_by = ?, rejected_at = NOW(),
             rejection_reason = ?, updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND revision_status = 'Pending Approval'"
    );
    $stmt->bind_param('isii', $actorId, $reason, $actorId, $revisionId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The PO amendment changed before rejection completed.', 409);
    }
    $stmt->close();

    $poId = (int) $revision['po_id'];
    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Rejected', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ?"
    );
    $updatePo->bind_param('ii', $actorId, $poId);
    $updatePo->execute();
    $updatePo->close();

    $freshRevision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    procurementLocalAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        null,
        'amendment_rejected',
        $actor,
        ['reason' => $reason]
    );
    return $freshRevision;
}

function procurementLocalAdvanceCancelPoAmendment(
    mysqli $conn,
    int $revisionId,
    string $reason,
    array $actor
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('An amendment cancellation reason is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $revision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The PO amendment could not be found.', 404);
    }
    if (!in_array((string) $revision['revision_status'], ['Draft', 'Pending Approval'], true)) {
        throw new RuntimeException('Only a draft or pending PO amendment can be cancelled.', 409);
    }
    if ((int) ($revision['created_by'] ?? 0) !== $actorId
        && (string) ($actor['role'] ?? '') !== 'super_admin') {
        throw new RuntimeException('Only the amendment creator or a Super Admin can cancel it.', 403);
    }

    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(),
             cancellation_reason = ?, updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND revision_status IN ('Draft', 'Pending Approval')"
    );
    $stmt->bind_param('isii', $actorId, $reason, $actorId, $revisionId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The PO amendment changed before cancellation completed.', 409);
    }
    $stmt->close();

    $poId = (int) $revision['po_id'];
    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Cancelled', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ?"
    );
    $updatePo->bind_param('ii', $actorId, $poId);
    $updatePo->execute();
    $updatePo->close();

    $freshRevision = procurementLocalAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    procurementLocalAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        null,
        'amendment_cancelled',
        $actor,
        ['reason' => $reason]
    );
    return $freshRevision;
}

function procurementLocalAdvanceResolveRecoveryReconciliation(
    mysqli $conn,
    int $reconciliationId,
    mixed $resolutionTypeValue,
    string $reference,
    string $notes,
    array $actor
): array {
    $resolutionType = procurementLocalAdvanceValidateStatus(
        $resolutionTypeValue,
        PROCUREMENT_LOCAL_ADVANCE_RECOVERY_RESOLUTION_TYPES,
        'Resolution Type'
    );
    $reference = trim($reference);
    $notes = trim($notes);
    if ($reference === '') {
        throw new RuntimeException('A recovery, refund or supplier-credit reference is required.', 400);
    }
    if ($notes === '') {
        throw new RuntimeException('Resolution notes are required.', 400);
    }

    $reconciliation = procurementLocalAdvanceFetchReconciliation($conn, $reconciliationId, true);
    if (!$reconciliation) {
        throw new RuntimeException('The PO reconciliation could not be found.', 404);
    }
    if ((string) $reconciliation['reconciliation_direction'] !== 'Decrease'
        || (string) $reconciliation['reconciliation_status'] !== 'Recovery Required') {
        throw new RuntimeException('Only an unresolved decrease reconciliation can be resolved manually.', 409);
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET reconciliation_status = 'Resolved', resolution_type = ?, recovery_reference = ?,
             resolution_notes = ?, resolved_by = ?, resolved_at = NOW(),
             updated_by = ?, updated_at = NOW()
         WHERE id = ? AND reconciliation_status = 'Recovery Required'"
    );
    $stmt->bind_param('sssiii', $resolutionType, $reference, $notes, $actorId, $actorId, $reconciliationId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The PO reconciliation changed before resolution completed.', 409);
    }
    $stmt->close();

    $poId = (int) $reconciliation['po_id'];
    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Resolved', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ?"
    );
    $updatePo->bind_param('ii', $actorId, $poId);
    $updatePo->execute();
    $updatePo->close();

    $freshReconciliation = procurementLocalAdvanceFetchReconciliation(
        $conn,
        $reconciliationId,
        true
    ) ?? [];
    $revisionId = (int) ($freshReconciliation['revision_id'] ?? 0);
    $eventDetails = [
        'resolution_type' => $resolutionType,
        'recovery_reference' => $reference,
        'resolution_notes' => $notes,
        'reconciliation_direction' => (string) ($freshReconciliation['reconciliation_direction'] ?? 'Decrease'),
        'reconciliation_status' => (string) ($freshReconciliation['reconciliation_status'] ?? 'Resolved'),
        'reconciliation_amount' => (string) ($freshReconciliation['reconciliation_amount'] ?? '0.00'),
        'previous_committed_amount' => (string) ($freshReconciliation['previous_committed_amount'] ?? '0.00'),
        'revised_committed_amount' => (string) ($freshReconciliation['revised_committed_amount'] ?? '0.00'),
        'account_reconciliation_id' => (int) ($freshReconciliation['account_reconciliation_id'] ?? 0),
    ];
    procurementLocalAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        $reconciliationId,
        'reconciliation_resolved',
        $actor,
        $eventDetails
    );
    procurementLocalAdvanceRecordAccountPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        $reconciliationId,
        'po_recovery_resolved',
        $actor,
        $eventDetails
    );

    return $freshReconciliation;
}

function procurementLocalAdvanceSerializePoRevision(array $revision): array
{
    foreach ([
        'id', 'canonical_request_id', 'procurement_request_id', 'po_id', 'revision_number', 'previous_revision_id', 'is_locked',
        'created_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by',
        'updated_by', 'version',
    ] as $field) {
        if (array_key_exists($field, $revision)) {
            $revision[$field] = $revision[$field] === null ? null : (int) $revision[$field];
        }
    }
    foreach (['commercial_snapshot_json' => 'commercial_snapshot', 'change_summary_json' => 'change_summary'] as $source => $target) {
        $decoded = json_decode((string) ($revision[$source] ?? ''), true);
        $revision[$target] = is_array($decoded) ? $decoded : null;
        unset($revision[$source]);
    }
    return $revision;
}

function procurementLocalAdvanceSerializeReconciliation(array $reconciliation): array
{
    foreach ([
        'id', 'po_id', 'revision_id', 'previous_revision_id', 'supplementary_purchase_id',
        'supplementary_advance_payment_request_id', 'account_reconciliation_id',
        'account_synced_by', 'created_by', 'resolved_by', 'updated_by',
    ] as $field) {
        if (array_key_exists($field, $reconciliation)) {
            $reconciliation[$field] = $reconciliation[$field] === null
                ? null
                : (int) $reconciliation[$field];
        }
    }
    return $reconciliation;
}

function procurementLocalAdvanceListPoAmendments(mysqli $conn, int $poId): array
{
    if ($poId <= 0) {
        throw new RuntimeException('A valid Local Advance PO ID is required.', 400);
    }
    $po = procurementLocalAdvanceFetchPo($conn, $poId);
    if (!$po) {
        throw new RuntimeException('The Local Advance PO could not be found.', 404);
    }

    $revisionStmt = $conn->prepare(
        "SELECT revision.*,
                CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS rejected_by_name
         FROM procurement_local_advance_po_revisions revision
         LEFT JOIN user_table cu ON cu.id = revision.created_by
         LEFT JOIN user_table au ON au.id = revision.approved_by
         LEFT JOIN user_table ru ON ru.id = revision.rejected_by
         WHERE revision.po_id = ?
         ORDER BY revision.revision_number DESC, revision.id DESC"
    );
    $revisionStmt->bind_param('i', $poId);
    $revisionStmt->execute();
    $revisions = $revisionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $revisionStmt->close();
    $revisions = array_map('procurementLocalAdvanceSerializePoRevision', $revisions);

    $reconciliationStmt = $conn->prepare(
        "SELECT reconciliation.*,
                revision.revision_number, revision.revision_reference,
                CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS resolved_by_name
         FROM procurement_local_advance_po_revision_reconciliations reconciliation
         INNER JOIN procurement_local_advance_po_revisions revision ON revision.id = reconciliation.revision_id
         LEFT JOIN user_table cu ON cu.id = reconciliation.created_by
         LEFT JOIN user_table ru ON ru.id = reconciliation.resolved_by
         WHERE reconciliation.po_id = ?
         ORDER BY reconciliation.created_at DESC, reconciliation.id DESC"
    );
    $reconciliationStmt->bind_param('i', $poId);
    $reconciliationStmt->execute();
    $reconciliations = $reconciliationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reconciliationStmt->close();
    $reconciliations = array_map('procurementLocalAdvanceSerializeReconciliation', $reconciliations);

    return [
        'po' => $po,
        'payment_summary' => procurementLocalAdvancePoPaymentSummary($conn, $poId),
        'revisions' => $revisions,
        'reconciliations' => $reconciliations,
        'events' => procurementLocalAdvanceListPoWorkflowEvents($conn, $poId),
        'account_reconciliations' => procurementLocalAdvanceListAccountPoReconciliations(
            $conn,
            ['po_id' => $poId]
        ),
    ];
}

function procurementLocalAdvanceFetchRecord(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    if ($forUpdate) {
        $lockStmt = $conn->prepare(
            "SELECT id FROM procurement_requests
             WHERE request_type = 'local_advance_purchase'
               AND legacy_source_table = 'procurement_local_advance_purchases'
               AND legacy_source_id = ? AND deleted_at IS NULL
             LIMIT 1 FOR UPDATE"
        );
        $lockStmt->bind_param('i', $id);
        $lockStmt->execute();
        $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    }
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT r.*,
                COALESCE(pr.po_number, p.po_number) AS po_number,
                COALESCE(pr.po_number_normalized, p.po_number_normalized) AS po_number_normalized,
                COALESCE(pr.project_id, p.project_id) AS project_id,
                COALESCE(pr.project_code, p.project_code) AS project_code,
                COALESCE(pr.project_name, p.project_name) AS project_name,
                COALESCE(pr.supplier_id, p.supplier_id) AS supplier_id,
                COALESCE(pr.supplier_name, p.supplier_name) AS supplier_name,
                COALESCE(pr.supplier_ledger, p.supplier_ledger) AS supplier_ledger,
                COALESCE(pr.wht_status, p.wht_status) AS wht_status,
                COALESCE(pr.wht_rate, p.wht_rate) AS wht_rate,
                COALESCE(pr.wht_amount, p.wht_amount) AS wht_amount,
                CASE WHEN pr.id IS NOT NULL THEN pr.purchase_value ELSE p.purchase_value END AS purchase_value,
                COALESCE(pr.po_subtotal, p.po_subtotal) AS po_subtotal,
                COALESCE(pr.po_discount, p.po_discount) AS po_discount,
                COALESCE(pr.po_other_charges, p.po_other_charges) AS po_other_charges,
                COALESCE(pr.po_vat_status, p.po_vat_status) AS po_vat_status,
                COALESCE(pr.po_vat_rate, p.po_vat_rate) AS po_vat_rate,
                COALESCE(pr.po_vat_amount, p.po_vat_amount) AS po_vat_amount,
                COALESCE(pr.po_value, p.po_value) AS po_value,
                COALESCE(pr.advance_base_amount, p.advance_base_amount) AS advance_base_amount,
                p.po_status AS po_status,
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
                COALESCE(apr.supplier_credit_applied, 0.00) AS account_supplier_credit_applied,
                COALESCE(apr.cash_amount_paid, 0.00) AS account_cash_amount_paid,
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
         FROM {$relation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
         LEFT JOIN advance_payment_request apr
           ON apr.id = r.advance_payment_request_id
          AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = r.advance_payment_request_id
          AND r.account_request_type = 'compass_fund_request'
         LEFT JOIN user_table cu ON cu.id = r.created_by
         LEFT JOIN user_table uu ON uu.id = r.updated_by
         LEFT JOIN user_table au ON au.id = r.approved_by
         LEFT JOIN user_table ru ON ru.id = r.approval_reversed_by
         LEFT JOIN user_table rtu ON rtu.id = r.retrieved_by
         WHERE r.id = ? AND r.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        $row['allocated_percentage'] = procurementLocalAdvancePercentString(
            procurementLocalAdvanceAllocatedUnits($conn, (int) $row['po_id'])
        );

        $grossCents = procurementLocalAdvanceMoneyToCents(
            $row['account_advance_payment'] ?? 0,
            'Account Advance Payment',
            true
        );
        $summary = [
            'reserved_amount' => '0.00',
            'applied_amount' => '0.00',
            'effective_offset_amount' => '0.00',
            'basis' => '',
            'reason' => '',
            'references' => [],
        ];
        if ((int) ($row['advance_payment_request_id'] ?? 0) > 0
            && (string) ($row['account_request_type'] ?? '') !== PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
            $summary = procurementSupplierPaymentOffsetSummaryForRequest(
                $conn,
                'local_advance_purchase',
                (int) $row['advance_payment_request_id']
            );
        }
        $offsetCents = min(
            $grossCents,
            max(
                procurementLocalAdvanceMoneyToCents(
                    $summary['effective_offset_amount'] ?? 0,
                    'Supplier Offset',
                    true
                ),
                procurementLocalAdvanceMoneyToCents(
                    $row['account_supplier_credit_applied'] ?? 0,
                    'Account Supplier Credit',
                    true
                )
            )
        );
        $row['account_supplier_offset_reserved'] = (string) ($summary['reserved_amount'] ?? '0.00');
        $row['account_supplier_offset_applied'] = (string) ($summary['applied_amount'] ?? '0.00');
        $row['account_supplier_offset_amount'] = procurementLocalAdvanceCents($offsetCents);
        $row['account_net_cash_payable'] = procurementLocalAdvanceCents(max(0, $grossCents - $offsetCents));
        $row['account_offset_basis'] = trim((string) ($summary['basis'] ?? ''));
        $row['account_offset_reason'] = trim((string) ($summary['reason'] ?? ''));
        $row['account_offset_references'] = is_array($summary['references'] ?? null)
            ? $summary['references']
            : [];
    }
    return $row;
}

function procurementLocalAdvanceSerializeRecord(array $row): array
{
    foreach ([
        'id', 'po_id', 'project_id', 'supplier_id', 'advance_payment_request_id',
        'previous_advance_payment_request_id', 'approved_by', 'approval_reversed_by',
        'retrieved_by', 'created_by', 'updated_by', 'version', 'po_version',
        'handoff_revision', 'account_payment_batch_id', 'payment_batch_id',
        'account_wht_adjusted_by', 'po_revision_id', 'po_revision_number',
        'parent_purchase_id', 'revision_reconciliation_id', 'current_revision_id',
        'current_revision_number', 'po_revision_is_locked',
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }

    $allocatedUnits = procurementLocalAdvancePercentUnitsAllowZero($row['allocated_percentage'] ?? 0);
    $row['allocated_percentage'] = procurementLocalAdvancePercentString($allocatedUnits);
    $row['available_percentage'] = procurementLocalAdvancePercentString(
        max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $allocatedUnits)
    );

    $accountPaymentStatus = trim((string) ($row['account_payment_status'] ?? ''));
    $row['is_compass_handoff'] = (string) ($row['account_request_type'] ?? '') === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS;
    $row['is_retrieved'] = ($row['handoff_status'] ?? '') === 'Retrieved';
    $row['is_editable'] = ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending'
        && empty($row['advance_payment_request_id']);
    $row['is_deletable'] = $row['is_editable'] && !$row['is_retrieved'];
    $row['is_approvable'] = $row['is_editable']
        && (string) ($row['po_status'] ?? '') !== 'Cancelled';
    $row['is_reversible'] = ($row['approval_status'] ?? '') === 'Approved'
        && ($row['handoff_status'] ?? '') === 'In Account'
        && ($row['payment_status'] ?? '') === 'Pending'
        && ($accountPaymentStatus === '' || $accountPaymentStatus === 'Pending')
        && !empty($row['advance_payment_request_id']);
    $row['is_retrievable'] = $row['is_reversible'];
    $row['is_resubmittable'] = $row['is_retrieved']
        && ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending';
    $row['has_pending_po_amendment'] = (string) ($row['amendment_status'] ?? '') === 'Pending Approval';
    $row['is_po_amendable'] = (int) ($row['po_revision_is_locked'] ?? 0) === 1
        && !$row['has_pending_po_amendment']
        && (
            in_array((string) ($row['payment_status'] ?? ''), ['Processing', 'Paid'], true)
            || (float) ($row['amount_paid'] ?? 0) > 0
            || !empty($row['payment_batch_id'])
            || !empty($row['payment_processing_started_at'])
            || !empty($row['paid_at'])
        );

    return $row;
}

function procurementLocalAdvanceRecordEvent(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): void {
    $actorId = (int) ($actor['id'] ?? 0);
    $actorEmail = (string) ($actor['email'] ?? 'system');
    $detailsJson = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $eventId = procurementRequestCanonicalRecordEvent(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        $purchaseId,
        $eventType,
        $actorId,
        $actorEmail,
        $detailsJson
    );

    if ($eventId > 0) {
        $details['procurement_event_id'] = $eventId;
    }
    procurementNotificationPublishLocalAdvanceEvent($conn, $purchaseId, $eventType, $actor, $details);
    procurementNotificationMirrorLocalAdvanceEventToAccount($conn, $purchaseId, $eventType, $actor, $details);
}

function procurementLocalAdvanceLegacyVatStatus(array $purchase): string
{
    if (strcasecmp((string) ($purchase['po_vat_status'] ?? 'No'), 'Yes') !== 0) {
        return '0.00%';
    }

    $effectiveWhtRate = $purchase['account_wht_override_rate']
        ?? $purchase['wht_rate']
        ?? 0;
    $whtBasisPoints = (int) round(((float) $effectiveWhtRate) * 10000);
    if ($whtBasisPoints === 200) {
        return '2.00%';
    }
    if ($whtBasisPoints === 500) {
        return '5.00%';
    }
    return '7.50%';
}

function procurementLocalAdvanceFindReusableCompassFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    string $expectedAmount
): ?array {
    procurementCompassAssertStorageReady($conn);
    $purchaseId = (int) ($purchase['id'] ?? 0);
    $source = PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE;

    $existing = $conn->prepare(
        "SELECT * FROM compass_fund_request_table
         WHERE procurement_source = ? AND procurement_purchase_id = ?
         LIMIT 2 FOR UPDATE"
    );
    $existing->bind_param('si', $source, $purchaseId);
    $existing->execute();
    $linked = $existing->get_result()->fetch_all(MYSQLI_ASSOC);
    $existing->close();
    if (count($linked) > 1) {
        throw new RuntimeException('More than one Compass Fund Request is linked to this Local Advance Purchase.', 409);
    }
    if ($linked !== []) {
        $request = $linked[0];
        if ((string) ($request['payment_status'] ?? 'Pending') !== 'Pending') {
            throw new RuntimeException('The existing Compass Fund Request is already being processed and cannot be reused.', 409);
        }
        return $request;
    }

    $supplierId = (int) ($purchase['supplier_id'] ?? 0);
    $supplierName = trim((string) ($purchase['supplier_name'] ?? ''));
    $purchaseNumber = trim((string) ($purchase['purchase_number'] ?? ''));
    $poNumber = trim((string) ($purchase['po_number'] ?? ''));
    $candidateStmt = $conn->prepare(
        "SELECT * FROM compass_fund_request_table
         WHERE (procurement_purchase_id IS NULL OR procurement_purchase_id = 0)
           AND (procurement_source IS NULL OR TRIM(procurement_source) = '')
           AND payment_status = 'Pending'
           AND (supplier_id = ? OR LOWER(TRIM(suppliers_name)) = LOWER(TRIM(?)))
           AND (
                invoice_number = ? OR purchase_number = ? OR po_number = ?
           )
         ORDER BY id ASC
         LIMIT 10 FOR UPDATE"
    );
    $candidateStmt->bind_param(
        'issss',
        $supplierId,
        $supplierName,
        $purchaseNumber,
        $purchaseNumber,
        $poNumber
    );
    $candidateStmt->execute();
    $candidates = $candidateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $candidateStmt->close();

    $expectedCents = procurementLocalAdvanceMoneyToCents($expectedAmount, 'Expected Compass Advance Amount');
    $percentage = procurementLocalAdvancePercentString(
        procurementLocalAdvancePercentUnitsAllowZero($purchase['po_percentage'] ?? 0)
    );
    $matches = [];
    foreach ($candidates as $candidate) {
        $candidateAmountCents = procurementLocalAdvanceMoneyToCents(
            $candidate['amount'] ?? 0,
            'Existing Compass Fund Request Amount'
        );
        $candidatePercentage = procurementLocalAdvancePercentString(
            procurementLocalAdvancePercentUnitsAllowZero($candidate['percentage'] ?? 0)
        );
        if ($candidateAmountCents === $expectedCents && $candidatePercentage === $percentage) {
            $matches[] = $candidate;
        }
    }
    if (count($matches) > 1) {
        throw new RuntimeException(
            'Approval cannot continue because more than one matching unlinked Compass advance request already exists.',
            409
        );
    }
    if ($matches === []) {
        return null;
    }

    $request = $matches[0];
    $requestId = (int) $request['id'];
    $requestType = (string) ($purchase['request_type'] ?? 'Original');
    $parentPurchaseId = (int) ($purchase['parent_purchase_id'] ?? 0);
    $reconciliationId = (int) ($purchase['revision_reconciliation_id'] ?? 0);
    $poId = (int) ($purchase['po_id'] ?? 0);
    $poRevisionId = (int) ($purchase['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($purchase['po_revision_number'] ?? 1));
    $poSnapshotJson = (string) ($purchase['po_snapshot_json'] ?? '');
    $currentPoRevisionId = (int) ($purchase['current_revision_id'] ?? $poRevisionId);
    $currentPoRevisionNumber = max(1, (int) ($purchase['current_revision_number'] ?? $poRevisionNumber));

    $link = $conn->prepare(
        "UPDATE compass_fund_request_table
         SET procurement_source = ?, procurement_purchase_id = ?, procurement_root_po_id = ?,
             procurement_request_type = ?, procurement_parent_purchase_id = NULLIF(?, 0),
             procurement_reconciliation_id = NULLIF(?, 0), procurement_revision = ?,
             procurement_po_revision_id = NULLIF(?, 0), procurement_po_revision_number = ?,
             procurement_po_snapshot_json = ?,
             procurement_current_po_revision_id = NULLIF(?, 0),
             procurement_current_po_revision_number = ?,
             purchase_number = COALESCE(NULLIF(TRIM(purchase_number), ''), ?),
             po_number = COALESCE(NULLIF(TRIM(po_number), ''), ?)
         WHERE id = ? AND (procurement_purchase_id IS NULL OR procurement_purchase_id = 0)"
    );
    $link->bind_param(
        'siisiiiisisissi',
        $source,
        $purchaseId,
        $poId,
        $requestType,
        $parentPurchaseId,
        $reconciliationId,
        $revision,
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson,
        $currentPoRevisionId,
        $currentPoRevisionNumber,
        $purchaseNumber,
        $poNumber,
        $requestId
    );
    $link->execute();
    if ($link->affected_rows !== 1) {
        $link->close();
        throw new RuntimeException('The matching Compass Fund Request changed before it could be linked.', 409);
    }
    $link->close();

    $request['procurement_source'] = $source;
    $request['procurement_purchase_id'] = $purchaseId;
    $request['procurement_request_type'] = $requestType;
    $request['procurement_revision'] = $revision;
    return $request;
}

function procurementLocalAdvanceCreateCompassFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    ?string $advancePaymentOverride = null
): array {
    procurementCompassAssertStorageReady($conn);
    if (!procurementCompassIsSupplier($purchase['supplier_id'] ?? null, $purchase['supplier_name'] ?? null)) {
        throw new RuntimeException('Compass advance routing is only available for Compass Power Solutions Ltd.', 409);
    }

    $approvalDateRow = $conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc();
    $approvalDate = (string) ($approvalDateRow['approval_date'] ?? date('Y-m-d'));
    $supplierName = (string) $purchase['supplier_name'];
    $supplierId = (int) $purchase['supplier_id'];
    $projectCode = (string) $purchase['project_code'];
    $poNumber = (string) $purchase['po_number'];
    $purchaseNumber = trim((string) ($purchase['purchase_number'] ?? ''));
    if ($purchaseNumber === '') {
        $purchaseNumber = trim((string) ($purchase['request_number'] ?? ''));
    }
    $percentage = (string) $purchase['po_percentage'];
    $subtotal = (string) $purchase['po_subtotal'];
    $discount = (string) $purchase['po_discount'];
    $otherCharges = (string) $purchase['po_other_charges'];
    $netCents = procurementLocalAdvanceMoneyToCents($subtotal, 'PO Subtotal')
        - procurementLocalAdvanceMoneyToCents($discount, 'PO Discount');
    $vat = (string) $purchase['po_vat_amount'];
    $vatCents = procurementLocalAdvanceMoneyToCents($vat, 'PO VAT Amount');
    $hasVat = $vatCents > 0
        && strcasecmp((string) ($purchase['po_vat_status'] ?? 'No'), 'Yes') === 0;
    $hasAccountWhtOverride = trim((string) ($purchase['account_wht_override_status'] ?? '')) !== '';
    $wht = $hasVat
        ? (string) ($hasAccountWhtOverride
            ? ($purchase['account_wht_override_amount'] ?? '0.00')
            : $purchase['wht_amount'])
        : '0.00';
    $amountPayableCents = $netCents + $vatCents
        - procurementLocalAdvanceMoneyToCents($wht, 'WHT Amount');
    if ($amountPayableCents < 0) {
        throw new RuntimeException('Calculated Compass advance amount cannot be negative.', 500);
    }
    $otherChargesCents = procurementLocalAdvanceMoneyToCents($otherCharges, 'PO Other Charges');
    $percentageUnits = procurementLocalAdvancePercentUnitsAllowZero($percentage);
    $calculatedAdvanceCents = procurementLocalAdvanceMultiplyDivideRounded(
        $amountPayableCents + $otherChargesCents,
        $percentageUnits,
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
    );
    $advancePayment = $advancePaymentOverride !== null
        ? procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($advancePaymentOverride, 'Supplementary Compass Advance Payment')
        )
        : procurementLocalAdvanceCents($calculatedAdvanceCents);

    $reusable = procurementLocalAdvanceFindReusableCompassFundRequest(
        $conn,
        $purchase,
        $revision,
        $advancePayment
    );
    if ($reusable !== null) {
        return ['id' => (int) $reusable['id'], 'reused' => true, 'request' => $reusable];
    }

    $vatPolicy = procurementLocalAdvanceLegacyVatStatus($purchase);
    $vatRate = $hasVat ? (string) $purchase['po_vat_rate'] : '0.000000';
    $vatStatus = $hasVat ? (string) $purchase['po_vat_status'] : 'No';
    $whtStatus = $hasVat
        ? (string) ($hasAccountWhtOverride
            ? $purchase['account_wht_override_status']
            : $purchase['wht_status'])
        : '0.00%';
    $whtRate = $hasVat
        ? (string) ($hasAccountWhtOverride
            ? ($purchase['account_wht_override_rate'] ?? '0.000000')
            : $purchase['wht_rate'])
        : '0.000000';
    $requestVariant = (string) ($purchase['request_type'] ?? 'Original');
    $description = procurementLocalAdvanceSubstring(
        $requestVariant . ' advance payment - PO ' . $poNumber,
        255
    );
    $classification = 'Procurement';
    $paymentStatus = 'Pending';
    $source = PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE;
    $purchaseId = (int) $purchase['id'];
    $poId = (int) ($purchase['po_id'] ?? 0);
    $parentPurchaseId = (int) ($purchase['parent_purchase_id'] ?? 0);
    $reconciliationId = (int) ($purchase['revision_reconciliation_id'] ?? 0);
    $poRevisionId = (int) ($purchase['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($purchase['po_revision_number'] ?? 1));
    $poSnapshotJson = trim((string) ($purchase['po_snapshot_json'] ?? ''));
    if ($poRevisionId <= 0 || $poSnapshotJson === '') {
        $poRevision = procurementLocalAdvanceResolveCurrentPoRevision($conn, $poId, true);
        $poRevisionId = (int) $poRevision['id'];
        $poRevisionNumber = (int) $poRevision['revision_number'];
        $poSnapshotJson = (string) $poRevision['commercial_snapshot_json'];
    }
    $currentPoRevisionId = (int) ($purchase['current_revision_id'] ?? $poRevisionId);
    $currentPoRevisionNumber = max(1, (int) ($purchase['current_revision_number'] ?? $poRevisionNumber));
    $transactionDate = trim((string) ($purchase['transaction_date'] ?? '')) ?: $approvalDate;
    $noteParts = ['ProcureDesk ' . trim((string) ($purchase['request_number'] ?? $purchaseNumber))];
    $remark = trim((string) ($purchase['remark'] ?? ''));
    if ($remark !== '') {
        $noteParts[] = $remark;
    }
    $note = procurementLocalAdvanceSubstring(implode(' | ', array_filter($noteParts)), 255);
    $overrideStatus = $hasAccountWhtOverride ? (string) $purchase['account_wht_override_status'] : null;
    $overrideRate = $hasAccountWhtOverride ? (string) ($purchase['account_wht_override_rate'] ?? '0.000000') : null;
    $overrideAmount = $hasAccountWhtOverride ? (string) ($purchase['account_wht_override_amount'] ?? '0.00') : null;
    $overrideReason = $hasAccountWhtOverride ? (string) ($purchase['account_wht_adjustment_reason'] ?? '') : null;
    $overrideBy = $hasAccountWhtOverride ? (int) ($purchase['account_wht_adjusted_by'] ?? 0) : 0;
    $overrideAt = $hasAccountWhtOverride ? ($purchase['account_wht_adjusted_at'] ?? null) : null;

    $stmt = $conn->prepare(
        'INSERT INTO compass_fund_request_table
            (suppliers_name, supplier_id, invoice_number, purchase_number, po_number,
             invoice_date, purchase_date, date_received, project_code, description,
             classification, percentage, net_value, vat_policy, vat, vat_rate, vat_status,
             wht, wht_status, wht_rate, discount, other_charges, amount, note, payment_status,
             wht_override_status, wht_override_rate, wht_override_amount,
             wht_override_reason, wht_override_by, wht_override_at,
             procurement_source, procurement_purchase_id, procurement_root_po_id,
             procurement_request_type, procurement_parent_purchase_id,
             procurement_reconciliation_id, procurement_revision,
             procurement_po_revision_id, procurement_po_revision_number,
             procurement_po_snapshot_json, procurement_current_po_revision_id,
             procurement_current_po_revision_number, account_remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?,
                 NULLIF(?, 0), ?, ?, NULLIF(?, 0), ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Compass Advance Fund Request handoff.', 500);
    }
    $accountRemarks = $note;
    $stmt->bind_param(
        'sisssssssssssssssssssssssssssissiisiiiiisiis',
        $supplierName,
        $supplierId,
        $purchaseNumber,
        $purchaseNumber,
        $poNumber,
        $transactionDate,
        $transactionDate,
        $approvalDate,
        $projectCode,
        $description,
        $classification,
        $percentage,
        $subtotal,
        $vatPolicy,
        $vat,
        $vatRate,
        $vatStatus,
        $wht,
        $whtStatus,
        $whtRate,
        $discount,
        $otherCharges,
        $advancePayment,
        $note,
        $paymentStatus,
        $overrideStatus,
        $overrideRate,
        $overrideAmount,
        $overrideReason,
        $overrideBy,
        $overrideAt,
        $source,
        $purchaseId,
        $poId,
        $requestVariant,
        $parentPurchaseId,
        $reconciliationId,
        $revision,
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson,
        $currentPoRevisionId,
        $currentPoRevisionNumber,
        $accountRemarks
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    if ($id <= 0) {
        throw new RuntimeException('The Compass Advance Fund Request could not be created.', 500);
    }
    return ['id' => $id, 'reused' => false, 'request' => null];
}

function procurementLocalAdvanceCreateAccountFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    string $accountRequestType,
    ?string $advancePaymentOverride = null
): array {
    if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
        return procurementLocalAdvanceCreateCompassFundRequest(
            $conn,
            $purchase,
            $revision,
            $advancePaymentOverride
        );
    }
    if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE) {
        return [
            'id' => procurementLocalAdvanceCreateAdvancePaymentRequest(
                $conn,
                $purchase,
                $revision,
                $advancePaymentOverride
            ),
            'reused' => false,
            'request' => null,
        ];
    }
    throw new RuntimeException('Unsupported Local Advance Account handoff destination.', 500);
}

function procurementLocalAdvanceCreateAdvancePaymentRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    ?string $advancePaymentOverride = null
): int {
    $purchaseId = (int) $purchase['id'];
    $existing = $conn->prepare(
        "SELECT id FROM advance_payment_request
         WHERE procurement_source = 'local_advance_purchase' AND procurement_purchase_id = ?
         LIMIT 1"
    );
    $existing->bind_param('i', $purchaseId);
    $existing->execute();
    $duplicate = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($duplicate) {
        throw new RuntimeException('This Local Advance Purchase already has an active Advance Fund Request.', 409);
    }

    $approvalDateRow = $conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc();
    $approvalDate = (string) ($approvalDateRow['approval_date'] ?? date('Y-m-d'));
    $supplierName = (string) $purchase['supplier_name'];
    $supplierLedger = (int) $purchase['supplier_ledger'];
    $site = (string) $purchase['project_code'];
    $poNumber = (string) $purchase['po_number'];
    $percentage = (string) $purchase['po_percentage'];
    $amount = (string) $purchase['po_subtotal'];
    $discount = (string) $purchase['po_discount'];
    $netCents = procurementLocalAdvanceMoneyToCents($amount, 'PO Subtotal')
        - procurementLocalAdvanceMoneyToCents($discount, 'PO Discount');
    $vat = (string) $purchase['po_vat_amount'];
    $vatCents = procurementLocalAdvanceMoneyToCents($vat, 'PO VAT Amount');
    $hasVat = $vatCents > 0
        && strcasecmp((string) ($purchase['po_vat_status'] ?? 'No'), 'Yes') === 0;
    $hasAccountWhtOverride = trim((string) ($purchase['account_wht_override_status'] ?? '')) !== '';
    $wht = $hasVat
        ? (string) ($hasAccountWhtOverride
            ? ($purchase['account_wht_override_amount'] ?? '0.00')
            : $purchase['wht_amount'])
        : '0.00';
    $amountPayableCents = $netCents
        + $vatCents
        - procurementLocalAdvanceMoneyToCents($wht, 'WHT Amount');
    if ($amountPayableCents < 0) {
        throw new RuntimeException('Calculated Advance Fund Request amount cannot be negative.', 500);
    }

    $netAmount = procurementLocalAdvanceCents($netCents);
    $amountPayable = procurementLocalAdvanceCents($amountPayableCents);
    $otherCharges = (string) $purchase['po_other_charges'];
    $otherChargesCents = procurementLocalAdvanceMoneyToCents($otherCharges, 'PO Other Charges');
    $percentageUnits = procurementLocalAdvancePercentUnitsAllowZero(
        (string) $purchase['po_percentage']
    );
    $calculatedAdvanceCents = procurementLocalAdvanceMultiplyDivideRounded(
        $amountPayableCents + $otherChargesCents,
        $percentageUnits,
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
    );
    $advancePayment = $advancePaymentOverride !== null
        ? procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents(
                $advancePaymentOverride,
                'Supplementary Advance Payment'
            )
        )
        : procurementLocalAdvanceCents($calculatedAdvanceCents);
    $paymentStatus = 'Pending';
    $vatStatus = procurementLocalAdvanceLegacyVatStatus($purchase);
    $vatRate = $hasVat ? (string) $purchase['po_vat_rate'] : '0.000000';
    $whtStatus = $hasVat
        ? (string) ($hasAccountWhtOverride
            ? $purchase['account_wht_override_status']
            : $purchase['wht_status'])
        : '0.00%';
    $whtRate = $hasVat
        ? (string) ($hasAccountWhtOverride
            ? ($purchase['account_wht_override_rate'] ?? '0.000000')
            : $purchase['wht_rate'])
        : '0.000000';
    $reference = trim((string) ($purchase['request_number'] ?? ''));
    $remark = trim((string) ($purchase['remark'] ?? ''));
    $noteParts = [];
    if ($reference !== '') {
        $noteParts[] = 'ProcureDesk ' . $reference;
    }
    if ($remark !== '') {
        $noteParts[] = $remark;
    }
    $accountRemarks = implode(' | ', $noteParts);
    $note = procurementLocalAdvanceSubstring($accountRemarks, 255);
    $source = 'local_advance_purchase';
    $poId = (int) ($purchase['po_id'] ?? 0);
    $requestType = (string) ($purchase['request_type'] ?? 'Original');
    $parentPurchaseId = (int) ($purchase['parent_purchase_id'] ?? 0);
    $reconciliationId = (int) ($purchase['revision_reconciliation_id'] ?? 0);
    $poRevisionId = (int) ($purchase['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($purchase['po_revision_number'] ?? 1));
    $poSnapshotJson = trim((string) ($purchase['po_snapshot_json'] ?? ''));
    if ($poRevisionId <= 0 || $poSnapshotJson === '') {
        $poRevision = procurementLocalAdvanceResolveCurrentPoRevision(
            $conn,
            $poId,
            true
        );
        $poRevisionId = (int) $poRevision['id'];
        $poRevisionNumber = (int) $poRevision['revision_number'];
        $poSnapshotJson = (string) $poRevision['commercial_snapshot_json'];
    }

    $stmt = $conn->prepare(
        'INSERT INTO advance_payment_request
            (suppliers_name, supplier_id, site, po_number, date_received, percentage,
             amount, discount, net_amount, vat, vat_rate, vat_status, wht_status, wht_rate,
             wht, amount_payable, other_charges, advance_payment, payment_status, note,
             procurement_source, procurement_purchase_id, procurement_root_po_id,
             procurement_request_type, procurement_parent_purchase_id,
             procurement_reconciliation_id, procurement_revision,
             procurement_po_revision_id, procurement_po_revision_number,
             procurement_po_snapshot_json, procurement_current_po_revision_id,
             procurement_current_po_revision_number, account_remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Advance Fund Request handoff.', 500);
    }
    $stmt->bind_param(
        'sisssssssssssssssssssiisiiiiisiis',
        $supplierName,
        $supplierLedger,
        $site,
        $poNumber,
        $approvalDate,
        $percentage,
        $amount,
        $discount,
        $netAmount,
        $vat,
        $vatRate,
        $vatStatus,
        $whtStatus,
        $whtRate,
        $wht,
        $amountPayable,
        $otherCharges,
        $advancePayment,
        $paymentStatus,
        $note,
        $source,
        $purchaseId,
        $poId,
        $requestType,
        $parentPurchaseId,
        $reconciliationId,
        $revision,
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson,
        $poRevisionId,
        $poRevisionNumber,
        $accountRemarks
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    if ($id <= 0) {
        throw new RuntimeException('The Advance Fund Request could not be created.', 500);
    }
    return $id;
}

function procurementLocalAdvanceRequestSnapshot(array $request): string
{
    return (string) json_encode(
        $request,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function procurementLocalAdvanceCreateHandoff(
    mysqli $conn,
    int $purchaseId,
    int $revision,
    string $accountRequestType,
    int $accountRequestId,
    int $actorId
): void {
    $linkStmt = $conn->prepare(
        "SELECT po_revision_id, po_revision_number, po_snapshot_json, po_id
         FROM procurement_requests
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ? LIMIT 1 FOR UPDATE"
    );
    $linkStmt->bind_param('i', $purchaseId);
    $linkStmt->execute();
    $link = $linkStmt->get_result()->fetch_assoc();
    $linkStmt->close();
    if (!$link) {
        throw new RuntimeException('The Local Advance Purchase revision link could not be found.', 409);
    }

    $poRevisionId = (int) ($link['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($link['po_revision_number'] ?? 1));
    $poSnapshotJson = trim((string) ($link['po_snapshot_json'] ?? ''));
    if ($poRevisionId <= 0 || $poSnapshotJson === '') {
        $poRevision = procurementLocalAdvanceResolveCurrentPoRevision($conn, (int) $link['po_id'], true);
        $poRevisionId = (int) $poRevision['id'];
        $poRevisionNumber = (int) $poRevision['revision_number'];
        $poSnapshotJson = (string) $poRevision['commercial_snapshot_json'];
        $repair = $conn->prepare(
            "UPDATE procurement_requests
             SET po_revision_id = ?, po_revision_number = ?, po_snapshot_json = ?
             WHERE request_type = 'local_advance_purchase'
               AND legacy_source_table = 'procurement_local_advance_purchases'
               AND legacy_source_id = ?"
        );
        $repair->bind_param('iisi', $poRevisionId, $poRevisionNumber, $poSnapshotJson, $purchaseId);
        $repair->execute();
        $repair->close();
    }

    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        $purchaseId,
        $revision,
        $accountRequestType,
        $accountRequestId,
        'In Account',
        $actorId,
        null,
        null,
        null,
        null,
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson
    );
}

function procurementLocalAdvanceArchiveHandoff(
    mysqli $conn,
    int $purchaseId,
    int $revision,
    string $accountRequestType,
    int $accountRequestId,
    array $accountRequest,
    array $actor,
    string $status,
    string $source,
    string $reason
): void {
    $actorId = (int) ($actor['id'] ?? 0);
    $linkStmt = $conn->prepare(
        "SELECT po_revision_id, po_revision_number, po_snapshot_json, po_id
         FROM procurement_requests
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ? LIMIT 1 FOR UPDATE"
    );
    $linkStmt->bind_param('i', $purchaseId);
    $linkStmt->execute();
    $link = $linkStmt->get_result()->fetch_assoc();
    $linkStmt->close();
    if (!$link) {
        throw new RuntimeException('The Local Advance Purchase revision link could not be found.', 409);
    }

    $poRevisionId = (int) ($link['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($link['po_revision_number'] ?? 1));
    $poSnapshotJson = trim((string) ($link['po_snapshot_json'] ?? ''));
    if ($poRevisionId <= 0 || $poSnapshotJson === '') {
        $poRevision = procurementLocalAdvanceResolveCurrentPoRevision($conn, (int) $link['po_id'], true);
        $poRevisionId = (int) $poRevision['id'];
        $poRevisionNumber = (int) $poRevision['revision_number'];
        $poSnapshotJson = (string) $poRevision['commercial_snapshot_json'];
    }

    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        $purchaseId,
        $revision,
        $accountRequestType,
        $accountRequestId,
        $status,
        $actorId,
        $actorId,
        $source,
        $reason,
        procurementLocalAdvanceRequestSnapshot($accountRequest),
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson
    );
}

function procurementLocalAdvanceAccountRequestTable(string $accountRequestType): string
{
    return match ($accountRequestType) {
        PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS => 'compass_fund_request_table',
        PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE => 'advance_payment_request',
        default => throw new RuntimeException('Unsupported Local Advance Account handoff destination.', 500),
    };
}

function procurementLocalAdvanceFetchAccountRequestForUpdate(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): ?array {
    $table = procurementLocalAdvanceAccountRequestTable($accountRequestType);
    $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $accountRequestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $request;
}

function procurementLocalAdvanceDeletePendingAccountRequest(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): void {
    $table = procurementLocalAdvanceAccountRequestTable($accountRequestType);
    $pending = 'Pending';
    $delete = $conn->prepare("DELETE FROM `{$table}` WHERE id = ? AND payment_status = ?");
    $delete->bind_param('is', $accountRequestId, $pending);
    $delete->execute();
    if ($delete->affected_rows !== 1) {
        $delete->close();
        throw new RuntimeException('The linked Account fund request changed before it could be removed safely.', 409);
    }
    $delete->close();
}

function procurementLocalAdvanceApproveOne(mysqli $conn, int $id, array $actor): array
{
    $conn->begin_transaction();
    try {
        $purchase = procurementLocalAdvanceFetchRecord($conn, $id, true);
        if (!$purchase) {
            throw new RuntimeException('Local Advance Purchase not found.', 404);
        }
        if ((string) $purchase['approval_status'] !== 'Unapproved') {
            throw new RuntimeException('This Local Advance Purchase is already approved.', 409);
        }
        if ((string) $purchase['payment_status'] !== 'Pending') {
            throw new RuntimeException('Only pending Local Advance Purchases can be approved.', 409);
        }
        if ((string) $purchase['po_status'] === 'Cancelled') {
            throw new RuntimeException('A cancelled PO cannot be approved.', 409);
        }
        if (!empty($purchase['advance_payment_request_id'])) {
            throw new RuntimeException('This purchase still has an active Account handoff.', 409);
        }

        $previousHandoffStatus = (string) ($purchase['handoff_status'] ?? 'Not Sent');
        if (!in_array($previousHandoffStatus, ['Not Sent', 'Retrieved'], true)) {
            throw new RuntimeException('This purchase is not eligible for approval or resubmission.', 409);
        }

        $po = procurementLocalAdvanceFetchPo($conn, (int) $purchase['po_id'], true);
        if (!$po) {
            throw new RuntimeException('The linked PO could not be found.', 409);
        }
        procurementLocalAdvanceAssertPoOpen($po);
        procurementLocalAdvanceAssertAllocationAvailable(
            $conn,
            (int) $purchase['po_id'],
            procurementLocalAdvancePercentUnits((string) $purchase['po_percentage']),
            $id
        );

        $revision = max(0, (int) ($purchase['handoff_revision'] ?? 0)) + 1;
        $accountRequestType = procurementCompassResolveLocalAccountRequestType(
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $purchase['supplier_id'] ?? null,
            $purchase['supplier_name'] ?? null
        );
        $handoffRequest = procurementLocalAdvanceCreateAccountFundRequest(
            $conn,
            $purchase,
            $revision,
            $accountRequestType
        );
        $accountRequestId = (int) $handoffRequest['id'];
        $reusedAccountRequest = (bool) ($handoffRequest['reused'] ?? false);
        $actorId = (int) $actor['id'];
        procurementLocalAdvanceCreateHandoff(
            $conn,
            $id,
            $revision,
            $accountRequestType,
            $accountRequestId,
            $actorId
        );

        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Approved', handoff_status = 'In Account',
                 handoff_revision = ?, account_request_type = ?,
                 account_request_id = ?, approved_by = ?, approved_at = NOW(),
                 date_received = CURRENT_DATE(), approval_reversed_by = NULL,
                 approval_reversed_at = NULL, retrieved_by = NULL, retrieved_at = NULL,
                 retrieval_reason = NULL, retrieval_source = NULL,
                 payment_status = 'Pending', payment_status_source = 'procuredesk',
                 payment_status_updated_at = NOW(),
                 account_amount_paid = 0.00, account_processing_method = NULL,
                 account_processing_reference = NULL, account_processing_started_at = NULL,
                 account_expected_completion_at = NULL, account_completion_mode = NULL,
                 account_confirmation_status = 'Not Scheduled',
                 account_payment_reference = NULL, account_paid_at = NULL,
                 account_payment_remarks = NULL, account_payment_batch_id = NULL,
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE request_type = 'local_advance_purchase'
               AND legacy_source_table = 'procurement_local_advance_purchases'
               AND legacy_source_id = ?"
        );
        $update->bind_param('isiiii', $revision, $accountRequestType, $accountRequestId, $actorId, $actorId, $id);
        $update->execute();
        $update->close();

        $eventType = $previousHandoffStatus === 'Retrieved' ? 'resubmitted_to_account' : 'approved';
        $eventDetails = [
            'account_request_type' => $accountRequestType,
            'account_request_id' => $accountRequestId,
            'handoff_revision' => $revision,
            'previous_handoff_status' => $previousHandoffStatus,
            'existing_account_request_linked' => $reusedAccountRequest,
        ];
        if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
            $eventDetails['compass_fund_request_id'] = $accountRequestId;
        } else {
            $eventDetails['advance_payment_request_id'] = $accountRequestId;
        }
        procurementLocalAdvanceRecordEvent($conn, $id, $eventType, $actor, $eventDetails);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $id
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $id) ?? []);
}

function procurementLocalAdvanceReverseApprovalOne(
    mysqli $conn,
    int $id,
    array $actor,
    string $reason
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('An approval-reversal reason is required.', 400);
    }

    $conn->begin_transaction();
    try {
        $purchase = procurementLocalAdvanceFetchRecord($conn, $id, true);
        if (!$purchase) {
            throw new RuntimeException('Local Advance Purchase not found.', 404);
        }
        if ((string) $purchase['approval_status'] !== 'Approved' || empty($purchase['advance_payment_request_id'])) {
            throw new RuntimeException('Only an approved Local Advance Purchase can be unapproved.', 409);
        }

        $accountRequestType = trim((string) ($purchase['account_request_type'] ?? ''))
            ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE;
        $accountRequestId = (int) $purchase['advance_payment_request_id'];
        $accountRequest = procurementLocalAdvanceFetchAccountRequestForUpdate(
            $conn,
            $accountRequestType,
            $accountRequestId
        );
        if (!$accountRequest) {
            throw new RuntimeException('The linked Account fund request could not be found. Reversal was blocked.', 409);
        }
        if ((string) $accountRequest['payment_status'] !== 'Pending') {
            throw new RuntimeException(
                'Approval cannot be reversed after Account has started processing the fund request.',
                409
            );
        }

        $revision = max(1, (int) ($purchase['handoff_revision'] ?? 1));
        procurementLocalAdvanceArchiveHandoff(
            $conn,
            $id,
            $revision,
            $accountRequestType,
            $accountRequestId,
            $accountRequest,
            $actor,
            'Approval Reversed',
            'procurement',
            $reason
        );

        procurementLocalAdvanceDeletePendingAccountRequest($conn, $accountRequestType, $accountRequestId);

        $actorId = (int) $actor['id'];
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Not Sent',
                 account_request_id = NULL, previous_account_request_id = ?,
                 approved_by = NULL, approved_at = NULL, date_received = NULL,
                 approval_reversed_by = ?, approval_reversed_at = NOW(),
                 payment_status = 'Pending', payment_status_source = 'procuredesk',
                 payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE request_type = 'local_advance_purchase'
               AND legacy_source_table = 'procurement_local_advance_purchases'
               AND legacy_source_id = ?"
        );
        $update->bind_param('iiii', $accountRequestId, $actorId, $actorId, $id);
        $update->execute();
        $update->close();

        $eventDetails = [
            'reason' => $reason,
            'account_request_type' => $accountRequestType,
            'removed_account_request_id' => $accountRequestId,
            'handoff_revision' => $revision,
        ];
        if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
            $eventDetails['removed_compass_fund_request_id'] = $accountRequestId;
        } else {
            $eventDetails['removed_advance_payment_request_id'] = $accountRequestId;
        }
        procurementLocalAdvanceRecordEvent($conn, $id, 'approval_reversed', $actor, $eventDetails);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $id
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $id) ?? []);
}

function procurementLocalAdvanceRetrieveOne(
    mysqli $conn,
    int $id,
    array $actor,
    string $reason,
    string $source = 'procurement'
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A return or retrieval reason is required.', 400);
    }
    $source = strtolower(trim($source)) === 'account' ? 'account' : 'procurement';

    $conn->begin_transaction();
    try {
        $purchase = procurementLocalAdvanceFetchRecord($conn, $id, true);
        if (!$purchase) {
            throw new RuntimeException('Local Advance Purchase not found.', 404);
        }
        if ((string) $purchase['approval_status'] !== 'Approved'
            || (string) ($purchase['handoff_status'] ?? '') !== 'In Account'
            || empty($purchase['advance_payment_request_id'])) {
            throw new RuntimeException('Only a purchase currently awaiting Account payment can be retrieved.', 409);
        }
        if ((string) $purchase['payment_status'] !== 'Pending') {
            throw new RuntimeException('This purchase cannot be retrieved after Account has started processing it.', 409);
        }

        $accountRequestType = trim((string) ($purchase['account_request_type'] ?? ''))
            ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE;
        $accountRequestId = (int) $purchase['advance_payment_request_id'];
        $accountRequest = procurementLocalAdvanceFetchAccountRequestForUpdate(
            $conn,
            $accountRequestType,
            $accountRequestId
        );
        if (!$accountRequest) {
            throw new RuntimeException('The active Account fund request could not be found.', 409);
        }
        if ((string) $accountRequest['payment_status'] !== 'Pending') {
            throw new RuntimeException('This request is already being processed and cannot be returned.', 409);
        }
        if (!empty($accountRequest['procurement_purchase_id'])
            && (int) $accountRequest['procurement_purchase_id'] !== $id) {
            throw new RuntimeException('The Account handoff does not match this procurement record.', 409);
        }

        $revision = max(1, (int) ($purchase['handoff_revision'] ?? 1));
        procurementLocalAdvanceArchiveHandoff(
            $conn,
            $id,
            $revision,
            $accountRequestType,
            $accountRequestId,
            $accountRequest,
            $actor,
            'Retrieved',
            $source,
            $reason
        );

        procurementLocalAdvanceDeletePendingAccountRequest($conn, $accountRequestType, $accountRequestId);

        $actorId = (int) ($actor['id'] ?? 0);
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Retrieved',
                 account_request_id = NULL, previous_account_request_id = ?,
                 approved_by = NULL, approved_at = NULL, date_received = NULL,
                 retrieved_by = ?, retrieved_at = NOW(), retrieval_reason = ?, retrieval_source = ?,
                 payment_status = 'Pending', payment_status_source = 'procuredesk',
                 payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE request_type = 'local_advance_purchase'
               AND legacy_source_table = 'procurement_local_advance_purchases'
               AND legacy_source_id = ?"
        );
        $update->bind_param('iissii', $accountRequestId, $actorId, $reason, $source, $actorId, $id);
        $update->execute();
        $update->close();

        $eventType = $source === 'account' ? 'returned_by_account' : 'retrieved_from_account';
        $eventDetails = [
            'reason' => $reason,
            'source' => $source,
            'account_request_type' => $accountRequestType,
            'removed_account_request_id' => $accountRequestId,
            'handoff_revision' => $revision,
        ];
        if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
            $eventDetails['removed_compass_fund_request_id'] = $accountRequestId;
        } else {
            $eventDetails['removed_advance_payment_request_id'] = $accountRequestId;
        }
        procurementLocalAdvanceRecordEvent($conn, $id, $eventType, $actor, $eventDetails);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $id
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $id) ?? []);
}

function procurementLocalAdvanceRetrieveAdvanceRequestOne(
    mysqli $conn,
    int $advanceRequestId,
    array $actor,
    string $reason
): array {
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT id FROM {$relation} source
         WHERE advance_payment_request_id = ?
           AND (account_request_type IS NULL OR account_request_type = 'advance_payment_request')
           AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $advanceRequestId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        throw new RuntimeException('Only ProcureDesk-linked Advance Fund Requests can be returned.', 409);
    }

    return procurementLocalAdvanceRetrieveOne($conn, (int) $purchase['id'], $actor, $reason, 'account');
}

function procurementLocalAdvanceRetrieveCompassRequestOne(
    mysqli $conn,
    int $compassRequestId,
    array $actor,
    string $reason
): array {
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT id FROM {$relation} source
         WHERE advance_payment_request_id = ?
           AND account_request_type = 'compass_fund_request'
           AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $compassRequestId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        throw new RuntimeException('Only ProcureDesk-linked Compass Advance requests can be returned.', 409);
    }

    return procurementLocalAdvanceRetrieveOne($conn, (int) $purchase['id'], $actor, $reason, 'account');
}

function procurementSyncLocalAdvancePurchasePaymentDetails(
    mysqli $conn,
    array $advanceRequestIds,
    int $actorId,
    string $eventType = 'account_payment_updated',
    ?string $actorEmail = null,
    array $eventDetails = []
): void {
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    procurementLocalAdvanceEnsureStorage($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $advanceRequestIds))));
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT r.legacy_source_id AS purchase_id, r.po_revision_id,
                r.payment_status AS previous_payment_status,
                apr.id AS advance_request_id, apr.payment_status, apr.amount_paid,
                apr.processing_method, apr.processing_reference, apr.processing_started_at,
                apr.expected_completion_at, apr.completion_mode,
                apr.payment_confirmation_status, apr.payment_reference, apr.paid_at,
                apr.account_remarks, apr.payment_batch_id
         FROM procurement_requests r
         INNER JOIN advance_payment_request apr ON apr.id = r.account_request_id
         WHERE r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
           AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')
           AND apr.id IN ($placeholders) AND r.deleted_at IS NULL"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = ?, payment_status_source = 'account',
             payment_status_updated_at = NOW(), account_amount_paid = ?,
             account_processing_method = ?, account_processing_reference = ?,
             account_processing_started_at = ?, account_expected_completion_at = ?,
             account_completion_mode = ?, account_confirmation_status = ?,
             account_payment_reference = ?, account_paid_at = ?,
             account_payment_remarks = ?, account_payment_batch_id = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ? AND deleted_at IS NULL"
    );

    foreach ($rows as $row) {
        $status = trim((string) ($row['payment_status'] ?? 'Pending'));
        if ($status === 'Unconfirmed') {
            $status = 'Processing';
        }
        if (!in_array($status, PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES, true)) {
            continue;
        }

        $amountPaid = number_format((float) ($row['amount_paid'] ?? 0), 2, '.', '');
        $method = $row['processing_method'] === null ? null : (string) $row['processing_method'];
        $processingReference = $row['processing_reference'] === null ? null : (string) $row['processing_reference'];
        $processingStartedAt = $row['processing_started_at'] === null ? null : (string) $row['processing_started_at'];
        $expectedCompletionAt = $row['expected_completion_at'] === null ? null : (string) $row['expected_completion_at'];
        $completionMode = $row['completion_mode'] === null ? null : (string) $row['completion_mode'];
        $confirmationStatus = $row['payment_confirmation_status'] === null ? null : (string) $row['payment_confirmation_status'];
        $paymentReference = $row['payment_reference'] === null ? null : (string) $row['payment_reference'];
        $paidAt = $row['paid_at'] === null ? null : (string) $row['paid_at'];
        $remarks = $row['account_remarks'] === null ? null : (string) $row['account_remarks'];
        $batchId = $row['payment_batch_id'] === null ? null : (int) $row['payment_batch_id'];
        $purchaseId = (int) $row['purchase_id'];

        $update->bind_param(
            'sdsssssssssiii',
            $status,
            $amountPaid,
            $method,
            $processingReference,
            $processingStartedAt,
            $expectedCompletionAt,
            $completionMode,
            $confirmationStatus,
            $paymentReference,
            $paidAt,
            $remarks,
            $batchId,
            $actorId,
            $purchaseId
        );
        $update->execute();

        $poRevisionId = (int) ($row['po_revision_id'] ?? 0);
        if ($poRevisionId > 0 && ($status === 'Processing' || $status === 'Paid' || (float) $amountPaid > 0)) {
            procurementLocalAdvanceLockPoRevision($conn, $poRevisionId, 'Account payment activity recorded');
        }

        procurementLocalAdvanceRecordEvent(
            $conn,
            $purchaseId,
            $eventType,
            [
                'id' => $actorId,
                'email' => trim((string) $actorEmail) !== ''
                    ? trim((string) $actorEmail)
                    : ($actorId > 0 ? 'account-user' : 'system'),
            ],
            array_merge([
                'advance_payment_request_id' => (int) $row['advance_request_id'],
                'previous_payment_status' => (string) ($row['previous_payment_status'] ?? ''),
                'payment_status' => $status,
                'amount_paid' => $amountPaid,
                'processing_method' => $method,
                'processing_reference' => $processingReference,
                'processing_started_at' => $processingStartedAt,
                'expected_completion_at' => $expectedCompletionAt,
                'completion_mode' => $completionMode,
                'payment_confirmation_status' => $confirmationStatus,
                'payment_reference' => $paymentReference,
                'paid_at' => $paidAt,
                'account_remarks' => $remarks,
                'payment_batch_id' => $batchId,
            ], $eventDetails)
        );
        procurementLocalAdvanceRefreshSupplementaryReconciliation(
            $conn,
            $purchaseId,
            $actorId
        );
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $purchaseId
        );
    }
    $update->close();
}

function procurementSyncLocalAdvanceCompassPaymentDetails(
    mysqli $conn,
    array $compassRequestIds,
    int $actorId,
    string $eventType = 'account_compass_advance_payment_updated',
    ?string $actorEmail = null,
    array $eventDetails = []
): void {
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    procurementLocalAdvanceEnsureStorage($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $compassRequestIds))));
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT r.legacy_source_id AS purchase_id, r.po_revision_id,
                r.payment_status AS previous_payment_status,
                cfr.id AS compass_request_id, cfr.payment_status, cfr.amount_paid,
                cfr.processing_method, cfr.processing_reference, cfr.processing_started_at,
                cfr.expected_completion_at, cfr.completion_mode,
                cfr.payment_confirmation_status, cfr.payment_reference, cfr.paid_at,
                cfr.account_remarks, cfr.payment_batch_id
         FROM procurement_requests r
         INNER JOIN compass_fund_request_table cfr ON cfr.id = r.account_request_id
         WHERE r.request_type = 'local_advance_purchase'
           AND r.legacy_source_table = 'procurement_local_advance_purchases'
           AND r.account_request_type = 'compass_fund_request'
           AND cfr.id IN ($placeholders) AND r.deleted_at IS NULL"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = ?, payment_status_source = 'account',
             payment_status_updated_at = NOW(), account_amount_paid = ?,
             account_processing_method = ?, account_processing_reference = ?,
             account_processing_started_at = ?, account_expected_completion_at = ?,
             account_completion_mode = ?, account_confirmation_status = ?,
             account_payment_reference = ?, account_paid_at = ?,
             account_payment_remarks = ?, account_payment_batch_id = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND account_request_type = 'compass_fund_request'
           AND legacy_source_id = ? AND deleted_at IS NULL"
    );

    foreach ($rows as $row) {
        $status = trim((string) ($row['payment_status'] ?? 'Pending'));
        if ($status === 'Unconfirmed') {
            $status = 'Processing';
        }
        if (!in_array($status, PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES, true)) {
            continue;
        }

        $amountPaid = number_format((float) ($row['amount_paid'] ?? 0), 2, '.', '');
        $method = $row['processing_method'] === null ? null : (string) $row['processing_method'];
        $processingReference = $row['processing_reference'] === null ? null : (string) $row['processing_reference'];
        $processingStartedAt = $row['processing_started_at'] === null ? null : (string) $row['processing_started_at'];
        $expectedCompletionAt = $row['expected_completion_at'] === null ? null : (string) $row['expected_completion_at'];
        $completionMode = $row['completion_mode'] === null ? null : (string) $row['completion_mode'];
        $confirmationStatus = $row['payment_confirmation_status'] === null ? null : (string) $row['payment_confirmation_status'];
        $paymentReference = $row['payment_reference'] === null ? null : (string) $row['payment_reference'];
        $paidAt = $row['paid_at'] === null ? null : (string) $row['paid_at'];
        $remarks = $row['account_remarks'] === null ? null : (string) $row['account_remarks'];
        $batchId = $row['payment_batch_id'] === null ? null : (int) $row['payment_batch_id'];
        $purchaseId = (int) $row['purchase_id'];

        $update->bind_param(
            'sdsssssssssiii',
            $status,
            $amountPaid,
            $method,
            $processingReference,
            $processingStartedAt,
            $expectedCompletionAt,
            $completionMode,
            $confirmationStatus,
            $paymentReference,
            $paidAt,
            $remarks,
            $batchId,
            $actorId,
            $purchaseId
        );
        $update->execute();

        $poRevisionId = (int) ($row['po_revision_id'] ?? 0);
        if ($poRevisionId > 0 && ($status === 'Processing' || $status === 'Paid' || (float) $amountPaid > 0)) {
            procurementLocalAdvanceLockPoRevision($conn, $poRevisionId, 'Compass Account payment activity recorded');
        }

        procurementLocalAdvanceRecordEvent(
            $conn,
            $purchaseId,
            $eventType,
            [
                'id' => $actorId,
                'email' => trim((string) $actorEmail) !== ''
                    ? trim((string) $actorEmail)
                    : ($actorId > 0 ? 'account-user' : 'system'),
            ],
            array_merge([
                'account_request_type' => PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS,
                'compass_fund_request_id' => (int) $row['compass_request_id'],
                'previous_payment_status' => (string) ($row['previous_payment_status'] ?? ''),
                'payment_status' => $status,
                'amount_paid' => $amountPaid,
                'processing_method' => $method,
                'processing_reference' => $processingReference,
                'processing_started_at' => $processingStartedAt,
                'expected_completion_at' => $expectedCompletionAt,
                'completion_mode' => $completionMode,
                'payment_confirmation_status' => $confirmationStatus,
                'payment_reference' => $paymentReference,
                'paid_at' => $paidAt,
                'account_remarks' => $remarks,
                'payment_batch_id' => $batchId,
            ], $eventDetails)
        );
        procurementLocalAdvanceRefreshSupplementaryReconciliation($conn, $purchaseId, $actorId);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $purchaseId
        );
    }
    $update->close();
}

function procurementAssertAdvancePaymentRequestsCanBeDeleted(
    mysqli $conn,
    array $advanceRequestIds
): void {
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $advanceRequestIds))));
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT advance_payment_request_id
         FROM {$relation} source
         WHERE advance_payment_request_id IN ($placeholders)
           AND (account_request_type IS NULL OR account_request_type = 'advance_payment_request')
           AND approval_status = 'Approved' AND deleted_at IS NULL"
    );
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows !== []) {
        throw new RuntimeException(
            'Linked ProcureDesk Advance Fund Requests cannot be deleted in Account. Return them to Procurement or reverse the approval while they are still Pending.',
            409
        );
    }
}

function procurementLocalAdvanceLinkedRequest(
    mysqli $conn,
    int $advanceRequestId
): ?array {
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT r.id, r.approval_status, r.payment_status, r.handoff_status,
                r.advance_payment_request_id, p.po_number
         FROM {$relation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE r.advance_payment_request_id = ?
           AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')
           AND r.deleted_at IS NULL LIMIT 1"
    );
    $stmt->bind_param('i', $advanceRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementLocalAdvanceAccountAllocatedUnits(
    mysqli $conn,
    string $poNumber,
    ?int $excludeAdvanceRequestId = null
): int {
    [, $normalized] = procurementLocalAdvanceNormalizePoNumber($poNumber);
    $relation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $local = $conn->prepare(
        "SELECT COALESCE(SUM(r.po_percentage), 0) AS allocated
         FROM {$relation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE p.po_number_normalized = ? AND r.deleted_at IS NULL
           AND r.payment_status <> 'Cancelled'"
    );
    $local->bind_param('s', $normalized);
    $local->execute();
    $localAllocated = (string) ($local->get_result()->fetch_assoc()['allocated'] ?? '0');
    $local->close();
    $units = procurementLocalAdvancePercentUnitsAllowZero($localAllocated);

    if ($excludeAdvanceRequestId !== null) {
        $manual = $conn->prepare(
            "SELECT COALESCE(SUM(CAST(REPLACE(TRIM(percentage), '%', '') AS DECIMAL(12,6))), 0) AS allocated
             FROM advance_payment_request
             WHERE id <> ?
               AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(po_number), ' ', ''), '/', ''), '-', ''), '.', '')) = ?
               AND payment_status <> 'Cancelled'
               AND (
                    procurement_source IS NULL
                    OR procurement_source <> 'local_advance_purchase'
                    OR procurement_purchase_id IS NULL
               )"
        );
        $manual->bind_param('is', $excludeAdvanceRequestId, $normalized);
    } else {
        $manual = $conn->prepare(
            "SELECT COALESCE(SUM(CAST(REPLACE(TRIM(percentage), '%', '') AS DECIMAL(12,6))), 0) AS allocated
             FROM advance_payment_request
             WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(po_number), ' ', ''), '/', ''), '-', ''), '.', '')) = ?
               AND payment_status <> 'Cancelled'
               AND (
                    procurement_source IS NULL
                    OR procurement_source <> 'local_advance_purchase'
                    OR procurement_purchase_id IS NULL
               )"
        );
        $manual->bind_param('s', $normalized);
    }
    $manual->execute();
    $manualAllocated = (string) ($manual->get_result()->fetch_assoc()['allocated'] ?? '0');
    $manual->close();

    return $units + procurementLocalAdvancePercentUnitsAllowZero($manualAllocated);
}

function procurementLocalAdvanceAssertAccountAllocationAvailable(
    mysqli $conn,
    string $poNumber,
    mixed $percentage,
    ?int $excludeAdvanceRequestId = null
): void {
    $requestedUnits = procurementLocalAdvancePercentUnits($percentage);
    $allocatedUnits = procurementLocalAdvanceAccountAllocatedUnits(
        $conn,
        $poNumber,
        $excludeAdvanceRequestId
    );
    if ($allocatedUnits + $requestedUnits > PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX) {
        $availableUnits = max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $allocatedUnits);
        throw new RuntimeException(
            'The requested percentage exceeds the available '
                . rtrim(rtrim(procurementLocalAdvancePercentString($availableUnits), '0'), '.')
                . '% for this PO.',
            409
        );
    }
}

function procurementLocalAdvanceBatchIds(mixed $value): array
{
    $values = is_array($value) ? $value : [$value];
    $ids = [];
    foreach ($values as $candidate) {
        $id = (int) $candidate;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_map('intval', array_keys($ids));
    if ($ids === []) {
        throw new RuntimeException('Select at least one Local Advance Purchase.', 400);
    }
    if (count($ids) > PROCUREMENT_LOCAL_ADVANCE_MAX_BATCH) {
        throw new RuntimeException('A maximum of 100 Local Advance Purchases can be processed at once.', 400);
    }
    return $ids;
}

function procurementLocalAdvanceUpdatePoStatusOne(
    mysqli $conn,
    int $purchaseId,
    string $poStatus,
    array $actor,
    string $reason = ''
): array {
    $reason = trim($reason);
    if ($poStatus === 'Cancelled' && $reason === '') {
        throw new RuntimeException('A reason is required when cancelling a PO.', 400);
    }
    if ($poStatus === 'Cancelled') {
        return procurementLocalAdvanceCancelPoOne($conn, $purchaseId, $actor, $reason);
    }

    $conn->begin_transaction();
    try {
        $record = procurementLocalAdvanceFetchRecord($conn, $purchaseId, true);
        if (!$record) {
            throw new RuntimeException('Local Advance Purchase not found.', 404);
        }
        $poId = (int) $record['po_id'];
        $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
        if (!$po) {
            throw new RuntimeException('The linked PO could not be found.', 409);
        }


        $previousStatus = (string) $po['po_status'];
        if ($previousStatus !== $poStatus) {
            $actorId = (int) $actor['id'];
            $update = $conn->prepare(
                'UPDATE procurement_local_advance_pos
                 SET po_status = ?, updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE id = ?'
            );
            $update->bind_param('sii', $poStatus, $actorId, $poId);
            $update->execute();
            $update->close();

            $relation = $relation ?? procurementRequestCanonicalLocalAdvanceReadRelation();
            $idsStmt = $conn->prepare(
                "SELECT id FROM {$relation} source WHERE po_id = ? AND deleted_at IS NULL"
            );
            $idsStmt->bind_param('i', $poId);
            $idsStmt->execute();
            $affectedIds = array_map(
                static fn(array $row): int => (int) $row['id'],
                $idsStmt->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $idsStmt->close();

            foreach ($affectedIds as $id) {
                procurementLocalAdvanceRecordEvent($conn, $id, 'po_status_updated', $actor, [
                    'previous_status' => $previousStatus,
                    'po_status' => $poStatus,
                    'reason' => $reason !== '' ? $reason : null,
                ]);
            }
            procurementRequestCanonicalSyncRequests(
                $conn,
                PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
                $affectedIds
            );
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $purchaseId) ?? []);
}

function procurementLocalAdvanceCancelPoOne(
    mysqli $conn,
    int $purchaseId,
    array $actor,
    string $reason
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required when cancelling a PO.', 400);
    }

    procurementSupplierAdjustmentEnsureStorage($conn);
    $conn->begin_transaction();
    try {
        $record = procurementLocalAdvanceFetchRecord($conn, $purchaseId, true);
        if (!$record) {
            throw new RuntimeException('Local Advance Purchase not found.', 404);
        }
        $poId = (int) ($record['po_id'] ?? 0);
        $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
        if (!$po) {
            throw new RuntimeException('The linked PO could not be found.', 409);
        }
        if ((string) ($po['po_status'] ?? '') === 'Cancelled') {
            $conn->commit();
            return procurementLocalAdvanceSerializeRecord($record);
        }

        $rows = procurementLocalAdvanceFetchAccountAllocationRows($conn, $poId, true);
        foreach ($rows as $row) {
            if (procurementLocalAdvanceAllocationPaymentStatus($row) === 'Processing') {
                throw new RuntimeException(
                    'Complete or cancel all Account processing payments before cancelling this PO.',
                    409
                );
            }
        }

        $actorId = (int) ($actor['id'] ?? 0);
        procurementSupplierAdjustmentCancelOpenPayablesForPo(
            $conn,
            'local_advance_purchase',
            $poId,
            $actorId,
            $reason
        );

        $recoverableTotalCents = 0;
        $cancelledPending = [];
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            $status = procurementLocalAdvanceAllocationPaymentStatus($row);
            $accountRequestId = (int) ($row['linked_advance_payment_request_id'] ?? 0);
            $accountRequestType = trim((string) ($row['account_request_type'] ?? ''))
                ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE;

            if ($status === 'Pending') {
                if ($accountRequestId > 0) {
                    $accountRequest = procurementLocalAdvanceFetchAccountRequestForUpdate(
                        $conn,
                        $accountRequestType,
                        $accountRequestId
                    );
                    if (!$accountRequest || (string) ($accountRequest['payment_status'] ?? '') !== 'Pending') {
                        throw new RuntimeException(
                            'A linked Account request changed before cancellation completed.',
                            409
                        );
                    }
                    procurementLocalAdvanceArchiveHandoff(
                        $conn,
                        $rowId,
                        max(1, (int) ($row['handoff_revision'] ?? 1)),
                        $accountRequestType,
                        $accountRequestId,
                        $accountRequest,
                        $actor,
                        'Cancelled',
                        'procurement',
                        $reason
                    );
                    procurementLocalAdvanceDeletePendingAccountRequest(
                        $conn,
                        $accountRequestType,
                        $accountRequestId
                    );
                }

                $pendingUpdate = $conn->prepare(
                    "UPDATE procurement_requests
                     SET payment_status = 'Cancelled', payment_status_source = 'procuredesk',
                         payment_status_updated_at = NOW(), handoff_status = 'Not Sent',
                         account_request_id = NULL,
                         previous_account_request_id = COALESCE(NULLIF(?, 0), previous_account_request_id),
                         updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE request_type = 'local_advance_purchase'
                       AND legacy_source_table = 'procurement_local_advance_purchases'
                       AND legacy_source_id = ? AND deleted_at IS NULL"
                );
                $pendingUpdate->bind_param('iii', $accountRequestId, $actorId, $rowId);
                $pendingUpdate->execute();
                $pendingUpdate->close();
                $cancelledPending[] = $rowId;
                continue;
            }

            if ($status !== 'Paid') {
                continue;
            }

            $paidCents = procurementLocalAdvanceMoneyToCents(
                $row['account_amount_paid_effective'] ?? 0,
                'Paid Amount',
                true
            );
            if ($paidCents <= 0) {
                $paidCents = procurementLocalAdvanceMoneyToCents(
                    $row['committed_amount'] ?? 0,
                    'Paid Commitment',
                    true
                );
            }
            if ($paidCents <= 0) {
                continue;
            }

            $supplierId = (int) ($row['exact_revision_supplier_id'] ?? $po['supplier_id'] ?? 0);
            $supplierName = trim((string) ($row['exact_revision_supplier_name'] ?? $po['supplier_name'] ?? ''));
            $supplierLedger = trim((string) ($row['exact_revision_supplier_ledger'] ?? $po['supplier_ledger'] ?? ''));
            $alreadyRecordedCents = procurementLocalAdvanceMoneyToCents(
                procurementSupplierAdjustmentRecoverableRecordedForSourceSupplier(
                    $conn,
                    'local_advance_purchase',
                    $rowId,
                    $supplierId,
                    'NGN'
                ),
                'Existing Recovery',
                true
            );
            $newRecoveryCents = max(0, $paidCents - $alreadyRecordedCents);
            if ($newRecoveryCents <= 0) {
                continue;
            }

            $exactRevisionId = (int) ($row['po_revision_id'] ?? 0);
            $exactRevision = $exactRevisionId > 0
                ? procurementLocalAdvanceFetchPoRevision($conn, $exactRevisionId, true)
                : null;
            $snapshotSource = $exactRevision ?: $po;
            $originSnapshot = procurementLocalAdvancePoCommercialSnapshot($snapshotSource);
            $revisedSnapshot = $originSnapshot;
            $revisedSnapshot['po_status'] = 'Cancelled';

            procurementSupplierAdjustmentCreate($conn, [
                'source_type' => 'local_advance_purchase',
                'source_purchase_id' => $rowId,
                'source_po_id' => $poId,
                'source_revision_id' => $exactRevisionId,
                'source_revision_number' => (int) ($row['po_revision_number'] ?? 0),
                'adjustment_kind' => 'Cancellation',
                'adjustment_direction' => 'Recoverable',
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'supplier_ledger' => $supplierLedger,
                'currency' => 'NGN',
                'amount' => procurementLocalAdvanceCents($newRecoveryCents),
                'reason' => $reason,
                'origin_snapshot' => $originSnapshot,
                'revised_snapshot' => $revisedSnapshot,
            ], $actorId);
            $recoverableTotalCents += $newRecoveryCents;
        }

        $cancelDrafts = $conn->prepare(
            "UPDATE procurement_local_advance_po_revisions
             SET revision_status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(),
                 cancellation_reason = ?, updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE po_id = ? AND revision_status IN ('Draft', 'Pending Approval')"
        );
        $cancelDrafts->bind_param('isii', $actorId, $reason, $actorId, $poId);
        $cancelDrafts->execute();
        $cancelDrafts->close();

        $poUpdate = $conn->prepare(
            "UPDATE procurement_local_advance_pos
             SET po_status = 'Cancelled', amendment_status = 'Cancelled',
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE id = ?"
        );
        $poUpdate->bind_param('ii', $actorId, $poId);
        $poUpdate->execute();
        if ($poUpdate->affected_rows !== 1) {
            $poUpdate->close();
            throw new RuntimeException('The Local Advance PO changed before cancellation completed.', 409);
        }
        $poUpdate->close();

        $affectedIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $rows
        );
        foreach ($affectedIds as $affectedId) {
            procurementLocalAdvanceRecordEvent($conn, $affectedId, 'po_cancelled', $actor, [
                'reason' => $reason,
                'po_id' => $poId,
                'recoverable_total' => procurementLocalAdvanceCents($recoverableTotalCents),
                'pending_handoffs_cancelled' => $cancelledPending,
            ]);
        }
        procurementRequestCanonicalSyncRequests(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $affectedIds
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $purchaseId) ?? []);
}
