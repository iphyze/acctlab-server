<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$servicePath = $root . '/includes/fxFundRequestPaymentService.php';
$routePath = $root . '/routes/request/fx/processForPayment.php';
$indexPath = $root . '/index.php';
$directCreatePath = $root . '/routes/fx/payment/createPayment.php';
$foundationPath = $root . '/database/20260809_fx_fund_request_foundation.sql';

$service = file_get_contents($servicePath) ?: '';
$route = file_get_contents($routePath) ?: '';
$index = file_get_contents($indexPath) ?: '';
$directCreate = file_get_contents($directCreatePath) ?: '';
$foundation = file_get_contents($foundationPath) ?: '';

require_once $servicePath;

$checks = [];
$checks['processing_route_registered'] = str_contains($index, "'/request/fx/processForPayment' => 'routes/request/fx/processForPayment.php'");
$checks['processing_requires_admin'] = str_contains($route, '$user = requireAdmin();');
$checks['processing_explicitly_uses_active_connection'] = str_contains($route, '$writeConn = databaseActiveConnection($conn);');
$checks['processing_is_transactional'] = str_contains($route, 'begin_transaction()')
    && str_contains($route, 'commit()')
    && str_contains($route, 'rollback()');
$checks['request_row_locked_for_double_process_safety'] = str_contains($service, 'FOR UPDATE')
    && str_contains($service, 'fx_instruction_letter_id')
    && str_contains($service, 'processed_at');
$checks['only_pending_requests_are_processable'] = str_contains($service, "Only Pending FX Fund Requests can be processed for payment.");
$checks['beneficiary_resolved_from_canonical_table'] = str_contains($service, 'FROM bank_beneficiary_details_table');
$checks['payment_bank_resolved_from_canonical_table'] = str_contains($service, 'FROM fx_banks_table');
$checks['instruction_insert_targets_existing_fx_payment_table'] = str_contains($service, 'INSERT INTO fx_instruction_letter_table');
$checks['instruction_amount_uses_resolved_payment_conversion'] = str_contains($service, "\$conversion['payment_amount']")
    && str_contains($service, 'fxFundRequestResolvePaymentConversion')
    && !str_contains($route, "['amount_figure']");
$checks['instruction_status_respects_completion_mode'] = str_contains($service, "!empty(\$processing['is_immediate']) ? 'Paid' : 'Pending'");
$checks['fund_request_status_respects_completion_mode'] = str_contains($route, "\$fundRequestStatus = !empty(\$effectiveProcessing['is_immediate']) ? 'Paid' : 'Processing'")
    && str_contains($route, 'payment_status = ?');
$checks['request_instruction_link_saved'] = str_contains($route, 'fx_instruction_letter_id = NULLIF(?, 0)')
    && str_contains($route, 'processed_by = ?')
    && str_contains($route, 'processed_at = NOW()');
$checks['link_update_has_second_idempotency_guard'] = str_contains($route, 'fx_instruction_letter_id IS NULL AND processed_at IS NULL');
$checks['direct_fx_creation_route_still_exists'] = str_contains($index, "'/fx/payment/createPayment' => 'routes/fx/payment/createPayment.php'")
    && str_contains($directCreate, 'INSERT INTO fx_instruction_letter_table');
$checks['direct_fx_creation_not_redirected_to_fund_requests'] = !str_contains($directCreate, 'fx_fund_request_table')
    && !str_contains($directCreate, 'processForPayment');
$checks['processing_route_contains_no_schema_mutation'] = !str_contains($route, 'ALTER TABLE')
    && !str_contains($service, 'ALTER TABLE')
    && str_contains($foundation, 'fx_instruction_letter_id');

try {
    $normalized = fxFundRequestNormalizeProcessingPayload([
        'requestId' => 41,
        'beneficiaryDetailsId' => 7,
        'paymentBankId' => 3,
        'reference' => 'INV-22 / PO-14',
        'payment_purpose' => 'Final FX payment',
        'payment_currency' => 'usd',
        'payment_amount' => 1550000,
        'payment_date' => '2026-08-09',
    ]);
    $checks['processing_payload_normalizes_ids_currency_date'] = $normalized['request_id'] === 41
        && $normalized['beneficiary_details_id'] === 7
        && $normalized['payment_bank_id'] === 3
        && $normalized['payment_currency'] === 'USD'
        && $normalized['payment_amount'] === 1550000.0
        && $normalized['payment_date'] === '2026-08-09'
        && $normalized['completion_mode'] === 'Notify'
        && $normalized['processing_business_days'] === 1;
} catch (Throwable) {
    $checks['processing_payload_normalizes_ids_currency_date'] = false;
}

try {
    $words = fxFundRequestAmountWords(47108.25, 'USD');
    $checks['amount_words_uses_payment_currency_and_minor_units'] = $words === 'Forty Seven Thousand One Hundred Eight USD & Twenty Five Cents Only';
} catch (Throwable) {
    $checks['amount_words_uses_payment_currency_and_minor_units'] = false;
}

try {
    fxFundRequestNormalizeProcessingPayload([
        'requestId' => 1,
        'beneficiaryDetailsId' => 1,
        'paymentBankId' => 1,
        'reference' => 'REF',
        'payment_currency' => 'USD',
        'payment_date' => '09/08/2026',
    ]);
    $checks['invalid_processing_date_rejected'] = false;
} catch (Throwable $e) {
    $checks['invalid_processing_date_rejected'] = $e->getCode() === 400;
}

try {
    fxFundRequestNormalizeProcessingPayload([
        'requestId' => 1,
        'beneficiaryDetailsId' => 1,
        'paymentBankId' => 1,
        'reference' => '',
        'payment_currency' => 'USD',
        'payment_date' => '2026-08-09',
    ]);
    $checks['missing_reference_rejected'] = false;
} catch (Throwable $e) {
    $checks['missing_reference_rejected'] = $e->getCode() === 400;
}


try {
    $conversion = fxFundRequestResolvePaymentConversion(
        ['currency' => 'USD', 'payable_amount' => 10000],
        ['payment_currency' => 'NGN', 'payment_amount' => 15500000]
    );
    $checks['cross_currency_conversion_is_derived_by_division'] = abs($conversion['exchange_rate'] - 1550.0) < 0.0000001;
} catch (Throwable) {
    $checks['cross_currency_conversion_is_derived_by_division'] = false;
}

try {
    $sameCurrency = fxFundRequestResolvePaymentConversion(
        ['currency' => 'EUR', 'payable_amount' => 2450.75],
        ['payment_currency' => 'EUR', 'payment_amount' => null]
    );
    $checks['same_currency_rate_is_one'] = $sameCurrency['exchange_rate'] === 1.0
        && $sameCurrency['payment_amount'] === 2450.75;
} catch (Throwable) {
    $checks['same_currency_rate_is_one'] = false;
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
