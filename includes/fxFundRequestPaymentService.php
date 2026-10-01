<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementSupplierFinancialAdjustmentService.php';

/**
 * Payment-processing helpers for FX Fund Requests.
 *
 * This service is intentionally additive: it creates a normal row in
 * fx_instruction_letter_table from an eligible FX Fund Request while the
 * existing direct /fx/payment/createPayment route remains independent.
 */


function fxFundRequestSupplierCreditRequestType(array $request): string
{
    return strcasecmp(trim((string) ($request['request_type'] ?? '')), 'Advance') === 0
        ? 'fx_advance_purchase'
        : 'fx_final_purchase';
}

function fxFundRequestSupplierCreditPreview(mysqli $conn, array $request): array
{
    $supplierId = (int) ($request['suppliers_id'] ?? 0);
    $currency = strtoupper(trim((string) ($request['currency'] ?? '')));
    $gross = round((float) ($request['payable_amount'] ?? 0), 2);
    if ($supplierId <= 0 || $gross <= 0 || !in_array($currency, fxFundRequestProcessingAllowedCurrencies(), true)) {
        return [
            'gross_amount' => number_format(max(0, $gross), 2, '.', ''),
            'available_credit' => '0.00',
            'credit_amount' => '0.00',
            'cash_required' => number_format(max(0, $gross), 2, '.', ''),
        ];
    }
    $available = (float) procurementSupplierAvailableCreditForSupplier($conn, $supplierId, $currency);
    $credit = min($gross, max(0, $available));
    return [
        'gross_amount' => number_format($gross, 2, '.', ''),
        'available_credit' => number_format(max(0, $available), 2, '.', ''),
        'credit_amount' => number_format($credit, 2, '.', ''),
        'cash_required' => number_format(max(0, $gross - $credit), 2, '.', ''),
    ];
}

function fxFundRequestReserveSupplierCredit(mysqli $conn, array $request, int $actorId): array
{
    return procurementSupplierReserveCreditForPayment(
        $conn,
        (int) ($request['suppliers_id'] ?? 0),
        (string) ($request['currency'] ?? ''),
        $request['payable_amount'] ?? 0,
        fxFundRequestSupplierCreditRequestType($request),
        (int) ($request['id'] ?? 0),
        $actorId
    );
}

const FX_FUND_REQUEST_COMPLETION_MODES = ['Notify', 'Immediate', 'Automatic'];
const FX_FUND_REQUEST_COMPLETION_MIN_BUSINESS_DAYS = 1;
const FX_FUND_REQUEST_COMPLETION_MAX_BUSINESS_DAYS = 5;

function fxFundRequestProcessingCompletion(array $data): array
{
    $modeProvided = array_key_exists('completion_mode', $data) || array_key_exists('completionMode', $data);
    $daysProvided = array_key_exists('processing_business_days', $data) || array_key_exists('processingBusinessDays', $data);
    $modeRaw = trim((string) ($data['completion_mode'] ?? $data['completionMode'] ?? 'Notify'));
    $mode = null;
    foreach (FX_FUND_REQUEST_COMPLETION_MODES as $allowed) {
        if (strcasecmp($modeRaw, $allowed) === 0) {
            $mode = $allowed;
            break;
        }
    }
    if ($mode === null) {
        throw new Exception('Completion mode must be Notify, Immediate or Automatic.', 400);
    }

    if ($mode === 'Immediate') {
        return [
            'completion_mode' => 'Immediate',
            'processing_business_days' => 0,
            'expected_completion_at' => date('Y-m-d H:i:s'),
            'is_immediate' => true,
        ];
    }

    $rawDays = $data['processing_business_days'] ?? $data['processingBusinessDays'] ?? null;
    // Transitional compatibility for an older FX client that predates the
    // completion controls. Once either completion field is supplied, the
    // selected period is explicit and validated exactly like Local processing.
    if (!$modeProvided && !$daysProvided && ($rawDays === null || $rawDays === '')) {
        $rawDays = 1;
    }
    if ($rawDays === null || $rawDays === '' || filter_var($rawDays, FILTER_VALIDATE_INT) === false) {
        throw new Exception('Processing period is required for Notify and Automatic completion.', 400);
    }
    $days = (int) $rawDays;
    if ($days < FX_FUND_REQUEST_COMPLETION_MIN_BUSINESS_DAYS || $days > FX_FUND_REQUEST_COMPLETION_MAX_BUSINESS_DAYS) {
        throw new Exception('Processing period must be between 1 and 5 business days.', 400);
    }

    $timezone = new DateTimeZone('Africa/Lagos');
    $due = new DateTimeImmutable('now', $timezone);
    $remaining = $days;
    while ($remaining > 0) {
        $due = $due->modify('+1 day');
        if ((int) $due->format('N') <= 5) {
            $remaining--;
        }
    }

    return [
        'completion_mode' => $mode,
        'processing_business_days' => $days,
        'expected_completion_at' => $due->format('Y-m-d H:i:s'),
        'is_immediate' => false,
    ];
}

