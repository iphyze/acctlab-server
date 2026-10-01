<?php

declare(strict_types=1);

const ACCOUNT_SUPPLIER_WHT_DEFAULT_VAT_BASIS_POINTS = 750; // 7.50%
const ACCOUNT_SUPPLIER_WHT_MAX_PLAUSIBLE_BASIS_POINTS = 1000; // 10.00%
const ACCOUNT_SUPPLIER_WHT_RECONCILIATION_TOLERANCE_CENTS = 100; // NGN 1.00
const ACCOUNT_SUPPLIER_WHT_PAYMENT_STATUSES = ['Pending', 'Processing', 'Paid', 'Failed', 'Cancelled', 'Unconfirmed'];

function accountSupplierWhtMoneyToCents(mixed $value): int
{
    if ($value === null) {
        return 0;
    }

    $text = trim(str_replace([',', '₦', 'NGN'], '', (string) $value));
    if ($text === '' || !is_numeric($text)) {
        return 0;
    }

    return (int) round(((float) $text) * 100, 0, PHP_ROUND_HALF_UP);
}

function accountSupplierWhtCentsToMoney(int $cents): float
{
    return round($cents / 100, 2);
}

function accountSupplierWhtRateBasisPoints(mixed $value): int
{
    if ($value === null) {
        return 0;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return 0;
    }

    $text = str_replace(['%', ',', ' '], '', $text);
    if (!is_numeric($text)) {
        return 0;
    }

    $rate = (float) $text;
    // Decimal database rates such as 0.05 mean 5%; legacy labels such as 5.00 mean 5%.
    if ($rate > 0 && $rate <= 1) {
        $rate *= 100;
    }
    return max(0, (int) round($rate * 100, 0, PHP_ROUND_HALF_UP));
}

function accountSupplierWhtRateAmount(int $baseCents, int $basisPoints): int
{
    if ($baseCents <= 0 || $basisPoints <= 0) {
        return 0;
    }
    return (int) round(($baseCents * $basisPoints) / 10000, 0, PHP_ROUND_HALF_UP);
}

function accountSupplierWhtNormalizePolicy(mixed $value): string
{
    return strtolower(trim((string) $value));
}

function accountSupplierWhtPolicySignalsVat(string $policy): bool
{
    if ($policy === '') {
        return false;
    }

    $basisPoints = accountSupplierWhtRateBasisPoints($policy);
    if ($basisPoints > 0) {
        return true;
    }

    // Historical free-text values are not automatically trusted. They can still be
    // recovered later when the payable amount exactly matches a recognised WHT fingerprint.
    return false;
}

function accountSupplierWhtLegacyFingerprintMatches(int $baseCents, int $gapCents, int $supplierRateBasisPoints): bool
{
    if ($baseCents <= 0 || $gapCents < 0) {
        return false;
    }

    $candidateRates = [200, 500, 1000];
    if ($supplierRateBasisPoints > 0 && $supplierRateBasisPoints <= ACCOUNT_SUPPLIER_WHT_MAX_PLAUSIBLE_BASIS_POINTS) {
        $candidateRates[] = $supplierRateBasisPoints;
    }
    $candidateRates = array_values(array_unique($candidateRates));

    foreach ($candidateRates as $basisPoints) {
        $expected = accountSupplierWhtRateAmount($baseCents, $basisPoints);
        if (abs($expected - $gapCents) <= ACCOUNT_SUPPLIER_WHT_RECONCILIATION_TOLERANCE_CENTS) {
            return true;
        }
    }
    return false;
}

/**
 * Calculate the WHT represented by one Supplier Fund Request line.
 *
 * The source table spans several generations of the application:
 * - current rows store VAT/WHT explicitly and may have an Account WHT override;
 * - legacy rows often store VAT/WHT as zero even though `amount` is the payable
 *   after withholding. For those rows the gross is reconstructed from the taxable
 *   base plus 7.5% VAT and compared with the payable amount;
 * - rows with no evidence that VAT was charged are never assigned hypothetical WHT.
 */
