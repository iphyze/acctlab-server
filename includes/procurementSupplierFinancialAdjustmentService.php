<?php

declare(strict_types=1);

const PROCUREMENT_SUPPLIER_ADJUSTMENT_SOURCE_TYPES = [
    'local_final_purchase',
    'local_advance_purchase',
    'fx_final_purchase',
    'fx_advance_purchase',
];
const PROCUREMENT_SUPPLIER_ADJUSTMENT_DIRECTIONS = ['Recoverable', 'Payable'];
const PROCUREMENT_SUPPLIER_ADJUSTMENT_STATUSES = ['Open', 'Credit Available', 'Partially Settled', 'Settled', 'Cancelled'];
const PROCUREMENT_SUPPLIER_ADJUSTMENT_KINDS = [
    'Supplier Change',
    'Value Decrease',
    'Value Increase',
    'Cancellation',
    'Other',
];

function procurementSupplierAdjustmentTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function procurementSupplierAdjustmentEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS procurement_supplier_financial_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            idempotency_key VARCHAR(190) NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            source_purchase_id BIGINT UNSIGNED NOT NULL,
            source_po_id BIGINT UNSIGNED NULL,
            source_revision_id BIGINT UNSIGNED NULL,
            source_revision_number INT UNSIGNED NULL,
            adjustment_kind VARCHAR(40) NOT NULL,
            adjustment_direction VARCHAR(20) NOT NULL,
            supplier_id INT NOT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            supplier_ledger VARCHAR(120) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            amount DECIMAL(18,2) NOT NULL,
            outstanding_amount DECIMAL(18,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Open',
            reason TEXT NOT NULL,
            origin_snapshot_json LONGTEXT NULL,
            revised_snapshot_json LONGTEXT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            settled_at DATETIME NULL,
            UNIQUE KEY uq_procurement_supplier_adjustment_idempotency (idempotency_key),
            INDEX idx_procurement_supplier_adjustment_supplier (supplier_id, currency, status),
            INDEX idx_procurement_supplier_adjustment_source (source_type, source_purchase_id),
            INDEX idx_procurement_supplier_adjustment_po (source_po_id, source_revision_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_supplier_financial_adjustment_credit_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            adjustment_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(190) NOT NULL,
            issued_date DATE NULL,
            amount DECIMAL(18,2) NOT NULL,
            available_amount DECIMAL(18,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Available',
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_by INT NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason TEXT NULL,
            UNIQUE KEY uq_procurement_supplier_credit_note_reference (adjustment_id, reference),
            INDEX idx_procurement_supplier_credit_note_adjustment (adjustment_id, status),
            CONSTRAINT fk_procurement_supplier_credit_note_adjustment
                FOREIGN KEY (adjustment_id)
                REFERENCES procurement_supplier_financial_adjustments(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_supplier_financial_adjustment_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            adjustment_id BIGINT UNSIGNED NOT NULL,
            allocation_type VARCHAR(30) NOT NULL,
            target_source_type VARCHAR(40) NULL,
            target_purchase_id BIGINT UNSIGNED NULL,
            target_account_request_type VARCHAR(40) NULL,
            target_account_request_id BIGINT UNSIGNED NULL,
            amount DECIMAL(18,2) NOT NULL,
            reference VARCHAR(190) NULL,
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_procurement_supplier_adjustment_allocation (adjustment_id, created_at),
            INDEX idx_procurement_supplier_adjustment_target (target_source_type, target_purchase_id),
            CONSTRAINT fk_procurement_supplier_adjustment_allocation
                FOREIGN KEY (adjustment_id)
                REFERENCES procurement_supplier_financial_adjustments(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_supplier_credit_reservations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            credit_note_id BIGINT UNSIGNED NULL,
            adjustment_id BIGINT UNSIGNED NOT NULL,
            supplier_id INT NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            request_type VARCHAR(40) NOT NULL,
            request_id BIGINT UNSIGNED NOT NULL,
            payment_batch_id BIGINT UNSIGNED NULL,
            amount DECIMAL(18,2) NOT NULL,
            offset_basis VARCHAR(30) NOT NULL DEFAULT 'Credit Note',
            agreement_reference VARCHAR(190) NULL,
            notes TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Reserved',
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by INT NULL,
            applied_at DATETIME NULL,
            released_by INT NULL,
            released_at DATETIME NULL,
            release_reason VARCHAR(500) NULL,
            INDEX idx_supplier_credit_reservation_request (request_type, request_id, status),
            INDEX idx_supplier_credit_reservation_note (credit_note_id, status),
            INDEX idx_supplier_credit_reservation_batch (payment_batch_id, status),
            CONSTRAINT fk_supplier_credit_reservation_note
                FOREIGN KEY (credit_note_id)
                REFERENCES procurement_supplier_financial_adjustment_credit_notes(id)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_supplier_credit_reservation_adjustment
                FOREIGN KEY (adjustment_id)
                REFERENCES procurement_supplier_financial_adjustments(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        if (!$conn->query($query)) {
            throw new RuntimeException('Unable to prepare supplier financial adjustment storage.', 500);
        }
    }

    $ensured = true;
}

function procurementSupplierAdjustmentValidateStatus(mixed $value, array $allowed, string $label): string
{
    $candidate = trim((string) $value);
    if (!in_array($candidate, $allowed, true)) {
        throw new RuntimeException($label . ' is invalid.', 400);
    }
    return $candidate;
}

function procurementSupplierAdjustmentNormalizeCurrency(mixed $value): string
{
    $currency = strtoupper(trim((string) $value));
    if ($currency === '') {
        $currency = 'NGN';
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Currency must be a valid three-letter code.', 400);
    }
    return $currency;
}

function procurementSupplierAdjustmentResolveSupplierIdentity(
    mysqli $conn,
    int $supplierId,
    string $supplierName = ''
): array {
    static $identityCache = [];
    $supplierId = max(0, $supplierId);
    if ($supplierId > 0 && isset($identityCache[$supplierId])) {
        return $identityCache[$supplierId];
    }

    $identity = [
        'canonical_id' => $supplierId,
        'ledger_number' => $supplierId >= 40000000 ? $supplierId : 0,
        'supplier_name' => trim($supplierName),
    ];

    if ($supplierId <= 0 || !procurementSupplierAdjustmentTableExists($conn, 'suppliers_table')) {
        return $identity;
    }

    $stmt = $conn->prepare(
        "SELECT id, supplier_name, supplier_number
         FROM suppliers_table
         WHERE id = ? OR supplier_number = ?
         ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END
         LIMIT 1"
    );
    $stmt->bind_param('iii', $supplierId, $supplierId, $supplierId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ($row) {
        $identity['canonical_id'] = (int) ($row['id'] ?? $supplierId);
        $identity['ledger_number'] = (int) ($row['supplier_number'] ?? 0);
        $identity['supplier_name'] = trim((string) ($row['supplier_name'] ?? $supplierName));
    }

    $identityCache[$supplierId] = $identity;
    if ((int) ($identity['canonical_id'] ?? 0) > 0) {
        $identityCache[(int) $identity['canonical_id']] = $identity;
    }
    if ((int) ($identity['ledger_number'] ?? 0) > 0) {
        $identityCache[(int) $identity['ledger_number']] = $identity;
    }
    return $identity;
}

function procurementSupplierAdjustmentSameSupplier(
    mysqli $conn,
    int $leftSupplierId,
    int $rightSupplierId
): bool {
    if ($leftSupplierId <= 0 || $rightSupplierId <= 0) {
        return false;
    }
    $left = procurementSupplierAdjustmentResolveSupplierIdentity($conn, $leftSupplierId);
    $right = procurementSupplierAdjustmentResolveSupplierIdentity($conn, $rightSupplierId);
    return (int) ($left['canonical_id'] ?? 0) > 0
        && (int) ($left['canonical_id'] ?? 0) === (int) ($right['canonical_id'] ?? 0);
}

function procurementSupplierAdjustmentMoneyToCents(
    mixed $value,
    string $label = 'Adjustment Amount',
    bool $allowZero = false
): int {
    $raw = trim(str_replace([',', '₦'], '', (string) $value));
    if ($raw === '' || !preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        throw new RuntimeException($label . ' must be a valid non-negative amount.', 400);
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

function procurementSupplierAdjustmentCents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function procurementSupplierAdjustmentMoney(mixed $value, string $label = 'Adjustment Amount'): string
{
    return procurementSupplierAdjustmentCents(
        procurementSupplierAdjustmentMoneyToCents($value, $label)
    );
}

function procurementSupplierAdjustmentSnapshot(?array $snapshot): ?string
{
    if ($snapshot === null || $snapshot === []) {
        return null;
    }
    return (string) json_encode(
        $snapshot,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function procurementSupplierAdjustmentIdempotencyKey(array $data): string
{
    $parts = [
        (string) ($data['source_type'] ?? ''),
        (string) ($data['source_purchase_id'] ?? 0),
        (string) ($data['source_po_id'] ?? 0),
        (string) ($data['source_revision_id'] ?? 0),
        (string) ($data['source_revision_number'] ?? 0),
        (string) ($data['adjustment_kind'] ?? ''),
        (string) ($data['adjustment_direction'] ?? ''),
        (string) ($data['supplier_id'] ?? 0),
        procurementSupplierAdjustmentNormalizeCurrency($data['currency'] ?? 'NGN'),
    ];
    return hash('sha256', implode('|', $parts));
}

function procurementSupplierAdjustmentCreate(
    mysqli $conn,
    array $data,
    int $actorId
): array {
    procurementSupplierAdjustmentEnsureStorage($conn);

    $sourceType = procurementSupplierAdjustmentValidateStatus(
        $data['source_type'] ?? '',
        PROCUREMENT_SUPPLIER_ADJUSTMENT_SOURCE_TYPES,
        'Adjustment Source Type'
    );
    $direction = procurementSupplierAdjustmentValidateStatus(
        $data['adjustment_direction'] ?? '',
        PROCUREMENT_SUPPLIER_ADJUSTMENT_DIRECTIONS,
        'Adjustment Direction'
    );
    $kind = procurementSupplierAdjustmentValidateStatus(
        $data['adjustment_kind'] ?? '',
        PROCUREMENT_SUPPLIER_ADJUSTMENT_KINDS,
        'Adjustment Type'
    );
    $sourcePurchaseId = (int) ($data['source_purchase_id'] ?? 0);
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    if ($sourcePurchaseId <= 0 || $supplierId <= 0 || $actorId <= 0) {
        throw new RuntimeException('Adjustment source, supplier and actor are required.', 400);
    }

    $supplierName = trim((string) ($data['supplier_name'] ?? ''));
    $supplierLedger = trim((string) ($data['supplier_ledger'] ?? ''));
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($supplierName === '' || $supplierLedger === '' || $reason === '') {
        throw new RuntimeException('Supplier details and adjustment reason are required.', 400);
    }

    $currency = procurementSupplierAdjustmentNormalizeCurrency($data['currency'] ?? 'NGN');
    $amount = procurementSupplierAdjustmentMoney($data['amount'] ?? null);
    $sourcePoId = (int) ($data['source_po_id'] ?? 0);
    $sourceRevisionId = (int) ($data['source_revision_id'] ?? 0);
    $sourceRevisionNumber = (int) ($data['source_revision_number'] ?? 0);
    $originSnapshot = procurementSupplierAdjustmentSnapshot($data['origin_snapshot'] ?? null);
    $revisedSnapshot = procurementSupplierAdjustmentSnapshot($data['revised_snapshot'] ?? null);
    $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = procurementSupplierAdjustmentIdempotencyKey([
            'source_type' => $sourceType,
            'source_purchase_id' => $sourcePurchaseId,
            'source_po_id' => $sourcePoId,
            'source_revision_id' => $sourceRevisionId,
            'source_revision_number' => $sourceRevisionNumber,
            'adjustment_kind' => $kind,
            'adjustment_direction' => $direction,
            'supplier_id' => $supplierId,
            'currency' => $currency,
        ]);
    }

    $stmt = $conn->prepare(
        "INSERT INTO procurement_supplier_financial_adjustments
            (idempotency_key, source_type, source_purchase_id, source_po_id,
             source_revision_id, source_revision_number, adjustment_kind,
             adjustment_direction, supplier_id, supplier_name, supplier_ledger,
             currency, amount, outstanding_amount, status, reason,
             origin_snapshot_json, revised_snapshot_json, created_by, updated_by)
         VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $stmt->bind_param(
        'ssiiiississssssssii',
        $idempotencyKey,
        $sourceType,
        $sourcePurchaseId,
        $sourcePoId,
        $sourceRevisionId,
        $sourceRevisionNumber,
        $kind,
        $direction,
        $supplierId,
        $supplierName,
        $supplierLedger,
        $currency,
        $amount,
        $amount,
        $reason,
        $originSnapshot,
        $revisedSnapshot,
        $actorId,
        $actorId
    );
    $stmt->execute();
    $adjustmentId = (int) $conn->insert_id;
    $stmt->close();

    $fetch = $conn->prepare(
        'SELECT * FROM procurement_supplier_financial_adjustments WHERE id = ? LIMIT 1'
    );
    $fetch->bind_param('i', $adjustmentId);
    $fetch->execute();
    $record = $fetch->get_result()->fetch_assoc() ?: [];
    $fetch->close();
    return $record;
}

function procurementSupplierAdjustmentOutstandingForSupplier(
    mysqli $conn,
    int $supplierId,
    string $currency = 'NGN'
): string {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $currency = procurementSupplierAdjustmentNormalizeCurrency($currency);
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(outstanding_amount), 0) AS outstanding
         FROM procurement_supplier_financial_adjustments
         WHERE supplier_id = ? AND currency = ?
           AND adjustment_direction = 'Recoverable'
           AND status IN ('Open', 'Credit Available', 'Partially Settled')"
    );
    $stmt->bind_param('is', $supplierId, $currency);
    $stmt->execute();
    $amount = (string) ($stmt->get_result()->fetch_assoc()['outstanding'] ?? '0.00');
    $stmt->close();
    return procurementSupplierAdjustmentCents(
        procurementSupplierAdjustmentMoneyToCents($amount, 'Outstanding Adjustment', true)
    );
}

function procurementSupplierAdjustmentRecoverableRecordedForSourceSupplier(
    mysqli $conn,
    string $sourceType,
    int $sourcePurchaseId,
    int $supplierId,
    string $currency = 'NGN'
): string {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $sourceType = procurementSupplierAdjustmentValidateStatus(
        $sourceType,
        PROCUREMENT_SUPPLIER_ADJUSTMENT_SOURCE_TYPES,
        'Adjustment Source Type'
    );
    $currency = procurementSupplierAdjustmentNormalizeCurrency($currency);
    if ($sourcePurchaseId <= 0 || $supplierId <= 0) {
        return '0.00';
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS recorded
         FROM procurement_supplier_financial_adjustments
         WHERE source_type = ? AND source_purchase_id = ?
           AND supplier_id = ? AND currency = ?
           AND adjustment_direction = 'Recoverable'
           AND status <> 'Cancelled'"
    );
    $stmt->bind_param('siis', $sourceType, $sourcePurchaseId, $supplierId, $currency);
    $stmt->execute();
    $amount = (string) ($stmt->get_result()->fetch_assoc()['recorded'] ?? '0.00');
    $stmt->close();
    return procurementSupplierAdjustmentCents(
        procurementSupplierAdjustmentMoneyToCents($amount, 'Recorded Recovery', true)
    );
}

function procurementSupplierAdjustmentCancelOpenPayablesForSource(
    mysqli $conn,
    string $sourceType,
    int $sourcePurchaseId,
    int $actorId,
    string $reason
): int {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $sourceType = procurementSupplierAdjustmentValidateStatus(
        $sourceType,
        PROCUREMENT_SUPPLIER_ADJUSTMENT_SOURCE_TYPES,
        'Adjustment Source Type'
    );
    if ($sourcePurchaseId <= 0 || $actorId <= 0) {
        return 0;
    }
    $reason = trim($reason) !== '' ? trim($reason) : 'Source purchase cancelled.';

    $stmt = $conn->prepare(
        "UPDATE procurement_supplier_financial_adjustments
         SET status = 'Cancelled', outstanding_amount = 0.00,
             reason = CONCAT(reason, '\nCancelled obligation: ', ?),
             updated_by = ?, updated_at = NOW()
         WHERE source_type = ? AND source_purchase_id = ?
           AND adjustment_direction = 'Payable'
           AND status IN ('Open', 'Credit Available', 'Partially Settled')"
    );
    $stmt->bind_param('sisi', $reason, $actorId, $sourceType, $sourcePurchaseId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

function procurementSupplierAdjustmentCancelOpenPayablesForPo(
    mysqli $conn,
    string $sourceType,
    int $sourcePoId,
    int $actorId,
    string $reason
): int {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $sourceType = procurementSupplierAdjustmentValidateStatus(
        $sourceType,
        PROCUREMENT_SUPPLIER_ADJUSTMENT_SOURCE_TYPES,
        'Adjustment Source Type'
    );
    if ($sourcePoId <= 0 || $actorId <= 0) {
        return 0;
    }
    $reason = trim($reason) !== '' ? trim($reason) : 'Source PO cancelled.';

    $stmt = $conn->prepare(
        "UPDATE procurement_supplier_financial_adjustments
         SET status = 'Cancelled', outstanding_amount = 0.00,
             reason = CONCAT(reason, '\nCancelled obligation: ', ?),
             updated_by = ?, updated_at = NOW()
         WHERE source_type = ? AND source_po_id = ?
           AND adjustment_direction = 'Payable'
           AND status IN ('Open', 'Credit Available', 'Partially Settled')"
    );
    $stmt->bind_param('sisi', $reason, $actorId, $sourceType, $sourcePoId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}


function procurementSupplierAdjustmentFetchForUpdate(mysqli $conn, int $adjustmentId): array
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    if ($adjustmentId <= 0) {
        throw new RuntimeException('A valid supplier adjustment is required.', 400);
    }
    $stmt = $conn->prepare(
        'SELECT * FROM procurement_supplier_financial_adjustments WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Supplier adjustment could not be found.', 404);
    }
    if ((string) ($row['adjustment_direction'] ?? '') !== 'Recoverable') {
        throw new RuntimeException('Only supplier recoveries can be settled here.', 409);
    }
    if (in_array((string) ($row['status'] ?? ''), ['Settled', 'Cancelled'], true)) {
        throw new RuntimeException('This supplier recovery is already closed.', 409);
    }
    return $row;
}

function procurementSupplierAdjustmentAvailableCreditCents(mysqli $conn, int $adjustmentId): int
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(available_amount), 0) AS available\n         FROM procurement_supplier_financial_adjustment_credit_notes\n         WHERE adjustment_id = ? AND status IN ('Available', 'Partially Applied')"
    );
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $value = (string) ($stmt->get_result()->fetch_assoc()['available'] ?? '0.00');
    $stmt->close();
    return procurementSupplierAdjustmentMoneyToCents($value, 'Available Credit', true);
}

function procurementSupplierAdjustmentRefreshStatus(mysqli $conn, int $adjustmentId, int $actorId): array
{
    $stmt = $conn->prepare(
        'SELECT amount, outstanding_amount, status FROM procurement_supplier_financial_adjustments WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Supplier adjustment could not be found.', 404);
    }

    $amountCents = procurementSupplierAdjustmentMoneyToCents($row['amount'] ?? 0, 'Adjustment Amount', true);
    $outstandingCents = procurementSupplierAdjustmentMoneyToCents($row['outstanding_amount'] ?? 0, 'Outstanding Amount', true);
    $availableCreditCents = procurementSupplierAdjustmentAvailableCreditCents($conn, $adjustmentId);

    if ($outstandingCents <= 0) {
        $status = 'Settled';
    } elseif ($outstandingCents < $amountCents) {
        $status = 'Partially Settled';
    } elseif ($availableCreditCents > 0) {
        $status = 'Credit Available';
    } else {
        $status = 'Open';
    }

    $stmt = $conn->prepare(
        "UPDATE procurement_supplier_financial_adjustments\n         SET status = ?, updated_by = ?, updated_at = NOW(),\n             settled_at = CASE WHEN ? = 'Settled' THEN COALESCE(settled_at, NOW()) ELSE NULL END\n         WHERE id = ?"
    );
    $stmt->bind_param('sisi', $status, $actorId, $status, $adjustmentId);
    $stmt->execute();
    $stmt->close();

    return procurementSupplierAdjustmentGet($conn, $adjustmentId);
}

function procurementSupplierAdjustmentGet(mysqli $conn, int $adjustmentId): array
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    $stmt = $conn->prepare(
        "SELECT a.*,\n                COALESCE(c.credit_note_amount, 0) AS credit_note_amount,\n                COALESCE(c.credit_available_amount, 0) AS credit_available_amount,\n                COALESCE(x.settled_amount, 0) AS settled_amount,\n                COALESCE(r.reserved_offset_amount, 0) AS reserved_offset_amount\n         FROM procurement_supplier_financial_adjustments a\n         LEFT JOIN (\n             SELECT adjustment_id, SUM(amount) AS credit_note_amount, SUM(available_amount) AS credit_available_amount\n             FROM procurement_supplier_financial_adjustment_credit_notes\n             WHERE status <> 'Cancelled' GROUP BY adjustment_id\n         ) c ON c.adjustment_id = a.id\n         LEFT JOIN (\n             SELECT adjustment_id, SUM(amount) AS settled_amount\n             FROM procurement_supplier_financial_adjustment_allocations\n             WHERE allocation_type IN ('Refund', 'Recovery') GROUP BY adjustment_id\n         ) x ON x.adjustment_id = a.id\n         LEFT JOIN (\n             SELECT adjustment_id, SUM(amount) AS reserved_offset_amount\n             FROM procurement_supplier_credit_reservations\n             WHERE status = 'Reserved' GROUP BY adjustment_id\n         ) r ON r.adjustment_id = a.id\n         WHERE a.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Supplier adjustment could not be found.', 404);
    }

    $noteStmt = $conn->prepare(
        "SELECT id, reference, issued_date, amount, available_amount, status, notes, created_by, created_at\n         FROM procurement_supplier_financial_adjustment_credit_notes\n         WHERE adjustment_id = ? ORDER BY id DESC"
    );
    $noteStmt->bind_param('i', $adjustmentId);
    $noteStmt->execute();
    $row['credit_notes'] = $noteStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $noteStmt->close();

    $allocationStmt = $conn->prepare(
        "SELECT id, allocation_type, amount, reference, notes, created_by, created_at\n         FROM procurement_supplier_financial_adjustment_allocations\n         WHERE adjustment_id = ? ORDER BY id DESC"
    );
    $allocationStmt->bind_param('i', $adjustmentId);
    $allocationStmt->execute();
    $row['settlements'] = $allocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocationStmt->close();
    return $row;
}

function procurementSupplierAdjustmentList(mysqli $conn, array $filters = []): array
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    $page = max(1, (int) ($filters['page'] ?? 1));
    $pageSize = max(10, min(100, (int) ($filters['page_size'] ?? 25)));
    $offset = ($page - 1) * $pageSize;
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
    $supplierId = (int) ($filters['supplier_id'] ?? 0);

    $where = ["a.adjustment_direction = 'Recoverable'", "a.status <> 'Cancelled'"];
    $types = '';
    $values = [];
    if ($search !== '') {
        $where[] = '(a.supplier_name LIKE ? OR a.supplier_ledger LIKE ? OR a.reason LIKE ?)';
        $term = '%' . $search . '%';
        $types .= 'sss';
        array_push($values, $term, $term, $term);
    }
    if ($status !== '' && strtolower($status) !== 'all') {
        $where[] = 'a.status = ?';
        $types .= 's';
        $values[] = $status;
    }
    if ($currency !== '' && strtolower($currency) !== 'all') {
        $where[] = 'a.currency = ?';
        $types .= 's';
        $values[] = procurementSupplierAdjustmentNormalizeCurrency($currency);
    }
    if ($supplierId > 0) {
        $where[] = 'a.supplier_id = ?';
        $types .= 'i';
        $values[] = $supplierId;
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM procurement_supplier_financial_adjustments a WHERE {$whereSql}");
    if ($types !== '') $countStmt->bind_param($types, ...$values);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT a.*,\n                   COALESCE(c.credit_note_amount, 0) AS credit_note_amount,\n                   COALESCE(c.credit_available_amount, 0) AS credit_available_amount,\n                   COALESCE(x.settled_amount, 0) AS settled_amount,\n                   COALESCE(r.reserved_offset_amount, 0) AS reserved_offset_amount\n            FROM procurement_supplier_financial_adjustments a\n            LEFT JOIN (\n                SELECT adjustment_id, SUM(amount) AS credit_note_amount, SUM(available_amount) AS credit_available_amount\n                FROM procurement_supplier_financial_adjustment_credit_notes\n                WHERE status <> 'Cancelled' GROUP BY adjustment_id\n            ) c ON c.adjustment_id = a.id\n            LEFT JOIN (\n                SELECT adjustment_id, SUM(amount) AS settled_amount\n                FROM procurement_supplier_financial_adjustment_allocations\n                WHERE allocation_type IN ('Refund', 'Recovery') GROUP BY adjustment_id\n            ) x ON x.adjustment_id = a.id\n            LEFT JOIN (\n                SELECT adjustment_id, SUM(amount) AS reserved_offset_amount\n                FROM procurement_supplier_credit_reservations\n                WHERE status = 'Reserved' GROUP BY adjustment_id\n            ) r ON r.adjustment_id = a.id\n            WHERE {$whereSql}\n            ORDER BY a.updated_at DESC, a.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $listTypes = $types . 'ii';
    $listValues = [...$values, $pageSize, $offset];
    $stmt->bind_param($listTypes, ...$listValues);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'pages' => max(1, (int) ceil($total / $pageSize))]];
}

function procurementSupplierAdjustmentRegisterCreditNote(mysqli $conn, int $adjustmentId, array $data, int $actorId): array
{
    $adjustment = procurementSupplierAdjustmentFetchForUpdate($conn, $adjustmentId);
    $reference = trim((string) ($data['reference'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $issuedDate = trim((string) ($data['issued_date'] ?? ''));
    if ($reference === '') {
        throw new RuntimeException('Credit note reference is required.', 400);
    }
    if ($issuedDate !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $issuedDate)) {
        throw new RuntimeException('Credit note date is invalid.', 400);
    }
    $amountCents = procurementSupplierAdjustmentMoneyToCents($data['amount'] ?? null, 'Credit Note Amount');
    $outstandingCents = procurementSupplierAdjustmentMoneyToCents($adjustment['outstanding_amount'] ?? 0, 'Outstanding Amount', true);
    $availableCents = procurementSupplierAdjustmentAvailableCreditCents($conn, $adjustmentId);
    $reservedStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS reserved_amount
         FROM procurement_supplier_credit_reservations
         WHERE adjustment_id = ? AND status = 'Reserved'"
    );
    $reservedStmt->bind_param('i', $adjustmentId);
    $reservedStmt->execute();
    $reservedCents = procurementSupplierAdjustmentMoneyToCents(
        $reservedStmt->get_result()->fetch_assoc()['reserved_amount'] ?? 0,
        'Reserved Supplier Offset',
        true
    );
    $reservedStmt->close();
    if ($amountCents > max(0, $outstandingCents - $availableCents - $reservedCents)) {
        throw new RuntimeException('Credit note exceeds the recovery balance not already reserved for an offset.', 409);
    }
    $amount = procurementSupplierAdjustmentCents($amountCents);
    $dateOrNull = $issuedDate !== '' ? $issuedDate : null;
    $stmt = $conn->prepare(
        "INSERT INTO procurement_supplier_financial_adjustment_credit_notes\n         (adjustment_id, reference, issued_date, amount, available_amount, status, notes, created_by)\n         VALUES (?, ?, ?, ?, ?, 'Available', ?, ?)"
    );
    $stmt->bind_param('isssssi', $adjustmentId, $reference, $dateOrNull, $amount, $amount, $notes, $actorId);
    if (!$stmt->execute()) {
        if ((int) $stmt->errno === 1062) {
            throw new RuntimeException('This credit note reference is already recorded for the recovery.', 409);
        }
        throw new RuntimeException('Unable to register supplier credit note.', 500);
    }
    $stmt->close();
    procurementSupplierAdjustmentRefreshStatus($conn, $adjustmentId, $actorId);
    return procurementSupplierAdjustmentGet($conn, $adjustmentId);
}



function procurementSupplierAdjustmentResolveLocalAdvanceSource(
    mysqli $conn,
    array $adjustment,
    string $resolutionType,
    string $reference,
    string $notes,
    int $actorId
): void {
    $sourceType = (string) ($adjustment['source_type'] ?? '');
    if (!in_array($sourceType, ['local_advance_purchase', 'fx_advance_purchase'], true)) {
        return;
    }
    $revisionId = (int) ($adjustment['source_revision_id'] ?? 0);
    $poId = (int) ($adjustment['source_po_id'] ?? 0);
    if ($revisionId <= 0 || $poId <= 0) {
        return;
    }
    if (!procurementSupplierAdjustmentTableExists($conn, 'procurement_local_advance_po_revision_reconciliations')) {
        return;
    }

    $requestScope = $sourceType === 'fx_advance_purchase' ? 'fx_advance_purchase' : 'local_advance_purchase';
    $currency = procurementSupplierAdjustmentNormalizeCurrency($adjustment['currency'] ?? 'NGN');
    $stmt = $conn->prepare(
        "SELECT id FROM procurement_local_advance_po_revision_reconciliations\n         WHERE revision_id = ? AND po_id = ? AND request_scope = ? AND currency = ?\n           AND reconciliation_direction = 'Decrease'\n           AND reconciliation_status = 'Recovery Required'\n         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('iiss', $revisionId, $poId, $requestScope, $currency);
    $stmt->execute();
    $reconciliationId = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($reconciliationId <= 0) {
        return;
    }

    $resolutionType = in_array($resolutionType, ['Refund', 'Recovery', 'Supplier Credit'], true)
        ? $resolutionType
        : 'Recovery';
    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations\n         SET reconciliation_status = 'Resolved', resolution_type = ?, recovery_reference = ?,\n             resolution_notes = ?, resolved_by = ?, resolved_at = NOW(),\n             updated_by = ?, updated_at = NOW()\n         WHERE id = ? AND reconciliation_status = 'Recovery Required'"
    );
    $stmt->bind_param('sssiii', $resolutionType, $reference, $notes, $actorId, $actorId, $reconciliationId);
    $stmt->execute();
    $stmt->close();

    if (procurementSupplierAdjustmentTableExists($conn, 'procurement_local_advance_pos')) {
        $stmt = $conn->prepare(
            "UPDATE procurement_local_advance_pos\n             SET amendment_status = 'Resolved', updated_by = ?, updated_at = NOW(), version = version + 1\n             WHERE id = ? AND request_scope = ? AND currency = ?"
        );
        $stmt->bind_param('iiss', $actorId, $poId, $requestScope, $currency);
        $stmt->execute();
        $stmt->close();
    }
}

function procurementSupplierAdjustmentRecordRecovery(mysqli $conn, int $adjustmentId, array $data, int $actorId): array
{
    $adjustment = procurementSupplierAdjustmentFetchForUpdate($conn, $adjustmentId);
    $method = trim((string) ($data['method'] ?? ''));
    if (!in_array($method, ['Refund', 'Recovery'], true)) {
        throw new RuntimeException('Recovery method must be Refund or Recovery.', 400);
    }
    $reference = trim((string) ($data['reference'] ?? ''));
    if ($reference === '') {
        throw new RuntimeException('Recovery reference is required.', 400);
    }
    $notes = trim((string) ($data['notes'] ?? ''));
    $amountCents = procurementSupplierAdjustmentMoneyToCents($data['amount'] ?? null, 'Recovered Amount');
    $outstandingCents = procurementSupplierAdjustmentMoneyToCents($adjustment['outstanding_amount'] ?? 0, 'Outstanding Amount', true);
    $availableCreditCents = procurementSupplierAdjustmentAvailableCreditCents($conn, $adjustmentId);
    $reservedStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS reserved_amount
         FROM procurement_supplier_credit_reservations
         WHERE adjustment_id = ? AND status = 'Reserved'"
    );
    $reservedStmt->bind_param('i', $adjustmentId);
    $reservedStmt->execute();
    $reservedOffsetCents = procurementSupplierAdjustmentMoneyToCents(
        $reservedStmt->get_result()->fetch_assoc()['reserved_amount'] ?? 0,
        'Reserved Supplier Offset',
        true
    );
    $reservedStmt->close();
    $unreservedCents = max(0, $outstandingCents - $availableCreditCents - $reservedOffsetCents);
    if ($amountCents > $unreservedCents) {
        throw new RuntimeException('Recovered amount exceeds the balance not already reserved for supplier credit or offset.', 409);
    }
    $amount = procurementSupplierAdjustmentCents($amountCents);
    $stmt = $conn->prepare(
        "INSERT INTO procurement_supplier_financial_adjustment_allocations\n         (adjustment_id, allocation_type, amount, reference, notes, created_by)\n         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issssi', $adjustmentId, $method, $amount, $reference, $notes, $actorId);
    $stmt->execute();
    $stmt->close();

    $newOutstanding = procurementSupplierAdjustmentCents(max(0, $outstandingCents - $amountCents));
    $stmt = $conn->prepare(
        'UPDATE procurement_supplier_financial_adjustments SET outstanding_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('sii', $newOutstanding, $actorId, $adjustmentId);
    $stmt->execute();
    $stmt->close();
    $updated = procurementSupplierAdjustmentRefreshStatus($conn, $adjustmentId, $actorId);
    if ((string) ($updated['status'] ?? '') === 'Settled') {
        procurementSupplierAdjustmentResolveLocalAdvanceSource(
            $conn,
            $adjustment,
            $method,
            $reference,
            $notes !== '' ? $notes : 'Supplier recovery settled in AcctLab.',
            $actorId
        );
        $updated = procurementSupplierAdjustmentGet($conn, $adjustmentId);
    }
    return $updated;
}

function procurementSupplierAdjustmentCancelCreditNote(mysqli $conn, int $creditNoteId, string $reason, int $actorId): array
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    $reason = trim($reason);
    if ($creditNoteId <= 0 || $reason === '') {
        throw new RuntimeException('Credit note and cancellation reason are required.', 400);
    }
    $stmt = $conn->prepare(
        'SELECT * FROM procurement_supplier_financial_adjustment_credit_notes WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $creditNoteId);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (!$note) throw new RuntimeException('Credit note could not be found.', 404);
    if ((string) ($note['status'] ?? '') !== 'Available' || (string) ($note['available_amount'] ?? '') !== (string) ($note['amount'] ?? '')) {
        throw new RuntimeException('Only an unused credit note can be cancelled.', 409);
    }
    $stmt = $conn->prepare(
        "UPDATE procurement_supplier_financial_adjustment_credit_notes\n         SET status = 'Cancelled', available_amount = 0.00, cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?\n         WHERE id = ?"
    );
    $stmt->bind_param('isi', $actorId, $reason, $creditNoteId);
    $stmt->execute();
    $stmt->close();
    $adjustmentId = (int) $note['adjustment_id'];
    procurementSupplierAdjustmentRefreshStatus($conn, $adjustmentId, $actorId);
    return procurementSupplierAdjustmentGet($conn, $adjustmentId);
}




function procurementSupplierAvailableOffsetForSupplier(
    mysqli $conn,
    int $supplierId,
    string $currency = 'NGN'
): string {
    if ($supplierId <= 0) return '0.00';
    $currency = procurementSupplierAdjustmentNormalizeCurrency($currency);
    $identity = procurementSupplierAdjustmentResolveSupplierIdentity($conn, $supplierId);
    $canonicalId = (int) ($identity['canonical_id'] ?? $supplierId);
    $ledgerNumber = (int) ($identity['ledger_number'] ?? 0);
    $ledger = $ledgerNumber > 0 ? (string) $ledgerNumber : '';
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(a.outstanding_amount), 0) AS outstanding,
                COALESCE(SUM(r.reserved_amount), 0) AS reserved
         FROM procurement_supplier_financial_adjustments a
         LEFT JOIN (
             SELECT adjustment_id, SUM(amount) AS reserved_amount
             FROM procurement_supplier_credit_reservations
             WHERE status = 'Reserved'
             GROUP BY adjustment_id
         ) r ON r.adjustment_id = a.id
         WHERE a.adjustment_direction = 'Recoverable'
           AND (a.supplier_id = ? OR a.supplier_id = ? OR a.supplier_ledger = ?)
           AND a.currency = ?
           AND a.status IN ('Open', 'Credit Available', 'Partially Settled')
           AND a.outstanding_amount > 0"
    );
    $stmt->bind_param('iiss', $canonicalId, $ledgerNumber, $ledger, $currency);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $outstandingCents = procurementSupplierAdjustmentMoneyToCents($row['outstanding'] ?? 0, 'Outstanding Offset', true);
    $reservedCents = procurementSupplierAdjustmentMoneyToCents($row['reserved'] ?? 0, 'Reserved Offset', true);
    return procurementSupplierAdjustmentCents(max(0, $outstandingCents - $reservedCents));
}


function procurementSupplierReservedOffsetForRequest(
    mysqli $conn,
    string $requestType,
    int $requestId
): string {
    if ($requestId <= 0 || trim($requestType) === '') return '0.00';
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS reserved_amount
         FROM procurement_supplier_credit_reservations
         WHERE request_type = ? AND request_id = ? AND status = 'Reserved'"
    );
    $stmt->bind_param('si', $requestType, $requestId);
    $stmt->execute();
    $amount = (string) ($stmt->get_result()->fetch_assoc()['reserved_amount'] ?? '0.00');
    $stmt->close();
    return procurementSupplierAdjustmentCents(
        procurementSupplierAdjustmentMoneyToCents($amount, 'Reserved Offset', true)
    );
}

