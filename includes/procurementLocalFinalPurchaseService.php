<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/procurementNotificationService.php';
require_once __DIR__ . '/procurementRequestCanonicalSyncService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/procurementCompassHandoffService.php';
require_once __DIR__ . '/procurementSupplierFinancialAdjustmentService.php';
require_once __DIR__ . '/procurementPurchaseReplacementService.php';

const PROCUREMENT_LOCAL_FINAL_PO_STATUSES = ['Closed', 'Unclosed', 'Partially Closed', 'Paid', 'Cancelled'];
const PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES = ['Pending', 'Processing', 'Paid', 'Failed', 'Cancelled'];
const PROCUREMENT_LOCAL_FINAL_APPROVAL_STATUSES = ['Unapproved', 'Approved'];
const PROCUREMENT_LOCAL_FINAL_HANDOFF_STATUSES = ['Not Sent', 'In Account', 'Retrieved'];
const PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES = ['Electrical', 'Mechanical', 'Stationaries', 'Electromechanical'];
const PROCUREMENT_LOCAL_FINAL_MAX_BATCH = 100;

function procurementLocalFinalStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function procurementLocalFinalUpper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function procurementLocalFinalSubstring(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function procurementLocalFinalTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($conn) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}

function procurementLocalFinalTableType(mysqli $conn, string $table): ?string
{
    $stmt = $conn->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return is_string($type) ? strtoupper($type) : null;
}

function procurementLocalFinalEnsureColumn(
    mysqli $conn,
    string $table,
    string $column,
    string $definition
): void {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();

    if (!$exists && procurementLocalFinalTableType($conn, $table) === 'VIEW') {
        throw new RuntimeException('Local Final Purchase compatibility view is incomplete: ' . $column, 500);
    }
    if (!$exists && !$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        throw new RuntimeException('Unable to update Local Final Purchase storage.', 500);
    }
}

function procurementLocalFinalEnsureCompassRevisionIndex(mysqli $conn): void
{
    if (!procurementLocalFinalTableExists($conn, 'compass_fund_request_table')) {
        return;
    }

    $old = $conn->query(
        "SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_NAME = 'compass_fund_request_table'
           AND INDEX_NAME = 'uq_compass_procurement_purchase'"
    );
    $oldExists = (int) (($old ? $old->fetch_assoc() : [])['total'] ?? 0) > 0;
    if ($oldExists) {
        $conn->query(
            "UPDATE compass_fund_request_table
             SET procurement_revision = 1
             WHERE procurement_source IN ('local_final_purchase','local_advance_purchase')
               AND procurement_purchase_id IS NOT NULL
               AND (procurement_revision IS NULL OR procurement_revision = 0)"
        );
        if (!$conn->query('ALTER TABLE compass_fund_request_table DROP INDEX uq_compass_procurement_purchase')) {
            throw new RuntimeException('Unable to enable versioned Compass procurement handoffs.', 500);
        }
    }

    $new = $conn->query(
        "SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_NAME = 'compass_fund_request_table'
           AND INDEX_NAME = 'uq_compass_procurement_purchase_revision'"
    );
    $newExists = (int) (($new ? $new->fetch_assoc() : [])['total'] ?? 0) > 0;
    if (!$newExists && !$conn->query(
        'ALTER TABLE compass_fund_request_table
         ADD UNIQUE KEY uq_compass_procurement_purchase_revision
         (procurement_source, procurement_purchase_id, procurement_revision)'
    )) {
        throw new RuntimeException('Unable to enable versioned Compass procurement handoffs.', 500);
    }
}

function procurementLocalFinalEnsurePaidRevisionStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }
    procurementLocalFinalEnsureCompassRevisionIndex($conn);

    $query = "CREATE TABLE IF NOT EXISTS procurement_local_final_paid_revisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        purchase_id BIGINT UNSIGNED NOT NULL,
        revision_number INT UNSIGNED NOT NULL,
        reason TEXT NOT NULL,
        supplier_changed TINYINT(1) NOT NULL DEFAULT 0,
        previous_supplier_id INT NOT NULL,
        previous_supplier_name VARCHAR(255) NOT NULL,
        previous_supplier_ledger VARCHAR(120) NOT NULL,
        revised_supplier_id INT NOT NULL,
        revised_supplier_name VARCHAR(255) NOT NULL,
        revised_supplier_ledger VARCHAR(120) NOT NULL,
        previous_payable_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        amount_paid_at_revision DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        revised_payable_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        recoverable_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        additional_payable_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        previous_account_request_type VARCHAR(40) NULL,
        previous_account_request_id BIGINT UNSIGNED NULL,
        new_account_request_type VARCHAR(40) NULL,
        new_account_request_id BIGINT UNSIGNED NULL,
        origin_snapshot_json LONGTEXT NOT NULL,
        revised_snapshot_json LONGTEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_local_final_paid_revision (purchase_id, revision_number),
        INDEX idx_local_final_paid_revision_purchase (purchase_id, created_at),
        INDEX idx_local_final_paid_revision_account (new_account_request_type, new_account_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($query)) {
        throw new RuntimeException('Unable to prepare Local Final paid revision storage.', 500);
    }
    $ensured = true;
}

function procurementAssertLocalFinalPurchaseReadStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    procurementRequestCanonicalLocalFinalAssertReady($conn);
    procurementSupplierAdjustmentEnsureStorage($conn);

    $accountColumns = [
        'id', 'amount', 'payment_status', 'amount_paid', 'processing_method',
        'processing_reference', 'processing_started_at', 'expected_completion_at',
        'completion_mode', 'payment_confirmation_status', 'payment_reference',
        'paid_at', 'account_remarks', 'payment_batch_id',
    ];
    if (!procurementRequestCanonicalRuntimeColumnsReady(
        $conn,
        'supplier_fund_request_table',
        $accountColumns
    )) {
        procurementEnsureLocalFinalPurchaseStorage($conn);
        return;
    }

    procurementCompassAssertStorageReady($conn);
}

function procurementEnsureLocalFinalPurchaseStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    procurementRequestCanonicalLocalFinalAssertReady($conn);

    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'procurement_source', "VARCHAR(40) NULL AFTER `payment_status`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'procurement_purchase_id', 'BIGINT UNSIGNED NULL AFTER `procurement_source`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'procurement_revision', 'INT UNSIGNED NULL AFTER `procurement_purchase_id`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'processing_method', 'VARCHAR(30) NULL AFTER `payment_status`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'processing_reference', 'VARCHAR(160) NULL AFTER `processing_method`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'processing_started_at', 'DATETIME NULL AFTER `processing_reference`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'processing_business_days', 'TINYINT UNSIGNED NULL AFTER `processing_started_at`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'expected_completion_at', 'DATETIME NULL AFTER `processing_business_days`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'completion_mode', 'VARCHAR(30) NULL AFTER `expected_completion_at`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'payment_confirmation_status', "VARCHAR(40) NOT NULL DEFAULT 'Not Scheduled' AFTER `completion_mode`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'amount_paid', 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `payment_confirmation_status`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'paid_at', 'DATETIME NULL AFTER `amount_paid`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'payment_reference', 'VARCHAR(160) NULL AFTER `paid_at`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'account_remarks', 'TEXT NULL AFTER `payment_reference`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'payment_batch_id', 'BIGINT UNSIGNED NULL AFTER `account_remarks`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'payment_updated_by', 'INT NULL AFTER `payment_batch_id`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'payment_updated_at', 'DATETIME NULL AFTER `payment_updated_by`');
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'wht_override_status', "VARCHAR(40) NULL AFTER `wht`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'wht_override_rate', "DECIMAL(8,6) NULL AFTER `wht_override_status`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'wht_override_amount', "DECIMAL(18,2) NULL AFTER `wht_override_rate`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'wht_override_reason', "TEXT NULL AFTER `wht_override_amount`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'wht_override_by', "INT NULL AFTER `wht_override_reason`");
    procurementLocalFinalEnsureColumn($conn, 'supplier_fund_request_table', 'wht_override_at', "DATETIME NULL AFTER `wht_override_by`");
    procurementCompassAssertStorageReady($conn);
    procurementLocalFinalEnsurePaidRevisionStorage($conn);

    $scope = procurementRequestCanonicalLocalFinalScopeSql();
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
         WHERE {$scope}"
    );
    $conn->query(
        "UPDATE supplier_fund_request_table sfr
         INNER JOIN procurement_requests p ON p.account_request_id = sfr.id
         SET sfr.procurement_source = 'local_final_purchase',
             sfr.procurement_purchase_id = p.legacy_source_id,
             sfr.procurement_revision = GREATEST(p.handoff_revision, 1)
         WHERE p.request_type = 'local_final_purchase'
           AND p.legacy_source_table = 'procurement_local_final_purchases'
           AND (p.account_request_type IS NULL OR p.account_request_type = 'supplier_fund_request')
           AND p.deleted_at IS NULL"
    );
    $conn->query(
        "UPDATE compass_fund_request_table cfr
         INNER JOIN procurement_requests p ON p.account_request_id = cfr.id
         SET cfr.procurement_source = 'local_final_purchase',
             cfr.procurement_purchase_id = p.legacy_source_id,
             cfr.procurement_request_type = 'local_final_purchase',
             cfr.procurement_revision = GREATEST(p.handoff_revision, 1)
         WHERE p.request_type = 'local_final_purchase'
           AND p.legacy_source_table = 'procurement_local_final_purchases'
           AND p.account_request_type = 'compass_fund_request'
           AND p.deleted_at IS NULL"
    );

    $ensured = true;
}

function procurementLocalFinalNormalizePurchaseNumber(string $value): array
{
    $display = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($display === '') {
        throw new RuntimeException('Purchase Number is required.', 400);
    }
    if (procurementLocalFinalStringLength($display) > 120) {
        throw new RuntimeException('Purchase Number must not exceed 120 characters.', 400);
    }

    $normalized = procurementLocalFinalUpper(preg_replace('/\s+/', '', $display) ?? '');
    return [$display, $normalized];
}

function procurementLocalFinalRequiredText(array $data, string $field, string $label, int $maxLength = 255): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException($label . ' is required.', 400);
    }
    if (procurementLocalFinalStringLength($value) > $maxLength) {
        throw new RuntimeException($label . ' must not exceed ' . $maxLength . ' characters.', 400);
    }
    return $value;
}

function procurementLocalFinalOptionalText(array $data, string $field, int $maxLength = 4000): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if (procurementLocalFinalStringLength($value) > $maxLength) {
        throw new RuntimeException(ucwords(str_replace('_', ' ', $field)) . ' is too long.', 400);
    }
    return $value;
}

function procurementLocalFinalDate(array $data, string $field, string $label): string
{
    $value = trim((string) ($data[$field] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException($label . ' must be a valid date in YYYY-MM-DD format.', 400);
    }
    return $value;
}

function procurementLocalFinalMoneyToCents(mixed $value, string $label, bool $allowZero = true): int
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

function procurementLocalFinalCents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function procurementLocalFinalPercentFromBasisPoints(int $basisPoints): string
{
    return number_format($basisPoints / 10000, 6, '.', '');
}

function procurementLocalFinalRateAmount(int $baseCents, int $basisPoints): int
{
    return intdiv(($baseCents * $basisPoints) + 5000, 10000);
}

function procurementLocalFinalVatStatus(mixed $value, string $label): array
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
    throw new RuntimeException($label . ' must be Yes or No.', 400);
}

function procurementLocalFinalWhtBasisPoints(string $whtStatus): int
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

function procurementLocalFinalLegacyVatPolicy(bool $hasVat, int $whtBasisPoints): string
{
    if (!$hasVat) {
        return '0.00%';
    }
    if ($whtBasisPoints === 200) {
        return '2.00%';
    }
    if ($whtBasisPoints === 500) {
        return '5.00%';
    }
    return '7.50%';
}

function procurementLocalFinalResolveProject(mysqli $conn, array $data): array
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

