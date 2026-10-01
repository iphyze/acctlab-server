<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/20260809_fx_fund_request_currency_enhancement.sql') ?: '';
$service = file_get_contents($root . '/includes/fxFundRequestService.php') ?: '';
$paymentService = file_get_contents($root . '/includes/fxFundRequestPaymentService.php') ?: '';
$processRoute = file_get_contents($root . '/routes/request/fx/processForPayment.php') ?: '';
$directCreate = file_get_contents($root . '/routes/fx/payment/createPayment.php') ?: '';
$listRoute = file_get_contents($root . '/routes/request/fx/getFilteredRequest.php') ?: '';

require_once $root . '/includes/fxFundRequestService.php';
require_once $root . '/includes/fxFundRequestPaymentService.php';

$checks = [];
$checks['migration_adds_request_currency'] = str_contains($migration, 'ADD COLUMN `currency` CHAR(3) NULL');
$checks['migration_adds_payment_audit_fields'] = str_contains($migration, 'ADD COLUMN `payment_currency` CHAR(3) NULL')
    && str_contains($migration, 'ADD COLUMN `payment_amount` DECIMAL(18,2) NULL')
    && str_contains($migration, 'ADD COLUMN `exchange_rate` DECIMAL(24,10) NULL');
$checks['migration_does_not_alter_instruction_table'] = !str_contains($migration, 'ALTER TABLE `lambert2_acctlab_db`.`fx_instruction_letter_table`');
$checks['supported_request_currencies_are_exact'] = fxFundRequestAllowedCurrencies() === ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR'];
$checks['processing_currencies_are_exact'] = fxFundRequestProcessingAllowedCurrencies() === ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR'];
$checks['all_currency_filter_is_normalized_before_validation'] = str_contains($listRoute, "strtolower(\$currencyInput) === 'all'")
    && str_contains($listRoute, "? 'all'")
    && str_contains($listRoute, "strtoupper(\$currencyInput)");

try {
    $same = fxFundRequestResolvePaymentConversion([
        'currency' => 'USD',
        'payable_amount' => '1000.00',
    ], [
        'payment_currency' => 'USD',
        'payment_amount' => null,
    ]);
    $checks['same_currency_defaults_amount_and_rate_one'] = $same['payment_amount'] === 1000.0
        && $same['exchange_rate'] === 1.0;
} catch (Throwable) {
    $checks['same_currency_defaults_amount_and_rate_one'] = false;
}

try {
    fxFundRequestResolvePaymentConversion([
        'currency' => 'USD',
        'payable_amount' => '1000.00',
    ], [
        'payment_currency' => 'USD',
        'payment_amount' => 999.0,
    ]);
    $checks['same_currency_different_amount_rejected'] = false;
} catch (Throwable $e) {
    $checks['same_currency_different_amount_rejected'] = $e->getCode() === 400;
}

try {
    $cross = fxFundRequestResolvePaymentConversion([
        'currency' => 'USD',
        'payable_amount' => '10000.00',
    ], [
        'payment_currency' => 'NGN',
        'payment_amount' => 15500000.00,
    ]);
    $checks['cross_currency_rate_is_payment_divided_by_request'] = abs($cross['exchange_rate'] - 1550.0) < 0.0000001
        && $cross['payment_amount'] === 15500000.0;
} catch (Throwable) {
    $checks['cross_currency_rate_is_payment_divided_by_request'] = false;
}

try {
    fxFundRequestResolvePaymentConversion([
        'currency' => 'USD',
        'payable_amount' => '10000.00',
    ], [
        'payment_currency' => 'NGN',
        'payment_amount' => null,
    ]);
    $checks['cross_currency_requires_payment_amount'] = false;
} catch (Throwable $e) {
    $checks['cross_currency_requires_payment_amount'] = $e->getCode() === 400;
}

$checks['instruction_uses_actual_payment_amount'] = str_contains($paymentService, "\$conversion['payment_amount']")
    && str_contains($paymentService, "'currency_table' => \$paymentCurrency");
$checks['processing_persists_conversion_audit'] = str_contains($processRoute, 'payment_currency = ?')
    && str_contains($processRoute, 'payment_amount = ?')
    && str_contains($processRoute, 'exchange_rate = ?');
$checks['direct_fx_payment_creation_still_independent'] = str_contains($directCreate, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($directCreate, 'fx_fund_request_table')
    && !str_contains($directCreate, 'exchange_rate');
$checks['ngn_amount_words_supported'] = fxFundRequestAmountWords(1500.25, 'NGN') === 'One Thousand Five Hundred Naira & Twenty Five Kobo Only';

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