function accountSupplierWhtCalculateLine(array $row): array
{
    $subtotalCents = accountSupplierWhtMoneyToCents($row['net_value'] ?? 0);
    $discountCents = accountSupplierWhtMoneyToCents($row['discount'] ?? 0);
    $taxableBaseCents = $subtotalCents - $discountCents;
    $otherChargesCents = accountSupplierWhtMoneyToCents($row['other_charges'] ?? 0);
    $payableCents = accountSupplierWhtMoneyToCents($row['amount'] ?? 0);
    $storedVatCents = accountSupplierWhtMoneyToCents($row['vat'] ?? 0);
    $storedWhtCents = accountSupplierWhtMoneyToCents($row['wht'] ?? 0);
    $overrideStatus = trim((string) ($row['wht_override_status'] ?? ''));
    $overrideRaw = $row['wht_override_amount'] ?? null;
    $policy = accountSupplierWhtNormalizePolicy($row['vat_policy'] ?? '');
    $supplierRateBasisPoints = accountSupplierWhtRateBasisPoints($row['supplier_wht_status'] ?? '');

    $result = [
        'eligible' => true,
        'review_required' => false,
        'review_reason' => null,
        'method' => 'none',
        'vat_charged' => false,
        'taxable_base' => accountSupplierWhtCentsToMoney(max(0, $taxableBaseCents)),
        'vat_amount' => 0.0,
        'gross_amount' => 0.0,
        'payable_amount' => accountSupplierWhtCentsToMoney($payableCents),
        'wht_amount' => 0.0,
        'wht_rate' => 0.0,
        'reconciliation_difference' => 0.0,
    ];

    if ($taxableBaseCents < 0) {
        $result['eligible'] = false;
        $result['review_required'] = true;
        $result['review_reason'] = 'Discount exceeds the supplier subtotal.';
        $result['method'] = 'review';
        return $result;
    }

    $policySignalsVat = accountSupplierWhtPolicySignalsVat($policy);
    $hasStoredVat = $storedVatCents > 0;
    $vatCharged = $hasStoredVat || $policySignalsVat;
    $vatCents = $hasStoredVat
        ? $storedVatCents
        : ($vatCharged ? accountSupplierWhtRateAmount($taxableBaseCents, ACCOUNT_SUPPLIER_WHT_DEFAULT_VAT_BASIS_POINTS) : 0);
    $grossCents = $taxableBaseCents + $vatCents + $otherChargesCents;
    $grossLessPayableCents = $grossCents - $payableCents;

    // Explicit Account override is authoritative, including an intentional zero override.
    if ($overrideStatus !== '' && $overrideRaw !== null && trim((string) $overrideRaw) !== '') {
        $whtCents = max(0, accountSupplierWhtMoneyToCents($overrideRaw));
        $result['vat_charged'] = $vatCharged || $whtCents > 0;
        $result['vat_amount'] = accountSupplierWhtCentsToMoney($vatCents);
        $result['gross_amount'] = accountSupplierWhtCentsToMoney($grossCents);
        $result['wht_amount'] = accountSupplierWhtCentsToMoney($whtCents);
        $result['wht_rate'] = $taxableBaseCents > 0 ? round(($whtCents / $taxableBaseCents) * 100, 4) : 0.0;
        $result['method'] = 'explicit_override';
        if ($vatCharged) {
            $difference = $grossLessPayableCents - $whtCents;
            $result['reconciliation_difference'] = accountSupplierWhtCentsToMoney($difference);
            if (abs($difference) > ACCOUNT_SUPPLIER_WHT_RECONCILIATION_TOLERANCE_CENTS) {
                $result['review_required'] = true;
                $result['review_reason'] = 'Explicit WHT override does not reconcile to gross less payable.';
            }
        }
        return $result;
    }

    // Stored WHT is the next most reliable source. Do not replace it with a reconstructed figure.
    if ($storedWhtCents > 0) {
        $result['vat_charged'] = true;
        if (!$vatCharged) {
            // Explicit WHT proves tax treatment even if the old VAT column was not populated.
            $vatCharged = true;
            $vatCents = accountSupplierWhtRateAmount($taxableBaseCents, ACCOUNT_SUPPLIER_WHT_DEFAULT_VAT_BASIS_POINTS);
            $grossCents = $taxableBaseCents + $vatCents + $otherChargesCents;
            $grossLessPayableCents = $grossCents - $payableCents;
        }
        $result['vat_amount'] = accountSupplierWhtCentsToMoney($vatCents);
        $result['gross_amount'] = accountSupplierWhtCentsToMoney($grossCents);
        $result['wht_amount'] = accountSupplierWhtCentsToMoney($storedWhtCents);
        $result['wht_rate'] = $taxableBaseCents > 0 ? round(($storedWhtCents / $taxableBaseCents) * 100, 4) : 0.0;
        $result['method'] = 'stored_wht';
        $difference = $grossLessPayableCents - $storedWhtCents;
        $result['reconciliation_difference'] = accountSupplierWhtCentsToMoney($difference);
        if (abs($difference) > ACCOUNT_SUPPLIER_WHT_RECONCILIATION_TOLERANCE_CENTS) {
            $result['review_required'] = true;
            $result['review_reason'] = 'Stored WHT does not reconcile to gross less payable.';
        }
        return $result;
    }

    // No VAT evidence means no WHT is inferred. This prevents 0%-VAT legacy lines
    // from being incorrectly assigned 7.5% WHT merely from a hypothetical gross.
    if (!$vatCharged) {
        if ($policy === '' || accountSupplierWhtRateBasisPoints($policy) === 0) {
            // One narrow legacy recovery path: a free-text policy may still represent a VAT line.
            // Only accept it when the reconstructed gross/payable gap exactly matches a standard
            // WHT fingerprint (or the supplier master rate).
            $legacyVatCents = accountSupplierWhtRateAmount($taxableBaseCents, ACCOUNT_SUPPLIER_WHT_DEFAULT_VAT_BASIS_POINTS);
            $legacyGrossCents = $taxableBaseCents + $legacyVatCents + $otherChargesCents;
            $legacyGapCents = $legacyGrossCents - $payableCents;
            if ($policy !== '' && $policy !== '0.00%' && $legacyGapCents >= 0
                && accountSupplierWhtLegacyFingerprintMatches($taxableBaseCents, $legacyGapCents, $supplierRateBasisPoints)) {
                $vatCharged = true;
                $vatCents = $legacyVatCents;
                $grossCents = $legacyGrossCents;
                $grossLessPayableCents = $legacyGapCents;
                $result['method'] = 'legacy_tax_fingerprint';
            } else {
                $result['method'] = 'no_vat_no_wht';
                $result['gross_amount'] = accountSupplierWhtCentsToMoney($taxableBaseCents + $otherChargesCents);
                return $result;
            }
        }
    }

    $result['vat_charged'] = $vatCharged;
    $result['vat_amount'] = accountSupplierWhtCentsToMoney($vatCents);
    $result['gross_amount'] = accountSupplierWhtCentsToMoney($grossCents);

    if ($grossLessPayableCents < -ACCOUNT_SUPPLIER_WHT_RECONCILIATION_TOLERANCE_CENTS) {
        $result['eligible'] = false;
        $result['review_required'] = true;
        $result['review_reason'] = 'Payable amount exceeds the reconstructed supplier gross.';
        $result['method'] = 'review';
        $result['reconciliation_difference'] = accountSupplierWhtCentsToMoney($grossLessPayableCents);
        return $result;
    }

    $whtCents = max(0, $grossLessPayableCents);
    if ($whtCents <= ACCOUNT_SUPPLIER_WHT_RECONCILIATION_TOLERANCE_CENTS) {
        $whtCents = 0;
    }
    $basisPoints = $taxableBaseCents > 0
        ? (int) round(($whtCents * 10000) / $taxableBaseCents, 0, PHP_ROUND_HALF_UP)
        : 0;

    // Gross-less-payable is the requested legacy rule, but unusually large deductions are
    // excluded from totals rather than silently described as WHT.
    if ($basisPoints > ACCOUNT_SUPPLIER_WHT_MAX_PLAUSIBLE_BASIS_POINTS) {
        $result['eligible'] = false;
        $result['review_required'] = true;
        $result['review_reason'] = 'Gross less payable implies WHT above 10%; review this source line.';
        $result['method'] = 'review';
        $result['reconciliation_difference'] = accountSupplierWhtCentsToMoney($grossLessPayableCents);
        return $result;
    }

    $result['wht_amount'] = accountSupplierWhtCentsToMoney($whtCents);
    $result['wht_rate'] = $taxableBaseCents > 0 ? round(($whtCents / $taxableBaseCents) * 100, 4) : 0.0;
    if ($result['method'] !== 'legacy_tax_fingerprint') {
        $result['method'] = $hasStoredVat ? 'stored_vat_gross_less_payable' : 'legacy_7_5_vat_gross_less_payable';
    }
    return $result;
}