function procurementLocalFinalResolveSupplier(mysqli $conn, array $data): array
{
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $supplierLedger = trim((string) ($data['supplier_ledger'] ?? ''));

    if ($supplierId > 0) {
        $stmt = $conn->prepare(
            'SELECT id, supplier_name, supplier_number, wht_status
             FROM suppliers_table WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $supplierId);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Compatibility: existing AcctLab controls commonly expose supplier_number as supplier_id.
        if (!$supplier) {
            $stmt = $conn->prepare(
                'SELECT id, supplier_name, supplier_number, wht_status
                 FROM suppliers_table WHERE supplier_number = ? LIMIT 1'
            );
            $stmt->bind_param('i', $supplierId);
            $stmt->execute();
            $supplier = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif ($supplierLedger !== '' && ctype_digit($supplierLedger)) {
        $ledgerNumber = (int) $supplierLedger;
        $stmt = $conn->prepare(
            'SELECT id, supplier_name, supplier_number, wht_status
             FROM suppliers_table WHERE supplier_number = ? LIMIT 1'
        );
        $stmt->bind_param('i', $ledgerNumber);
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
        'wht_basis_points' => procurementLocalFinalWhtBasisPoints($whtStatus),
    ];
}

function procurementLocalFinalValidateStatus(mixed $value, array $allowed, string $label): string
{
    $candidate = trim((string) $value);
    foreach ($allowed as $status) {
        if (strcasecmp($candidate, $status) === 0) {
            return $status;
        }
    }
    throw new RuntimeException($label . ' is invalid.', 400);
}

function procurementLocalFinalBuildPayload(mysqli $conn, array $data): array
{
    [$purchaseNumber, $purchaseNumberNormalized] = procurementLocalFinalNormalizePurchaseNumber(
        (string) ($data['purchase_number'] ?? '')
    );
    $project = procurementLocalFinalResolveProject($conn, $data);
    $supplier = procurementLocalFinalResolveSupplier($conn, $data);

    $purchaseSubtotal = procurementLocalFinalMoneyToCents($data['purchase_subtotal'] ?? null, 'Purchase Subtotal', false);
    $purchaseDiscount = procurementLocalFinalMoneyToCents($data['purchase_discount'] ?? null, 'Purchase Discount');
    $purchaseOtherCharges = procurementLocalFinalMoneyToCents($data['purchase_other_charges'] ?? null, 'Purchase Other Charges');
    if ($purchaseDiscount > $purchaseSubtotal) {
        throw new RuntimeException('Purchase Discount cannot be greater than Purchase Subtotal.', 400);
    }
    [$purchaseVatStatus, $purchaseVatBasisPoints] = procurementLocalFinalVatStatus(
        $data['purchase_vat_status'] ?? null,
        'Purchase VAT Status'
    );
    $purchaseNet = $purchaseSubtotal - $purchaseDiscount;
    $purchaseVatAmount = procurementLocalFinalRateAmount($purchaseNet, $purchaseVatBasisPoints);
    $purchaseValue = $purchaseNet + $purchaseOtherCharges + $purchaseVatAmount;

    // WHT is only applicable when purchase VAT is charged. The supplier's WHT
    // configuration is used when VAT applies; otherwise the effective WHT is 0%.
    $effectiveWhtBasisPoints = $purchaseVatBasisPoints > 0
        ? (int) $supplier['wht_basis_points']
        : 0;
    $effectiveWhtStatus = $effectiveWhtBasisPoints > 0
        ? (string) $supplier['wht_status']
        : '0.00%';
    $whtAmount = procurementLocalFinalRateAmount($purchaseNet, $effectiveWhtBasisPoints);

    $poSubtotal = procurementLocalFinalMoneyToCents($data['po_subtotal'] ?? null, 'PO Subtotal', false);
    $poDiscount = procurementLocalFinalMoneyToCents($data['po_discount'] ?? null, 'PO Discount');
    $poOtherCharges = procurementLocalFinalMoneyToCents($data['po_other_charges'] ?? null, 'PO Other Charges');
    if ($poDiscount > $poSubtotal) {
        throw new RuntimeException('PO Discount cannot be greater than PO Subtotal.', 400);
    }
    [$poVatStatus, $poVatBasisPoints] = procurementLocalFinalVatStatus(
        $data['po_vat_status'] ?? null,
        'PO VAT Status'
    );
    $poNet = $poSubtotal - $poDiscount;
    $poVatAmount = procurementLocalFinalRateAmount($poNet, $poVatBasisPoints);
    $poValue = $poNet + $poOtherCharges + $poVatAmount;

    return [
        'po_number' => procurementLocalFinalRequiredText($data, 'po_number', 'PO Number', 120),
        'purchase_number' => $purchaseNumber,
        'purchase_number_normalized' => $purchaseNumberNormalized,
        'grn_ref' => procurementLocalFinalRequiredText($data, 'grn_ref', 'GRN REF', 120),
        'material_type' => procurementLocalFinalValidateStatus(
            $data['material_type'] ?? '',
            PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES,
            'Material Type'
        ),
        'project_id' => $project['id'],
        'project_code' => $project['code'],
        'project_name' => $project['name'],
        'supplier_id' => $supplier['id'],
        'supplier_name' => $supplier['name'],
        'supplier_ledger' => $supplier['ledger'],
        'wht_status' => $effectiveWhtStatus,
        'wht_rate' => procurementLocalFinalPercentFromBasisPoints($effectiveWhtBasisPoints),
        'wht_amount' => procurementLocalFinalCents($whtAmount),
        'invoice_number' => procurementLocalFinalRequiredText($data, 'invoice_number', 'Invoice Number', 255),
        'invoice_date' => procurementLocalFinalDate($data, 'invoice_date', 'Invoice Date'),
        'purchase_date' => procurementLocalFinalDate($data, 'purchase_date', 'Purchase Date'),
        'purchase_subtotal' => procurementLocalFinalCents($purchaseSubtotal),
        'purchase_discount' => procurementLocalFinalCents($purchaseDiscount),
        'purchase_other_charges' => procurementLocalFinalCents($purchaseOtherCharges),
        'purchase_vat_status' => $purchaseVatStatus,
        'purchase_vat_rate' => procurementLocalFinalPercentFromBasisPoints($purchaseVatBasisPoints),
        'purchase_vat_amount' => procurementLocalFinalCents($purchaseVatAmount),
        'purchase_value' => procurementLocalFinalCents($purchaseValue),
        'po_subtotal' => procurementLocalFinalCents($poSubtotal),
        'po_discount' => procurementLocalFinalCents($poDiscount),
        'po_other_charges' => procurementLocalFinalCents($poOtherCharges),
        'po_vat_status' => $poVatStatus,
        'po_vat_rate' => procurementLocalFinalPercentFromBasisPoints($poVatBasisPoints),
        'po_vat_amount' => procurementLocalFinalCents($poVatAmount),
        'po_value' => procurementLocalFinalCents($poValue),
        'remark' => procurementLocalFinalOptionalText($data, 'remark'),
        'po_status' => procurementLocalFinalValidateStatus(
            $data['po_status'] ?? '',
            PROCUREMENT_LOCAL_FINAL_PO_STATUSES,
            'PO Status'
        ),
    ];
}

function procurementLocalFinalAssertPurchaseNumberAvailable(
    mysqli $conn,
    string $normalized,
    ?int $excludeId = null
): void {
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    if ($excludeId !== null) {
        $stmt = $conn->prepare(
            "SELECT id FROM {$relation} source
             WHERE purchase_number_normalized = ? AND id <> ? LIMIT 1"
        );
        $stmt->bind_param('si', $normalized, $excludeId);
    } else {
        $stmt = $conn->prepare(
            "SELECT id FROM {$relation} source
             WHERE purchase_number_normalized = ? LIMIT 1"
        );
        $stmt->bind_param('s', $normalized);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) {
        throw new RuntimeException('Purchase Number already exists.', 409);
    }
}

function procurementLocalFinalRecordEvent(
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
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        $purchaseId,
        $eventType,
        $actorId,
        $actorEmail,
        $detailsJson
    );

    $notificationDetails = $details;
    if ($eventId > 0) {
        $notificationDetails['procurement_event_id'] = $eventId;
    }
    procurementNotificationPublishLocalFinalEvent(
        $conn,
        $purchaseId,
        $eventType,
        $actor,
        $notificationDetails
    );
    procurementNotificationMirrorLocalFinalEventToAccount(
        $conn,
        $purchaseId,
        $eventType,
        $actor,
        $notificationDetails
    );
}

function procurementLocalFinalFetchRecord(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT p.*,
                COALESCE(cfr.amount, sfr.amount, p.account_payable_amount, GREATEST(p.purchase_value - p.wht_amount, 0.00)) AS account_payable_amount,
                COALESCE(cfr.payment_status, sfr.payment_status) AS account_payment_status,
                COALESCE(cfr.amount_paid, sfr.amount_paid, p.account_amount_paid, 0.00) AS amount_paid,
                COALESCE(sfr.supplier_credit_applied, 0.00) AS account_supplier_credit_applied,
                COALESCE(sfr.cash_amount_paid, 0.00) AS account_cash_amount_paid,
                COALESCE(cfr.processing_method, sfr.processing_method, p.account_processing_method) AS payment_processing_method,
                COALESCE(cfr.processing_reference, sfr.processing_reference, p.account_processing_reference) AS payment_processing_reference,
                COALESCE(cfr.processing_started_at, sfr.processing_started_at, p.account_processing_started_at) AS payment_processing_started_at,
                COALESCE(cfr.expected_completion_at, sfr.expected_completion_at, p.account_expected_completion_at) AS expected_payment_completion_at,
                COALESCE(cfr.completion_mode, sfr.completion_mode, p.account_completion_mode) AS payment_completion_mode,
                COALESCE(cfr.payment_confirmation_status, sfr.payment_confirmation_status, p.account_confirmation_status) AS payment_confirmation_status,
                COALESCE(cfr.payment_reference, sfr.payment_reference, p.account_payment_reference) AS payment_reference,
                COALESCE(cfr.paid_at, sfr.paid_at, p.account_paid_at) AS paid_at,
                COALESCE(cfr.account_remarks, sfr.account_remarks, p.account_payment_remarks) AS account_remarks,
                COALESCE(cfr.payment_batch_id, sfr.payment_batch_id, p.account_payment_batch_id) AS payment_batch_id,
                CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                CONCAT(COALESCE(uu.fname, ''), ' ', COALESCE(uu.lname, '')) AS updated_by_name,
                CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS approval_reversed_by_name,
                CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
         FROM {$relation} p
         LEFT JOIN supplier_fund_request_table sfr
           ON sfr.id = p.supplier_fund_request_id
          AND (p.account_request_type IS NULL OR p.account_request_type = 'supplier_fund_request')
         LEFT JOIN compass_fund_request_table cfr
           ON cfr.id = p.supplier_fund_request_id
          AND p.account_request_type = 'compass_fund_request'
         LEFT JOIN user_table cu ON cu.id = p.created_by
         LEFT JOIN user_table uu ON uu.id = p.updated_by
         LEFT JOIN user_table au ON au.id = p.approved_by
         LEFT JOIN user_table ru ON ru.id = p.approval_reversed_by
         LEFT JOIN user_table rtu ON rtu.id = p.retrieved_by
         WHERE p.id = ? AND p.deleted_at IS NULL
         LIMIT 1$lock"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        $grossCents = procurementLocalFinalMoneyToCents(
            $row['account_payable_amount'] ?? 0,
            'Account Payable Amount',
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
        if ((int) ($row['supplier_fund_request_id'] ?? 0) > 0
            && (string) ($row['account_request_type'] ?? '') !== PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
            $summary = procurementSupplierPaymentOffsetSummaryForRequest(
                $conn,
                'local_final_purchase',
                (int) $row['supplier_fund_request_id']
            );
        }
        $offsetCents = min(
            $grossCents,
            max(
                procurementLocalFinalMoneyToCents(
                    $summary['effective_offset_amount'] ?? 0,
                    'Supplier Offset',
                    true
                ),
                procurementLocalFinalMoneyToCents(
                    $row['account_supplier_credit_applied'] ?? 0,
                    'Account Supplier Credit',
                    true
                )
            )
        );
        $row['account_supplier_offset_reserved'] = (string) ($summary['reserved_amount'] ?? '0.00');
        $row['account_supplier_offset_applied'] = (string) ($summary['applied_amount'] ?? '0.00');
        $row['account_supplier_offset_amount'] = procurementLocalFinalCents($offsetCents);
        $row['account_net_cash_payable'] = procurementLocalFinalCents(max(0, $grossCents - $offsetCents));
        $row['account_offset_basis'] = trim((string) ($summary['basis'] ?? ''));
        $row['account_offset_reason'] = trim((string) ($summary['reason'] ?? ''));
        $row['account_offset_references'] = is_array($summary['references'] ?? null)
            ? $summary['references']
            : [];
    }
    return $row;
}

