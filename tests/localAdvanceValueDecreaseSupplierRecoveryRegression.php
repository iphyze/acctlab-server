<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementLocalAdvancePurchaseService.php');
$migration = (string) file_get_contents($root . '/database/new/20260917_local_advance_value_decrease_supplier_recovery_backfill.sql');

$checks = [
    'same_supplier_decrease_creates_recoverable_adjustment' =>
        str_contains($service, 'function procurementLocalAdvanceCreateValueDecreaseAdjustments(')
        && str_contains($service, "'adjustment_kind' => 'Value Decrease'")
        && str_contains($service, "'adjustment_direction' => 'Recoverable'"),

    'recovery_uses_allocated_reconciliation_amount' =>
        str_contains($service, "\$allocation['recovery_allocated_amount']")
        && str_contains($service, 'Value Decrease Recovery'),

    'supplier_change_does_not_duplicate_value_decrease_recovery' =>
        str_contains($service, 'if ($recoveryCents > 0 && !$supplierChanged)'),

    'existing_recoveries_are_backfilled_idempotently' =>
        str_contains($migration, 'INSERT IGNORE INTO procurement_supplier_financial_adjustments')
        && str_contains($migration, "reconciliation.reconciliation_status = 'Recovery Required'")
        && str_contains($migration, 'allocation.recovery_allocated_amount > 0')
        && str_contains($migration, 'current_revision.supplier_id = previous_revision.supplier_id'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
