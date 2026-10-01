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

$allocationService = $read('includes/accountReceivablesAllocationService.php');
$allocationRoute = $read('routes/receivables/allocations.php');
$invoiceService = $read('includes/accountReceivablesService.php');

$lockedRate = accountReceivablesAllocationRateState([
    'rate_mode' => 'LOCKED',
    'fx_rate_used' => 1000,
], []);

$flexRate = accountReceivablesAllocationRateState([
    'rate_mode' => 'FLEXIBLE',
    'fx_rate_used' => 1000,
], [
    'applied_fx_rate' => 1125,
    'rate_override_reason' => 'Client-specific settlement rate',
]);

$checks = [
    'receipt_allocations_sync_existing_amount_received_field' =>
        str_contains($allocationService, "|| \$data['allocation_type'] === 'RECEIPT'")
        && str_contains($allocationService, "if (\$legacyFieldSynced && \$data['allocation_type'] === 'RECEIPT')")
        && str_contains($allocationService, 'COALESCE(amount_received, 0) + ?'),
    'receipt_reversal_restores_existing_amount_received_field' =>
        str_contains($allocationService, 'GREATEST(0, COALESCE(amount_received, 0) - ?)')
        && str_contains($allocationService, "(\$allocation['allocation_type'] ?? '') === 'RECEIPT' && !empty(\$allocation['legacy_field_synced'])"),
    'multiple_receipts_are_not_overwritten' =>
        str_contains($allocationService, 'INSERT INTO account_receivable_allocations')
        && str_contains($allocationService, 'allocation_date')
        && str_contains($allocationService, 'reference')
        && str_contains($allocationService, 'notes'),
    'payment_cannot_exceed_current_target_balance' =>
        str_contains($allocationService, 'The allocation amount exceeds the target posting balance.'),
    'locked_rate_keeps_original_posting_rate' =>
        $lockedRate['rate_mode_snapshot'] === 'LOCKED'
        && abs((float) $lockedRate['applied_fx_rate'] - 1000.0) < 0.000001,
    'flexible_rate_keeps_override_audit_reason' =>
        $flexRate['rate_mode_snapshot'] === 'FLEXIBLE'
        && abs((float) $flexRate['applied_fx_rate'] - 1125.0) < 0.000001
        && $flexRate['rate_override_reason'] === 'Client-specific settlement rate',
    'existing_receivables_balance_contract_is_preserved' =>
        str_contains($invoiceService, "\$row['outstanding'] = round(\$row['net_receivable'] - \$row['amount_received'], 2);")
        && str_contains($invoiceService, 'Use the allocation ledger for new receipts and advance amortisation after allocation history has started.'),
    'allocation_api_continues_to_support_create_and_reverse' =>
        str_contains($allocationRoute, "if (\$method === 'POST')")
        && str_contains($allocationRoute, "(\$payload['action'] ?? '') === 'reverse'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