function accountSupplierWhtSafeSourceDateExpression(string $column): string
{
    return "CASE
        WHEN TRIM({$column}) REGEXP '^20[0-9]{2}-[0-9]{2}-[0-9]{2}$'
        THEN STR_TO_DATE(TRIM({$column}), '%Y-%m-%d')
        ELSE NULL
    END";
}

function accountSupplierWhtEffectiveDateExpression(string $alias = 'sfr'): string
{
    $dateReceived = accountSupplierWhtSafeSourceDateExpression("{$alias}.date_received");
    $purchaseDate = accountSupplierWhtSafeSourceDateExpression("{$alias}.purchase_date");
    $invoiceDate = accountSupplierWhtSafeSourceDateExpression("{$alias}.invoice_date");

    return "COALESCE(
        {$dateReceived},
        {$purchaseDate},
        {$invoiceDate},
        DATE({$alias}.created_at)
    )";
}

function accountSupplierWhtPlausibleDate(mixed $value): ?string
{
    $text = trim((string) ($value ?? ''));
    if (!preg_match('/^(20\d{2}-\d{2}-\d{2})(?:[ T]|$)/', $text, $match)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $match[1]) {
        return null;
    }
    return $match[1];
}

function accountSupplierWhtResolveReportDate(array $row): ?string
{
    foreach (['effective_date', 'date_received', 'purchase_date', 'invoice_date', 'created_at'] as $field) {
        $date = accountSupplierWhtPlausibleDate($row[$field] ?? null);
        if ($date !== null) {
            return $date;
        }
    }
    return null;
}

