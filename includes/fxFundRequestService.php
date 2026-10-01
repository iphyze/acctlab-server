<?php

declare(strict_types=1);

/**
 * Canonical validation/calculation helpers for FX Fund Requests.
 *
 * This service intentionally does not create or mutate fx_instruction_letter_table.
 * The existing direct FX payment flow remains independent of this request workflow.
 */

function fxFundRequestRequireString(array $data, string $field): string
{
    if (!array_key_exists($field, $data)) {
        throw new Exception("Field '{$field}' is required.", 400);
    }

    $value = trim((string) $data[$field]);
    if ($value === '') {
        throw new Exception("Field '{$field}' is required.", 400);
    }

    return $value;
}

function fxFundRequestOptionalString(array $data, string $field): ?string
{
    if (!array_key_exists($field, $data) || $data[$field] === null) {
        return null;
    }

    $value = trim((string) $data[$field]);
    return $value === '' ? null : $value;
}

function fxFundRequestPositiveId(array $data, string $field): int
{
    if (!array_key_exists($field, $data) || filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
        throw new Exception("Field '{$field}' must be a valid integer.", 400);
    }

    $value = (int) $data[$field];
    if ($value <= 0) {
        throw new Exception("Field '{$field}' must be greater than zero.", 400);
    }

    return $value;
}

function fxFundRequestMoney(array $data, string $field): float
{
    if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
        throw new Exception("Field '{$field}' is required.", 400);
    }

    $raw = is_string($data[$field]) ? str_replace([',', ' '], '', trim($data[$field])) : $data[$field];
    if (!is_numeric($raw)) {
        throw new Exception("Field '{$field}' must be numeric.", 400);
    }

    $value = round((float) $raw, 2);
    if ($value < 0) {
        throw new Exception("Field '{$field}' cannot be negative.", 400);
    }

    return $value;
}

function fxFundRequestDate(array $data, string $field, bool $required): ?string
{
    $value = fxFundRequestOptionalString($data, $field);
    if ($value === null) {
        if ($required) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

    if ($date === false || $hasErrors || $date->format('Y-m-d') !== $value) {
        throw new Exception("Field '{$field}' must use YYYY-MM-DD format.", 400);
    }

    return $value;
}


function fxFundRequestAllowedCurrencies(): array
{
    return ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR'];
}

function fxFundRequestCurrency(array $data, string $field = 'currency'): string
{
    $currency = strtoupper(fxFundRequestRequireString($data, $field));
    if (!in_array($currency, fxFundRequestAllowedCurrencies(), true)) {
        throw new Exception(
            "Field '{$field}' must be one of: " . implode(', ', fxFundRequestAllowedCurrencies()) . '.',
            400
        );
    }

    return $currency;
}

function fxFundRequestNormalizeType(array $data): string
{
    $raw = strtolower(fxFundRequestRequireString($data, 'request_type'));
    return match ($raw) {
        'final' => 'Final',
        'advance' => 'Advance',
        default => throw new Exception("Field 'request_type' must be Final or Advance.", 400),
    };
}

/**
 * Accept either percentage labels ("7.50%"), percentage numbers (7.5),
 * or decimal rates (0.075) and return the canonical decimal rate.
 */
function fxFundRequestRate(array $data, string $field, array $allowed): float
{
    if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
        throw new Exception("Field '{$field}' is required.", 400);
    }

    $raw = trim((string) $data[$field]);
    $hasPercentSign = str_contains($raw, '%');
    $normalized = str_replace(['%', ',', ' '], '', $raw);
    if (!is_numeric($normalized)) {
        throw new Exception("Field '{$field}' has an invalid rate.", 400);
    }

    $value = (float) $normalized;
    if ($hasPercentSign || $value > 1) {
        $value /= 100;
    }
    $value = round($value, 6);

    foreach ($allowed as $candidate) {
        if (abs($value - (float) $candidate) < 0.0000005) {
            return (float) $candidate;
        }
    }

    $allowedLabels = array_map(static fn(float $rate): string => number_format($rate * 100, 2) . '%', $allowed);
    throw new Exception("Field '{$field}' must be one of: " . implode(', ', $allowedLabels) . '.', 400);
}

