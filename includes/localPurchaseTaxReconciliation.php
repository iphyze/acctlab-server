<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementRequestCanonicalSyncService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/accountPaymentStorageRuntimeReadService.php';

const LOCAL_PURCHASE_TAX_RECONCILIATION_ACTOR_EMAIL = 'system@vat-wht-reconciliation';
const LOCAL_PURCHASE_TAX_PERCENT_SCALE = 1000000;
const LOCAL_PURCHASE_TAX_PERCENT_MAX = 100000000;
const LOCAL_PURCHASE_TAX_ACTIVE_ITEM_STATUSES = ['Processing', 'Awaiting Confirmation', 'Delayed'];

function localPurchaseTaxTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function localPurchaseTaxColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function localPurchaseTaxEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $query = "CREATE TABLE IF NOT EXISTS local_purchase_vat_wht_reconciliation_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id VARCHAR(80) NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        account_request_id INT NULL,
        action VARCHAR(40) NOT NULL,
        result_status VARCHAR(30) NOT NULL,
        reason VARCHAR(500) NULL,
        before_json LONGTEXT NULL,
        after_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_local_tax_reconciliation_run (run_id, result_status),
        INDEX idx_local_tax_reconciliation_source (source_type, source_id, created_at),
        INDEX idx_local_tax_reconciliation_account (account_request_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($query)) {
        throw new RuntimeException('Unable to initialize VAT/WHT reconciliation storage.', 500);
    }
}

function localPurchaseTaxMoneyToCents(mixed $value, string $label = 'Amount'): int
{
    if (is_int($value)) {
        $raw = (string) $value;
    } elseif (is_float($value)) {
        $raw = number_format($value, 4, '.', '');
    } else {
        $raw = trim(str_replace([',', '₦'], '', (string) $value));
    }

    if ($raw === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
        throw new RuntimeException($label . ' must be numeric.');
    }

    $negative = str_starts_with($raw, '-');
    if ($negative) {
        $raw = substr($raw, 1);
    }

    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $fraction = preg_replace('/\D/', '', $fraction) ?? '';
    $firstTwo = str_pad(substr($fraction, 0, 2), 2, '0');
    $cents = ((int) $whole * 100) + (int) $firstTwo;
    if (isset($fraction[2]) && (int) $fraction[2] >= 5) {
        $cents++;
    }

    return $negative ? -$cents : $cents;
}

function localPurchaseTaxCents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function localPurchaseTaxPercentUnits(mixed $value): int
{
    $raw = trim(str_replace(['%', ','], '', (string) $value));
    if ($raw === '' || !preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        throw new RuntimeException('PO Percentage must be numeric.');
    }

    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $fraction = preg_replace('/\D/', '', $fraction) ?? '';
    $firstSix = str_pad(substr($fraction, 0, 6), 6, '0');
    $units = ((int) $whole * LOCAL_PURCHASE_TAX_PERCENT_SCALE) + (int) $firstSix;
    if (isset($fraction[6]) && (int) $fraction[6] >= 5) {
        $units++;
    }

    if ($units <= 0 || $units > LOCAL_PURCHASE_TAX_PERCENT_MAX) {
        throw new RuntimeException('PO Percentage must be greater than zero and not exceed 100.');
    }
    return $units;
}

function localPurchaseTaxMultiplyDivideRounded(int $amount, int $multiplier, int $divisor): int
{
    $quotient = intdiv($amount, $divisor);
    $remainder = $amount % $divisor;
    return ($quotient * $multiplier)
        + intdiv(($remainder * $multiplier) + intdiv($divisor, 2), $divisor);
}

function localPurchaseTaxIsVatCharged(mixed $status, mixed $rate): bool
{
    $normalized = strtolower(trim((string) $status));
    $statusHasVat = in_array(
        $normalized,
        ['yes', 'yes - 7.5%', '7.5%', '7.50%', '1', 'true'],
        true
    );
    return $statusHasVat && (float) $rate > 0.0000005;
}

function localPurchaseTaxHasNonZeroWht(array $row): bool
{
    $status = strtolower(trim((string) ($row['wht_status'] ?? '')));
    $zeroStatuses = ['', '0', '0%', '0.0%', '0.00%', 'none', 'no'];
    return !in_array($status, $zeroStatuses, true)
        || abs((float) ($row['wht_rate'] ?? 0)) > 0.0000005
        || abs((float) ($row['wht_amount'] ?? $row['wht'] ?? 0)) > 0.005;
}