function procurementLocalFinalSerializeRecord(array $row): array
{
    foreach ([
        'id', 'canonical_request_id', 'procurement_request_id', 'project_id', 'supplier_id', 'supplier_fund_request_id',
        'previous_supplier_fund_request_id', 'approved_by', 'approval_reversed_by',
        'retrieved_by', 'created_by', 'updated_by', 'version', 'handoff_revision',
        'account_payment_batch_id', 'payment_batch_id', 'account_wht_adjusted_by'
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }
    $row['account_request_type'] = trim((string) ($row['account_request_type'] ?? '')) ?: null;
    $row['is_compass_handoff'] = $row['account_request_type'] === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS;
    $row['is_retrieved'] = ($row['handoff_status'] ?? '') === 'Retrieved';
    $row['is_editable'] = ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending'
        && empty($row['supplier_fund_request_id']);
    $row['is_deletable'] = $row['is_editable'] && !$row['is_retrieved'];
    $accountPaymentStatus = trim((string) ($row['account_payment_status'] ?? ''));
    $row['is_retrievable'] = ($row['approval_status'] ?? '') === 'Approved'
        && ($row['handoff_status'] ?? '') === 'In Account'
        && ($row['payment_status'] ?? '') === 'Pending'
        && $accountPaymentStatus === 'Pending'
        && !empty($row['supplier_fund_request_id']);
    $row['is_resubmittable'] = $row['is_retrieved']
        && ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending';
    $row['is_paid_revisable'] = ($row['approval_status'] ?? '') === 'Approved'
        && ($row['payment_status'] ?? '') === 'Paid'
        && ($row['po_status'] ?? '') !== 'Cancelled';
    return $row;
}

function procurementLocalFinalComparableReference(mixed $value): string
{
    $normalized = procurementLocalFinalUpper(trim((string) $value));
    return preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';
}

function procurementLocalFinalReferencesMatch(mixed $left, mixed $right): bool
{
    $leftNormalized = procurementLocalFinalComparableReference($left);
    $rightNormalized = procurementLocalFinalComparableReference($right);
    if ($leftNormalized === '' || $rightNormalized === '') {
        return false;
    }
    if ($leftNormalized === $rightNormalized) {
        return true;
    }

    $shorter = strlen($leftNormalized) <= strlen($rightNormalized) ? $leftNormalized : $rightNormalized;
    $longer = strlen($leftNormalized) > strlen($rightNormalized) ? $leftNormalized : $rightNormalized;
    return strlen($shorter) >= 4 && str_ends_with($longer, $shorter);
}

function procurementLocalFinalExpectedSupplierAmountCents(array $purchase): int
{
    $hasVat = procurementLocalFinalMoneyToCents(
        $purchase['purchase_vat_amount'] ?? '0.00',
        'Purchase VAT Amount'
    ) > 0 && strcasecmp((string) ($purchase['purchase_vat_status'] ?? 'No'), 'Yes') === 0;
    $whtAmount = $hasVat ? (string) ($purchase['wht_amount'] ?? '0.00') : '0.00';
    $payableCents = procurementLocalFinalMoneyToCents(
        $purchase['purchase_value'] ?? '0.00',
        'Purchase Value'
    ) - procurementLocalFinalMoneyToCents($whtAmount, 'WHT Amount');
    if ($payableCents < 0) {
        throw new RuntimeException('Calculated Supplier Fund Request amount cannot be negative.', 500);
    }
    return $payableCents;
}

function procurementLocalFinalFindReusableSupplierFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision
): ?array {
    $purchaseNumber = (string) $purchase['purchase_number'];
    [, $normalizedPurchaseNumber] = procurementLocalFinalNormalizePurchaseNumber($purchaseNumber);
    $stmt = $conn->prepare(
        "SELECT * FROM supplier_fund_request_table
         WHERE UPPER(REPLACE(TRIM(purchase_number), ' ', '')) = ?
         ORDER BY id DESC FOR UPDATE"
    );
    $stmt->bind_param('s', $normalizedPurchaseNumber);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($candidates === []) {
        return null;
    }

    $purchaseId = (int) $purchase['id'];
    $supplierLedger = trim((string) ($purchase['supplier_ledger'] ?? ''));
    $supplierName = procurementLocalFinalComparableReference($purchase['supplier_name'] ?? '');
    $projectCode = procurementLocalFinalComparableReference($purchase['project_code'] ?? '');
    $expectedAmountCents = procurementLocalFinalExpectedSupplierAmountCents($purchase);
    $matches = [];

    foreach ($candidates as $candidate) {
        $linkedPurchaseId = (int) ($candidate['procurement_purchase_id'] ?? 0);
        $linkedSource = strtolower(trim((string) ($candidate['procurement_source'] ?? '')));
        if ($linkedPurchaseId > 0 && !(
            $linkedPurchaseId === $purchaseId && $linkedSource === 'local_final_purchase'
        )) {
            continue;
        }

        $candidateSupplierId = trim((string) ($candidate['supplier_id'] ?? ''));
        $candidateSupplierName = procurementLocalFinalComparableReference($candidate['suppliers_name'] ?? '');
        $supplierMatches = ($supplierLedger !== '' && $candidateSupplierId === $supplierLedger)
            || ($supplierName !== '' && $candidateSupplierName === $supplierName);
        $poMatches = procurementLocalFinalReferencesMatch(
            $candidate['po_number'] ?? '',
            $purchase['po_number'] ?? ''
        );
        $candidateProjectCode = procurementLocalFinalComparableReference($candidate['project_code'] ?? '');
        $projectMatches = $projectCode === '' || $candidateProjectCode === $projectCode;
        $amountMatches = procurementLocalFinalMoneyToCents(
            $candidate['amount'] ?? '0.00',
            'Existing Supplier Fund Request Amount'
        ) === $expectedAmountCents;

        if ($supplierMatches && $poMatches && $projectMatches && $amountMatches) {
            $matches[] = $candidate;
        }
    }

    if (count($matches) > 1) {
        throw new RuntimeException(
            'Approval cannot continue because more than one matching Supplier Fund Request already exists.',
            409
        );
    }
    if ($matches === []) {
        throw new RuntimeException(
            'Approval cannot continue because this Purchase Number belongs to a different Supplier Fund Request.',
            409
        );
    }

    $request = $matches[0];
    $source = 'local_final_purchase';
    $requestId = (int) $request['id'];
    $requestLinkedPurchaseId = (int) ($request['procurement_purchase_id'] ?? 0);
    $link = $conn->prepare(
        "UPDATE supplier_fund_request_table
         SET procurement_source = ?, procurement_purchase_id = ?, procurement_revision = ?
         WHERE id = ?
           AND (procurement_purchase_id IS NULL OR procurement_purchase_id = ?)
           AND (procurement_source IS NULL OR procurement_source = '' OR procurement_source = ?)"
    );
    $link->bind_param('siiiis', $source, $purchaseId, $revision, $requestId, $purchaseId, $source);
    $link->execute();
    if ($link->affected_rows < 1 && $requestLinkedPurchaseId !== $purchaseId) {
        $link->close();
        throw new RuntimeException(
            'The matching Supplier Fund Request was linked to another purchase before approval completed.',
            409
        );
    }
    $link->close();

    $request['procurement_source'] = $source;
    $request['procurement_purchase_id'] = $purchaseId;
    $request['procurement_revision'] = $revision;
    return $request;
}

function procurementLocalFinalCreateSupplierFundRequest(mysqli $conn, array $purchase, int $revision): array
{
    $reusableRequest = procurementLocalFinalFindReusableSupplierFundRequest($conn, $purchase, $revision);
    if ($reusableRequest !== null) {
        return [
            'id' => (int) $reusableRequest['id'],
            'reused' => true,
            'request' => $reusableRequest,
        ];
    }

    $approvalDateRow = $conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc();
    $approvalDate = (string) ($approvalDateRow['approval_date'] ?? date('Y-m-d'));
    $invoiceMonth = date('M-Y', strtotime((string) $purchase['invoice_date']));
    $purchaseMonth = date('M-Y', strtotime((string) $purchase['purchase_date']));
    $remark = trim((string) ($purchase['remark'] ?? ''));
    $materialType = procurementLocalFinalValidateStatus(
        $purchase['material_type'] ?? '',
        PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES,
        'Material Type'
    );
    $description = procurementLocalFinalSubstring($materialType, 255);
    $note = 'ProcureDesk GRN REF: ' . (string) $purchase['grn_ref'];
    if ($remark !== '') {
        $note .= ' | ' . $remark;
    }
    $note = procurementLocalFinalSubstring($note, 255);
    $paymentPercentage = '100.00%';
    $paymentStatus = 'Pending';
    $supplierLedger = (int) $purchase['supplier_ledger'];
    $vatAmount = (string) $purchase['purchase_vat_amount'];
    $hasVat = procurementLocalFinalMoneyToCents($vatAmount, 'Purchase VAT Amount') > 0
        && strcasecmp((string) ($purchase['purchase_vat_status'] ?? 'No'), 'Yes') === 0;
    $whtStatus = $hasVat ? (string) $purchase['wht_status'] : '0.00%';
    $whtBasisPoints = $hasVat ? procurementLocalFinalWhtBasisPoints($whtStatus) : 0;
    $legacyVatPolicy = procurementLocalFinalLegacyVatPolicy($hasVat, $whtBasisPoints);
    $whtAmount = $hasVat ? (string) $purchase['wht_amount'] : '0.00';
    $payableAmount = procurementLocalFinalCents(procurementLocalFinalExpectedSupplierAmountCents($purchase));

    $stmt = $conn->prepare(
        'INSERT INTO supplier_fund_request_table
            (suppliers_name, supplier_id, invoice_number, purchase_number, po_number,
             invoice_date, purchase_date, date_received, invoice_month, purchase_month,
             project_code, description, vat_policy, vat, wht, payment_percentage,
             net_value, discount, other_charges, amount, note, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Supplier Fund Request handoff.', 500);
    }

    $supplierName = (string) $purchase['supplier_name'];
    $invoiceNumber = (string) $purchase['invoice_number'];
    $purchaseNumber = (string) $purchase['purchase_number'];
    $poNumber = (string) $purchase['po_number'];
    $invoiceDate = (string) $purchase['invoice_date'];
    $purchaseDate = (string) $purchase['purchase_date'];
    $projectCode = (string) $purchase['project_code'];
    $subtotal = (string) $purchase['purchase_subtotal'];
    $discount = (string) $purchase['purchase_discount'];
    $otherCharges = (string) $purchase['purchase_other_charges'];

    $stmt->bind_param(
        'sissssssssssssssssssss',
        $supplierName,
        $supplierLedger,
        $invoiceNumber,
        $purchaseNumber,
        $poNumber,
        $invoiceDate,
        $purchaseDate,
        $approvalDate,
        $invoiceMonth,
        $purchaseMonth,
        $projectCode,
        $description,
        $legacyVatPolicy,
        $vatAmount,
        $whtAmount,
        $paymentPercentage,
        $subtotal,
        $discount,
        $otherCharges,
        $payableAmount,
        $note,
        $paymentStatus
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    $source = 'local_final_purchase';
    $purchaseId = (int) $purchase['id'];
    $link = $conn->prepare(
        'UPDATE supplier_fund_request_table
         SET procurement_source = ?, procurement_purchase_id = ?, procurement_revision = ?
         WHERE id = ?'
    );
    $link->bind_param('siii', $source, $purchaseId, $revision, $id);
    $link->execute();
    $link->close();

    return [
        'id' => $id,
        'reused' => false,
        'request' => null,
    ];
}

function procurementLocalFinalFindReusableCompassFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision
): ?array {
    $purchaseId = (int) $purchase['id'];
    $source = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;

    $linked = $conn->prepare(
        "SELECT * FROM compass_fund_request_table
         WHERE procurement_source = ? AND procurement_purchase_id = ?
         LIMIT 2 FOR UPDATE"
    );
    $linked->bind_param('si', $source, $purchaseId);
    $linked->execute();
    $linkedRows = $linked->get_result()->fetch_all(MYSQLI_ASSOC);
    $linked->close();
    if (count($linkedRows) > 1) {
        throw new RuntimeException('More than one Compass Fund Request is linked to this purchase.', 409);
    }
    if ($linkedRows !== []) {
        $request = $linkedRows[0];
        $requestId = (int) $request['id'];
        $requestType = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;
        $update = $conn->prepare(
            'UPDATE compass_fund_request_table
             SET procurement_revision = ?, procurement_request_type = ?
             WHERE id = ?'
        );
        $update->bind_param('isi', $revision, $requestType, $requestId);
        $update->execute();
        $update->close();
        $request['procurement_revision'] = $revision;
        $request['procurement_request_type'] = $requestType;
        return $request;
    }

    $invoiceNumber = trim((string) ($purchase['invoice_number'] ?? ''));
    $supplierId = (int) ($purchase['supplier_id'] ?? 0);
    $supplierName = trim((string) ($purchase['supplier_name'] ?? ''));
    $stmt = $conn->prepare(
        "SELECT * FROM compass_fund_request_table
         WHERE invoice_number = ?
           AND (supplier_id = ? OR LOWER(TRIM(suppliers_name)) = LOWER(TRIM(?)))
         ORDER BY id DESC FOR UPDATE"
    );
    $stmt->bind_param('sis', $invoiceNumber, $supplierId, $supplierName);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($candidates === []) {
        return null;
    }

    $projectCode = procurementLocalFinalComparableReference($purchase['project_code'] ?? '');
    $expectedAmountCents = procurementLocalFinalExpectedSupplierAmountCents($purchase);
    $matches = [];
    foreach ($candidates as $candidate) {
        $linkedPurchaseId = (int) ($candidate['procurement_purchase_id'] ?? 0);
        $linkedSource = strtolower(trim((string) ($candidate['procurement_source'] ?? '')));
        if ($linkedPurchaseId > 0 && !(
            $linkedPurchaseId === $purchaseId && $linkedSource === $source
        )) {
            continue;
        }

        $candidateProject = procurementLocalFinalComparableReference($candidate['project_code'] ?? '');
        $projectMatches = $projectCode === '' || $candidateProject === $projectCode;
        $amountMatches = procurementLocalFinalMoneyToCents(
            $candidate['amount'] ?? '0.00',
            'Existing Compass Fund Request Amount'
        ) === $expectedAmountCents;
        $purchaseNumber = trim((string) ($candidate['purchase_number'] ?? ''));
        $poNumber = trim((string) ($candidate['po_number'] ?? ''));
        $purchaseMatches = $purchaseNumber === '' || procurementLocalFinalReferencesMatch(
            $purchaseNumber,
            $purchase['purchase_number'] ?? ''
        );
        $poMatches = $poNumber === '' || procurementLocalFinalReferencesMatch(
            $poNumber,
            $purchase['po_number'] ?? ''
        );

        if ($projectMatches && $amountMatches && $purchaseMatches && $poMatches) {
            $matches[] = $candidate;
        }
    }

    if (count($matches) > 1) {
        throw new RuntimeException(
            'Approval cannot continue because more than one matching Compass Fund Request already exists.',
            409
        );
    }
    if ($matches === []) {
        throw new RuntimeException(
            'Approval cannot continue because this Compass invoice already belongs to a different fund request.',
            409
        );
    }

    $request = $matches[0];
    $requestId = (int) $request['id'];
    $requestType = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;
    $purchaseNumber = (string) $purchase['purchase_number'];
    $poNumber = (string) $purchase['po_number'];
    $purchaseDate = (string) $purchase['purchase_date'];
    $link = $conn->prepare(
        "UPDATE compass_fund_request_table
         SET procurement_source = ?, procurement_purchase_id = ?, procurement_request_type = ?,
             procurement_revision = ?,
             purchase_number = COALESCE(NULLIF(TRIM(purchase_number), ''), ?),
             po_number = COALESCE(NULLIF(TRIM(po_number), ''), ?),
             purchase_date = COALESCE(NULLIF(TRIM(purchase_date), ''), ?)
         WHERE id = ?
           AND (procurement_purchase_id IS NULL OR procurement_purchase_id = ?)
           AND (procurement_source IS NULL OR procurement_source = '' OR procurement_source = ?)"
    );
    $link->bind_param(
        'sisisssiis',
        $source,
        $purchaseId,
        $requestType,
        $revision,
        $purchaseNumber,
        $poNumber,
        $purchaseDate,
        $requestId,
        $purchaseId,
        $source
    );
    $link->execute();
    if ($link->affected_rows < 1 && (int) ($request['procurement_purchase_id'] ?? 0) !== $purchaseId) {
        $link->close();
        throw new RuntimeException(
            'The matching Compass Fund Request was linked to another purchase before approval completed.',
            409
        );
    }
    $link->close();

    $request['procurement_source'] = $source;
    $request['procurement_purchase_id'] = $purchaseId;
    $request['procurement_request_type'] = $requestType;
    $request['procurement_revision'] = $revision;
    return $request;
}

function procurementLocalFinalCreateCompassFundRequest(mysqli $conn, array $purchase, int $revision): array
{
    procurementCompassAssertStorageReady($conn);
    if (!procurementCompassIsSupplier($purchase['supplier_id'] ?? null, $purchase['supplier_name'] ?? null)) {
        throw new RuntimeException('Compass Fund Request routing is only available for Compass Power Solutions Ltd.', 409);
    }

    $reusableRequest = procurementLocalFinalFindReusableCompassFundRequest($conn, $purchase, $revision);
    if ($reusableRequest !== null) {
        return [
            'id' => (int) $reusableRequest['id'],
            'reused' => true,
            'request' => $reusableRequest,
        ];
    }

    $approvalDateRow = $conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc();
    $approvalDate = (string) ($approvalDateRow['approval_date'] ?? date('Y-m-d'));
    $remark = trim((string) ($purchase['remark'] ?? ''));
    $materialType = procurementLocalFinalValidateStatus(
        $purchase['material_type'] ?? '',
        PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES,
        'Material Type'
    );
    $note = 'ProcureDesk GRN REF: ' . (string) $purchase['grn_ref'];
    if ($remark !== '') {
        $note .= ' | ' . $remark;
    }
    $note = procurementLocalFinalSubstring($note, 255);

    $vatAmount = (string) $purchase['purchase_vat_amount'];
    $hasVat = procurementLocalFinalMoneyToCents($vatAmount, 'Purchase VAT Amount') > 0
        && strcasecmp((string) ($purchase['purchase_vat_status'] ?? 'No'), 'Yes') === 0;
    $whtStatus = $hasVat ? (string) $purchase['wht_status'] : '0.00%';
    $whtBasisPoints = $hasVat ? procurementLocalFinalWhtBasisPoints($whtStatus) : 0;
    $vatPolicy = procurementLocalFinalLegacyVatPolicy($hasVat, $whtBasisPoints);
    $whtAmount = $hasVat ? (string) $purchase['wht_amount'] : '0.00';
    $payableAmount = procurementLocalFinalCents(procurementLocalFinalExpectedSupplierAmountCents($purchase));

    $supplierName = (string) $purchase['supplier_name'];
    $supplierId = (int) $purchase['supplier_id'];
    $invoiceNumber = (string) $purchase['invoice_number'];
    $purchaseNumber = (string) $purchase['purchase_number'];
    $poNumber = (string) $purchase['po_number'];
    $invoiceDate = (string) $purchase['invoice_date'];
    $purchaseDate = (string) $purchase['purchase_date'];
    $projectCode = (string) $purchase['project_code'];
    $description = procurementLocalFinalSubstring($materialType, 255);
    $classification = 'Procurement';
    $percentage = '100';
    $subtotal = (string) $purchase['purchase_subtotal'];
    $discount = (string) $purchase['purchase_discount'];
    $otherCharges = (string) $purchase['purchase_other_charges'];
    $paymentStatus = 'Pending';
    $vatRate = (string) $purchase['purchase_vat_rate'];
    $vatStatus = (string) $purchase['purchase_vat_status'];
    $whtRate = procurementLocalFinalPercentFromBasisPoints($whtBasisPoints);
    $source = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;
    $purchaseId = (int) $purchase['id'];
    $requestType = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;

    $stmt = $conn->prepare(
        'INSERT INTO compass_fund_request_table
            (suppliers_name, supplier_id, invoice_number, purchase_number, po_number,
             invoice_date, purchase_date, date_received, project_code, description,
             classification, percentage, net_value, vat_policy, vat, vat_rate, vat_status,
             wht, wht_status, wht_rate, discount, other_charges, amount, note, payment_status,
             procurement_source, procurement_purchase_id, procurement_request_type, procurement_revision)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Compass Fund Request handoff.', 500);
    }
    $stmt->bind_param(
        'sissssssssssssssssssssssssisi',
        $supplierName,
        $supplierId,
        $invoiceNumber,
        $purchaseNumber,
        $poNumber,
        $invoiceDate,
        $purchaseDate,
        $approvalDate,
        $projectCode,
        $description,
        $classification,
        $percentage,
        $subtotal,
        $vatPolicy,
        $vatAmount,
        $vatRate,
        $vatStatus,
        $whtAmount,
        $whtStatus,
        $whtRate,
        $discount,
        $otherCharges,
        $payableAmount,
        $note,
        $paymentStatus,
        $source,
        $purchaseId,
        $requestType,
        $revision
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'reused' => false,
        'request' => null,
    ];
}

function procurementLocalFinalCreateAccountFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    string $accountRequestType
): array {
    if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
        return procurementLocalFinalCreateCompassFundRequest($conn, $purchase, $revision);
    }
    if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER) {
        return procurementLocalFinalCreateSupplierFundRequest($conn, $purchase, $revision);
    }
    throw new RuntimeException('Unsupported Local Final Account handoff destination.', 500);
}

