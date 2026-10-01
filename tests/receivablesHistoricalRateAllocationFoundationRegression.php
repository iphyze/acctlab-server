<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value): int { return strlen($value); }
}

$root = dirname(__DIR__);
require_once $root . '/includes/accountReceivablesAllocationService.php';

$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$index = $read('index.php');
$route = $read('routes/receivables/allocations.php');
$service = $read('includes/accountReceivablesService.php');
$allocationService = $read('includes/accountReceivablesAllocationService.php');
$migration = $read('database/new/20261001_receivables_historical_rate_allocation_foundation.sql');

$settings = [
    'reporting_date' => '2026-10-01',
    'ageing_basis' => 'invoice_date',
    'default_credit_days' => 30,
    'usd_ngn_rate' => 1550,
    'settled_threshold' => 0.005,
];
$base = [
    'project_name' => 'TEST PROJECT',
    'client_name' => 'TEST CLIENT',
    'invoice_number' => 'TEST-001',
    'invoice_date' => '2026-10-01',
    'credit_days' => 30,
    'currency' => 'NGN',
    'line_type' => 'Invoice',
    'invoice_value_net' => 500000,
    'fx_rate_used' => 1000,
    'position' => 'Open',
];

$locked = accountReceivablesNormalizeInvoiceInput($base, $settings);
$flexible = accountReceivablesNormalizeInvoiceInput(array_merge($base, ['rate_mode' => 'flexible']), $settings);

$invalidModeRejected = false;
try {
    accountReceivablesNormalizeInvoiceInput(array_merge($base, ['rate_mode' => 'floating']), $settings);
} catch (RuntimeException $error) {
    $invalidModeRejected = $error->getCode() === 422;
}

$lockedMismatchRejected = false;
try {
    accountReceivablesAllocationRateState([
        'rate_mode' => 'LOCKED',
        'fx_rate_used' => 1000,
    ], ['applied_fx_rate' => 1200]);
} catch (RuntimeException $error) {
    $lockedMismatchRejected = $error->getCode() === 409;
}

$flexibleReasonRequired = false;
try {
    accountReceivablesAllocationRateState([
        'rate_mode' => 'FLEXIBLE',
        'fx_rate_used' => 1000,
    ], ['applied_fx_rate' => 1200]);
} catch (RuntimeException $error) {
    $flexibleReasonRequired = $error->getCode() === 422;
}

$override = accountReceivablesAllocationRateState([
    'rate_mode' => 'FLEXIBLE',
    'fx_rate_used' => 1000,
], [
    'applied_fx_rate' => 1200,
    'rate_override_reason' => 'Client settlement exception',
]);

$checks = [
    'migration_adds_rate_mode_without_rewriting_legacy_amounts' =>
        str_contains($migration, "rate_mode VARCHAR(10) NOT NULL DEFAULT 'LOCKED'")
        && !preg_match('/UPDATE\s+account_receivable_invoices[\s\S]{0,500}(amount_received|advance_amortisation)\s*=/i', $migration),
    'migration_creates_normalized_allocation_ledger' =>
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS account_receivable_allocations')
        && str_contains($migration, 'posting_fx_rate DECIMAL(18,6)')
        && str_contains($migration, 'applied_fx_rate DECIMAL(18,6)')
        && str_contains($migration, 'rate_mode_snapshot VARCHAR(10)')
        && str_contains($migration, 'reversed_at DATETIME NULL'),
    'existing_records_default_to_locked' => $locked['rate_mode'] === 'LOCKED',
    'flexible_mode_is_supported' => $flexible['rate_mode'] === 'FLEXIBLE',
    'invalid_rate_mode_is_rejected' => $invalidModeRejected,
    'allocation_route_is_registered_and_admin_protected' =>
        str_contains($index, "'/receivables/allocations'")
        && str_contains($route, 'requireAdmin()')
        && str_contains($route, 'accountReceivablesCreateAllocation')
        && str_contains($route, 'accountReceivablesReverseAllocation'),
    'locked_allocations_cannot_silently_change_rate' => $lockedMismatchRejected,
    'flexible_override_requires_audit_reason' => $flexibleReasonRequired,
    'flexible_override_preserves_original_and_applied_rates' =>
        abs((float) $override['posting_fx_rate'] - 1000.0) < 0.000001
        && abs((float) $override['applied_fx_rate'] - 1200.0) < 0.000001
        && $override['rate_override_reason'] === 'Client settlement exception',
    'original_rate_is_locked_after_allocation_history_exists' =>
        str_contains($service, 'accountReceivablesAllocationHistoryCount($conn, $id) > 0')
        && str_contains($service, 'The original posting FX rate cannot be changed after allocations have been recorded.'),
    'source_and_target_integrity_is_enforced' =>
        str_contains($allocationService, 'The source and target postings must use the same currency.')
        && str_contains($allocationService, 'The allocation amount exceeds the remaining available amount')
        && str_contains($allocationService, 'The allocation amount exceeds the target posting balance.'),
    'legacy_receivable_calculations_are_not_replaced_in_batch_one' =>
        str_contains($service, "\$row['outstanding'] = round(\$row['net_receivable'] - \$row['amount_received'], 2);")
        && str_contains($service, "+ \$row['advance_amortisation']"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