function accountSupplierWhtValidateDate(mixed $value, string $label): ?string
{
    $date = trim((string) ($value ?? ''));
    if ($date === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException("$label must use YYYY-MM-DD format.", 400);
    }
    return $date;
}

function accountSupplierWhtNormalizePaymentStatusFilter(mixed $value): string
{
    $status = trim((string) ($value ?? ''));
    if ($status === '') {
        return 'Paid';
    }
    if (strcasecmp($status, 'all') === 0) {
        return 'all';
    }

    foreach (ACCOUNT_SUPPLIER_WHT_PAYMENT_STATUSES as $allowedStatus) {
        if (strcasecmp($status, $allowedStatus) === 0) {
            return $allowedStatus;
        }
    }

    throw new RuntimeException('Invalid Supplier WHT payment status filter.', 400);
}

function accountSupplierWhtBind(mysqli_stmt $stmt, string $types, array $params): void
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

function accountSupplierWhtSupplierFilterIdentity(mysqli $conn, int $supplierId): ?array
{
    if ($supplierId <= 0) {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, supplier_number, supplier_name FROM suppliers_table WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to resolve the selected supplier.', 500);
    }
    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }
    return [
        'id' => (int) ($row['id'] ?? $supplierId),
        'supplier_number' => (int) ($row['supplier_number'] ?? 0),
        'supplier_name' => trim((string) ($row['supplier_name'] ?? '')),
    ];
}

function accountSupplierWhtSupplierGroupKey(string $supplierName, int $supplierId): string
{
    $normalizedName = strtolower(trim((string) preg_replace('/\s+/', ' ', $supplierName)));
    if ($normalizedName !== '') {
        return 'name:' . $normalizedName;
    }
    return 'id:' . $supplierId;
}