function procurementLocalFinalApplySupplierRequestSnapshot(
    mysqli $conn,
    int $purchaseId,
    array $request,
    int $actorId
): void {
    $status = trim((string) ($request['payment_status'] ?? 'Pending'));
    if ($status === 'Unconfirmed') {
        $status = 'Processing';
    }
    if (!in_array($status, PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES, true)) {
        $status = 'Pending';
    }

    $amountPaid = number_format((float) ($request['amount_paid'] ?? 0), 2, '.', '');
    $method = $request['processing_method'] === null ? null : (string) $request['processing_method'];
    $processingReference = $request['processing_reference'] === null
        ? null
        : (string) $request['processing_reference'];
    $processingStartedAt = $request['processing_started_at'] === null
        ? null
        : (string) $request['processing_started_at'];
    $expectedCompletionAt = $request['expected_completion_at'] === null
        ? null
        : (string) $request['expected_completion_at'];
    $completionMode = $request['completion_mode'] === null ? null : (string) $request['completion_mode'];
    $confirmationStatus = $request['payment_confirmation_status'] === null
        ? null
        : (string) $request['payment_confirmation_status'];
    $paymentReference = $request['payment_reference'] === null ? null : (string) $request['payment_reference'];
    $paidAt = $request['paid_at'] === null ? null : (string) $request['paid_at'];
    $remarks = $request['account_remarks'] === null ? null : (string) $request['account_remarks'];
    $batchId = $request['payment_batch_id'] === null ? null : (int) $request['payment_batch_id'];

    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = ?, payment_status_source = 'account',
             payment_status_updated_at = NOW(), account_amount_paid = ?,
             account_processing_method = ?, account_processing_reference = ?,
             account_processing_started_at = ?, account_expected_completion_at = ?,
             account_completion_mode = ?, account_confirmation_status = ?,
             account_payment_reference = ?, account_paid_at = ?,
             account_payment_remarks = ?, account_payment_batch_id = ?,
             updated_by = ?, updated_at = NOW()
         WHERE legacy_source_id = ? AND deleted_at IS NULL
           AND request_type = 'local_final_purchase'
           AND legacy_source_table = 'procurement_local_final_purchases' "
    );
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
    $update->close();
}

function procurementLocalFinalSupplierRequestSnapshot(array $request): string
{
    return (string) json_encode(
        $request,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function procurementLocalFinalCreateHandoff(
    mysqli $conn,
    int $purchaseId,
    int $revision,
    string $accountRequestType,
    int $accountRequestId,
    int $actorId
): void {
    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        $purchaseId,
        $revision,
        $accountRequestType,
        $accountRequestId,
        'In Account',
        $actorId
    );
}

function procurementLocalFinalArchiveHandoff(
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
    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        $purchaseId,
        $revision,
        $accountRequestType,
        $accountRequestId,
        $status,
        (int) ($actor['id'] ?? 0),
        (int) ($actor['id'] ?? 0),
        $source,
        $reason,
        procurementLocalFinalSupplierRequestSnapshot($accountRequest)
    );
}

function procurementLocalFinalAccountRequestTable(string $accountRequestType): string
{
    return match ($accountRequestType) {
        PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS => 'compass_fund_request_table',
        PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER => 'supplier_fund_request_table',
        default => throw new RuntimeException('Unsupported Local Final Account handoff destination.', 500),
    };
}

function procurementLocalFinalFetchAccountRequestForUpdate(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): ?array {
    $table = procurementLocalFinalAccountRequestTable($accountRequestType);
    $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $accountRequestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $request;
}