function procurementSupplierOffsetTargetDetails(mysqli $conn, string $requestType, int $requestId): array
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    if ($requestId <= 0) {
        throw new RuntimeException('A valid payment request is required.', 400);
    }

    if ($requestType === 'local_final_purchase') {
        $stmt = $conn->prepare(
            "SELECT id, supplier_id, suppliers_name AS supplier_name, amount AS gross_amount, payment_status
             FROM supplier_fund_request_table WHERE id = ? LIMIT 1"
        );
        $currency = 'NGN';
    } elseif ($requestType === 'local_advance_purchase') {
        $stmt = $conn->prepare(
            "SELECT id, supplier_id, suppliers_name AS supplier_name, advance_payment AS gross_amount, payment_status
             FROM advance_payment_request WHERE id = ? LIMIT 1"
        );
        $currency = 'NGN';
    } else {
        throw new RuntimeException('Supplier offset is not available for this request type.', 400);
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Payment request could not be found.', 404);
    }
    if (strtolower(trim((string) ($row['payment_status'] ?? ''))) !== 'pending') {
        throw new RuntimeException('Supplier offsets can only be changed while the payment request is Pending.', 409);
    }
    $identity = procurementSupplierAdjustmentResolveSupplierIdentity(
        $conn,
        (int) ($row['supplier_id'] ?? 0),
        (string) ($row['supplier_name'] ?? '')
    );
    $row['supplier_raw_id'] = (int) ($row['supplier_id'] ?? 0);
    $row['supplier_id'] = (int) ($identity['canonical_id'] ?? $row['supplier_id'] ?? 0);
    $row['supplier_ledger'] = (string) ((int) ($identity['ledger_number'] ?? 0));
    $row['supplier_name'] = (string) ($identity['supplier_name'] ?? $row['supplier_name'] ?? '');
    $row['currency'] = $currency;
    return $row;
}

