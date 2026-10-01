<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value): int { return strlen($value); }
}

$root = dirname(__DIR__);
require_once $root . '/includes/accountReceivablesService.php';

$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$locked = accountReceivablesApplyHistoricalRateMetrics([
    'currency' => 'USD',
    'rate_mode' => 'LOCKED',
    'fx_rate_used' => 1000,
    'outstanding' => 100,
    'total_deductions' => 400,
    'wht_credit_note_outstanding' => 10,
    'invoice_value_gross' => 500,
]);

$flexible = accountReceivablesApplyHistoricalRateMetrics([
    'currency' => 'USD',
    'rate_mode' => 'FLEXIBLE',
    'fx_rate_used' => 1000,
    'outstanding' => 100,
    'total_deductions' => 400,
    'wht_credit_note_outstanding' => 10,
    'invoice_value_gross' => 500,
], [
    // 400 USD reduction was applied at 1,200 instead of the posting rate 1,000.
    'outstanding_fx_adjustment_ngn' => -80000,
    'advance_amortisation_fx_adjustment_ngn' => 80000,
]);

$missing = accountReceivablesApplyHistoricalRateMetrics([
    'currency' => 'USD',
    'rate_mode' => 'LOCKED',
    'fx_rate_used' => null,
    'outstanding' => 100,
    'total_deductions' => 0,
    'wht_credit_note_outstanding' => 0,
    'invoice_value_gross' => 100,
]);

$dashboard = $read('includes/accountReceivablesDashboardService.php');
$ageing = $read('includes/accountReceivablesAgeingService.php');
$deductions = $read('includes/accountReceivablesDeductionsService.php');
$report = $read('includes/accountReceivablesReportService.php');

$checks = [
    'locked_posting_uses_original_rate_for_remaining_exposure' =>
        abs((float) $locked['outstanding_ngn_equivalent'] - 100000.0) < 0.005
        && $locked['historical_rate_basis'] === 'LOCKED_POSTING_RATE',
    'flexible_allocations_use_recorded_override_without_rewriting_posting_rate' =>
        abs((float) $flexible['outstanding_ngn_equivalent'] - 20000.0) < 0.005
        && abs((float) $flexible['total_deductions_ngn_equivalent'] - 480000.0) < 0.005
        && $flexible['historical_rate_basis'] === 'POSTING_AND_ALLOCATION_RATES',
    'missing_posting_rate_is_not_silently_revalued_at_latest_rate' =>
        $missing['historical_rate_available'] === false
        && $missing['outstanding_ngn_equivalent'] === null,
    'active_allocation_rate_variance_is_aggregated_for_reporting' =>
        str_contains($read('includes/accountReceivablesService.php'), 'outstanding_fx_adjustment_ngn')
        && str_contains($read('includes/accountReceivablesService.php'), 'advance_amortisation_fx_adjustment_ngn')
        && str_contains($read('includes/accountReceivablesService.php'), "reversed_at IS NULL")
        && str_contains($read('includes/accountReceivablesService.php'), "currency = 'USD'"),
    'dashboard_combined_exposure_uses_historical_equivalent_totals' =>
        str_contains($dashboard, "total_receivable_ngn_equivalent")
        && str_contains($dashboard, "HISTORICAL_POSTING_AND_ALLOCATION_RATES")
        && !str_contains($dashboard, 'currency[\'USD\'][\'total_receivable\'] * $fxRate'),
    'ageing_combined_exposure_uses_historical_equivalent_totals' =>
        str_contains($ageing, "ngn_equivalent_total")
        && str_contains($ageing, "HISTORICAL_POSTING_AND_ALLOCATION_RATES")
        && !str_contains($ageing, 'currencyState[\'USD\'][\'total\'] * $fxRate'),
    'deductions_and_wht_use_posting_level_historical_equivalents' =>
        str_contains($deductions, "total_deductions_ngn_equivalent")
        && str_contains($deductions, "wht_credit_note_outstanding_ngn_equivalent")
        && str_contains($deductions, "HISTORICAL_POSTING_AND_ALLOCATION_RATES"),
    'management_workbook_keeps_native_amounts_and_adds_historical_ngn_columns' =>
        str_contains($report, "'Rate Treatment'")
        && str_contains($report, "'Outstanding (NGN Eq.)'")
        && str_contains($report, 'setCellValue("AO{$dataRow}"')
        && str_contains($report, '!$AO$')
        && !str_contains($report, '*$H$4'),
    'latest_fx_is_reference_only_not_the_management_conversion_basis' =>
        str_contains($report, 'the latest FX rate is reference-only')
        && str_contains($report, "'fx_conversion_basis' => 'HISTORICAL_POSTING_AND_ALLOCATION_RATES'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