function procurementLocalFinalDeletePendingAccountRequest(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): void {
    $table = procurementLocalFinalAccountRequestTable($accountRequestType);
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

function procurementLocalFinalApproveOne(mysqli $conn, int $id, array $actor): array
{
    $conn->begin_transaction();
    try {
        $purchase = procurementLocalFinalFetchRecord($conn, $id, true);
        if (!$purchase) {
            throw new RuntimeException('Local Final Purchase not found.', 404);
        }
        if ((string) $purchase['approval_status'] !== 'Unapproved') {
            throw new RuntimeException('This Local Final Purchase is already approved.', 409);
        }
        if (trim((string) ($purchase['material_type'] ?? '')) === '') {
            throw new RuntimeException('Select a Material Type before approving this Local Final Purchase.', 409);
        }
        if ((string) $purchase['payment_status'] !== 'Pending') {
            throw new RuntimeException('Only pending Local Final Purchases can be approved.', 409);
        }
        if ((string) $purchase['po_status'] === 'Cancelled') {
            throw new RuntimeException('A cancelled PO cannot be approved.', 409);
        }
        if (!empty($purchase['supplier_fund_request_id'])) {
            throw new RuntimeException('This purchase still has an active Account handoff.', 409);
        }

        $previousHandoffStatus = (string) ($purchase['handoff_status'] ?? 'Not Sent');
        if (!in_array($previousHandoffStatus, ['Not Sent', 'Retrieved'], true)) {
            throw new RuntimeException('This purchase is not eligible for approval or resubmission.', 409);
        }

        $revision = max(0, (int) ($purchase['handoff_revision'] ?? 0)) + 1;
        $accountRequestType = procurementCompassResolveLocalAccountRequestType(
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $purchase['supplier_id'] ?? null,
            $purchase['supplier_name'] ?? null
        );
        $handoffRequest = procurementLocalFinalCreateAccountFundRequest(
            $conn,
            $purchase,
            $revision,
            $accountRequestType
        );
        $accountRequestId = (int) $handoffRequest['id'];
        $reusedAccountRequest = (bool) ($handoffRequest['reused'] ?? false);
        $actorId = (int) $actor['id'];
        procurementLocalFinalCreateHandoff(
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
                 handoff_revision = ?, account_request_type = ?, account_request_id = ?,
                 approved_by = ?, approved_at = NOW(),
                 approval_reversed_by = NULL, approval_reversed_at = NULL,
                 retrieved_by = NULL, retrieved_at = NULL,
                 retrieval_reason = NULL, retrieval_source = NULL,
                 payment_status = 'Pending', payment_status_source = 'procuredesk',
                 payment_status_updated_at = NOW(),
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ?
               AND request_type = 'local_final_purchase'
               AND legacy_source_table = 'procurement_local_final_purchases' "
        );
        $update->bind_param('isiiii', $revision, $accountRequestType, $accountRequestId, $actorId, $actorId, $id);
        $update->execute();
        $update->close();

        if ($reusedAccountRequest && is_array($handoffRequest['request'] ?? null)) {
            procurementLocalFinalApplySupplierRequestSnapshot(
                $conn,
                $id,
                $handoffRequest['request'],
                $actorId
            );
        }

        $eventType = $previousHandoffStatus === 'Retrieved' ? 'resubmitted_to_account' : 'approved';
        $eventDetails = [
            'account_request_type' => $accountRequestType,
            'account_request_id' => $accountRequestId,
            'handoff_revision' => $revision,
            'previous_handoff_status' => $previousHandoffStatus,
            'existing_account_request_linked' => $reusedAccountRequest,
            'existing_payment_status' => $reusedAccountRequest
                ? (string) (($handoffRequest['request']['payment_status'] ?? 'Pending'))
                : null,
        ];
        if ($accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS) {
            $eventDetails['compass_fund_request_id'] = $accountRequestId;
        } else {
            $eventDetails['supplier_fund_request_id'] = $accountRequestId;
        }
        procurementLocalFinalRecordEvent($conn, $id, $eventType, $actor, $eventDetails);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $id
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []);
}

function procurementLocalFinalReverseApprovalOne(mysqli $conn, int $id, array $actor, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('An approval-reversal reason is required.', 400);
    }

    $conn->begin_transaction();
    try {
        $purchase = procurementLocalFinalFetchRecord($conn, $id, true);
        if (!$purchase) {
            throw new RuntimeException('Local Final Purchase not found.', 404);
        }
        if ((string) $purchase['approval_status'] !== 'Approved' || empty($purchase['supplier_fund_request_id'])) {
            throw new RuntimeException('Only an approved Local Final Purchase can be unapproved.', 409);
        }

        $accountRequestType = trim((string) ($purchase['account_request_type'] ?? ''))
            ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER;
        $accountRequestId = (int) $purchase['supplier_fund_request_id'];
        $accountRequest = procurementLocalFinalFetchAccountRequestForUpdate(
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
        procurementLocalFinalArchiveHandoff(
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
        procurementLocalFinalDeletePendingAccountRequest($conn, $accountRequestType, $accountRequestId);

        $actorId = (int) $actor['id'];
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Not Sent',
                 account_request_type = NULL, account_request_id = NULL, previous_account_request_id = ?,
                 approved_by = NULL, approved_at = NULL,
                 approval_reversed_by = ?, approval_reversed_at = NOW(),
                 payment_status = 'Pending', payment_status_source = 'procuredesk',
                 payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE legacy_source_id = ?
               AND request_type = 'local_final_purchase'
               AND legacy_source_table = 'procurement_local_final_purchases' "
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
            $eventDetails['removed_supplier_fund_request_id'] = $accountRequestId;
        }
        procurementLocalFinalRecordEvent($conn, $id, 'approval_reversed', $actor, $eventDetails);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $id
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []);
}

function procurementLocalFinalRetrieveOne(
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
        $purchase = procurementLocalFinalFetchRecord($conn, $id, true);
        if (!$purchase) {
            throw new RuntimeException('Local Final Purchase not found.', 404);
        }
        if ((string) $purchase['approval_status'] !== 'Approved'
            || (string) ($purchase['handoff_status'] ?? '') !== 'In Account'
            || empty($purchase['supplier_fund_request_id'])) {
            throw new RuntimeException('Only a purchase currently awaiting Account payment can be retrieved.', 409);
        }
        if ((string) $purchase['payment_status'] !== 'Pending') {
            throw new RuntimeException('This purchase cannot be retrieved after Account has started processing it.', 409);
        }

        $accountRequestType = trim((string) ($purchase['account_request_type'] ?? ''))
            ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER;
        $accountRequestId = (int) $purchase['supplier_fund_request_id'];
        $accountRequest = procurementLocalFinalFetchAccountRequestForUpdate(
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
        procurementLocalFinalArchiveHandoff(
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
        procurementLocalFinalDeletePendingAccountRequest($conn, $accountRequestType, $accountRequestId);

        $actorId = (int) ($actor['id'] ?? 0);
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Retrieved',
                 account_request_type = NULL, account_request_id = NULL, previous_account_request_id = ?,
                 approved_by = NULL, approved_at = NULL,
                 retrieved_by = ?, retrieved_at = NOW(), retrieval_reason = ?, retrieval_source = ?,
                 payment_status = 'Pending', payment_status_source = 'procuredesk',
                 payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE legacy_source_id = ?
               AND request_type = 'local_final_purchase'
               AND legacy_source_table = 'procurement_local_final_purchases' "
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
            $eventDetails['removed_supplier_fund_request_id'] = $accountRequestId;
        }
        procurementLocalFinalRecordEvent($conn, $id, $eventType, $actor, $eventDetails);
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $id
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []);
}

function procurementLocalFinalRetrieveSupplierRequestOne(
    mysqli $conn,
    int $supplierRequestId,
    array $actor,
    string $reason
): array {
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id FROM {$relation} source
         WHERE supplier_fund_request_id = ?
           AND (account_request_type IS NULL OR account_request_type = 'supplier_fund_request')
           AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $supplierRequestId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        throw new RuntimeException('Only ProcureDesk-linked Supplier Fund Requests can be returned.', 409);
    }

    return procurementLocalFinalRetrieveOne($conn, (int) $purchase['id'], $actor, $reason, 'account');
}

function procurementLocalFinalRetrieveCompassRequestOne(
    mysqli $conn,
    int $compassRequestId,
    array $actor,
    string $reason
): array {
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id FROM {$relation} source
         WHERE supplier_fund_request_id = ?
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
        throw new RuntimeException('Only ProcureDesk-linked Compass Fund Requests can be returned.', 409);
    }

    return procurementLocalFinalRetrieveOne($conn, (int) $purchase['id'], $actor, $reason, 'account');
}

function procurementLocalFinalBatchIds(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Select at least one Local Final Purchase.', 400);
    }
    $ids = array_values(array_unique(array_filter(
        array_map(static fn(mixed $id): int => (int) $id, $value),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        throw new RuntimeException('No valid Local Final Purchase IDs were supplied.', 400);
    }
    if (count($ids) > PROCUREMENT_LOCAL_FINAL_MAX_BATCH) {
        throw new RuntimeException('A maximum of 100 records can be processed at once.', 400);
    }
    return $ids;
}

function procurementLocalFinalManualSupplierRequestConflict(mysqli $conn, string $purchaseNumber): ?array
{
    procurementRequestCanonicalLocalFinalAssertReady($conn);
    [, $normalized] = procurementLocalFinalNormalizePurchaseNumber($purchaseNumber);
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id, approval_status, supplier_fund_request_id
         FROM {$relation} source
         WHERE purchase_number_normalized = ? AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementSyncLocalFinalPurchasePaymentDetails(
    mysqli $conn,
    array $supplierRequestIds,
    int $actorId,
    string $eventType = 'account_payment_updated'
): void {
    procurementEnsureLocalFinalPurchaseStorage($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $supplierRequestIds))));
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT p.id AS purchase_id, p.payment_status AS previous_payment_status,
                sfr.id AS supplier_request_id, sfr.payment_status, sfr.amount_paid,
                sfr.processing_method, sfr.processing_reference, sfr.processing_started_at,
                sfr.expected_completion_at, sfr.completion_mode,
                sfr.payment_confirmation_status, sfr.payment_reference, sfr.paid_at,
                sfr.account_remarks, sfr.payment_batch_id
         FROM {$relation} p
         INNER JOIN supplier_fund_request_table sfr ON sfr.id = p.supplier_fund_request_id
          AND (p.account_request_type IS NULL OR p.account_request_type = 'supplier_fund_request')
         WHERE sfr.id IN ($placeholders) AND p.deleted_at IS NULL"
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
         WHERE legacy_source_id = ? AND deleted_at IS NULL
           AND request_type = 'local_final_purchase'
           AND legacy_source_table = 'procurement_local_final_purchases' "
    );

    foreach ($rows as $row) {
        $status = trim((string) ($row['payment_status'] ?? 'Pending'));
        if ($status === 'Unconfirmed') {
            $status = 'Processing';
        }
        if (!in_array($status, PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES, true)) {
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

        procurementLocalFinalRecordEvent(
            $conn,
            $purchaseId,
            $eventType,
            ['id' => $actorId, 'email' => $actorId > 0 ? 'account-user' : 'system'],
            [
                'supplier_fund_request_id' => (int) $row['supplier_request_id'],
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
            ]
        );
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $purchaseId
        );
    }
    $update->close();
}

function procurementSyncLocalFinalCompassPaymentDetails(
    mysqli $conn,
    array $compassRequestIds,
    int $actorId,
    string $eventType = 'account_compass_payment_updated'
): void {
    procurementEnsureLocalFinalPurchaseStorage($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $compassRequestIds))));
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT p.id AS purchase_id, p.payment_status AS previous_payment_status,
                cfr.id AS compass_request_id, cfr.payment_status, cfr.amount_paid,
                cfr.processing_method, cfr.processing_reference, cfr.processing_started_at,
                cfr.expected_completion_at, cfr.completion_mode,
                cfr.payment_confirmation_status, cfr.payment_reference, cfr.paid_at,
                cfr.account_remarks, cfr.payment_batch_id
         FROM {$relation} p
         INNER JOIN compass_fund_request_table cfr ON cfr.id = p.supplier_fund_request_id
         WHERE p.account_request_type = 'compass_fund_request'
           AND cfr.id IN ($placeholders) AND p.deleted_at IS NULL"
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
         WHERE legacy_source_id = ? AND deleted_at IS NULL
           AND request_type = 'local_final_purchase'
           AND account_request_type = 'compass_fund_request'
           AND legacy_source_table = 'procurement_local_final_purchases' "
    );

    foreach ($rows as $row) {
        $status = trim((string) ($row['payment_status'] ?? 'Pending'));
        if ($status === 'Unconfirmed') {
            $status = 'Processing';
        }
        if (!in_array($status, PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES, true)) {
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

        procurementLocalFinalRecordEvent(
            $conn,
            $purchaseId,
            $eventType,
            ['id' => $actorId, 'email' => $actorId > 0 ? 'account-user' : 'system'],
            [
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
            ]
        );
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $purchaseId
        );
    }
    $update->close();
}