function localPurchaseTaxCalculateNoVatFinal(array $purchase): array
{
    $subtotal = localPurchaseTaxMoneyToCents($purchase['purchase_subtotal'] ?? 0, 'Purchase Subtotal');
    $discount = localPurchaseTaxMoneyToCents($purchase['purchase_discount'] ?? 0, 'Purchase Discount');
    $otherCharges = localPurchaseTaxMoneyToCents($purchase['purchase_other_charges'] ?? 0, 'Purchase Other Charges');
    $net = $subtotal - $discount;
    if ($net < 0) {
        throw new RuntimeException('Purchase Discount exceeds Purchase Subtotal.');
    }

    return [
        'purchase_net' => localPurchaseTaxCents($net),
        'purchase_vat_status' => 'No',
        'purchase_vat_rate' => '0.000000',
        'purchase_vat_amount' => '0.00',
        'wht_status' => '0.00%',
        'wht_rate' => '0.000000',
        'wht_amount' => '0.00',
        'purchase_value' => localPurchaseTaxCents($net + $otherCharges),
        'account_payable' => localPurchaseTaxCents($net + $otherCharges),
    ];
}

function localPurchaseTaxCalculateNoVatAdvancePo(array $po): array
{
    $subtotal = localPurchaseTaxMoneyToCents($po['po_subtotal'] ?? 0, 'PO Subtotal');
    $discount = localPurchaseTaxMoneyToCents($po['po_discount'] ?? 0, 'PO Discount');
    $otherCharges = localPurchaseTaxMoneyToCents($po['po_other_charges'] ?? 0, 'PO Other Charges');
    $net = $subtotal - $discount;
    if ($net < 0) {
        throw new RuntimeException('PO Discount exceeds PO Subtotal.');
    }

    $base = $net + $otherCharges;
    return [
        'po_net' => localPurchaseTaxCents($net),
        'po_vat_status' => 'No',
        'po_vat_rate' => '0.000000',
        'po_vat_amount' => '0.00',
        'wht_status' => '0.00%',
        'wht_rate' => '0.000000',
        'wht_amount' => '0.00',
        'po_value' => localPurchaseTaxCents($base),
        'advance_base_amount' => localPurchaseTaxCents($base),
    ];
}

function localPurchaseTaxCalculateAdvanceExpected(string $advanceBase, mixed $percentage): string
{
    $baseCents = localPurchaseTaxMoneyToCents($advanceBase, 'Advance Base');
    $percentageUnits = localPurchaseTaxPercentUnits($percentage);
    return localPurchaseTaxCents(
        localPurchaseTaxMultiplyDivideRounded(
            $baseCents,
            $percentageUnits,
            LOCAL_PURCHASE_TAX_PERCENT_MAX
        )
    );
}

function localPurchaseTaxJson(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($json === false) {
        throw new RuntimeException('Unable to serialize reconciliation audit data.');
    }
    return $json;
}