function accountSupplierWhtSourceRows(mysqli $conn, array $filters, bool $includeDetailFields = false): array
{
    $supplierId = isset($filters['supplier_id']) && trim((string) $filters['supplier_id']) !== ''
        ? (int) $filters['supplier_id']
        : 0;
    if ($supplierId < 0) {
        throw new RuntimeException('Supplier ID must be valid.', 400);
    }
    $supplierName = trim((string) ($filters['supplier'] ?? $filters['supplier_name'] ?? ''));
    $fromDate = accountSupplierWhtValidateDate($filters['from_date'] ?? $filters['from'] ?? null, 'From date');
    $toDate = accountSupplierWhtValidateDate($filters['to_date'] ?? $filters['to'] ?? null, 'To date');
    if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
        throw new RuntimeException('From date cannot be later than To date.', 400);
    }
    $paymentStatus = accountSupplierWhtNormalizePaymentStatusFilter(
        $filters['payment_status'] ?? $filters['status'] ?? null
    );

    $effectiveDate = accountSupplierWhtEffectiveDateExpression('sfr');
    $canonicalSupplierName = "COALESCE(
        NULLIF(TRIM(st_id.supplier_name), ''),
        NULLIF(TRIM(st_number.supplier_name), ''),
        NULLIF(TRIM(st_name.supplier_name), ''),
        sfr.suppliers_name
    )";
    $where = ['1 = 1'];
    $types = '';
    $params = [];

    if ($paymentStatus !== 'all') {
        $where[] = "LOWER(COALESCE(NULLIF(TRIM(sfr.payment_status), ''), 'Pending')) = LOWER(?)";
        $types .= 's';
        $params[] = $paymentStatus;
    }

    if ($supplierId > 0) {
        $identity = accountSupplierWhtSupplierFilterIdentity($conn, $supplierId);
        if ($identity !== null && $identity['supplier_name'] !== '') {
            $where[] = '(sfr.supplier_id = ? OR sfr.supplier_id = ? OR LOWER(TRIM(sfr.suppliers_name)) = LOWER(?))';
            $types .= 'iis';
            $params[] = $identity['id'];
            $params[] = $identity['supplier_number'];
            $params[] = $identity['supplier_name'];
        } else {
            $where[] = 'sfr.supplier_id = ?';
            $types .= 'i';
            $params[] = $supplierId;
        }
    } elseif ($supplierName !== '') {
        $where[] = "LOWER(TRIM({$canonicalSupplierName})) = LOWER(?)";
        $types .= 's';
        $params[] = $supplierName;
    }
    if ($fromDate !== null) {
        $where[] = "$effectiveDate >= ?";
        $types .= 's';
        $params[] = $fromDate;
    }
    if ($toDate !== null) {
        $where[] = "$effectiveDate <= ?";
        $types .= 's';
        $params[] = $toDate;
    }

    $detailFields = $includeDetailFields
        ? ",
                sfr.suppliers_name AS source_supplier_name, sfr.supplier_id AS source_supplier_id,
                sfr.invoice_month, sfr.purchase_month, sfr.payment_percentage, sfr.note,
                sfr.wht_override_reason, sfr.wht_override_at,
                sfr.processing_method, sfr.processing_reference, sfr.processing_started_at,
                sfr.processing_business_days, sfr.expected_completion_at, sfr.completion_mode,
                sfr.payment_confirmation_status, sfr.amount_paid, sfr.supplier_credit_applied,
                sfr.cash_amount_paid, sfr.paid_at, sfr.payment_reference, sfr.account_remarks,
                sfr.payment_batch_id, sfr.procurement_source, sfr.procurement_purchase_id,
                sfr.procurement_revision, sfr.updated_at"
        : '';

    $sql = "SELECT
                sfr.id,
                COALESCE(st_id.id, st_number.id, st_name.id, NULLIF(sfr.supplier_id, 0), 0) AS supplier_id,
                {$canonicalSupplierName} AS supplier_name,
                COALESCE(st_id.wht_status, st_number.wht_status, st_name.wht_status) AS supplier_wht_status,
                sfr.invoice_number, sfr.purchase_number, sfr.po_number,
                sfr.invoice_date, sfr.purchase_date, sfr.date_received, sfr.created_at,
                sfr.project_code, sfr.description, sfr.vat_policy, sfr.vat, sfr.wht,
                sfr.wht_override_status, sfr.wht_override_rate, sfr.wht_override_amount,
                sfr.net_value, sfr.discount, sfr.other_charges, sfr.amount, sfr.payment_status,
                $effectiveDate AS effective_date
                {$detailFields}
            FROM supplier_fund_request_table sfr
            LEFT JOIN suppliers_table st_id ON st_id.id = sfr.supplier_id
            LEFT JOIN (
                SELECT supplier_number, MIN(id) AS supplier_id
                FROM suppliers_table
                GROUP BY supplier_number
            ) supplier_number_map ON supplier_number_map.supplier_number = sfr.supplier_id
            LEFT JOIN suppliers_table st_number ON st_number.id = supplier_number_map.supplier_id
            LEFT JOIN (
                SELECT LOWER(TRIM(supplier_name)) AS supplier_name_key, MIN(id) AS supplier_id
                FROM suppliers_table
                WHERE TRIM(supplier_name) <> ''
                GROUP BY LOWER(TRIM(supplier_name))
            ) supplier_name_map ON supplier_name_map.supplier_name_key = LOWER(TRIM(sfr.suppliers_name))
            LEFT JOIN suppliers_table st_name ON st_name.id = supplier_name_map.supplier_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY effective_date ASC, supplier_name ASC, sfr.id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Supplier WHT report query.', 500);
    }
    accountSupplierWhtBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function accountSupplierWhtBuildReport(array $sourceRows, array $filters = []): array
{
    $supplierBuckets = [];
    $years = [];
    $methodCounts = [];
    $reviewRows = [];
    $sourceLineCount = 0;
    $vatLineCount = 0;
    $whtLineCount = 0;
    $zeroWhtLineCount = 0;
    $totalWhtCents = 0;
    $yearTotals = [];

    foreach ($sourceRows as $row) {
        $sourceLineCount++;
        $calculation = accountSupplierWhtCalculateLine($row);
        $method = (string) ($calculation['method'] ?? 'unknown');
        $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;

        if (!empty($calculation['vat_charged'])) {
            $vatLineCount++;
        }
        if (!empty($calculation['review_required'])) {
            $reviewRows[] = [
                'request_id' => (int) ($row['id'] ?? 0),
                'supplier_id' => (int) ($row['supplier_id'] ?? 0),
                'supplier' => (string) ($row['supplier_name'] ?? $row['suppliers_name'] ?? ''),
                'invoice_number' => (string) ($row['invoice_number'] ?? ''),
                'purchase_number' => (string) ($row['purchase_number'] ?? ''),
                'effective_date' => (string) ($row['effective_date'] ?? ''),
                'reason' => (string) ($calculation['review_reason'] ?? 'Review required.'),
                'method' => $method,
                'reconciliation_difference' => (float) ($calculation['reconciliation_difference'] ?? 0),
            ];
        }
        if (empty($calculation['eligible'])) {
            continue;
        }

        $whtCents = accountSupplierWhtMoneyToCents($calculation['wht_amount'] ?? 0);
        if ($whtCents <= 0) {
            $zeroWhtLineCount++;
            continue;
        }
        $whtLineCount++;

        $effectiveDate = accountSupplierWhtResolveReportDate($row);
        $year = $effectiveDate !== null ? (int) substr($effectiveDate, 0, 4) : 0;
        if ($year <= 0) {
            $reviewRows[] = [
                'request_id' => (int) ($row['id'] ?? 0),
                'supplier_id' => (int) ($row['supplier_id'] ?? 0),
                'supplier' => (string) ($row['supplier_name'] ?? $row['suppliers_name'] ?? ''),
                'invoice_number' => (string) ($row['invoice_number'] ?? ''),
                'purchase_number' => (string) ($row['purchase_number'] ?? ''),
                'effective_date' => (string) ($row['effective_date'] ?? ''),
                'reason' => 'No valid report year could be resolved for this WHT line.',
                'method' => $method,
                'reconciliation_difference' => 0.0,
            ];
            continue;
        }

        $supplierId = (int) ($row['supplier_id'] ?? 0);
        $supplierName = trim((string) ($row['supplier_name'] ?? $row['suppliers_name'] ?? ''));
        $key = accountSupplierWhtSupplierGroupKey($supplierName, $supplierId);
        if (!isset($supplierBuckets[$key])) {
            $supplierBuckets[$key] = [
                'supplier_id' => $supplierId,
                'supplier' => $supplierName,
                'years_cents' => [],
                'total_cents' => 0,
                'line_count' => 0,
            ];
        } elseif ($supplierId > 0
            && ((int) $supplierBuckets[$key]['supplier_id'] <= 0 || $supplierId < (int) $supplierBuckets[$key]['supplier_id'])) {
            $supplierBuckets[$key]['supplier_id'] = $supplierId;
        }
        $supplierBuckets[$key]['years_cents'][$year] = ($supplierBuckets[$key]['years_cents'][$year] ?? 0) + $whtCents;
        $supplierBuckets[$key]['total_cents'] += $whtCents;
        $supplierBuckets[$key]['line_count']++;
        $yearTotals[$year] = ($yearTotals[$year] ?? 0) + $whtCents;
        $years[$year] = true;
        $totalWhtCents += $whtCents;
    }

    $years = array_keys($years);
    sort($years, SORT_NUMERIC);
    ksort($yearTotals, SORT_NUMERIC);

    $rows = [];
    foreach ($supplierBuckets as $bucket) {
        $yearValues = [];
        foreach ($years as $year) {
            $yearValues[(string) $year] = accountSupplierWhtCentsToMoney((int) ($bucket['years_cents'][$year] ?? 0));
        }
        $rows[] = [
            'supplier_id' => $bucket['supplier_id'],
            'supplier' => $bucket['supplier'],
            'years' => $yearValues,
            'total_wht' => accountSupplierWhtCentsToMoney($bucket['total_cents']),
            'line_count' => $bucket['line_count'],
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string) $a['supplier'], (string) $b['supplier']);
    });
    foreach ($rows as $index => &$reportRow) {
        $reportRow['sn'] = $index + 1;
    }
    unset($reportRow);

    $yearSummary = [];
    foreach ($years as $year) {
        $yearSummary[(string) $year] = accountSupplierWhtCentsToMoney((int) ($yearTotals[$year] ?? 0));
    }
    ksort($methodCounts);

    return [
        'filters' => [
            'supplier_id' => isset($filters['supplier_id']) && trim((string) $filters['supplier_id']) !== ''
                ? (int) $filters['supplier_id']
                : null,
            'supplier' => trim((string) ($filters['supplier'] ?? $filters['supplier_name'] ?? '')) ?: null,
            'from_date' => trim((string) ($filters['from_date'] ?? $filters['from'] ?? '')) ?: null,
            'to_date' => trim((string) ($filters['to_date'] ?? $filters['to'] ?? '')) ?: null,
            'payment_status' => accountSupplierWhtNormalizePaymentStatusFilter(
                $filters['payment_status'] ?? $filters['status'] ?? null
            ),
        ],
        'years' => $years,
        'rows' => $rows,
        'summary' => [
            'supplier_count' => count($rows),
            'source_line_count' => $sourceLineCount,
            'vat_applicable_line_count' => $vatLineCount,
            'wht_line_count' => $whtLineCount,
            'zero_wht_line_count' => $zeroWhtLineCount,
            'review_line_count' => count($reviewRows),
            'by_year' => $yearSummary,
            'total_wht' => accountSupplierWhtCentsToMoney($totalWhtCents),
        ],
        'quality' => [
            'calculation_methods' => $methodCounts,
            'review_rows' => $reviewRows,
        ],
        'calculation_policy' => [
            'vat_rate_fallback' => 7.5,
            'date_basis' => 'date_received, then purchase_date, then invoice_date, then created_at',
            'priority' => [
                'explicit WHT override',
                'stored WHT amount',
                'stored VAT + gross less payable',
                'legacy 7.5% VAT reconstruction + gross less payable',
            ],
            'no_vat_rule' => 'No WHT is inferred where the source line has no reliable evidence that VAT was charged.',
            'review_rule' => 'Implausible or unreconciled lines are surfaced for review instead of being silently added to WHT totals.',
            'payment_status_rule' => 'Paid Supplier Fund Requests are reported by default. Users may explicitly select another payment status or all statuses.',
        ],
    ];
}