function fxFundRequestProcessingAliasValue(array $data, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }

    return null;
}

function fxFundRequestProcessingPositiveId(array $data, array $keys, string $label): int
{
    $raw = fxFundRequestProcessingAliasValue($data, $keys);
    if ($raw === null || filter_var($raw, FILTER_VALIDATE_INT) === false) {
        throw new Exception("Field '{$label}' must be a valid integer.", 400);
    }

    $value = (int) $raw;
    if ($value <= 0) {
        throw new Exception("Field '{$label}' must be greater than zero.", 400);
    }

    return $value;
}


function fxFundRequestProcessingLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function fxFundRequestProcessingRequiredString(array $data, string $field, int $maxLength = 255): string
{
    if (!array_key_exists($field, $data)) {
        throw new Exception("Field '{$field}' is required.", 400);
    }

    $value = trim((string) $data[$field]);
    if ($value === '') {
        throw new Exception("Field '{$field}' is required.", 400);
    }

    if (fxFundRequestProcessingLength($value) > $maxLength) {
        throw new Exception("Field '{$field}' cannot exceed {$maxLength} characters.", 400);
    }

    return $value;
}

function fxFundRequestProcessingOptionalString(array $data, string $field, int $maxLength = 255): string
{
    if (!array_key_exists($field, $data) || $data[$field] === null) {
        return '';
    }

    $value = trim((string) $data[$field]);
    if (fxFundRequestProcessingLength($value) > $maxLength) {
        throw new Exception("Field '{$field}' cannot exceed {$maxLength} characters.", 400);
    }

    return $value;
}

function fxFundRequestProcessingDate(array $data, string $field): string
{
    $value = fxFundRequestProcessingRequiredString($data, $field, 10);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

    if ($date === false || $hasErrors || $date->format('Y-m-d') !== $value) {
        throw new Exception("Field '{$field}' must use YYYY-MM-DD format.", 400);
    }

    return $value;
}

function fxFundRequestProcessingAllowedCurrencies(): array
{
    return ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR'];
}

function fxFundRequestProcessingCurrency(array $data, array $keys, string $label): string
{
    $raw = fxFundRequestProcessingAliasValue($data, $keys);
    if ($raw === null || trim((string) $raw) === '') {
        throw new Exception("Field '{$label}' is required.", 400);
    }

    $currency = strtoupper(trim((string) $raw));
    if (!in_array($currency, fxFundRequestProcessingAllowedCurrencies(), true)) {
        throw new Exception(
            "Field '{$label}' must be one of: " . implode(', ', fxFundRequestProcessingAllowedCurrencies()) . '.',
            400
        );
    }

    return $currency;
}