function localPurchaseTaxLog(
    mysqli $conn,
    string $runId,
    string $sourceType,
    int $sourceId,
    ?int $accountRequestId,
    string $action,
    string $resultStatus,
    ?string $reason,
    ?array $before,
    ?array $after
): void {
    $beforeJson = $before === null ? null : localPurchaseTaxJson($before);
    $afterJson = $after === null ? null : localPurchaseTaxJson($after);
    $stmt = $conn->prepare(
        'INSERT INTO local_purchase_vat_wht_reconciliation_log
            (run_id, source_type, source_id, account_request_id, action,
             result_status, reason, before_json, after_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'ssiisssss',
        $runId,
        $sourceType,
        $sourceId,
        $accountRequestId,
        $action,
        $resultStatus,
        $reason,
        $beforeJson,
        $afterJson
    );
    $stmt->execute();
    $stmt->close();
}

function localPurchaseTaxHasActiveSupplierPayment(mysqli $conn, int $requestId): bool
{
    accountPaymentStorageCanonicalReadsEnabled($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $stmt = $conn->prepare(
        "SELECT 1
         FROM account_payment_batch_items
         WHERE request_type = 'local_final_purchase'
           AND request_id = ?
           AND status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
         LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $active = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $active;
}

function localPurchaseTaxHasActiveAdvancePayment(mysqli $conn, int $requestId): bool
{
    accountPaymentStorageCanonicalReadsEnabled($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE);
    $stmt = $conn->prepare(
        "SELECT 1
         FROM account_payment_batch_items
         WHERE request_type = 'local_advance_purchase'
           AND request_id = ?
           AND status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
         LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $active = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $active;
}

function localPurchaseTaxFinalSafetyReason(mysqli $conn, array $purchase): ?string
{
    $localStatus = trim((string) ($purchase['payment_status'] ?? ''));
    if (strcasecmp($localStatus, 'Pending') !== 0) {
        return "ProcureDesk payment status is $localStatus; processed records require manual accounting review.";
    }

    $approvalStatus = trim((string) ($purchase['approval_status'] ?? ''));
    $requestId = (int) ($purchase['supplier_fund_request_id'] ?? 0);
    if (strcasecmp($approvalStatus, 'Approved') === 0 && $requestId <= 0) {
        return 'The approved purchase has no linked Supplier Fund Request.';
    }

    if ($requestId <= 0) {
        return null;
    }

    if (!localPurchaseTaxTableExists($conn, 'supplier_fund_request_table')) {
        return 'The Supplier Fund Request table is unavailable.';
    }

    $stmt = $conn->prepare(
        'SELECT id, payment_status, payment_batch_id
         FROM supplier_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$request) {
        return 'The linked Supplier Fund Request could not be found.';
    }

    $accountStatus = trim((string) ($request['payment_status'] ?? ''));
    if (strcasecmp($accountStatus, 'Pending') !== 0) {
        return "The linked Supplier Fund Request is $accountStatus; processed records require manual accounting review.";
    }
    if (localPurchaseTaxHasActiveSupplierPayment($conn, $requestId)) {
        return 'The linked Supplier Fund Request belongs to an active payment operation.';
    }
    return null;
}

function localPurchaseTaxAdvancePoSafetyReason(mysqli $conn, int $poId): ?string
{
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT r.id, r.payment_status, r.approval_status, r.advance_payment_request_id,
                apr.payment_status AS account_payment_status
         FROM {$localAdvanceRelation} r
         LEFT JOIN advance_payment_request apr ON apr.id = r.advance_payment_request_id
         WHERE r.po_id = ? AND r.deleted_at IS NULL
         ORDER BY r.id ASC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $purchaseId = (int) $row['id'];
        $localStatus = trim((string) ($row['payment_status'] ?? ''));
        if (strcasecmp($localStatus, 'Pending') !== 0) {
            return "Local Advance Purchase $purchaseId is $localStatus; the PO requires manual accounting review.";
        }

        $requestId = (int) ($row['advance_payment_request_id'] ?? 0);
        $approved = strcasecmp((string) ($row['approval_status'] ?? ''), 'Approved') === 0;
        if ($approved && $requestId <= 0) {
            return "Approved Local Advance Purchase $purchaseId has no linked Advance Fund Request.";
        }
        if ($requestId <= 0) {
            continue;
        }

        $accountStatus = trim((string) ($row['account_payment_status'] ?? ''));
        if ($accountStatus === '') {
            return "The linked Advance Fund Request for Local Advance Purchase $purchaseId could not be found.";
        }
        if (strcasecmp($accountStatus, 'Pending') !== 0) {
            return "The linked Advance Fund Request for Local Advance Purchase $purchaseId is $accountStatus; the PO requires manual accounting review.";
        }
        if (localPurchaseTaxHasActiveAdvancePayment($conn, $requestId)) {
            return "The linked Advance Fund Request for Local Advance Purchase $purchaseId belongs to an active payment operation.";
        }
    }
    return null;
}

function localPurchaseTaxFetchFinalCandidates(mysqli $conn, int $limit = 0): array
{
    procurementRequestCanonicalLocalFinalAssertReady($conn);
    $localFinalRelation = procurementRequestCanonicalLocalFinalReadRelation();

    $sql = "SELECT p.*
            FROM {$localFinalRelation} p
            WHERE p.deleted_at IS NULL
              AND (
                    LOWER(TRIM(p.purchase_vat_status)) NOT IN ('yes', 'yes - 7.5%', '7.5%', '7.50%', '1', 'true')
                    OR p.purchase_vat_rate <= 0.000000
                  )
              AND (
                    ABS(p.purchase_vat_amount) > 0.005
                    OR ABS(p.wht_rate) > 0.0000005
                    OR ABS(p.wht_amount) > 0.005
                    OR LOWER(TRIM(p.wht_status)) NOT IN ('', '0', '0%', '0.0%', '0.00%', 'none', 'no')
                  )
            ORDER BY p.id ASC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function localPurchaseTaxFetchAdvanceCandidates(mysqli $conn, int $limit = 0): array
{
    if (!localPurchaseTaxTableExists($conn, 'procurement_local_advance_pos')) {
        return [];
    }

    $sql = "SELECT p.*
            FROM procurement_local_advance_pos p
            WHERE (
                    LOWER(TRIM(p.po_vat_status)) NOT IN ('yes', 'yes - 7.5%', '7.5%', '7.50%', '1', 'true')
                    OR p.po_vat_rate <= 0.000000
                  )
              AND (
                    ABS(p.po_vat_amount) > 0.005
                    OR ABS(p.wht_rate) > 0.0000005
                    OR ABS(p.wht_amount) > 0.005
                    OR LOWER(TRIM(p.wht_status)) NOT IN ('', '0', '0%', '0.0%', '0.00%', 'none', 'no')
                  )
            ORDER BY p.id ASC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function localPurchaseTaxInsertProcurementEvent(
    mysqli $conn,
    string $table,
    string $foreignKey,
    int $purchaseId,
    array $details
): void {
    $requestType = match ($table) {
        'procurement_local_final_purchase_events' => PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        'procurement_local_advance_purchase_events' => PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        default => null,
    };
    if ($requestType !== null) {
        procurementRequestCanonicalRecordEvent(
            $conn,
            $requestType,
            $purchaseId,
            'vat_wht_reconciled',
            0,
            LOCAL_PURCHASE_TAX_RECONCILIATION_ACTOR_EMAIL,
            localPurchaseTaxJson($details)
        );
        return;
    }

    if (!localPurchaseTaxTableExists($conn, $table)) {
        return;
    }
    $eventType = 'vat_wht_reconciled';
    $actorId = 0;
    $actorEmail = LOCAL_PURCHASE_TAX_RECONCILIATION_ACTOR_EMAIL;
    $detailsJson = localPurchaseTaxJson($details);
    $sql = "INSERT INTO `$table`
                (`$foreignKey`, event_type, actor_user_id, actor_email, details_json)
            VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isiss', $purchaseId, $eventType, $actorId, $actorEmail, $detailsJson);
    $stmt->execute();
    $stmt->close();
}

function localPurchaseTaxInsertAccountEvent(
    mysqli $conn,
    string $table,
    string $foreignKey,
    int $requestId,
    array $details
): void {
    $requestType = match ($table) {
        'account_supplier_payment_events' => 'local_final_purchase',
        'account_advance_payment_events' => 'local_advance_purchase',
        default => null,
    };
    if ($requestType === null) {
        return;
    }

    workflowEventRecordPayment(
        $conn,
        $requestType,
        $requestId,
        null,
        'vat_wht_reconciled',
        0,
        LOCAL_PURCHASE_TAX_RECONCILIATION_ACTOR_EMAIL,
        localPurchaseTaxJson($details)
    );
}

function localPurchaseTaxReconcileFinal(
    mysqli $conn,
    string $runId,
    int $purchaseId,
    bool $apply
): array {
    $conn->begin_transaction();
    try {
        procurementRequestCanonicalLocalFinalAssertReady($conn);
        $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
        $stmt = $conn->prepare(
            "SELECT p.* FROM procurement_requests p
             WHERE p.legacy_source_id = ? AND {$localFinalScope}
               AND p.deleted_at IS NULL FOR UPDATE"
        );
        $stmt->bind_param('i', $purchaseId);
        $stmt->execute();
        $purchase = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($purchase) {
            // Preserve the historical Local Final public-row shape for reconciliation logic.
            $purchase['id'] = (int) ($purchase['legacy_source_id'] ?? 0);
            $purchase['supplier_fund_request_id'] = $purchase['account_request_id'] ?? null;
            $purchase['previous_supplier_fund_request_id'] = $purchase['previous_account_request_id'] ?? null;
        }
        if (!$purchase) {
            throw new RuntimeException('Local Final Purchase no longer exists.');
        }

        $vatCharged = localPurchaseTaxIsVatCharged(
            $purchase['purchase_vat_status'] ?? '',
            $purchase['purchase_vat_rate'] ?? 0
        );
        if ($vatCharged) {
            $conn->rollback();
            return ['id' => $purchaseId, 'status' => 'skipped', 'reason' => 'VAT is charged.'];
        }

        $reason = localPurchaseTaxFinalSafetyReason($conn, $purchase);
        $calculated = localPurchaseTaxCalculateNoVatFinal($purchase);
        $requestId = (int) ($purchase['supplier_fund_request_id'] ?? 0);
        $before = [
            'purchase_vat_status' => $purchase['purchase_vat_status'],
            'purchase_vat_rate' => $purchase['purchase_vat_rate'],
            'purchase_vat_amount' => $purchase['purchase_vat_amount'],
            'wht_status' => $purchase['wht_status'],
            'wht_rate' => $purchase['wht_rate'],
            'wht_amount' => $purchase['wht_amount'],
            'purchase_value' => $purchase['purchase_value'],
        ];

        if ($reason !== null) {
            if ($apply) {
                localPurchaseTaxLog(
                    $conn,
                    $runId,
                    'local_final_purchase',
                    $purchaseId,
                    $requestId > 0 ? $requestId : null,
                    'manual_review',
                    'manual_review',
                    $reason,
                    $before,
                    $calculated
                );
                $conn->commit();
            } else {
                $conn->rollback();
            }
            return [
                'id' => $purchaseId,
                'po_number' => $purchase['po_number'] ?? null,
                'status' => 'manual_review',
                'reason' => $reason,
                'before' => $before,
                'proposed' => $calculated,
            ];
        }

        if (!$apply) {
            $conn->rollback();
            return [
                'id' => $purchaseId,
                'po_number' => $purchase['po_number'] ?? null,
                'status' => 'ready',
                'before' => $before,
                'proposed' => $calculated,
            ];
        }

        $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET purchase_vat_status = 'No', purchase_vat_rate = 0.000000,
                 purchase_vat_amount = 0.00, wht_status = '0.00%',
                 wht_rate = 0.000000, wht_amount = 0.00,
                 purchase_value = ?, version = version + 1, updated_at = NOW()
             WHERE legacy_source_id = ? AND {$canonicalScope}"
        );
        $purchaseValue = $calculated['purchase_value'];
        $update->bind_param('si', $purchaseValue, $purchaseId);
        $update->execute();
        $update->close();

        if ($requestId > 0) {
            $account = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET vat_policy = '0.00%', vat = '0.00', wht = '0.00', amount = ?
                 WHERE id = ? AND payment_status = 'Pending'"
            );
            $accountPayable = $calculated['account_payable'];
            $account->bind_param('si', $accountPayable, $requestId);
            $account->execute();
            $account->close();

            $verifyAccount = $conn->prepare(
                'SELECT payment_status FROM supplier_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $verifyAccount->bind_param('i', $requestId);
            $verifyAccount->execute();
            $verifiedAccount = $verifyAccount->get_result()->fetch_assoc();
            $verifyAccount->close();
            if (!$verifiedAccount || strcasecmp((string) $verifiedAccount['payment_status'], 'Pending') !== 0) {
                throw new RuntimeException('The linked Supplier Fund Request changed during reconciliation.');
            }
        }

        $details = [
            'run_id' => $runId,
            'rule' => 'VAT 0% forces WHT 0%',
            'before' => $before,
            'after' => $calculated,
        ];
        localPurchaseTaxInsertProcurementEvent(
            $conn,
            'procurement_local_final_purchase_events',
            'purchase_id',
            $purchaseId,
            $details
        );
        if ($requestId > 0) {
            localPurchaseTaxInsertAccountEvent(
                $conn,
                'account_supplier_payment_events',
                'supplier_fund_request_id',
                $requestId,
                $details
            );
        }
        localPurchaseTaxLog(
            $conn,
            $runId,
            'local_final_purchase',
            $purchaseId,
            $requestId > 0 ? $requestId : null,
            'reconciled',
            'success',
            null,
            $before,
            $calculated
        );
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
            $purchaseId
        );
        $conn->commit();
        return [
            'id' => $purchaseId,
            'po_number' => $purchase['po_number'] ?? null,
            'status' => 'reconciled',
            'before' => $before,
            'after' => $calculated,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function localPurchaseTaxReconcileAdvancePo(
    mysqli $conn,
    string $runId,
    int $poId,
    bool $apply
): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT * FROM procurement_local_advance_pos WHERE id = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $poId);
        $stmt->execute();
        $po = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$po) {
            throw new RuntimeException('Local Advance PO no longer exists.');
        }

        $vatCharged = localPurchaseTaxIsVatCharged($po['po_vat_status'] ?? '', $po['po_vat_rate'] ?? 0);
        if ($vatCharged) {
            $conn->rollback();
            return ['id' => $poId, 'status' => 'skipped', 'reason' => 'VAT is charged.'];
        }

        $reason = localPurchaseTaxAdvancePoSafetyReason($conn, $poId);
        $calculated = localPurchaseTaxCalculateNoVatAdvancePo($po);
        $before = [
            'po_vat_status' => $po['po_vat_status'],
            'po_vat_rate' => $po['po_vat_rate'],
            'po_vat_amount' => $po['po_vat_amount'],
            'wht_status' => $po['wht_status'],
            'wht_rate' => $po['wht_rate'],
            'wht_amount' => $po['wht_amount'],
            'po_value' => $po['po_value'],
            'advance_base_amount' => $po['advance_base_amount'],
        ];

        procurementRequestCanonicalLocalAdvanceAssertReady($conn);
        $localAdvanceScope = procurementRequestCanonicalLocalAdvanceScopeSql('r');
        $purchaseStmt = $conn->prepare(
            "SELECT r.*, r.legacy_source_id AS id,
                    r.account_request_id AS advance_payment_request_id,
                    r.previous_account_request_id AS previous_advance_payment_request_id,
                    r.request_variant AS request_type,
                    r.legacy_parent_purchase_id AS parent_purchase_id,
                    apr.id AS account_request_id, apr.payment_status AS account_payment_status
             FROM procurement_requests r
             LEFT JOIN advance_payment_request apr ON apr.id = r.account_request_id
             WHERE r.po_id = ? AND {$localAdvanceScope} AND r.deleted_at IS NULL
             ORDER BY r.legacy_source_id ASC FOR UPDATE"
        );
        $purchaseStmt->bind_param('i', $poId);
        $purchaseStmt->execute();
        $purchases = $purchaseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $purchaseStmt->close();

        $proposedRequests = [];
        foreach ($purchases as $purchase) {
            $proposedRequests[] = [
                'purchase_id' => (int) $purchase['id'],
                'account_request_id' => (int) ($purchase['advance_payment_request_id'] ?? 0) ?: null,
                'po_percentage' => (string) $purchase['po_percentage'],
                'expected_payment' => localPurchaseTaxCalculateAdvanceExpected(
                    $calculated['advance_base_amount'],
                    $purchase['po_percentage']
                ),
            ];
        }
        $proposed = $calculated + ['requests' => $proposedRequests];

        if ($reason !== null) {
            if ($apply) {
                localPurchaseTaxLog(
                    $conn,
                    $runId,
                    'local_advance_po',
                    $poId,
                    null,
                    'manual_review',
                    'manual_review',
                    $reason,
                    $before,
                    $proposed
                );
                $conn->commit();
            } else {
                $conn->rollback();
            }
            return [
                'id' => $poId,
                'po_number' => $po['po_number'] ?? null,
                'status' => 'manual_review',
                'reason' => $reason,
                'before' => $before,
                'proposed' => $proposed,
            ];
        }

        if (!$apply) {
            $conn->rollback();
            return [
                'id' => $poId,
                'po_number' => $po['po_number'] ?? null,
                'status' => 'ready',
                'before' => $before,
                'proposed' => $proposed,
            ];
        }

        $updatePo = $conn->prepare(
            "UPDATE procurement_local_advance_pos
             SET po_vat_status = 'No', po_vat_rate = 0.000000, po_vat_amount = 0.00,
                 wht_status = '0.00%', wht_rate = 0.000000, wht_amount = 0.00,
                 po_value = ?, advance_base_amount = ?,
                 version = version + 1, updated_at = NOW()
             WHERE id = ?"
        );
        $poValue = $calculated['po_value'];
        $advanceBase = $calculated['advance_base_amount'];
        $updatePo->bind_param('ssi', $poValue, $advanceBase, $poId);
        $updatePo->execute();
        $updatePo->close();

        foreach ($purchases as $purchase) {
            $purchaseId = (int) $purchase['id'];
            $expectedPayment = localPurchaseTaxCalculateAdvanceExpected(
                $calculated['advance_base_amount'],
                $purchase['po_percentage']
            );
            $canonicalScope = procurementRequestCanonicalLocalAdvanceScopeSql();
            $updatePurchase = $conn->prepare(
                "UPDATE procurement_requests
                 SET expected_payment = ?, version = version + 1, updated_at = NOW()
                 WHERE legacy_source_id = ? AND {$canonicalScope}
                   AND payment_status = 'Pending'"
            );
            $updatePurchase->bind_param('si', $expectedPayment, $purchaseId);
            $updatePurchase->execute();
            $updatePurchase->close();

            $verifyPurchase = $conn->prepare(
                "SELECT payment_status FROM procurement_requests
                 WHERE legacy_source_id = ? AND {$canonicalScope} LIMIT 1 FOR UPDATE"
            );
            $verifyPurchase->bind_param('i', $purchaseId);
            $verifyPurchase->execute();
            $verifiedPurchase = $verifyPurchase->get_result()->fetch_assoc();
            $verifyPurchase->close();
            if (!$verifiedPurchase || strcasecmp((string) $verifiedPurchase['payment_status'], 'Pending') !== 0) {
                throw new RuntimeException("Local Advance Purchase $purchaseId changed during reconciliation.");
            }

            $requestId = (int) ($purchase['advance_payment_request_id'] ?? 0);
            if ($requestId > 0) {
                $updateAccount = $conn->prepare(
                    "UPDATE advance_payment_request
                     SET vat = '0.00', vat_rate = 0.000000, vat_status = '0.00%',
                         wht_status = '0.00%', wht_rate = 0.000000, wht = '0.00',
                         amount_payable = ?, advance_payment = ?
                     WHERE id = ? AND payment_status = 'Pending'"
                );
                $netAmount = $calculated['po_net'];
                $updateAccount->bind_param('ssi', $netAmount, $expectedPayment, $requestId);
                $updateAccount->execute();
                $updateAccount->close();

                $verifyAccount = $conn->prepare(
                    'SELECT payment_status FROM advance_payment_request WHERE id = ? LIMIT 1 FOR UPDATE'
                );
                $verifyAccount->bind_param('i', $requestId);
                $verifyAccount->execute();
                $verifiedAccount = $verifyAccount->get_result()->fetch_assoc();
                $verifyAccount->close();
                if (!$verifiedAccount || strcasecmp((string) $verifiedAccount['payment_status'], 'Pending') !== 0) {
                    throw new RuntimeException("The linked Advance Fund Request $requestId changed during reconciliation.");
                }
            }

            $details = [
                'run_id' => $runId,
                'rule' => 'VAT 0% forces WHT 0%',
                'po_before' => $before,
                'po_after' => $calculated,
                'expected_payment_before' => $purchase['expected_payment'],
                'expected_payment_after' => $expectedPayment,
            ];
            localPurchaseTaxInsertProcurementEvent(
                $conn,
                'procurement_local_advance_purchase_events',
                'purchase_id',
                $purchaseId,
                $details
            );
            if ($requestId > 0) {
                localPurchaseTaxInsertAccountEvent(
                    $conn,
                    'account_advance_payment_events',
                    'advance_payment_request_id',
                    $requestId,
                    $details
                );
            }
        }

        localPurchaseTaxLog(
            $conn,
            $runId,
            'local_advance_po',
            $poId,
            null,
            'reconciled',
            'success',
            null,
            $before,
            $proposed
        );
        procurementRequestCanonicalSyncAdvancePo($conn, $poId);
        $conn->commit();
        return [
            'id' => $poId,
            'po_number' => $po['po_number'] ?? null,
            'status' => 'reconciled',
            'before' => $before,
            'after' => $proposed,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