function procurementSyncLocalFinalPurchasePaymentStatus(
    mysqli $conn,
    array $supplierRequestIds,
    string $paymentStatus,
    int $actorId
): void {
    procurementEnsureLocalFinalPurchaseStorage($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $supplierRequestIds))));
    if ($ids === []) {
        return;
    }
    $normalizedStatus = $paymentStatus === 'Unconfirmed' ? 'Processing' : $paymentStatus;
    if (!in_array($normalizedStatus, PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES, true)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$actorId], $ids);

    if ($normalizedStatus === 'Paid') {
        $stmt = $conn->prepare(
            "UPDATE supplier_fund_request_table
             SET amount_paid = CAST(amount AS DECIMAL(18,2)), paid_at = COALESCE(paid_at, NOW()),
                 payment_confirmation_status = 'Confirmed', payment_updated_by = ?, payment_updated_at = NOW()
             WHERE id IN ($placeholders)"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } elseif ($normalizedStatus === 'Processing') {
        $stmt = $conn->prepare(
            "UPDATE supplier_fund_request_table
             SET processing_started_at = COALESCE(processing_started_at, NOW()),
                 processing_method = COALESCE(processing_method, 'Manual'),
                 payment_confirmation_status = CASE
                     WHEN expected_completion_at IS NULL THEN 'Not Scheduled' ELSE 'Scheduled' END,
                 payment_updated_by = ?, payment_updated_at = NOW()
             WHERE id IN ($placeholders)"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    } elseif (in_array($normalizedStatus, ['Failed', 'Cancelled'], true)) {
        $stmt = $conn->prepare(
            "UPDATE supplier_fund_request_table
             SET amount_paid = 0.00, paid_at = NULL, payment_confirmation_status = ?,
                 payment_updated_by = ?, payment_updated_at = NOW()
             WHERE id IN ($placeholders)"
        );
        $statusTypes = 'si' . str_repeat('i', count($ids));
        $statusParams = array_merge([$normalizedStatus, $actorId], $ids);
        $stmt->bind_param($statusTypes, ...$statusParams);
        $stmt->execute();
        $stmt->close();
    }

    procurementSyncLocalFinalPurchasePaymentDetails(
        $conn,
        $ids,
        $actorId,
        'account_payment_status_updated'
    );
}

function procurementSyncLocalFinalPurchaseFromSupplierRequest(
    mysqli $conn,
    int $supplierRequestId,
    array $actor
): array {
    procurementEnsureLocalFinalPurchaseStorage($conn);
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT p.*, sfr.*,
                p.id AS local_purchase_id,
                p.purchase_number AS local_purchase_number,
                p.material_type AS local_material_type,
                p.remark AS local_remark
         FROM {$relation} p
         INNER JOIN supplier_fund_request_table sfr ON sfr.id = p.supplier_fund_request_id
          AND (p.account_request_type IS NULL OR p.account_request_type = 'supplier_fund_request')
         WHERE sfr.id = ? AND p.deleted_at IS NULL LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $supplierRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('The linked ProcureDesk Local Final Purchase could not be found.', 404);
    }
    if ((string) $row['payment_status'] !== 'Pending') {
        throw new RuntimeException('Commercial details can only be changed before Account starts processing payment.', 409);
    }

    $purchaseId = (int) $row['local_purchase_id'];
    [$purchaseNumber, $normalized] = procurementLocalFinalNormalizePurchaseNumber((string) $row['purchase_number']);
    procurementLocalFinalAssertPurchaseNumberAvailable($conn, $normalized, $purchaseId);

    $project = procurementLocalFinalResolveProject($conn, ['project_code' => (string) $row['project_code']]);
    $supplier = procurementLocalFinalResolveSupplier($conn, ['supplier_ledger' => (string) $row['supplier_id']]);
    $materialType = procurementLocalFinalValidateStatus(
        (string) $row['description'],
        PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES,
        'Material Type'
    );

    $subtotalCents = procurementLocalFinalMoneyToCents($row['net_value'], 'Purchase Subtotal', false);
    $discountCents = procurementLocalFinalMoneyToCents($row['discount'], 'Purchase Discount');
    $otherChargesCents = procurementLocalFinalMoneyToCents($row['other_charges'], 'Purchase Other Charges');
    $vatCents = procurementLocalFinalMoneyToCents($row['vat'], 'Purchase VAT Amount');
    if ($discountCents > $subtotalCents) {
        throw new RuntimeException('Purchase Discount cannot be greater than Purchase Subtotal.', 400);
    }
    $purchaseNetCents = $subtotalCents - $discountCents;
    $vatStatus = $vatCents > 0 ? 'Yes' : 'No';
    $vatRate = $vatCents > 0 ? '0.075000' : '0.000000';
    $requestWhtOverrideStatus = trim((string) ($row['wht_override_status'] ?? ''));
    $hasRequestWhtOverride = $vatCents > 0 && $requestWhtOverrideStatus !== '';
    $effectiveWhtBasisPoints = $vatCents > 0
        ? ($hasRequestWhtOverride
            ? procurementLocalFinalWhtBasisPoints($requestWhtOverrideStatus)
            : (int) $supplier['wht_basis_points'])
        : 0;
    $whtCents = procurementLocalFinalRateAmount($purchaseNetCents, $effectiveWhtBasisPoints);
    $purchaseValueCents = $purchaseNetCents + $otherChargesCents + $vatCents;
    $accountPayableCents = $purchaseValueCents - $whtCents;
    if ($accountPayableCents < 0) {
        throw new RuntimeException('Calculated Supplier Fund Request amount cannot be negative.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);

    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET po_number = ?, purchase_number = ?, purchase_number_normalized = ?,
             material_type = ?, project_id = ?, project_code = ?, project_name = ?,
             supplier_id = ?, supplier_name = ?, supplier_ledger = ?,
             wht_status = ?, wht_rate = ?, wht_amount = ?,
             invoice_number = ?, invoice_date = ?, purchase_date = ?,
             purchase_subtotal = ?, purchase_discount = ?, purchase_other_charges = ?,
             purchase_vat_status = ?, purchase_vat_rate = ?, purchase_vat_amount = ?,
             purchase_value = ?, payment_status_source = 'account',
             account_payment_remarks = ?, updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND deleted_at IS NULL
           AND request_type = 'local_final_purchase'
           AND legacy_source_table = 'procurement_local_final_purchases' "
    );
    $poNumber = trim((string) $row['po_number']);
    $projectId = (int) $project['id'];
    $supplierId = (int) $supplier['id'];
    $supplierLedger = (string) $supplier['ledger'];
    $whtStatus = $effectiveWhtBasisPoints > 0
        ? ($hasRequestWhtOverride
            ? number_format($effectiveWhtBasisPoints / 100, 2, '.', '') . '%'
            : (string) $supplier['wht_status'])
        : '0.00%';
    $whtRate = procurementLocalFinalPercentFromBasisPoints($effectiveWhtBasisPoints);
    $whtAmount = procurementLocalFinalCents($whtCents);
    $invoiceNumber = trim((string) $row['invoice_number']);
    $invoiceDate = procurementLocalFinalDate(['invoice_date' => $row['invoice_date']], 'invoice_date', 'Invoice Date');
    $purchaseDate = procurementLocalFinalDate(['purchase_date' => $row['purchase_date']], 'purchase_date', 'Purchase Date');
    $subtotal = procurementLocalFinalCents($subtotalCents);
    $discount = procurementLocalFinalCents($discountCents);
    $otherCharges = procurementLocalFinalCents($otherChargesCents);
    $vatAmount = procurementLocalFinalCents($vatCents);
    $purchaseValue = procurementLocalFinalCents($purchaseValueCents);
    $accountRemarks = trim((string) ($row['account_remarks'] ?? $row['note'] ?? ''));

    $canonicalRequest = $conn->prepare(
        'UPDATE supplier_fund_request_table
         SET suppliers_name = ?, supplier_id = ?, purchase_number = ?, project_code = ?,
             description = ?, vat_policy = ?, vat = ?, wht = ?, amount = ?,
             account_remarks = ?, payment_updated_by = ?, payment_updated_at = NOW()
         WHERE id = ?'
    );
    $canonicalVatPolicy = procurementLocalFinalLegacyVatPolicy($vatCents > 0, $effectiveWhtBasisPoints);
    $canonicalVatAmount = procurementLocalFinalCents($vatCents);
    $canonicalWhtAmount = procurementLocalFinalCents($whtCents);
    $canonicalPayableAmount = procurementLocalFinalCents($accountPayableCents);
    $canonicalRequest->bind_param(
        'sissssdddsii',
        $supplier['name'],
        $supplierLedger,
        $purchaseNumber,
        $project['code'],
        $materialType,
        $canonicalVatPolicy,
        $canonicalVatAmount,
        $canonicalWhtAmount,
        $canonicalPayableAmount,
        $accountRemarks,
        $actorId,
        $supplierRequestId
    );
    $canonicalRequest->execute();
    $canonicalRequest->close();

    $update->bind_param(
        'ssssississssssssssssssssii',
        $poNumber,
        $purchaseNumber,
        $normalized,
        $materialType,
        $projectId,
        $project['code'],
        $project['name'],
        $supplierId,
        $supplier['name'],
        $supplierLedger,
        $whtStatus,
        $whtRate,
        $whtAmount,
        $invoiceNumber,
        $invoiceDate,
        $purchaseDate,
        $subtotal,
        $discount,
        $otherCharges,
        $vatStatus,
        $vatRate,
        $vatAmount,
        $purchaseValue,
        $accountRemarks,
        $actorId,
        $purchaseId
    );
    $update->execute();
    $update->close();

    if ($hasRequestWhtOverride) {
        $overrideStatus = procurementLocalFinalWhtBasisPoints($requestWhtOverrideStatus) > 0
            ? $requestWhtOverrideStatus
            : '0.00%';
        $overrideRate = procurementLocalFinalPercentFromBasisPoints($effectiveWhtBasisPoints);
        $overrideAmount = procurementLocalFinalCents($whtCents);
        $overrideRequest = $conn->prepare(
            'UPDATE supplier_fund_request_table
             SET wht_override_status = ?, wht_override_rate = ?, wht_override_amount = ?
             WHERE id = ?'
        );
        $overrideRequest->bind_param(
            'sssi',
            $overrideStatus,
            $overrideRate,
            $overrideAmount,
            $supplierRequestId
        );
        $overrideRequest->execute();
        $overrideRequest->close();

        $overridePurchase = $conn->prepare(
            "UPDATE procurement_requests\n             SET account_wht_override_status = ?, account_wht_override_rate = ?,\n                 account_wht_override_amount = ?, account_payable_amount = ?\n             WHERE legacy_source_id = ? AND deleted_at IS NULL\n               AND request_type = 'local_final_purchase'\n               AND legacy_source_table = 'procurement_local_final_purchases'"
        );
        $overridePurchase->bind_param(
            'ssssi',
            $overrideStatus,
            $overrideRate,
            $overrideAmount,
            $canonicalPayableAmount,
            $purchaseId
        );
        $overridePurchase->execute();
        $overridePurchase->close();
    } elseif ($vatCents <= 0 && $requestWhtOverrideStatus !== '') {
        $clearRequestOverride = $conn->prepare(
            'UPDATE supplier_fund_request_table
             SET wht_override_status = NULL, wht_override_rate = NULL,
                 wht_override_amount = NULL, wht_override_reason = NULL,
                 wht_override_by = NULL, wht_override_at = NULL
             WHERE id = ?'
        );
        $clearRequestOverride->bind_param('i', $supplierRequestId);
        $clearRequestOverride->execute();
        $clearRequestOverride->close();

        $clearPurchaseOverride = $conn->prepare(
            "UPDATE procurement_requests\n             SET account_wht_override_status = NULL, account_wht_override_rate = NULL,\n                 account_wht_override_amount = NULL, account_payable_amount = ?,\n                 account_wht_adjustment_reason = NULL, account_wht_adjusted_by = NULL,\n                 account_wht_adjusted_at = NULL\n             WHERE legacy_source_id = ? AND deleted_at IS NULL\n               AND request_type = 'local_final_purchase'\n               AND legacy_source_table = 'procurement_local_final_purchases'"
        );
        $clearPurchaseOverride->bind_param('si', $canonicalPayableAmount, $purchaseId);
        $clearPurchaseOverride->execute();
        $clearPurchaseOverride->close();
    } else {
        $payableUpdate = $conn->prepare(
            "UPDATE procurement_requests\n             SET account_payable_amount = ? WHERE legacy_source_id = ? AND deleted_at IS NULL\n               AND request_type = 'local_final_purchase'\n               AND legacy_source_table = 'procurement_local_final_purchases'"
        );
        $payableUpdate->bind_param('si', $canonicalPayableAmount, $purchaseId);
        $payableUpdate->execute();
        $payableUpdate->close();
    }

    procurementLocalFinalRecordEvent(
        $conn,
        $purchaseId,
        'account_updated_sent_purchase',
        ['id' => $actorId, 'email' => (string) ($actor['email'] ?? 'account-user')],
        [
            'supplier_fund_request_id' => $supplierRequestId,
            'purchase_number' => $purchaseNumber,
            'supplier_name' => $supplier['name'],
            'project_code' => $project['code'],
            'material_type' => $materialType,
            'purchase_value' => $purchaseValue,
            'account_payable_amount' => number_format((float) $row['amount'], 2, '.', ''),
        ]
    );

    procurementSyncLocalFinalPurchasePaymentDetails(
        $conn,
        [$supplierRequestId],
        $actorId,
        'account_updated_sent_purchase_payment_details'
    );

    return procurementLocalFinalFetchRecord($conn, $purchaseId) ?? [];
}

function procurementAssertSupplierFundRequestsCanBeDeleted(mysqli $conn, array $supplierRequestIds): void
{
    procurementRequestCanonicalLocalFinalAssertReady($conn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $supplierRequestIds))));
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $relation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT supplier_fund_request_id
         FROM {$relation} source
         WHERE supplier_fund_request_id IN ($placeholders)
           AND (account_request_type IS NULL OR account_request_type = 'supplier_fund_request')
           AND approval_status = 'Approved' AND deleted_at IS NULL"
    );
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows !== []) {
        $linked = array_map(static fn(array $row): int => (int) $row['supplier_fund_request_id'], $rows);
        throw new RuntimeException(
            'Linked ProcureDesk Supplier Fund Requests cannot be deleted in Account. Reverse the approval in ProcureDesk while they are still Pending.',
            409
        );
    }
}

function procurementAssertCompassFundRequestsCanBeDeleted(mysqli $conn, array $compassRequestIds): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $compassRequestIds))));
    if ($ids === []) {
        return;
    }

    foreach ($ids as $compassRequestId) {
        if (procurementCompassLinkedProcurementRequest($conn, $compassRequestId) !== null) {
            throw new RuntimeException(
                'Linked ProcureDesk Compass Fund Requests cannot be deleted in Account. Return them to ProcureDesk while they are still Pending.',
                409
            );
        }
    }
}

function procurementAssertCompassFundRequestCanBeEdited(mysqli $conn, int $compassRequestId): void
{
    if ($compassRequestId <= 0) {
        return;
    }

    $linked = procurementCompassLinkedProcurementRequest($conn, $compassRequestId);
    if ($linked !== null) {
        throw new RuntimeException(
            'This Compass Fund Request is linked to ProcureDesk. Return the originating purchase from Account before editing it.',
            409
        );
    }
}

function procurementLocalFinalActorHasPermission(array $actor, string $permission): bool
{
    return (string) ($actor['role'] ?? '') === 'super_admin'
        || in_array($permission, $actor['permissions'] ?? [], true);
}

function procurementLocalFinalCommercialSnapshot(array $purchase): array
{
    $fields = [
        'id', 'po_number', 'purchase_number', 'grn_ref', 'material_type',
        'project_id', 'project_code', 'project_name',
        'supplier_id', 'supplier_name', 'supplier_ledger',
        'wht_status', 'wht_rate', 'wht_amount',
        'invoice_number', 'invoice_date', 'purchase_date',
        'purchase_subtotal', 'purchase_discount', 'purchase_other_charges',
        'purchase_vat_status', 'purchase_vat_rate', 'purchase_vat_amount', 'purchase_value',
        'po_subtotal', 'po_discount', 'po_other_charges',
        'po_vat_status', 'po_vat_rate', 'po_vat_amount', 'po_value',
        'remark', 'po_status', 'payment_status', 'approval_status',
        'handoff_revision', 'account_request_type', 'supplier_fund_request_id',
    ];
    $snapshot = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $purchase)) {
            $snapshot[$field] = $purchase[$field];
        }
    }
    return $snapshot;
}