function fxFundRequestProcessingOptionalMoney(array $data, array $keys, string $label): ?float
{
    $raw = fxFundRequestProcessingAliasValue($data, $keys);
    if ($raw === null || $raw === '') {
        return null;
    }

    $normalized = is_string($raw) ? str_replace([',', ' '], '', trim($raw)) : $raw;
    if (!is_numeric($normalized)) {
        throw new Exception("Field '{$label}' must be numeric.", 400);
    }

    $amount = round((float) $normalized, 2);
    if ($amount <= 0) {
        throw new Exception("Field '{$label}' must be greater than zero.", 400);
    }

    return $amount;
}

function fxFundRequestNormalizeProcessingPayload(array $data): array
{
    $paymentCurrency = fxFundRequestProcessingCurrency(
        $data,
        ['paymentCurrency', 'payment_currency', 'currency_table'],
        'payment_currency'
    );

    $completion = fxFundRequestProcessingCompletion($data);

    return [
        'request_id' => fxFundRequestProcessingPositiveId($data, ['requestId', 'request_id'], 'requestId'),
        'beneficiary_details_id' => fxFundRequestProcessingPositiveId(
            $data,
            ['beneficiaryDetailsId', 'beneficiary_details_id'],
            'beneficiaryDetailsId'
        ),
        'payment_bank_id' => fxFundRequestProcessingPositiveId(
            $data,
            ['paymentBankId', 'payment_bank_id'],
            'paymentBankId'
        ),
        'reference' => fxFundRequestProcessingRequiredString($data, 'reference'),
        'payment_purpose' => fxFundRequestProcessingOptionalString($data, 'payment_purpose'),
        'payment_currency' => $paymentCurrency,
        'payment_amount' => fxFundRequestProcessingOptionalMoney(
            $data,
            ['paymentAmount', 'payment_amount'],
            'payment_amount'
        ),
        'payment_date' => fxFundRequestProcessingDate($data, 'payment_date'),
        'completion_mode' => $completion['completion_mode'],
        'processing_business_days' => $completion['processing_business_days'],
        'expected_completion_at' => $completion['expected_completion_at'],
        'is_immediate' => $completion['is_immediate'],
    ];
}

function fxFundRequestResolvePaymentConversion(array $request, array $processing, ?array $creditPlan = null): array
{
    $requestCurrency = strtoupper(trim((string) ($request['currency'] ?? '')));
    if (!in_array($requestCurrency, fxFundRequestProcessingAllowedCurrencies(), true)) {
        throw new Exception('FX Fund Request does not have a valid request currency. Edit the request before processing.', 409);
    }

    $requestAmount = round((float) ($request['payable_amount'] ?? 0), 2);
    if ($requestAmount <= 0) {
        throw new Exception('FX Fund Request payable amount must be greater than zero before payment processing.', 409);
    }

    $creditAmount = min($requestAmount, max(0.0, round((float) ($creditPlan['credit_amount'] ?? 0), 2)));
    $cashRequestAmount = max(0.0, round($requestAmount - $creditAmount, 2));
    $paymentCurrency = (string) $processing['payment_currency'];
    $submittedPaymentAmount = $processing['payment_amount'];

    if ($cashRequestAmount <= 0) {
        $paymentCurrency = $requestCurrency;
        $paymentAmount = 0.0;
        $exchangeRate = 1.0;
    } elseif ($paymentCurrency === $requestCurrency) {
        if ($submittedPaymentAmount !== null && abs($submittedPaymentAmount - $cashRequestAmount) > 0.009) {
            throw new Exception(
                'Payment amount must equal the cash requirement after supplier credit when request and payment currencies are the same.',
                400
            );
        }
        $paymentAmount = $cashRequestAmount;
        $exchangeRate = 1.0;
    } else {
        if ($submittedPaymentAmount === null) {
            throw new Exception('Field \'payment_amount\' is required when payment currency differs from request currency.', 400);
        }
        $paymentAmount = $submittedPaymentAmount;
        $exchangeRate = round($paymentAmount / $cashRequestAmount, 10);
        if ($exchangeRate <= 0) {
            throw new Exception('Calculated exchange rate must be greater than zero.', 400);
        }
    }

    return [
        'request_currency' => $requestCurrency,
        'request_amount' => $requestAmount,
        'supplier_credit_applied' => $creditAmount,
        'cash_request_amount' => $cashRequestAmount,
        'payment_currency' => $paymentCurrency,
        'payment_amount' => $paymentAmount,
        'exchange_rate' => $exchangeRate,
    ];
}