function procurementSupplierAdjustmentOffsetAvailability(mysqli $conn, int $adjustmentId): array
{
    procurementSupplierAdjustmentEnsureStorage($conn);
    $adjustment = procurementSupplierAdjustmentFetchForUpdate($conn, $adjustmentId);
    $outstandingCents = procurementSupplierAdjustmentMoneyToCents(
        $adjustment['outstanding_amount'] ?? 0,
        'Outstanding Amount',
        true
    );

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(n.available_amount), 0) AS available_credit
         FROM procurement_supplier_financial_adjustment_credit_notes n
         WHERE n.adjustment_id = ? AND n.status <> 'Cancelled'"
    );
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $creditRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $reservedCreditStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS reserved_credit
         FROM procurement_supplier_credit_reservations
         WHERE adjustment_id = ? AND status = 'Reserved' AND credit_note_id IS NOT NULL"
    );
    $reservedCreditStmt->bind_param('i', $adjustmentId);
    $reservedCreditStmt->execute();
    $creditRow['reserved_credit'] = (string) ($reservedCreditStmt->get_result()->fetch_assoc()['reserved_credit'] ?? '0.00');
    $reservedCreditStmt->close();
    $creditAvailableCents = procurementSupplierAdjustmentMoneyToCents(
        $creditRow['available_credit'] ?? 0,
        'Available Credit',
        true
    );
    $reservedCreditCents = procurementSupplierAdjustmentMoneyToCents(
        $creditRow['reserved_credit'] ?? 0,
        'Reserved Credit',
        true
    );

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS reserved_direct
         FROM procurement_supplier_credit_reservations
         WHERE adjustment_id = ? AND status = 'Reserved' AND credit_note_id IS NULL"
    );
    $stmt->bind_param('i', $adjustmentId);
    $stmt->execute();
    $reservedDirectCents = procurementSupplierAdjustmentMoneyToCents(
        $stmt->get_result()->fetch_assoc()['reserved_direct'] ?? 0,
        'Reserved Direct Offset',
        true
    );
    $stmt->close();

    $directAvailableCents = max(
        0,
        $outstandingCents - $creditAvailableCents - $reservedCreditCents - $reservedDirectCents
    );

    return [
        'adjustment' => $adjustment,
        'outstanding_amount' => procurementSupplierAdjustmentCents($outstandingCents),
        'credit_note_available' => procurementSupplierAdjustmentCents($creditAvailableCents),
        'supplier_agreed_available' => procurementSupplierAdjustmentCents($directAvailableCents),
        'total_available' => procurementSupplierAdjustmentCents($creditAvailableCents + $directAvailableCents),
    ];
}

