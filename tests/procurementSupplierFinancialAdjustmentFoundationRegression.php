<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/procurementSupplierFinancialAdjustmentService.php');
$localFinal = $read('includes/procurementLocalFinalPurchaseService.php');
$localAdvance = $read('includes/procurementLocalAdvancePurchaseService.php');
$migration = $read('database/new/20260917_procurement_supplier_financial_adjustment_foundation.sql');

$checks = [
    'shared_adjustment_service_exists' => $service !== '',
    'both_local_purchase_flows_load_shared_service' =>
        str_contains($localFinal, "procurementSupplierFinancialAdjustmentService.php")
        && str_contains($localAdvance, "procurementSupplierFinancialAdjustmentService.php"),
    'both_local_purchase_flows_ensure_adjustment_storage' =>
        str_contains($localFinal, 'procurementSupplierAdjustmentEnsureStorage($conn)')
        && str_contains($localAdvance, 'procurementSupplierAdjustmentEnsureStorage($conn)'),
    'adjustment_ledger_preserves_source_and_revision_identity' =>
        str_contains($service, 'source_purchase_id')
        && str_contains($service, 'source_po_id')
        && str_contains($service, 'source_revision_id')
        && str_contains($service, 'source_revision_number'),
    'adjustment_ledger_preserves_supplier_identity' =>
        str_contains($service, 'supplier_id')
        && str_contains($service, 'supplier_name')
        && str_contains($service, 'supplier_ledger'),
    'recoverable_and_payable_directions_supported' =>
        str_contains($service, "['Recoverable', 'Payable']"),
    'adjustments_are_idempotent' =>
        str_contains($service, 'idempotency_key')
        && str_contains($service, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'),
    'future_credit_allocation_storage_exists' =>
        str_contains($service, 'procurement_supplier_financial_adjustment_allocations')
        && str_contains($migration, 'procurement_supplier_financial_adjustment_allocations'),
    'outstanding_supplier_recovery_query_is_supplier_currency_scoped' =>
        str_contains($service, 'procurementSupplierAdjustmentOutstandingForSupplier')
        && str_contains($service, "adjustment_direction = 'Recoverable'")
        && str_contains($service, "status IN ('Open', 'Credit Available', 'Partially Settled')"),
    'migration_matches_runtime_tables' =>
        str_contains($migration, 'procurement_supplier_financial_adjustments')
        && str_contains($migration, 'uq_procurement_supplier_adjustment_idempotency'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