/** @return array{request_items:array<int,array{request_id:int,payment_amount:?float}>,beneficiary_details_id:int,payment_bank_id:int,reference:string,payment_purpose:string,payment_currency:string,payment_date:string} */
function fxFundRequestNormalizeGroupedProcessingPayload(array $data): array
{
    $rawItems = $data['requestItems'] ?? $data['request_items'] ?? null;
    if (!is_array($rawItems) || $rawItems === []) {
        throw new Exception('Please select at least one FX Fund Request for payment.', 400);
    }
    if (count($rawItems) > 100) {
        throw new Exception('Too many FX Fund Requests selected. Maximum allowed is 100.', 400);
    }

    $items = [];
    $seen = [];
    foreach ($rawItems as $index => $rawItem) {
        if (!is_array($rawItem)) {
            throw new Exception('Each request item must be an object.', 400);
        }

        $requestId = fxFundRequestProcessingPositiveId(
            $rawItem,
            ['requestId', 'request_id'],
            'requestItems[' . $index . '].requestId'
        );
        if (isset($seen[$requestId])) {
            throw new Exception('FX Fund Request #' . $requestId . ' was selected more than once.', 400);
        }
        $seen[$requestId] = true;

        $items[] = [
            'request_id' => $requestId,
            'payment_amount' => fxFundRequestProcessingOptionalMoney(
                $rawItem,
                ['paymentAmount', 'payment_amount'],
                'requestItems[' . $index . '].paymentAmount'
            ),
        ];
    }

    usort($items, static fn(array $a, array $b): int => $a['request_id'] <=> $b['request_id']);
    $completion = fxFundRequestProcessingCompletion($data);

    return [
        'request_items' => $items,
        'beneficiary_details_id' => fxFundRequestProcessingPositiveId(
            $data,
            ['beneficiaryDetailsId', 'beneficiary_details_id'],
            'beneficiaryDetailsId'
        ),
        'payment_bank_id' => fxFundRequestProcessingPositiveId(
            $data,
            ['paymentBankId', 'payment_bank_id'],
            'paymentBankId'
        ),
        'reference' => fxFundRequestProcessingRequiredString($data, 'reference'),
        'payment_purpose' => fxFundRequestProcessingOptionalString($data, 'payment_purpose'),
        'payment_currency' => fxFundRequestProcessingCurrency(
            $data,
            ['paymentCurrency', 'payment_currency', 'currency_table'],
            'payment_currency'
        ),
        'payment_date' => fxFundRequestProcessingDate($data, 'payment_date'),
        'completion_mode' => $completion['completion_mode'],
        'processing_business_days' => $completion['processing_business_days'],
        'expected_completion_at' => $completion['expected_completion_at'],
        'is_immediate' => $completion['is_immediate'],
    ];
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestLockManyForProcessing(mysqli $conn, array $requestItems): array
{
    $ids = array_values(array_map(static fn(array $item): int => (int) $item['request_id'], $requestItems));
    if ($ids === []) {
        throw new Exception('Please select at least one FX Fund Request for payment.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT * FROM fx_fund_request_table WHERE id IN ($placeholders) ORDER BY id FOR UPDATE"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[(int) $row['id']] = $row;
    }
    $stmt->close();

    $missing = array_values(array_diff($ids, array_keys($rows)));
    if ($missing !== []) {
        throw new Exception('The following FX Fund Request IDs do not exist: ' . implode(', ', $missing) . '.', 404);
    }

    $ordered = [];
    foreach ($ids as $id) {
        $row = $rows[$id];
        if ($row['fx_instruction_letter_id'] !== null || $row['processed_at'] !== null) {
            throw new Exception('FX Fund Request #' . $id . ' has already been processed for payment.', 409);
        }
        if ((string) ($row['payment_status'] ?? '') !== 'Pending') {
            throw new Exception('Only Pending FX Fund Requests can be grouped for payment. Request #' . $id . ' is not Pending.', 409);
        }
        $ordered[] = $row;
    }

    return $ordered;
}

function fxFundRequestAssertGroupedSupplier(array $requests): array
{
    if ($requests === []) {
        throw new Exception('Please select at least one FX Fund Request for payment.', 400);
    }

    $supplierId = (int) ($requests[0]['suppliers_id'] ?? 0);
    $supplierName = trim((string) ($requests[0]['suppliers_name'] ?? ''));
    if ($supplierId <= 0 || $supplierName === '') {
        throw new Exception('Selected FX Fund Request does not have a valid supplier.', 409);
    }

    foreach ($requests as $request) {
        if ((int) ($request['suppliers_id'] ?? 0) !== $supplierId) {
            throw new Exception('Grouped FX payments can only contain Fund Requests for the same supplier.', 409);
        }
    }

    return ['suppliers_id' => $supplierId, 'suppliers_name' => $supplierName];
}

/** @return array{lines:array<int,array<string,mixed>>,payment_amount:float,payment_currency:string} */
function fxFundRequestResolveGroupedConversions(array $requests, array $processing, array $creditPlans = []): array
{
    $requestById = [];
    foreach ($requests as $request) {
        $requestById[(int) $request['id']] = $request;
    }

    $lines = [];
    $total = 0.0;
    foreach ($processing['request_items'] as $item) {
        $requestId = (int) $item['request_id'];
        if (!isset($requestById[$requestId])) {
            throw new Exception('FX Fund Request #' . $requestId . ' is unavailable for grouped payment.', 409);
        }

        $conversion = fxFundRequestResolvePaymentConversion($requestById[$requestId], [
            'payment_currency' => (string) $processing['payment_currency'],
            'payment_amount' => $item['payment_amount'],
        ], $creditPlans[$requestId] ?? null);
        $total = round($total + (float) $conversion['payment_amount'], 2);
        $lines[] = [
            'request_id' => $requestId,
            'request_type' => (string) ($requestById[$requestId]['request_type'] ?? ''),
            'po_number' => $requestById[$requestId]['po_number'] ?? null,
            'purchase_number' => $requestById[$requestId]['purchase_number'] ?? null,
            'invoice_number' => $requestById[$requestId]['invoice_number'] ?? null,
            'request_currency' => (string) $conversion['request_currency'],
            'request_amount' => (float) $conversion['request_amount'],
            'supplier_credit_applied' => (float) ($conversion['supplier_credit_applied'] ?? 0),
            'cash_request_amount' => (float) ($conversion['cash_request_amount'] ?? $conversion['request_amount']),
            'payment_currency' => (string) $conversion['payment_currency'],
            'payment_amount' => (float) $conversion['payment_amount'],
            'exchange_rate' => (float) $conversion['exchange_rate'],
        ];
    }

    return [
        'lines' => $lines,
        'payment_amount' => $total,
        'payment_currency' => (string) $processing['payment_currency'],
    ];
}

function fxFundRequestLockForProcessing(mysqli $conn, int $requestId): array
{
    $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('FX Fund Request was not found.', 404);
    }

    if ($row['fx_instruction_letter_id'] !== null || $row['processed_at'] !== null) {
        $instructionId = $row['fx_instruction_letter_id'] !== null ? (int) $row['fx_instruction_letter_id'] : null;
        $suffix = $instructionId ? " (FX Instruction #{$instructionId})" : '';
        throw new Exception('FX Fund Request has already been processed for payment' . $suffix . '.', 409);
    }

    if ((string) ($row['payment_status'] ?? '') !== 'Pending') {
        throw new Exception('Only Pending FX Fund Requests can be processed for payment.', 409);
    }

    return $row;
}

function fxFundRequestLoadBeneficiaryForProcessing(mysqli $conn, int $beneficiaryDetailsId): array
{
    $stmt = $conn->prepare(
        'SELECT id, beneficiary_name, beneficiary_address, beneficiary_bank, beneficiary_bank_address,
                swift_code, beneficiary_account_number, bank_code, account, sort_code,
                intermediary_bank, intermediary_bank_swift_code, intermediary_bank_iban,
                domiciliation, code_guichet, compte_no, cle_rib
         FROM bank_beneficiary_details_table
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $beneficiaryDetailsId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Selected FX beneficiary was not found.', 400);
    }

    return $row;
}

function fxFundRequestLoadPaymentBankForProcessing(mysqli $conn, int $paymentBankId): array
{
    $stmt = $conn->prepare(
        'SELECT id, bank_name, account_number, currency, bank_code
         FROM fx_banks_table
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $paymentBankId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Selected FX payment bank/account was not found.', 400);
    }

    return $row;
}

function fxFundRequestProcessingString(array $row, string $field): string
{
    return trim((string) ($row[$field] ?? ''));
}

function fxFundRequestNumberToWords(int $number): string
{
    if ($number < 0 || $number > 999999999999) {
        return (string) $number;
    }

    $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    if ($number < 20) {
        return $ones[$number] ?: 'Zero';
    }
    if ($number < 100) {
        $word = $tens[intdiv($number, 10)];
        $remainder = $number % 10;
        return $word . ($remainder ? ' ' . $ones[$remainder] : '');
    }
    if ($number < 1000) {
        $word = $ones[intdiv($number, 100)] . ' Hundred';
        $remainder = $number % 100;
        return $word . ($remainder ? ' ' . fxFundRequestNumberToWords($remainder) : '');
    }

    foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'] as $unit => $label) {
        if ($number >= $unit) {
            $head = intdiv($number, $unit);
            $remainder = $number % $unit;
            $word = fxFundRequestNumberToWords($head) . ' ' . $label;
            return $word . ($remainder ? ' ' . fxFundRequestNumberToWords($remainder) : '');
        }
    }

    return 'Zero';
}