function procurementSupplierOffsetOptionsForRequest(
    mysqli $conn,
    string $requestType,
    int $requestId
): array {
    $target = procurementSupplierOffsetTargetDetails($conn, $requestType, $requestId);
    $supplierId = (int) ($target['supplier_id'] ?? 0);
    $supplierLedger = trim((string) ($target['supplier_ledger'] ?? ''));
    $supplierLedgerId = ctype_digit($supplierLedger) ? (int) $supplierLedger : 0;
    $currency = procurementSupplierAdjustmentNormalizeCurrency($target['currency'] ?? 'NGN');
    $grossCents = procurementSupplierAdjustmentMoneyToCents($target['gross_amount'] ?? 0, 'Gross Payable', true);

    $stmt = $conn->prepare(
        "SELECT id FROM procurement_supplier_financial_adjustments
         WHERE adjustment_direction = 'Recoverable'
           AND (supplier_id = ? OR supplier_id = ? OR supplier_ledger = ?)
           AND currency = ?
           AND status IN ('Open', 'Credit Available', 'Partially Settled')
           AND outstanding_amount > 0
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->bind_param('iiss', $supplierId, $supplierLedgerId, $supplierLedger, $currency);
    $stmt->execute();
    $ids = array_map(
        static fn(array $row): int => (int) $row['id'],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $stmt->close();

    $reservedStmt = $conn->prepare(
        "SELECT adjustment_id,
                COALESCE(SUM(amount), 0) AS amount,
                COALESCE(SUM(CASE WHEN credit_note_id IS NOT NULL THEN amount ELSE 0 END), 0) AS credit_amount,
                COALESCE(SUM(CASE WHEN credit_note_id IS NULL THEN amount ELSE 0 END), 0) AS direct_amount
         FROM procurement_supplier_credit_reservations
         WHERE request_type = ? AND request_id = ? AND status = 'Reserved'
         GROUP BY adjustment_id"
    );
    $reservedStmt->bind_param('si', $requestType, $requestId);
    $reservedStmt->execute();
    $reservedRows = $reservedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reservedStmt->close();
    $reservedByAdjustment = [];
    foreach ($reservedRows as $reservedRow) {
        $reservedByAdjustment[(int) $reservedRow['adjustment_id']] = [
            'amount' => (string) ($reservedRow['amount'] ?? '0.00'),
            'credit_amount' => (string) ($reservedRow['credit_amount'] ?? '0.00'),
            'direct_amount' => (string) ($reservedRow['direct_amount'] ?? '0.00'),
        ];
    }

    $rows = [];
    $availableCents = 0;
    foreach ($ids as $adjustmentId) {
        $availability = procurementSupplierAdjustmentOffsetAvailability($conn, $adjustmentId);
        $adjustment = $availability['adjustment'];
        $totalCents = procurementSupplierAdjustmentMoneyToCents(
            $availability['total_available'] ?? 0,
            'Available Offset',
            true
        );
        $currentReservation = $reservedByAdjustment[$adjustmentId] ?? [];
        $currentReservedCents = procurementSupplierAdjustmentMoneyToCents(
            $currentReservation['amount'] ?? 0,
            'Current Reserved Offset',
            true
        );
        $currentReservedCreditCents = procurementSupplierAdjustmentMoneyToCents(
            $currentReservation['credit_amount'] ?? 0,
            'Current Reserved Credit Offset',
            true
        );
        $currentReservedDirectCents = procurementSupplierAdjustmentMoneyToCents(
            $currentReservation['direct_amount'] ?? 0,
            'Current Reserved Direct Offset',
            true
        );
        // The current request's reservation is already excluded from general availability.
        $rowAvailableCents = $totalCents + $currentReservedCents;
        if ($rowAvailableCents <= 0) continue;
        $availableCents += $rowAvailableCents;
        $rows[] = [
            'adjustment_id' => $adjustmentId,
            'source_type' => (string) ($adjustment['source_type'] ?? ''),
            'source_purchase_id' => (int) ($adjustment['source_purchase_id'] ?? 0),
            'source_po_id' => (int) ($adjustment['source_po_id'] ?? 0),
            'adjustment_kind' => (string) ($adjustment['adjustment_kind'] ?? ''),
            'reason' => (string) ($adjustment['reason'] ?? ''),
            'original_amount' => (string) ($adjustment['amount'] ?? '0.00'),
            'outstanding_amount' => (string) ($availability['outstanding_amount'] ?? '0.00'),
            'credit_note_available' => (string) ($availability['credit_note_available'] ?? '0.00'),
            'supplier_agreed_available' => (string) ($availability['supplier_agreed_available'] ?? '0.00'),
            'available_amount' => procurementSupplierAdjustmentCents($rowAvailableCents),
            'reserved_for_request' => procurementSupplierAdjustmentCents($currentReservedCents),
            'reserved_credit_for_request' => procurementSupplierAdjustmentCents($currentReservedCreditCents),
            'reserved_direct_for_request' => procurementSupplierAdjustmentCents($currentReservedDirectCents),
            'currency' => $currency,
        ];
    }

    $totals = procurementSupplierCreditReservationTotals($conn, $requestType, $requestId);
    $reservedCents = procurementSupplierAdjustmentMoneyToCents(
        $totals['reserved_amount'] ?? 0,
        'Reserved Offset',
        true
    );

    return [
        'request' => [
            'request_type' => $requestType,
            'request_id' => $requestId,
            'supplier_id' => $supplierId,
            'supplier_name' => (string) ($target['supplier_name'] ?? ''),
            'currency' => $currency,
            'gross_amount' => procurementSupplierAdjustmentCents($grossCents),
            'reserved_offset' => procurementSupplierAdjustmentCents($reservedCents),
            'cash_required' => procurementSupplierAdjustmentCents(max(0, $grossCents - $reservedCents)),
        ],
        'recoveries' => $rows,
        'available_total' => procurementSupplierAdjustmentCents($availableCents),
    ];
}

function procurementSupplierReserveManualOffsets(
    mysqli $conn,
    string $requestType,
    int $requestId,
    array $allocations,
    string $agreementReference,
    string $notes,
    int $actorId
): array {
    $target = procurementSupplierOffsetTargetDetails($conn, $requestType, $requestId);
    $supplierId = (int) ($target['supplier_id'] ?? 0);
    $currency = procurementSupplierAdjustmentNormalizeCurrency($target['currency'] ?? 'NGN');
    $grossCents = procurementSupplierAdjustmentMoneyToCents($target['gross_amount'] ?? 0, 'Gross Payable');

    // Replace any prior pending reservation for this payment request.
    procurementSupplierReleaseCreditReservations(
        $conn,
        $requestType,
        $requestId,
        $actorId,
        'Supplier offset selection updated before payment processing.'
    );

    $requested = [];
    $requestedTotalCents = 0;
    foreach ($allocations as $allocation) {
        if (!is_array($allocation)) continue;
        $adjustmentId = (int) ($allocation['adjustment_id'] ?? 0);
        $amountCents = procurementSupplierAdjustmentMoneyToCents(
            $allocation['amount'] ?? 0,
            'Offset Amount',
            true
        );
        if ($adjustmentId <= 0 || $amountCents <= 0) continue;
        $requested[$adjustmentId] = ($requested[$adjustmentId] ?? 0) + $amountCents;
        $requestedTotalCents += $amountCents;
    }
    if ($requestedTotalCents <= 0) {
        return procurementSupplierOffsetOptionsForRequest($conn, $requestType, $requestId);
    }
    if ($requestedTotalCents > $grossCents) {
        throw new RuntimeException('Supplier offset cannot exceed the payment request amount.', 409);
    }

    $requiresAgreementReference = false;
    foreach ($requested as $adjustmentId => $amountCents) {
        $availability = procurementSupplierAdjustmentOffsetAvailability($conn, (int) $adjustmentId);
        $adjustment = $availability['adjustment'];
        if (!procurementSupplierAdjustmentSameSupplier(
                $conn,
                (int) ($adjustment['supplier_id'] ?? 0),
                $supplierId
            )
            || procurementSupplierAdjustmentNormalizeCurrency($adjustment['currency'] ?? 'NGN') !== $currency) {
            throw new RuntimeException('Only recoveries for the same supplier and currency can be offset.', 409);
        }
        $availableCents = procurementSupplierAdjustmentMoneyToCents(
            $availability['total_available'] ?? 0,
            'Available Offset',
            true
        );
        if ($amountCents > $availableCents) {
            throw new RuntimeException('Selected supplier offset exceeds the available recovery balance.', 409);
        }

        $remainingCents = $amountCents;
        // Consume formal credit notes first, oldest first.
        $noteStmt = $conn->prepare(
            "SELECT id, available_amount
             FROM procurement_supplier_financial_adjustment_credit_notes
             WHERE adjustment_id = ?
               AND status IN ('Available', 'Partially Applied')
               AND available_amount > 0
             ORDER BY COALESCE(issued_date, DATE(created_at)) ASC, id ASC
             FOR UPDATE"
        );
        $noteStmt->bind_param('i', $adjustmentId);
        $noteStmt->execute();
        $notesRows = $noteStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $noteStmt->close();

        foreach ($notesRows as $noteRow) {
            if ($remainingCents <= 0) break;
            $noteAvailableCents = procurementSupplierAdjustmentMoneyToCents(
                $noteRow['available_amount'] ?? 0,
                'Credit Note Available Amount',
                true
            );
            if ($noteAvailableCents <= 0) continue;
            $takeCents = min($remainingCents, $noteAvailableCents);
            $newAvailableCents = $noteAvailableCents - $takeCents;
            $newAvailable = procurementSupplierAdjustmentCents($newAvailableCents);
            $noteStatus = $newAvailableCents > 0 ? 'Partially Applied' : 'Applied';
            $creditNoteId = (int) $noteRow['id'];
            $update = $conn->prepare(
                'UPDATE procurement_supplier_financial_adjustment_credit_notes
                 SET available_amount = ?, status = ? WHERE id = ?'
            );
            $update->bind_param('ssi', $newAvailable, $noteStatus, $creditNoteId);
            $update->execute();
            $update->close();

            $amount = procurementSupplierAdjustmentCents($takeCents);
            $basis = 'Credit Note';
            $nullReference = null;
            $reservation = $conn->prepare(
                "INSERT INTO procurement_supplier_credit_reservations
                 (credit_note_id, adjustment_id, supplier_id, currency, request_type, request_id,
                  amount, offset_basis, agreement_reference, notes, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Reserved', ?)"
            );
            $reservation->bind_param(
                'iiississssi',
                $creditNoteId,
                $adjustmentId,
                $supplierId,
                $currency,
                $requestType,
                $requestId,
                $amount,
                $basis,
                $nullReference,
                $notes,
                $actorId
            );
            $reservation->execute();
            $reservation->close();
            $remainingCents -= $takeCents;
        }

        if ($remainingCents > 0) {
            $requiresAgreementReference = true;
            if (trim($agreementReference) === '') {
                throw new RuntimeException('Supplier agreement reference or remark is required for an offset without a credit note.', 400);
            }
            $amount = procurementSupplierAdjustmentCents($remainingCents);
            $basis = 'Supplier Agreed Offset';
            $creditNoteId = null;
            $reservation = $conn->prepare(
                "INSERT INTO procurement_supplier_credit_reservations
                 (credit_note_id, adjustment_id, supplier_id, currency, request_type, request_id,
                  amount, offset_basis, agreement_reference, notes, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Reserved', ?)"
            );
            $reservation->bind_param(
                'iiississssi',
                $creditNoteId,
                $adjustmentId,
                $supplierId,
                $currency,
                $requestType,
                $requestId,
                $amount,
                $basis,
                $agreementReference,
                $notes,
                $actorId
            );
            $reservation->execute();
            $reservation->close();
        }
    }

    return procurementSupplierOffsetOptionsForRequest($conn, $requestType, $requestId);
}

function procurementSupplierCreditReservationTotals(
    mysqli $conn,
    string $requestType,
    int $requestId,
    ?int $paymentBatchId = null
): array {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $whereBatch = $paymentBatchId !== null && $paymentBatchId > 0
        ? ' AND payment_batch_id = ?'
        : '';
    $sql = "SELECT COALESCE(SUM(CASE WHEN status = 'Reserved' THEN amount ELSE 0 END), 0) AS reserved_amount,
                   COALESCE(SUM(CASE WHEN status = 'Applied' THEN amount ELSE 0 END), 0) AS applied_amount
            FROM procurement_supplier_credit_reservations
            WHERE request_type = ? AND request_id = ?{$whereBatch}";
    $stmt = $conn->prepare($sql);
    if ($paymentBatchId !== null && $paymentBatchId > 0) {
        $stmt->bind_param('sii', $requestType, $requestId, $paymentBatchId);
    } else {
        $stmt->bind_param('si', $requestType, $requestId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'reserved_amount' => procurementSupplierAdjustmentCents(
            procurementSupplierAdjustmentMoneyToCents($row['reserved_amount'] ?? 0, 'Reserved Credit', true)
        ),
        'applied_amount' => procurementSupplierAdjustmentCents(
            procurementSupplierAdjustmentMoneyToCents($row['applied_amount'] ?? 0, 'Applied Credit', true)
        ),
    ];
}

function procurementSupplierPaymentOffsetSummariesForRequests(
    mysqli $conn,
    string $requestType,
    array $requestIds
): array {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $ids = array_values(array_unique(array_filter(array_map(
        static fn($value): int => max(0, (int) $value),
        $requestIds
    ))));
    if (!$ids || trim($requestType) === '') {
        return [];
    }

    $idList = implode(',', $ids);
    $safeRequestType = $conn->real_escape_string($requestType);
    $sql = "SELECT r.request_id, r.status, r.amount,
                   COALESCE(NULLIF(r.offset_basis, ''), 'Credit Note') AS offset_basis,
                   r.agreement_reference,
                   a.adjustment_kind, a.reason
            FROM procurement_supplier_credit_reservations r
            INNER JOIN procurement_supplier_financial_adjustments a ON a.id = r.adjustment_id
            WHERE r.request_type = '{$safeRequestType}'
              AND r.request_id IN ({$idList})
              AND r.status IN ('Reserved', 'Applied')
            ORDER BY r.request_id ASC, r.created_at ASC, r.id ASC";
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Unable to load supplier offset summaries.', 500);
    }

    $summaries = [];
    while ($row = $result->fetch_assoc()) {
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0) continue;
        if (!isset($summaries[$requestId])) {
            $summaries[$requestId] = [
                'reserved_cents' => 0,
                'applied_cents' => 0,
                'basis_map' => [],
                'reason_map' => [],
                'reference_map' => [],
            ];
        }

        $amountCents = procurementSupplierAdjustmentMoneyToCents(
            $row['amount'] ?? 0,
            'Supplier Offset Amount',
            true
        );
        if ((string) ($row['status'] ?? '') === 'Applied') {
            $summaries[$requestId]['applied_cents'] += $amountCents;
        } else {
            $summaries[$requestId]['reserved_cents'] += $amountCents;
        }

        $basisLabel = trim((string) ($row['offset_basis'] ?? ''));
        if ($basisLabel !== '') $summaries[$requestId]['basis_map'][$basisLabel] = true;

        $reason = trim((string) ($row['reason'] ?? ''));
        $kind = trim((string) ($row['adjustment_kind'] ?? ''));
        $reasonLabel = trim(implode(' · ', array_filter([$kind, $reason])));
        if ($reasonLabel !== '') $summaries[$requestId]['reason_map'][$reasonLabel] = true;

        $reference = trim((string) ($row['agreement_reference'] ?? ''));
        if ($reference !== '') $summaries[$requestId]['reference_map'][$reference] = true;
    }
    $result->close();

    $normalized = [];
    foreach ($summaries as $requestId => $summary) {
        $reservedCents = (int) ($summary['reserved_cents'] ?? 0);
        $appliedCents = (int) ($summary['applied_cents'] ?? 0);
        $normalized[$requestId] = [
            'reserved_amount' => procurementSupplierAdjustmentCents($reservedCents),
            'applied_amount' => procurementSupplierAdjustmentCents($appliedCents),
            'effective_offset_amount' => procurementSupplierAdjustmentCents($reservedCents + $appliedCents),
            'basis' => implode(', ', array_keys($summary['basis_map'] ?? [])),
            'reason' => implode(' | ', array_keys($summary['reason_map'] ?? [])),
            'references' => array_keys($summary['reference_map'] ?? []),
        ];
    }

    return $normalized;
}


function procurementSupplierPaymentOffsetSummaryForRequest(
    mysqli $conn,
    string $requestType,
    int $requestId
): array {
    procurementSupplierAdjustmentEnsureStorage($conn);
    if ($requestId <= 0 || trim($requestType) === '') {
        return [
            'reserved_amount' => '0.00',
            'applied_amount' => '0.00',
            'effective_offset_amount' => '0.00',
            'basis' => '',
            'reason' => '',
            'references' => [],
        ];
    }

    $stmt = $conn->prepare(
        "SELECT r.status, r.amount, COALESCE(NULLIF(r.offset_basis, ''), 'Credit Note') AS offset_basis,
                r.agreement_reference, r.notes,
                a.adjustment_kind, a.reason, a.source_type, a.source_purchase_id, a.source_po_id
         FROM procurement_supplier_credit_reservations r
         INNER JOIN procurement_supplier_financial_adjustments a ON a.id = r.adjustment_id
         WHERE r.request_type = ? AND r.request_id = ?
           AND r.status IN ('Reserved', 'Applied')
         ORDER BY r.created_at ASC, r.id ASC"
    );
    $stmt->bind_param('si', $requestType, $requestId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $reservedCents = 0;
    $appliedCents = 0;
    $basis = [];
    $reasons = [];
    $references = [];
    foreach ($rows as $row) {
        $amountCents = procurementSupplierAdjustmentMoneyToCents(
            $row['amount'] ?? 0,
            'Supplier Offset Amount',
            true
        );
        if ((string) ($row['status'] ?? '') === 'Applied') {
            $appliedCents += $amountCents;
        } else {
            $reservedCents += $amountCents;
        }

        $basisLabel = trim((string) ($row['offset_basis'] ?? ''));
        if ($basisLabel !== '') $basis[$basisLabel] = true;

        $reason = trim((string) ($row['reason'] ?? ''));
        $kind = trim((string) ($row['adjustment_kind'] ?? ''));
        $reasonLabel = trim(implode(' · ', array_filter([$kind, $reason])));
        if ($reasonLabel !== '') $reasons[$reasonLabel] = true;

        $reference = trim((string) ($row['agreement_reference'] ?? ''));
        if ($reference !== '') $references[$reference] = true;
    }

    return [
        'reserved_amount' => procurementSupplierAdjustmentCents($reservedCents),
        'applied_amount' => procurementSupplierAdjustmentCents($appliedCents),
        'effective_offset_amount' => procurementSupplierAdjustmentCents($reservedCents + $appliedCents),
        'basis' => implode(', ', array_keys($basis)),
        'reason' => implode(' | ', array_keys($reasons)),
        'references' => array_keys($references),
    ];
}


function procurementSupplierReserveCreditForPayment(
    mysqli $conn,
    int $supplierId,
    string $currency,
    mixed $grossAmount,
    string $requestType,
    int $requestId,
    int $actorId,
    ?int $paymentBatchId = null
): array {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $currency = procurementSupplierAdjustmentNormalizeCurrency($currency);
    $identity = procurementSupplierAdjustmentResolveSupplierIdentity($conn, $supplierId);
    $supplierId = (int) ($identity['canonical_id'] ?? $supplierId);
    $supplierLedgerId = (int) ($identity['ledger_number'] ?? 0);
    $supplierLedger = $supplierLedgerId > 0 ? (string) $supplierLedgerId : '';
    $grossCents = procurementSupplierAdjustmentMoneyToCents($grossAmount, 'Gross Payable');
    if ($supplierId <= 0 || $requestId <= 0 || $actorId <= 0) {
        throw new RuntimeException('Supplier, request and actor are required to reserve supplier credit.', 400);
    }
    if (!in_array($requestType, PROCUREMENT_SUPPLIER_ADJUSTMENT_SOURCE_TYPES, true)) {
        throw new RuntimeException('Supplier credit request type is invalid.', 400);
    }

    $existing = procurementSupplierCreditReservationTotals($conn, $requestType, $requestId, $paymentBatchId);
    $existingReservedCents = procurementSupplierAdjustmentMoneyToCents(
        $existing['reserved_amount'] ?? 0,
        'Reserved Credit',
        true
    );
    $existingAppliedCents = procurementSupplierAdjustmentMoneyToCents(
        $existing['applied_amount'] ?? 0,
        'Applied Credit',
        true
    );
    if ($existingReservedCents > 0 || $existingAppliedCents > 0) {
        $creditCents = min($grossCents, $existingReservedCents + $existingAppliedCents);
        return [
            'gross_amount' => procurementSupplierAdjustmentCents($grossCents),
            'credit_amount' => procurementSupplierAdjustmentCents($creditCents),
            'cash_required' => procurementSupplierAdjustmentCents(max(0, $grossCents - $creditCents)),
        ];
    }

    $stmt = $conn->prepare(
        "SELECT n.id AS credit_note_id, n.adjustment_id, n.available_amount,
                a.outstanding_amount
         FROM procurement_supplier_financial_adjustment_credit_notes n
         INNER JOIN procurement_supplier_financial_adjustments a ON a.id = n.adjustment_id
         WHERE a.adjustment_direction = 'Recoverable'
           AND (a.supplier_id = ? OR a.supplier_id = ? OR a.supplier_ledger = ?)
           AND a.currency = ?
           AND a.status IN ('Open', 'Credit Available', 'Partially Settled')
           AND n.status IN ('Available', 'Partially Applied')
           AND n.available_amount > 0
         ORDER BY COALESCE(n.issued_date, DATE(n.created_at)) ASC, n.id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('iiss', $supplierId, $supplierLedgerId, $supplierLedger, $currency);
    $stmt->execute();
    $notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remainingCents = $grossCents;
    $reservedCents = 0;
    foreach ($notes as $note) {
        if ($remainingCents <= 0) break;
        $availableCents = procurementSupplierAdjustmentMoneyToCents(
            $note['available_amount'] ?? 0,
            'Credit Note Available Amount',
            true
        );
        if ($availableCents <= 0) continue;
        $takeCents = min($remainingCents, $availableCents);
        $take = procurementSupplierAdjustmentCents($takeCents);
        $newAvailableCents = $availableCents - $takeCents;
        $newAvailable = procurementSupplierAdjustmentCents($newAvailableCents);
        $noteStatus = $newAvailableCents > 0 ? 'Partially Applied' : 'Applied';

        $update = $conn->prepare(
            'UPDATE procurement_supplier_financial_adjustment_credit_notes
             SET available_amount = ?, status = ?
             WHERE id = ?'
        );
        $creditNoteId = (int) $note['credit_note_id'];
        $update->bind_param('ssi', $newAvailable, $noteStatus, $creditNoteId);
        $update->execute();
        $update->close();

        $reservation = $conn->prepare(
            "INSERT INTO procurement_supplier_credit_reservations
             (credit_note_id, adjustment_id, supplier_id, currency, request_type, request_id,
              payment_batch_id, amount, offset_basis, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Credit Note', 'Reserved', ?)"
        );
        $adjustmentId = (int) $note['adjustment_id'];
        $batchOrNull = $paymentBatchId !== null && $paymentBatchId > 0 ? $paymentBatchId : null;
        $reservation->bind_param(
            'iiissiisi',
            $creditNoteId,
            $adjustmentId,
            $supplierId,
            $currency,
            $requestType,
            $requestId,
            $batchOrNull,
            $take,
            $actorId
        );
        $reservation->execute();
        $reservation->close();

        $remainingCents -= $takeCents;
        $reservedCents += $takeCents;
    }

    return [
        'gross_amount' => procurementSupplierAdjustmentCents($grossCents),
        'credit_amount' => procurementSupplierAdjustmentCents($reservedCents),
        'cash_required' => procurementSupplierAdjustmentCents(max(0, $grossCents - $reservedCents)),
    ];
}

function procurementSupplierAttachCreditReservationsToBatch(
    mysqli $conn,
    string $requestType,
    int $requestId,
    int $paymentBatchId
): void {
    procurementSupplierAdjustmentEnsureStorage($conn);
    if ($paymentBatchId <= 0) return;
    $stmt = $conn->prepare(
        "UPDATE procurement_supplier_credit_reservations
         SET payment_batch_id = ?
         WHERE request_type = ? AND request_id = ? AND status = 'Reserved'
           AND (payment_batch_id IS NULL OR payment_batch_id = 0)"
    );
    $stmt->bind_param('isi', $paymentBatchId, $requestType, $requestId);
    $stmt->execute();
    $stmt->close();
}

function procurementSupplierFinalizeCreditReservations(
    mysqli $conn,
    string $requestType,
    int $requestId,
    int $actorId,
    ?int $paymentBatchId = null,
    ?string $reference = null
): string {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $batchSql = $paymentBatchId !== null && $paymentBatchId > 0
        ? ' AND payment_batch_id = ?'
        : '';
    $sql = "SELECT * FROM procurement_supplier_credit_reservations
            WHERE request_type = ? AND request_id = ? AND status = 'Reserved'{$batchSql}
            ORDER BY id ASC FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if ($paymentBatchId !== null && $paymentBatchId > 0) {
        $stmt->bind_param('sii', $requestType, $requestId, $paymentBatchId);
    } else {
        $stmt->bind_param('si', $requestType, $requestId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $totalCents = 0;
    $touchedAdjustments = [];
    foreach ($rows as $row) {
        $amountCents = procurementSupplierAdjustmentMoneyToCents($row['amount'] ?? 0, 'Reserved Credit', true);
        if ($amountCents <= 0) continue;
        $amount = procurementSupplierAdjustmentCents($amountCents);
        $adjustmentId = (int) $row['adjustment_id'];
        $reservationId = (int) $row['id'];

        $adjustmentStmt = $conn->prepare(
            'SELECT outstanding_amount FROM procurement_supplier_financial_adjustments WHERE id = ? FOR UPDATE'
        );
        $adjustmentStmt->bind_param('i', $adjustmentId);
        $adjustmentStmt->execute();
        $adjustment = $adjustmentStmt->get_result()->fetch_assoc() ?: [];
        $adjustmentStmt->close();
        $outstandingCents = procurementSupplierAdjustmentMoneyToCents(
            $adjustment['outstanding_amount'] ?? 0,
            'Outstanding Amount',
            true
        );
        if ($amountCents > $outstandingCents) {
            throw new RuntimeException('Reserved supplier credit exceeds the remaining recovery balance.', 409);
        }

        $basis = trim((string) ($row['offset_basis'] ?? 'Credit Note'));
        $allocationType = $basis === 'Supplier Agreed Offset' ? 'Supplier Agreed Offset' : 'Credit Offset';
        $agreementReference = trim((string) ($row['agreement_reference'] ?? ''));
        $ref = $agreementReference !== ''
            ? $agreementReference
            : (trim((string) ($reference ?? '')) ?: null);
        $allocationNotes = $basis === 'Supplier Agreed Offset'
            ? ('Supplier-agreed offset applied against later payment.' . (trim((string) ($row['notes'] ?? '')) !== '' ? ' ' . trim((string) $row['notes']) : ''))
            : 'Supplier credit note applied against later payment.';
        $allocation = $conn->prepare(
            "INSERT INTO procurement_supplier_financial_adjustment_allocations
             (adjustment_id, allocation_type, target_source_type, target_account_request_type,
              target_account_request_id, amount, reference, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $allocation->bind_param(
            'isssisssi',
            $adjustmentId,
            $allocationType,
            $requestType,
            $requestType,
            $requestId,
            $amount,
            $ref,
            $allocationNotes,
            $actorId
        );
        $allocation->execute();
        $allocation->close();

        $newOutstanding = procurementSupplierAdjustmentCents($outstandingCents - $amountCents);
        $updateAdjustment = $conn->prepare(
            'UPDATE procurement_supplier_financial_adjustments
             SET outstanding_amount = ?, updated_by = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $updateAdjustment->bind_param('sii', $newOutstanding, $actorId, $adjustmentId);
        $updateAdjustment->execute();
        $updateAdjustment->close();

        $updateReservation = $conn->prepare(
            "UPDATE procurement_supplier_credit_reservations
             SET status = 'Applied', applied_by = ?, applied_at = NOW()
             WHERE id = ? AND status = 'Reserved'"
        );
        $updateReservation->bind_param('ii', $actorId, $reservationId);
        $updateReservation->execute();
        $updateReservation->close();

        $totalCents += $amountCents;
        $touchedAdjustments[$adjustmentId] = true;
    }

    foreach (array_keys($touchedAdjustments) as $adjustmentId) {
        $updated = procurementSupplierAdjustmentRefreshStatus($conn, (int) $adjustmentId, $actorId);
        if ((string) ($updated['status'] ?? '') === 'Settled') {
            procurementSupplierAdjustmentResolveLocalAdvanceSource(
                $conn,
                $updated,
                'Supplier Credit',
                trim((string) ($reference ?? '')) ?: 'Supplier offset',
                'Supplier recovery offset applied against a later payment.',
                $actorId
            );
        }
    }

    return procurementSupplierAdjustmentCents($totalCents);
}

function procurementSupplierReleaseCreditReservations(
    mysqli $conn,
    string $requestType,
    int $requestId,
    int $actorId,
    string $reason,
    ?int $paymentBatchId = null
): string {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $reason = trim($reason) ?: 'Payment operation released.';
    $batchSql = $paymentBatchId !== null && $paymentBatchId > 0
        ? ' AND payment_batch_id = ?'
        : '';
    $sql = "SELECT * FROM procurement_supplier_credit_reservations
            WHERE request_type = ? AND request_id = ? AND status = 'Reserved'{$batchSql}
            ORDER BY id ASC FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if ($paymentBatchId !== null && $paymentBatchId > 0) {
        $stmt->bind_param('sii', $requestType, $requestId, $paymentBatchId);
    } else {
        $stmt->bind_param('si', $requestType, $requestId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $totalCents = 0;
    foreach ($rows as $row) {
        $amountCents = procurementSupplierAdjustmentMoneyToCents($row['amount'] ?? 0, 'Reserved Credit', true);
        if ($amountCents <= 0) continue;
        $creditNoteId = isset($row['credit_note_id']) ? (int) $row['credit_note_id'] : 0;
        if ($creditNoteId > 0) {
            $noteStmt = $conn->prepare(
                'SELECT amount, available_amount FROM procurement_supplier_financial_adjustment_credit_notes WHERE id = ? FOR UPDATE'
            );
            $noteStmt->bind_param('i', $creditNoteId);
            $noteStmt->execute();
            $note = $noteStmt->get_result()->fetch_assoc() ?: [];
            $noteStmt->close();
            if ($note) {
                $noteAmountCents = procurementSupplierAdjustmentMoneyToCents($note['amount'] ?? 0, 'Credit Note Amount', true);
                $availableCents = procurementSupplierAdjustmentMoneyToCents($note['available_amount'] ?? 0, 'Available Credit', true);
                $restoredCents = min($noteAmountCents, $availableCents + $amountCents);
                $restored = procurementSupplierAdjustmentCents($restoredCents);
                $noteStatus = $restoredCents >= $noteAmountCents ? 'Available' : 'Partially Applied';
                $updateNote = $conn->prepare(
                    'UPDATE procurement_supplier_financial_adjustment_credit_notes SET available_amount = ?, status = ? WHERE id = ?'
                );
                $updateNote->bind_param('ssi', $restored, $noteStatus, $creditNoteId);
                $updateNote->execute();
                $updateNote->close();
            }
        }

        $reservationId = (int) $row['id'];
        $updateReservation = $conn->prepare(
            "UPDATE procurement_supplier_credit_reservations
             SET status = 'Released', released_by = ?, released_at = NOW(), release_reason = ?
             WHERE id = ? AND status = 'Reserved'"
        );
        $updateReservation->bind_param('isi', $actorId, $reason, $reservationId);
        $updateReservation->execute();
        $updateReservation->close();
        $totalCents += $amountCents;
    }
    return procurementSupplierAdjustmentCents($totalCents);
}

function procurementSupplierAvailableCreditForSupplier(
    mysqli $conn,
    int $supplierId,
    string $currency = 'NGN'
): string {
    procurementSupplierAdjustmentEnsureStorage($conn);
    $currency = procurementSupplierAdjustmentNormalizeCurrency($currency);
    $identity = procurementSupplierAdjustmentResolveSupplierIdentity($conn, $supplierId);
    $canonicalId = (int) ($identity['canonical_id'] ?? $supplierId);
    $ledgerNumber = (int) ($identity['ledger_number'] ?? 0);
    $ledger = $ledgerNumber > 0 ? (string) $ledgerNumber : '';

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(n.available_amount), 0) AS available
         FROM procurement_supplier_financial_adjustment_credit_notes n
         INNER JOIN procurement_supplier_financial_adjustments a ON a.id = n.adjustment_id
         WHERE a.adjustment_direction = 'Recoverable'
           AND (a.supplier_id = ? OR a.supplier_id = ? OR a.supplier_ledger = ?)
           AND a.currency = ?
           AND a.status IN ('Open', 'Credit Available', 'Partially Settled')
           AND n.status IN ('Available', 'Partially Applied')"
    );
    $stmt->bind_param('iiss', $canonicalId, $ledgerNumber, $ledger, $currency);
    $stmt->execute();
    $available = (string) ($stmt->get_result()->fetch_assoc()['available'] ?? '0.00');
    $stmt->close();
    return procurementSupplierAdjustmentCents(
        procurementSupplierAdjustmentMoneyToCents($available, 'Available Credit', true)
    );
}