function procurementLocalFinalPaidAmountForSupplier(
    mysqli $conn,
    int $purchaseId,
    int $supplierId,
    string $supplierLedger
): string {
    $totalCents = 0;
    $ledgerId = (int) $supplierLedger;
    $isCompass = function_exists('procurementCompassIsSupplier')
        && procurementCompassIsSupplier($supplierId, '');

    if ($isCompass && $supplierId > 0 && procurementLocalFinalTableExists($conn, 'compass_fund_request_table')) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(CASE WHEN amount_paid > 0 THEN amount_paid ELSE amount END), 0) AS paid
             FROM compass_fund_request_table
             WHERE procurement_source = 'local_final_purchase'
               AND procurement_purchase_id = ?
               AND supplier_id = ?
               AND payment_status = 'Paid'"
        );
        $stmt->bind_param('ii', $purchaseId, $supplierId);
        $stmt->execute();
        $amount = (string) ($stmt->get_result()->fetch_assoc()['paid'] ?? '0.00');
        $stmt->close();
        $totalCents += procurementLocalFinalMoneyToCents($amount, 'Historical Compass Payment', true);
    } elseif ($ledgerId > 0) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(CASE WHEN amount_paid > 0 THEN amount_paid ELSE amount END), 0) AS paid
             FROM supplier_fund_request_table
             WHERE procurement_source = 'local_final_purchase'
               AND procurement_purchase_id = ?
               AND supplier_id = ?
               AND payment_status = 'Paid'"
        );
        $stmt->bind_param('ii', $purchaseId, $ledgerId);
        $stmt->execute();
        $amount = (string) ($stmt->get_result()->fetch_assoc()['paid'] ?? '0.00');
        $stmt->close();
        $totalCents += procurementLocalFinalMoneyToCents($amount, 'Historical Supplier Payment', true);
    }

    return procurementLocalFinalCents($totalCents);
}