function fxFundRequestAmountWords(float $amount, string $currencyTable): string
{
    $currencyTable = strtoupper(trim($currencyTable));
    $mainCurrency = [
        'USD' => 'USD',
        'EUR' => 'Euros',
        'GBP' => 'Pounds',
        'ZAR' => 'Rands',
        'AED' => 'UAE Dirhams',
        'NGN' => 'Naira',
    ][$currencyTable] ?? $currencyTable;

    $subCurrency = [
        'USD' => 'Cents',
        'EUR' => 'Cents',
        'GBP' => 'Cents',
        'ZAR' => 'Cents',
        'AED' => 'Fils',
        'NGN' => 'Kobo',
    ][$currencyTable] ?? 'Cents';

    $minorUnits = (int) round($amount * 100);
    $whole = intdiv($minorUnits, 100);
    $decimal = $minorUnits % 100;

    return fxFundRequestNumberToWords($whole)
        . ' ' . $mainCurrency
        . ' & ' . fxFundRequestNumberToWords($decimal)
        . ' ' . $subCurrency
        . ' Only';
}

function fxFundRequestBuildInstructionForProcessing(
    array $request,
    array $beneficiary,
    array $paymentBank,
    array $processing,
    array $conversion
): array {
    foreach (['beneficiary_name', 'beneficiary_address', 'beneficiary_bank', 'beneficiary_account_number'] as $field) {
        if (fxFundRequestProcessingString($beneficiary, $field) === '') {
            throw new Exception('Selected FX beneficiary is missing required payment details.', 400);
        }
    }

    foreach (['account_number', 'currency', 'bank_code'] as $field) {
        if (fxFundRequestProcessingString($paymentBank, $field) === '') {
            throw new Exception('Selected FX payment bank/account is incomplete.', 400);
        }
    }

    $amount = (float) $conversion['payment_amount'];
    $paymentCurrency = (string) $conversion['payment_currency'];

    return [
        'beneficiary_name' => fxFundRequestProcessingString($beneficiary, 'beneficiary_name'),
        'beneficiary_address' => fxFundRequestProcessingString($beneficiary, 'beneficiary_address'),
        'beneficiary_bank' => fxFundRequestProcessingString($beneficiary, 'beneficiary_bank'),
        'beneficiary_bank_address' => fxFundRequestProcessingString($beneficiary, 'beneficiary_bank_address'),
        'swift_code' => fxFundRequestProcessingString($beneficiary, 'swift_code'),
        'beneficiary_account_number' => fxFundRequestProcessingString($beneficiary, 'beneficiary_account_number'),
        'reference' => $processing['reference'],
        'payment_purpose' => $processing['payment_purpose'],
        'amount_figure' => $amount,
        'amount_words' => fxFundRequestAmountWords($amount, $paymentCurrency),
        'payment_account_number' => fxFundRequestProcessingString($paymentBank, 'account_number'),
        'payment_bank' => fxFundRequestProcessingString($paymentBank, 'bank_code'),
        'currency' => fxFundRequestProcessingString($paymentBank, 'currency'),
        'currency_table' => $paymentCurrency,
        'payment_date' => $processing['payment_date'],
        'bank_code' => fxFundRequestProcessingString($beneficiary, 'bank_code'),
        'sort_code' => fxFundRequestProcessingString($beneficiary, 'sort_code'),
        'account' => fxFundRequestProcessingString($beneficiary, 'account'),
        'intermediary_bank' => fxFundRequestProcessingString($beneficiary, 'intermediary_bank'),
        'intermediary_bank_swift_code' => fxFundRequestProcessingString($beneficiary, 'intermediary_bank_swift_code'),
        'intermediary_bank_iban' => fxFundRequestProcessingString($beneficiary, 'intermediary_bank_iban'),
        'domiciliation' => fxFundRequestProcessingString($beneficiary, 'domiciliation'),
        'code_guichet' => fxFundRequestProcessingString($beneficiary, 'code_guichet'),
        'compte_no' => fxFundRequestProcessingString($beneficiary, 'compte_no'),
        'cle_rib' => fxFundRequestProcessingString($beneficiary, 'cle_rib'),
        'payment_status' => !empty($processing['is_immediate']) ? 'Paid' : 'Pending',
    ];
}