function fxFundRequestPercentage(array $data): float
{
    if (!array_key_exists('percentage', $data) || $data['percentage'] === '' || $data['percentage'] === null) {
        throw new Exception("Field 'percentage' is required for Advance requests.", 400);
    }

    $raw = str_replace(['%', ',', ' '], '', trim((string) $data['percentage']));
    if (!is_numeric($raw)) {
        throw new Exception("Field 'percentage' must be numeric.", 400);
    }

    $percentage = round((float) $raw, 2);
    if ($percentage <= 0 || $percentage > 100) {
        throw new Exception("Field 'percentage' must be greater than 0 and not exceed 100.", 400);
    }

    return $percentage;
}

function fxFundRequestCalculate(
    float $subTotal,
    float $discount,
    float $otherCharges,
    float $vatRate,
    float $whtRate,
    ?float $percentage
): array {
    if ($discount > $subTotal) {
        throw new Exception('Discount cannot exceed sub total.', 400);
    }

    $netBase = round($subTotal - $discount, 2);
    $vatAmount = round($netBase * $vatRate, 2);
    $whtAmount = round($netBase * $whtRate, 2);
    $basePayable = round($netBase + $otherCharges + $vatAmount - $whtAmount, 2);

    if ($basePayable < 0) {
        throw new Exception('Calculated payable amount cannot be negative.', 400);
    }

    $payableAmount = $percentage === null
        ? $basePayable
        : round($basePayable * ($percentage / 100), 2);

    return [
        'net_base' => $netBase,
        'vat_amount' => $vatAmount,
        'wht_amount' => $whtAmount,
        'base_payable_amount' => $basePayable,
        'payable_amount' => $payableAmount,
    ];
}

function fxFundRequestNormalizePayload(array $data): array
{
    $requestType = fxFundRequestNormalizeType($data);
    $supplierName = fxFundRequestRequireString($data, 'suppliers_name');
    $supplierId = fxFundRequestPositiveId($data, 'suppliers_id');
    $projectCode = fxFundRequestRequireString($data, 'project_code');
    $currency = fxFundRequestCurrency($data);

    // A PO number is mandatory for Advance requests because cumulative
    // percentage allocation is enforced per PO. For Final requests it is
    // optional reference information only.
    $poNumber = $requestType === 'Advance'
        ? fxFundRequestRequireString($data, 'po_number')
        : fxFundRequestOptionalString($data, 'po_number');

    $subTotal = fxFundRequestMoney($data, 'sub_total');
    $discount = fxFundRequestMoney($data, 'discount');
    $otherCharges = fxFundRequestMoney($data, 'other_charges');
    $vatRate = fxFundRequestRate($data, 'vat_rate', [0.0, 0.075]);
    $whtRate = fxFundRequestRate($data, 'wht_rate', [0.0, 0.02, 0.05]);
    $dateReceived = fxFundRequestDate($data, 'date_received', false);

    $invoiceNumber = null;
    $purchaseNumber = null;
    $invoiceDate = null;
    $purchaseDate = null;
    $percentage = null;

    if ($requestType === 'Final') {
        $invoiceNumber = fxFundRequestRequireString($data, 'invoice_number');
        $purchaseNumber = fxFundRequestRequireString($data, 'purchase_number');
        $invoiceDate = fxFundRequestDate($data, 'invoice_date', true);
        $purchaseDate = fxFundRequestDate($data, 'purchase_date', true);
    } else {
        $percentage = fxFundRequestPercentage($data);
    }

    $calculation = fxFundRequestCalculate(
        $subTotal,
        $discount,
        $otherCharges,
        $vatRate,
        $whtRate,
        $percentage
    );

    return [
        'request_type' => $requestType,
        'suppliers_name' => $supplierName,
        'suppliers_id' => $supplierId,
        'invoice_number' => $invoiceNumber,
        'purchase_number' => $purchaseNumber,
        'po_number' => $poNumber,
        'invoice_date' => $invoiceDate,
        'purchase_date' => $purchaseDate,
        'date_received' => $dateReceived,
        'project_code' => $projectCode,
        'currency' => $currency,
        'sub_total' => $subTotal,
        'discount' => $discount,
        'other_charges' => $otherCharges,
        'vat_rate' => $vatRate,
        'vat_amount' => $calculation['vat_amount'],
        'wht_rate' => $whtRate,
        'wht_amount' => $calculation['wht_amount'],
        'percentage' => $percentage,
        'payable_amount' => $calculation['payable_amount'],
        'calculation' => $calculation,
    ];
}