function accountSupplierWhtBuildDetailRows(array $sourceRows): array
{
    $detailRows = [];

    foreach ($sourceRows as $row) {
        $calculation = accountSupplierWhtCalculateLine($row);
        if (empty($calculation['eligible'])) {
            continue;
        }

        $whtCents = accountSupplierWhtMoneyToCents($calculation['wht_amount'] ?? 0);
        if ($whtCents <= 0) {
            continue;
        }

        $reportDate = accountSupplierWhtResolveReportDate($row);
        if ($reportDate === null) {
            continue;
        }

        $canonicalSupplierId = (int) ($row['supplier_id'] ?? 0);
        $sourceSupplierId = (int) ($row['source_supplier_id'] ?? $canonicalSupplierId);
        $supplierName = trim((string) ($row['supplier_name'] ?? $row['source_supplier_name'] ?? $row['suppliers_name'] ?? ''));
        $sourceSupplierName = trim((string) ($row['source_supplier_name'] ?? $row['suppliers_name'] ?? $supplierName));
        $accountRemarks = trim((string) ($row['account_remarks'] ?? ''));
        $note = trim((string) ($row['note'] ?? ''));

        $detailRows[] = [
            'request_id' => (int) ($row['id'] ?? 0),
            'supplier_group_key' => accountSupplierWhtSupplierGroupKey($supplierName, $canonicalSupplierId),
            'supplier' => $supplierName !== '' ? $supplierName : $sourceSupplierName,
            'supplier_id' => $sourceSupplierId,
            'canonical_supplier_id' => $canonicalSupplierId,
            'source_supplier_name' => $sourceSupplierName,
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'purchase_number' => (string) ($row['purchase_number'] ?? ''),
            'po_number' => (string) ($row['po_number'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'purchase_date' => (string) ($row['purchase_date'] ?? ''),
            'date_received' => (string) ($row['date_received'] ?? ''),
            'report_date' => $reportDate,
            'invoice_month' => (string) ($row['invoice_month'] ?? ''),
            'purchase_month' => (string) ($row['purchase_month'] ?? ''),
            'project_code' => (string) ($row['project_code'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'vat_policy' => (string) ($row['vat_policy'] ?? ''),
            'vat_amount' => (float) ($calculation['vat_amount'] ?? 0),
            'wht_rate' => (float) ($calculation['wht_rate'] ?? 0),
            'wht_amount' => accountSupplierWhtCentsToMoney($whtCents),
            'net_value' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['net_value'] ?? 0)),
            'discount' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['discount'] ?? 0)),
            'other_charges' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['other_charges'] ?? 0)),
            'gross_amount' => (float) ($calculation['gross_amount'] ?? 0),
            'payable_amount' => (float) ($calculation['payable_amount'] ?? 0),
            'payment_percentage' => (string) ($row['payment_percentage'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'payment_confirmation_status' => (string) ($row['payment_confirmation_status'] ?? ''),
            'processing_method' => (string) ($row['processing_method'] ?? ''),
            'processing_reference' => (string) ($row['processing_reference'] ?? ''),
            'processing_started_at' => (string) ($row['processing_started_at'] ?? ''),
            'processing_business_days' => (string) ($row['processing_business_days'] ?? ''),
            'expected_completion_at' => (string) ($row['expected_completion_at'] ?? ''),
            'completion_mode' => (string) ($row['completion_mode'] ?? ''),
            'amount_paid' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['amount_paid'] ?? 0)),
            'supplier_credit_applied' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['supplier_credit_applied'] ?? 0)),
            'cash_amount_paid' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['cash_amount_paid'] ?? 0)),
            'payment_reference' => (string) ($row['payment_reference'] ?? ''),
            'paid_at' => (string) ($row['paid_at'] ?? ''),
            'account_remarks' => $accountRemarks !== '' ? $accountRemarks : $note,
            'note' => $note,
            'wht_override_status' => (string) ($row['wht_override_status'] ?? ''),
            'wht_override_rate' => $row['wht_override_rate'] ?? '',
            'wht_override_amount' => accountSupplierWhtCentsToMoney(accountSupplierWhtMoneyToCents($row['wht_override_amount'] ?? 0)),
            'wht_override_reason' => (string) ($row['wht_override_reason'] ?? ''),
            'wht_override_at' => (string) ($row['wht_override_at'] ?? ''),
            'procurement_source' => (string) ($row['procurement_source'] ?? ''),
            'procurement_purchase_id' => (string) ($row['procurement_purchase_id'] ?? ''),
            'procurement_revision' => (string) ($row['procurement_revision'] ?? ''),
            'payment_batch_id' => (string) ($row['payment_batch_id'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'calculation_method' => (string) ($calculation['method'] ?? ''),
            'reconciliation_difference' => (float) ($calculation['reconciliation_difference'] ?? 0),
        ];
    }

    usort($detailRows, static function (array $left, array $right): int {
        $supplierCompare = strcasecmp((string) ($left['supplier'] ?? ''), (string) ($right['supplier'] ?? ''));
        if ($supplierCompare !== 0) {
            return $supplierCompare;
        }

        $dateCompare = strcmp((string) ($left['report_date'] ?? ''), (string) ($right['report_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return ((int) ($left['request_id'] ?? 0)) <=> ((int) ($right['request_id'] ?? 0));
    });

    return $detailRows;
}

function accountSupplierWhtExportReport(mysqli $conn, array $filters = []): array
{
    $sourceRows = accountSupplierWhtSourceRows($conn, $filters, true);
    $report = accountSupplierWhtBuildReport($sourceRows, $filters);
    $report['detail_rows'] = accountSupplierWhtBuildDetailRows($sourceRows);
    return $report;
}

function accountSupplierWhtReport(mysqli $conn, array $filters = []): array
{
    return accountSupplierWhtBuildReport(accountSupplierWhtSourceRows($conn, $filters), $filters);
}