function fxFundRequestInsertInstructionForProcessing(mysqli $conn, array $instruction): int
{
    $stmt = $conn->prepare(
        'INSERT INTO fx_instruction_letter_table (
            beneficiary_name, beneficiary_address, beneficiary_bank, beneficiary_bank_address,
            swift_code, beneficiary_account_number, reference, payment_purpose, amount_figure,
            amount_words, payment_account_number, payment_bank, currency, currency_table,
            payment_date, bank_code, sort_code, account, intermediary_bank,
            intermediary_bank_swift_code, intermediary_bank_iban, domiciliation, code_guichet,
            compte_no, cle_rib, payment_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'ssssssssdsssssssssssssssss',
        $instruction['beneficiary_name'],
        $instruction['beneficiary_address'],
        $instruction['beneficiary_bank'],
        $instruction['beneficiary_bank_address'],
        $instruction['swift_code'],
        $instruction['beneficiary_account_number'],
        $instruction['reference'],
        $instruction['payment_purpose'],
        $instruction['amount_figure'],
        $instruction['amount_words'],
        $instruction['payment_account_number'],
        $instruction['payment_bank'],
        $instruction['currency'],
        $instruction['currency_table'],
        $instruction['payment_date'],
        $instruction['bank_code'],
        $instruction['sort_code'],
        $instruction['account'],
        $instruction['intermediary_bank'],
        $instruction['intermediary_bank_swift_code'],
        $instruction['intermediary_bank_iban'],
        $instruction['domiciliation'],
        $instruction['code_guichet'],
        $instruction['compte_no'],
        $instruction['cle_rib'],
        $instruction['payment_status']
    );
    $stmt->execute();
    $instructionId = (int) $stmt->insert_id;
    $stmt->close();

    return $instructionId;
}