function procurementLocalFinalCreateRevisionSupplierFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    string $amount,
    string $reason
): int {
    $approvalDateRow = $conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc();
    $approvalDate = (string) ($approvalDateRow['approval_date'] ?? date('Y-m-d'));
    $invoiceMonth = date('M-Y', strtotime((string) $purchase['invoice_date']));
    $purchaseMonth = date('M-Y', strtotime((string) $purchase['purchase_date']));
    $supplierLedger = (int) $purchase['supplier_ledger'];
    $description = procurementLocalFinalSubstring(
        procurementLocalFinalValidateStatus(
            $purchase['material_type'] ?? '',
            PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES,
            'Material Type'
        ) . ' paid revision',
        255
    );
    $note = procurementLocalFinalSubstring(
        'ProcureDesk paid revision #' . $revision . ': ' . trim($reason),
        255
    );
    $paymentPercentage = '100.00%';
    $paymentStatus = 'Pending';
    $zero = '0.00';
    $vatPolicy = procurementLocalFinalLegacyVatPolicy(false, 0);

    $stmt = $conn->prepare(
        'INSERT INTO supplier_fund_request_table
            (suppliers_name, supplier_id, invoice_number, purchase_number, po_number,
             invoice_date, purchase_date, date_received, invoice_month, purchase_month,
             project_code, description, vat_policy, vat, wht, payment_percentage,
             net_value, discount, other_charges, amount, note, payment_status,
             procurement_source, procurement_purchase_id, procurement_revision)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Local Final revision payment request.', 500);
    }
    $supplierName = (string) $purchase['supplier_name'];
    $invoiceNumber = (string) $purchase['invoice_number'];
    $purchaseNumber = (string) $purchase['purchase_number'];
    $poNumber = (string) $purchase['po_number'];
    $invoiceDate = (string) $purchase['invoice_date'];
    $purchaseDate = (string) $purchase['purchase_date'];
    $projectCode = (string) $purchase['project_code'];
    $source = 'local_final_purchase';
    $purchaseId = (int) $purchase['id'];
    $stmt->bind_param(
        'sisssssssssssssssssssssii',
        $supplierName,
        $supplierLedger,
        $invoiceNumber,
        $purchaseNumber,
        $poNumber,
        $invoiceDate,
        $purchaseDate,
        $approvalDate,
        $invoiceMonth,
        $purchaseMonth,
        $projectCode,
        $description,
        $vatPolicy,
        $zero,
        $zero,
        $paymentPercentage,
        $amount,
        $zero,
        $zero,
        $amount,
        $note,
        $paymentStatus,
        $source,
        $purchaseId,
        $revision
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function procurementLocalFinalCreateRevisionCompassFundRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    string $amount,
    string $reason
): int {
    procurementCompassAssertStorageReady($conn);
    if (!procurementCompassIsSupplier($purchase['supplier_id'] ?? null, $purchase['supplier_name'] ?? null)) {
        throw new RuntimeException('Compass routing is not valid for the revised supplier.', 409);
    }

    $approvalDateRow = $conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc();
    $approvalDate = (string) ($approvalDateRow['approval_date'] ?? date('Y-m-d'));
    $description = procurementLocalFinalSubstring(
        procurementLocalFinalValidateStatus(
            $purchase['material_type'] ?? '',
            PROCUREMENT_LOCAL_FINAL_MATERIAL_TYPES,
            'Material Type'
        ) . ' paid revision',
        255
    );
    $note = procurementLocalFinalSubstring(
        'ProcureDesk paid revision #' . $revision . ': ' . trim($reason),
        255
    );
    $zero = '0.00';
    $classification = 'Procurement';
    $percentage = '100';
    $paymentStatus = 'Pending';
    $vatPolicy = procurementLocalFinalLegacyVatPolicy(false, 0);
    $vatStatus = 'No';
    $source = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;
    $requestType = PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL;
    $purchaseId = (int) $purchase['id'];
    $supplierId = (int) $purchase['supplier_id'];
    $supplierName = (string) $purchase['supplier_name'];
    $invoiceNumber = (string) $purchase['invoice_number'];
    $purchaseNumber = (string) $purchase['purchase_number'];
    $poNumber = (string) $purchase['po_number'];
    $invoiceDate = (string) $purchase['invoice_date'];
    $purchaseDate = (string) $purchase['purchase_date'];
    $projectCode = (string) $purchase['project_code'];

    $stmt = $conn->prepare(
        'INSERT INTO compass_fund_request_table
            (suppliers_name, supplier_id, invoice_number, purchase_number, po_number,
             invoice_date, purchase_date, date_received, project_code, description,
             classification, percentage, net_value, vat_policy, vat, vat_rate, vat_status,
             wht, wht_status, wht_rate, discount, other_charges, amount, note, payment_status,
             procurement_source, procurement_purchase_id, procurement_request_type, procurement_revision)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Compass revision payment request.', 500);
    }
    $stmt->bind_param(
        'sissssssssssssssssssssssssisi',
        $supplierName,
        $supplierId,
        $invoiceNumber,
        $purchaseNumber,
        $poNumber,
        $invoiceDate,
        $purchaseDate,
        $approvalDate,
        $projectCode,
        $description,
        $classification,
        $percentage,
        $amount,
        $vatPolicy,
        $zero,
        $zero,
        $vatStatus,
        $zero,
        $zero,
        $zero,
        $zero,
        $zero,
        $amount,
        $note,
        $paymentStatus,
        $source,
        $purchaseId,
        $requestType,
        $revision
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function procurementLocalFinalCreateRevisionAccountRequest(
    mysqli $conn,
    array $purchase,
    int $revision,
    string $amount,
    string $reason
): array {
    $accountRequestType = procurementCompassResolveLocalAccountRequestType(
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        $purchase['supplier_id'] ?? null,
        $purchase['supplier_name'] ?? null
    );
    $id = $accountRequestType === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS
        ? procurementLocalFinalCreateRevisionCompassFundRequest($conn, $purchase, $revision, $amount, $reason)
        : procurementLocalFinalCreateRevisionSupplierFundRequest($conn, $purchase, $revision, $amount, $reason);

    return ['type' => $accountRequestType, 'id' => $id];
}

function procurementLocalFinalNextPaidRevisionNumber(mysqli $conn, int $purchaseId): int
{
    $stmt = $conn->prepare(
        'SELECT revision_number FROM procurement_local_final_paid_revisions
         WHERE purchase_id = ? ORDER BY revision_number DESC LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int) ($row['revision_number'] ?? 0)) + 1;
}

function procurementLocalFinalApplyPaidRevision(
    mysqli $conn,
    int $id,
    array $payload,
    array $actor,
    string $reason,
    ?int $expectedVersion = null
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A paid revision reason is required.', 400);
    }
    if (!procurementLocalFinalActorHasPermission($actor, 'payments.local_final.amend_paid_purchase')) {
        throw new RuntimeException('You are not permitted to revise paid Local Final Purchases.', 403);
    }
    if ((string) ($payload['po_status'] ?? '') === 'Cancelled') {
        throw new RuntimeException('Use the paid cancellation workflow to cancel a paid PO.', 409);
    }

    procurementLocalFinalEnsurePaidRevisionStorage($conn);
    procurementSupplierAdjustmentEnsureStorage($conn);

    $conn->begin_transaction();
    try {
        $existing = procurementLocalFinalFetchRecord($conn, $id, true);
        if (!$existing) {
            throw new RuntimeException('Local Final Purchase not found.', 404);
        }
        if ((string) ($existing['approval_status'] ?? '') !== 'Approved'
            || (string) ($existing['payment_status'] ?? '') !== 'Paid') {
            throw new RuntimeException('Only approved, paid Local Final Purchases can use paid revision.', 409);
        }
        if ((string) ($existing['po_status'] ?? '') === 'Cancelled') {
            throw new RuntimeException('A cancelled Local Final Purchase cannot be revised.', 409);
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) ($existing['version'] ?? 0)) {
            throw new RuntimeException('This record was updated by another user. Refresh and try again.', 409);
        }
        procurementLocalFinalAssertPurchaseNumberAvailable(
            $conn,
            (string) $payload['purchase_number_normalized'],
            $id
        );

        $oldSupplierId = (int) ($existing['supplier_id'] ?? 0);
        $oldSupplierLedger = trim((string) ($existing['supplier_ledger'] ?? ''));
        $newSupplierId = (int) ($payload['supplier_id'] ?? 0);
        $newSupplierLedger = trim((string) ($payload['supplier_ledger'] ?? ''));
        $supplierChanged = $oldSupplierId !== $newSupplierId
            || $oldSupplierLedger !== $newSupplierLedger;

        $paidCents = procurementLocalFinalMoneyToCents(
            procurementLocalFinalPaidAmountForSupplier($conn, $id, $oldSupplierId, $oldSupplierLedger),
            'Paid Amount',
            true
        );
        if ($paidCents <= 0) {
            $paidCents = procurementLocalFinalMoneyToCents(
                $existing['amount_paid'] ?? '0.00',
                'Paid Amount',
                true
            );
        }
        if ($paidCents <= 0) {
            throw new RuntimeException('The historical paid amount could not be verified. Paid revision was blocked.', 409);
        }

        $previousPayableCents = procurementLocalFinalExpectedSupplierAmountCents($existing);
        $revisedPayableCents = procurementLocalFinalExpectedSupplierAmountCents($payload);
        if ($supplierChanged) {
            $recoverableCents = $paidCents;
            $additionalPayableCents = $revisedPayableCents;
        } else {
            $recoverableCents = max($paidCents - $revisedPayableCents, 0);
            $additionalPayableCents = max($revisedPayableCents - $paidCents, 0);
        }

        $revisionNumber = procurementLocalFinalNextPaidRevisionNumber($conn, $id);
        $originSnapshot = procurementLocalFinalCommercialSnapshot($existing);
        $revisedSnapshot = procurementLocalFinalCommercialSnapshot(array_merge($existing, $payload));
        $originJson = (string) json_encode($originSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $revisedJson = (string) json_encode($revisedSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $previousAccountType = trim((string) ($existing['account_request_type'] ?? '')) ?: null;
        $previousAccountId = (int) ($existing['supplier_fund_request_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);

        if ($previousAccountId > 0 && $previousAccountType !== null) {
            $previousAccountRequest = procurementLocalFinalFetchAccountRequestForUpdate(
                $conn,
                $previousAccountType,
                $previousAccountId
            );
            if ($previousAccountRequest) {
                procurementLocalFinalArchiveHandoff(
                    $conn,
                    $id,
                    max(1, (int) ($existing['handoff_revision'] ?? 1)),
                    $previousAccountType,
                    $previousAccountId,
                    $previousAccountRequest,
                    $actor,
                    'Revised',
                    'procurement',
                    $reason
                );
            }
        }

        $insertRevision = $conn->prepare(
            'INSERT INTO procurement_local_final_paid_revisions
                (purchase_id, revision_number, reason, supplier_changed,
                 previous_supplier_id, previous_supplier_name, previous_supplier_ledger,
                 revised_supplier_id, revised_supplier_name, revised_supplier_ledger,
                 previous_payable_amount, amount_paid_at_revision, revised_payable_amount,
                 recoverable_amount, additional_payable_amount,
                 previous_account_request_type, previous_account_request_id,
                 origin_snapshot_json, revised_snapshot_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?)'
        );
        $supplierChangedInt = $supplierChanged ? 1 : 0;
        $oldSupplierName = (string) ($existing['supplier_name'] ?? '');
        $newSupplierName = (string) ($payload['supplier_name'] ?? '');
        $previousPayable = procurementLocalFinalCents($previousPayableCents);
        $paidAmount = procurementLocalFinalCents($paidCents);
        $revisedPayable = procurementLocalFinalCents($revisedPayableCents);
        $recoverableAmount = procurementLocalFinalCents($recoverableCents);
        $additionalPayableAmount = procurementLocalFinalCents($additionalPayableCents);
        $insertRevision->bind_param(
            'iisiississssssssissi',
            $id,
            $revisionNumber,
            $reason,
            $supplierChangedInt,
            $oldSupplierId,
            $oldSupplierName,
            $oldSupplierLedger,
            $newSupplierId,
            $newSupplierName,
            $newSupplierLedger,
            $previousPayable,
            $paidAmount,
            $revisedPayable,
            $recoverableAmount,
            $additionalPayableAmount,
            $previousAccountType,
            $previousAccountId,
            $originJson,
            $revisedJson,
            $actorId
        );
        $insertRevision->execute();
        $paidRevisionId = (int) $insertRevision->insert_id;
        $insertRevision->close();

        $revisionPurchase = array_merge($existing, $payload, ['id' => $id]);
        $newAccountType = null;
        $newAccountId = 0;
        $nextHandoffRevision = max(0, (int) ($existing['handoff_revision'] ?? 0));
        if ($additionalPayableCents > 0) {
            $nextHandoffRevision++;
            $newAccount = procurementLocalFinalCreateRevisionAccountRequest(
                $conn,
                $revisionPurchase,
                $nextHandoffRevision,
                $additionalPayableAmount,
                $reason
            );
            $newAccountType = (string) $newAccount['type'];
            $newAccountId = (int) $newAccount['id'];
            procurementLocalFinalCreateHandoff(
                $conn,
                $id,
                $nextHandoffRevision,
                $newAccountType,
                $newAccountId,
                $actorId
            );
        }

        if ($recoverableCents > 0) {
            procurementSupplierAdjustmentCreate($conn, [
                'source_type' => 'local_final_purchase',
                'source_purchase_id' => $id,
                'source_revision_id' => $paidRevisionId,
                'source_revision_number' => $revisionNumber,
                'adjustment_kind' => $supplierChanged ? 'Supplier Change' : 'Value Decrease',
                'adjustment_direction' => 'Recoverable',
                'supplier_id' => $oldSupplierId,
                'supplier_name' => $oldSupplierName,
                'supplier_ledger' => $oldSupplierLedger,
                'currency' => 'NGN',
                'amount' => $recoverableAmount,
                'reason' => $reason,
                'origin_snapshot' => $originSnapshot,
                'revised_snapshot' => $revisedSnapshot,
            ], $actorId);
        }
        if ($additionalPayableCents > 0) {
            procurementSupplierAdjustmentCreate($conn, [
                'source_type' => 'local_final_purchase',
                'source_purchase_id' => $id,
                'source_revision_id' => $paidRevisionId,
                'source_revision_number' => $revisionNumber,
                'adjustment_kind' => $supplierChanged ? 'Supplier Change' : 'Value Increase',
                'adjustment_direction' => 'Payable',
                'supplier_id' => $newSupplierId,
                'supplier_name' => $newSupplierName,
                'supplier_ledger' => $newSupplierLedger,
                'currency' => 'NGN',
                'amount' => $additionalPayableAmount,
                'reason' => $reason,
                'origin_snapshot' => $originSnapshot,
                'revised_snapshot' => $revisedSnapshot,
            ], $actorId);
        }

        $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
        $nextPaymentStatus = $newAccountId > 0 ? 'Pending' : 'Paid';
        $nextHandoffStatus = $newAccountId > 0 ? 'In Account' : 'Not Sent';
        $accountPayableAmount = $newAccountId > 0 ? $additionalPayableAmount : $revisedPayable;
        $accountAmountPaid = $newAccountId > 0 ? '0.00' : $paidAmount;
        $previousAccountIdForUpdate = $previousAccountId > 0 ? $previousAccountId : null;
        $newAccountIdForUpdate = $newAccountId > 0 ? $newAccountId : null;
        $update = $conn->prepare(
            "UPDATE procurement_requests SET
                po_number = ?, purchase_number = ?, purchase_number_normalized = ?, grn_ref = ?, material_type = ?,
                project_id = ?, project_code = ?, project_name = ?, supplier_id = ?, supplier_name = ?,
                supplier_ledger = ?, wht_status = ?, wht_rate = ?, wht_amount = ?, invoice_number = ?,
                invoice_date = ?, purchase_date = ?, purchase_subtotal = ?, purchase_discount = ?,
                purchase_other_charges = ?, purchase_vat_status = ?, purchase_vat_rate = ?,
                purchase_vat_amount = ?, purchase_value = ?, po_subtotal = ?, po_discount = ?,
                po_other_charges = ?, po_vat_status = ?, po_vat_rate = ?, po_vat_amount = ?, po_value = ?,
                remark = ?, po_status = ?,
                account_wht_override_status = NULL, account_wht_override_rate = NULL,
                account_wht_override_amount = NULL, account_wht_adjustment_reason = NULL,
                account_wht_adjusted_by = NULL, account_wht_adjusted_at = NULL,
                account_payable_amount = ?, account_amount_paid = ?,
                account_request_type = ?, account_request_id = ?, previous_account_request_id = ?,
                handoff_status = ?, handoff_revision = ?,
                payment_status = ?, payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                account_processing_method = NULL, account_processing_reference = NULL,
                account_processing_started_at = NULL, account_expected_completion_at = NULL,
                account_completion_mode = NULL, account_confirmation_status = NULL,
                account_payment_reference = NULL, account_paid_at = NULL,
                account_payment_remarks = NULL, account_payment_batch_id = NULL,
                updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$canonicalScope} AND deleted_at IS NULL"
        );
        $params = [
            $payload['po_number'], $payload['purchase_number'], $payload['purchase_number_normalized'], $payload['grn_ref'], $payload['material_type'],
            $payload['project_id'], $payload['project_code'], $payload['project_name'], $payload['supplier_id'], $payload['supplier_name'],
            $payload['supplier_ledger'], $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'], $payload['invoice_number'],
            $payload['invoice_date'], $payload['purchase_date'], $payload['purchase_subtotal'], $payload['purchase_discount'],
            $payload['purchase_other_charges'], $payload['purchase_vat_status'], $payload['purchase_vat_rate'],
            $payload['purchase_vat_amount'], $payload['purchase_value'], $payload['po_subtotal'], $payload['po_discount'],
            $payload['po_other_charges'], $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'], $payload['po_value'],
            $payload['remark'], $payload['po_status'],
            $accountPayableAmount, $accountAmountPaid,
            $newAccountType, $newAccountIdForUpdate, $previousAccountIdForUpdate,
            $nextHandoffStatus, $nextHandoffRevision, $nextPaymentStatus,
            $actorId, $id,
        ];
        $types = 'sssssississsssssssssssssssssssssssssiisisii';
        $update->bind_param($types, ...$params);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('The paid Local Final Purchase changed before revision completed.', 409);
        }
        $update->close();

        if ($newAccountId > 0) {
            $revisionUpdate = $conn->prepare(
                'UPDATE procurement_local_final_paid_revisions
                 SET new_account_request_type = ?, new_account_request_id = ? WHERE id = ?'
            );
            $revisionUpdate->bind_param('sii', $newAccountType, $newAccountId, $paidRevisionId);
            $revisionUpdate->execute();
            $revisionUpdate->close();
        }

        procurementLocalFinalRecordEvent($conn, $id, 'paid_purchase_revised', $actor, [
            'paid_revision_id' => $paidRevisionId,
            'revision_number' => $revisionNumber,
            'reason' => $reason,
            'supplier_changed' => $supplierChanged,
            'previous_supplier_id' => $oldSupplierId,
            'revised_supplier_id' => $newSupplierId,
            'amount_paid_at_revision' => $paidAmount,
            'previous_payable_amount' => $previousPayable,
            'revised_payable_amount' => $revisedPayable,
            'recoverable_amount' => $recoverableAmount,
            'additional_payable_amount' => $additionalPayableAmount,
            'new_account_request_type' => $newAccountType,
            'new_account_request_id' => $newAccountId > 0 ? $newAccountId : null,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []);
}

function procurementLocalFinalCancelOne(
    mysqli $conn,
    int $id,
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
        $record = procurementLocalFinalFetchRecord($conn, $id, true);
        if (!$record) {
            throw new RuntimeException('Local Final Purchase not found.', 404);
        }
        if ((string) ($record['po_status'] ?? '') === 'Cancelled') {
            $conn->commit();
            return procurementLocalFinalSerializeRecord($record);
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $accountRequestType = trim((string) ($record['account_request_type'] ?? ''))
            ?: PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER;
        $accountRequestId = (int) ($record['supplier_fund_request_id'] ?? 0);
        $accountRequest = null;
        $actualPaymentStatus = (string) ($record['payment_status'] ?? 'Pending');
        if ($accountRequestId > 0) {
            $accountRequest = procurementLocalFinalFetchAccountRequestForUpdate(
                $conn,
                $accountRequestType,
                $accountRequestId
            );
            if ($accountRequest) {
                $actualPaymentStatus = (string) ($accountRequest['payment_status'] ?? $actualPaymentStatus);
            }
        }
        if ($actualPaymentStatus === 'Processing') {
            throw new RuntimeException(
                'Complete or cancel the current Account processing payment before cancelling this PO.',
                409
            );
        }

        $previousAccountId = null;
        if ($actualPaymentStatus === 'Pending' && $accountRequestId > 0 && $accountRequest) {
            $revision = max(1, (int) ($record['handoff_revision'] ?? 1));
            procurementLocalFinalArchiveHandoff(
                $conn,
                $id,
                $revision,
                $accountRequestType,
                $accountRequestId,
                $accountRequest,
                $actor,
                'Cancelled',
                'procurement',
                $reason
            );
            procurementLocalFinalDeletePendingAccountRequest($conn, $accountRequestType, $accountRequestId);
            $previousAccountId = $accountRequestId;
            $accountRequestId = 0;
        }

        procurementSupplierAdjustmentCancelOpenPayablesForSource(
            $conn,
            'local_final_purchase',
            $id,
            $actorId,
            $reason
        );

        $supplierId = (int) ($record['supplier_id'] ?? 0);
        $supplierLedger = trim((string) ($record['supplier_ledger'] ?? ''));
        $paidCents = procurementLocalFinalMoneyToCents(
            procurementLocalFinalPaidAmountForSupplier($conn, $id, $supplierId, $supplierLedger),
            'Paid Amount',
            true
        );
        if ($paidCents <= 0 && $actualPaymentStatus === 'Paid') {
            $paidCents = procurementLocalFinalMoneyToCents(
                $record['amount_paid'] ?? $record['account_amount_paid'] ?? '0.00',
                'Paid Amount',
                true
            );
        }

        $newRecoveryCents = 0;
        if ($paidCents > 0 && $supplierId > 0) {
            $recordedRecoveryCents = procurementLocalFinalMoneyToCents(
                procurementSupplierAdjustmentRecoverableRecordedForSourceSupplier(
                    $conn,
                    'local_final_purchase',
                    $id,
                    $supplierId,
                    'NGN'
                ),
                'Existing Recovery',
                true
            );
            $newRecoveryCents = max(0, $paidCents - $recordedRecoveryCents);
            if ($newRecoveryCents > 0) {
                $originSnapshot = procurementLocalFinalCommercialSnapshot($record);
                $revisedSnapshot = $originSnapshot;
                $revisedSnapshot['po_status'] = 'Cancelled';
                procurementSupplierAdjustmentCreate($conn, [
                    'source_type' => 'local_final_purchase',
                    'source_purchase_id' => $id,
                    'source_revision_number' => max(1, (int) ($record['handoff_revision'] ?? 1)),
                    'adjustment_kind' => 'Cancellation',
                    'adjustment_direction' => 'Recoverable',
                    'supplier_id' => $supplierId,
                    'supplier_name' => (string) ($record['supplier_name'] ?? ''),
                    'supplier_ledger' => $supplierLedger,
                    'currency' => 'NGN',
                    'amount' => procurementLocalFinalCents($newRecoveryCents),
                    'reason' => $reason,
                    'origin_snapshot' => $originSnapshot,
                    'revised_snapshot' => $revisedSnapshot,
                ], $actorId);
            }
        }

        $hasPaidHistory = $paidCents > 0 || $actualPaymentStatus === 'Paid';
        $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
        if ($hasPaidHistory) {
            $paidAmount = procurementLocalFinalCents($paidCents);
            $update = $conn->prepare(
                "UPDATE procurement_requests
                 SET po_status = 'Cancelled',
                     payment_status = 'Paid', payment_status_source = 'procuredesk',
                     payment_status_updated_at = NOW(), account_amount_paid = ?,
                     updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$canonicalScope} AND deleted_at IS NULL"
            );
            $update->bind_param('sii', $paidAmount, $actorId, $id);
        } else {
            $previousAccountIdValue = (int) ($previousAccountId ?? 0);
            $update = $conn->prepare(
                "UPDATE procurement_requests
                 SET po_status = 'Cancelled', payment_status = 'Cancelled',
                     payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                     handoff_status = 'Not Sent', account_request_id = NULL,
                     previous_account_request_id = COALESCE(NULLIF(?, 0), previous_account_request_id),
                     updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$canonicalScope} AND deleted_at IS NULL"
            );
            $update->bind_param('iii', $previousAccountIdValue, $actorId, $id);
        }
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('The Local Final Purchase changed before cancellation completed.', 409);
        }
        $update->close();

        procurementLocalFinalRecordEvent($conn, $id, 'po_cancelled', $actor, [
            'reason' => $reason,
            'payment_history_preserved' => $hasPaidHistory,
            'paid_amount' => procurementLocalFinalCents($paidCents),
            'new_recoverable_amount' => procurementLocalFinalCents($newRecoveryCents),
            'removed_pending_account_request_id' => $previousAccountId,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []);
}