function fxFundRequestAssertSupplierAndProject(mysqli $conn, array &$request): void
{
    $supplierStmt = $conn->prepare(
        'SELECT id, supplier_name
         FROM suppliers_table
         WHERE id = ?
         LIMIT 1'
    );
    $supplierStmt->bind_param('i', $request['suppliers_id']);
    $supplierStmt->execute();
    $supplier = $supplierStmt->get_result()->fetch_assoc();
    $supplierStmt->close();

    if (!$supplier) {
        throw new Exception('Selected supplier was not found.', 400);
    }

    if (strcasecmp(trim((string) $supplier['supplier_name']), trim((string) $request['suppliers_name'])) !== 0) {
        throw new Exception('Selected supplier ID does not match the supplier name.', 400);
    }
    $request['suppliers_name'] = trim((string) $supplier['supplier_name']);

    $projectStmt = $conn->prepare('SELECT code FROM location_table WHERE code = ? LIMIT 1');
    $projectStmt->bind_param('s', $request['project_code']);
    $projectStmt->execute();
    $project = $projectStmt->get_result()->fetch_assoc();
    $projectStmt->close();

    if (!$project) {
        throw new Exception('Selected project code was not found.', 400);
    }
    $request['project_code'] = trim((string) $project['code']);
}

function fxFundRequestPoLockName(string $poNumber): string
{
    return 'fx_fr_po_' . sha1(strtolower(trim($poNumber)));
}

function fxFundRequestAcquireAdvancePoLock(mysqli $conn, string $poNumber): string
{
    $lockName = fxFundRequestPoLockName($poNumber);
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int) ($row['acquired'] ?? 0) !== 1) {
        throw new Exception('Could not reserve this PO for advance validation. Please retry.', 409);
    }

    return $lockName;
}

