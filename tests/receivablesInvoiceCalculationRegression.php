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

$migration = $read('database/new/20260919_account_receivables_percentage_calculations.sql');
$service = $read('includes/accountReceivablesService.php');
$settings = [
    'reporting_date' => '2026-09-19',
    'ageing_basis' => 'invoice_date',
    'default_credit_days' => 30,
    'usd_ngn_rate' => 1550,
    'settled_threshold' => 0.005,
];

$base = [
    'project_name' => 'TEST PROJECT',
    'client_name' => 'TEST CLIENT',
    'invoice_number' => 'TEST-001',
    'invoice_date' => '2026-09-19',
    'credit_days' => 30,
    'currency' => 'NGN',
    'line_type' => 'Invoice',
    'invoice_value_net' => 1000000,
    'position' => 'Open',
];

$percentage = accountReceivablesNormalizeInvoiceInput(array_merge($base, [
    'retention_rate_pct' => 5,
    'vat_rate_pct' => 7.5,
    'wht_rate_pct' => 2.5,
    'ncd_rate_pct' => 1,
    'stamp_duty_rate_pct' => 1,
]), $settings);

$manual = accountReceivablesNormalizeInvoiceInput(array_merge($base, [
    'retention_rate_pct' => null,
    'retention' => 43210.55,
    'vat_rate_pct' => null,
    'vat_charged' => 67890.12,
]), $settings);

$invalidRateRejected = false;
try {
    accountReceivablesNormalizeInvoiceInput(array_merge($base, ['vat_rate_pct' => 101]), $settings);
} catch (RuntimeException $error) {
    $invalidRateRejected = $error->getCode() === 422;
}

$checks = [
    'migration_adds_all_percentage_rate_columns' =>
        str_contains($migration, 'retention_rate_pct DECIMAL(9,6)')
        && str_contains($migration, 'vat_rate_pct DECIMAL(9,6)')
        && str_contains($migration, 'wht_rate_pct DECIMAL(9,6)')
        && str_contains($migration, 'ncd_rate_pct DECIMAL(9,6)')
        && str_contains($migration, 'stamp_duty_rate_pct DECIMAL(9,6)'),
    'legacy_rate_backfill_is_reconciliation_safe' =>
        str_contains($migration, 'ABS(vat_charged - ROUND(invoice_value_net')
        && str_contains($migration, "source_reference LIKE 'LEGACY-XLSX-R%'") ,
    'backend_calculates_percentage_amounts_authoritatively' =>
        abs($percentage['retention'] - 50000.00) < 0.01
        && abs($percentage['vat_charged'] - 75000.00) < 0.01
        && abs($percentage['wht'] - 25000.00) < 0.01
        && abs($percentage['ncd_levy'] - 10000.00) < 0.01
        && abs($percentage['stamp_duty'] - 10000.00) < 0.01,
    'backend_preserves_selected_rates' =>
        abs((float) $percentage['retention_rate_pct'] - 5.0) < 0.000001
        && abs((float) $percentage['vat_rate_pct'] - 7.5) < 0.000001,
    'manual_entry_remains_supported' =>
        $manual['retention_rate_pct'] === null
        && abs($manual['retention'] - 43210.55) < 0.01
        && $manual['vat_rate_pct'] === null
        && abs($manual['vat_charged'] - 67890.12) < 0.01,
    'percentage_rate_is_bounded' => $invalidRateRejected,
    'create_and_update_persist_rates' =>
        substr_count($service, '$data[\'retention_rate_pct\']') >= 2
        && substr_count($service, '$data[\'vat_rate_pct\']') >= 2
        && str_contains($service, 'retention_rate_pct = ?')
        && str_contains($service, 'vat_rate_pct = ?'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