function fxFundRequestReleaseAdvancePoLock(mysqli $conn, ?string $lockName): void
{
    if ($lockName === null || $lockName === '') {
        return;
    }

    try {
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {
        error_log('Unable to release FX Fund Request PO lock: ' . $ignored->getMessage());
    }
}

function fxFundRequestAssertFinalPurchaseAvailable(mysqli $conn, string $purchaseNumber, ?int $excludeId = null): void
{
    $sql = 'SELECT id FROM fx_fund_request_table WHERE request_type = \'Final\' AND purchase_number = ?';
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
    }
    $sql .= ' LIMIT 1 FOR UPDATE';

    $stmt = $conn->prepare($sql);
    if ($excludeId !== null) {
        $stmt->bind_param('si', $purchaseNumber, $excludeId);
    } else {
        $stmt->bind_param('s', $purchaseNumber);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($exists) {
        throw new Exception("Duplicate FX Final request. Purchase No.: {$purchaseNumber} already exists.", 409);
    }
}

function fxFundRequestAssertAdvancePercentageAvailable(
    mysqli $conn,
    string $poNumber,
    float $requestedPercentage,
    ?int $excludeId = null,
    ?string $currency = null
): array {
    $currency = $currency === null ? null : strtoupper(trim($currency));
    if ($currency === '') {
        $currency = null;
    }

    $sql = "SELECT f.id, f.percentage
            FROM fx_fund_request_table f
            WHERE f.request_type = 'Advance'
              AND f.po_number = ?
              AND f.payment_status <> 'Cancelled'
              AND NOT EXISTS (
                  SELECT 1
                  FROM procurement_requests pr
                  WHERE pr.account_request_type = 'fx_fund_request'
                    AND pr.account_request_id = f.id
                    AND pr.request_type = 'fx_advance_purchase'
                    AND pr.legacy_source_table = 'canonical_fx_advance_purchase'
                    AND pr.request_variant = 'Supplementary'
                    AND pr.deleted_at IS NULL
              )";
    if ($currency !== null) {
        // Legacy NULL currency rows are conservatively included because their
        // currency cannot be proven different from the request being checked.
        $sql .= " AND (f.currency = ? OR f.currency IS NULL OR f.currency = '')";
    }
    if ($excludeId !== null) {
        $sql .= ' AND f.id <> ?';
    }
    $sql .= ' FOR UPDATE';

    $stmt = $conn->prepare($sql);
    if ($currency !== null && $excludeId !== null) {
        $stmt->bind_param('ssi', $poNumber, $currency, $excludeId);
    } elseif ($currency !== null) {
        $stmt->bind_param('ss', $poNumber, $currency);
    } elseif ($excludeId !== null) {
        $stmt->bind_param('si', $poNumber, $excludeId);
    } else {
        $stmt->bind_param('s', $poNumber);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $existingPercentage = 0.0;
    foreach ($rows as $row) {
        $existingPercentage += (float) ($row['percentage'] ?? 0);
    }
    $existingPercentage = round($existingPercentage, 2);
    $resultingPercentage = round($existingPercentage + $requestedPercentage, 2);

    if ($resultingPercentage > 100.0 + 0.00001) {
        $remaining = max(0.0, round(100.0 - $existingPercentage, 2));
        $currencyLabel = $currency !== null ? " ({$currency})" : '';
        throw new Exception(
            "Advance percentage for PO {$poNumber}{$currencyLabel} would exceed 100%. Existing: "
            . number_format($existingPercentage, 2)
            . '%, remaining: '
            . number_format($remaining, 2)
            . '%.',
            409
        );
    }

    return [
        'existing_percentage' => $existingPercentage,
        'requested_percentage' => round($requestedPercentage, 2),
        'resulting_percentage' => $resultingPercentage,
        'remaining_percentage' => max(0.0, round(100.0 - $resultingPercentage, 2)),
        'currency' => $currency,
    ];
}

function fxFundRequestInsertLog(mysqli $conn, int $userId, string $email, string $action): void
{
    $stmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $email);
    $stmt->execute();
    $stmt->close();
}

function fxFundRequestFormatRow(array $row): array
{
    foreach (['sub_total', 'discount', 'other_charges', 'vat_amount', 'wht_amount', 'payable_amount', 'payment_amount'] as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null) {
            $row[$field] = number_format((float) $row[$field], 2, '.', '');
        }
    }
    foreach (['vat_rate', 'wht_rate'] as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null) {
            $row[$field] = number_format((float) $row[$field], 6, '.', '');
        }
    }
    if (array_key_exists('percentage', $row) && $row['percentage'] !== null) {
        $row['percentage'] = number_format((float) $row['percentage'], 2, '.', '');
    }
    if (array_key_exists('exchange_rate', $row) && $row['exchange_rate'] !== null) {
        $row['exchange_rate'] = number_format((float) $row['exchange_rate'], 10, '.', '');
    }
    if (array_key_exists('procurement_purchase_id', $row)) {
        $row['procurement_purchase_id'] = $row['procurement_purchase_id'] === null
            ? null
            : (int) $row['procurement_purchase_id'];
        $procurementType = trim((string) ($row['procurement_request_type'] ?? ''));
        $row['procuredesk_linked'] = $row['procurement_purchase_id'] !== null
            && in_array($procurementType, ['fx_final_purchase', 'fx_advance_purchase'], true);
        if ($procurementType !== '') {
            $row['procurement_request_type'] = $procurementType;
        }
    }
    return $row;
}
