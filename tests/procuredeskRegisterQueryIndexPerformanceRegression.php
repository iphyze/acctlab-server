<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$localFinal = $read('routes/procurement/payments/local/finalPurchases.php');
$localFinalSummary = $read('routes/procurement/payments/local/finalPurchaseSummary.php');
$localAdvance = $read('routes/procurement/payments/local/advancePurchases.php');
$localAdvanceSummary = $read('routes/procurement/payments/local/advancePurchaseSummary.php');
$fxFinal = $read('routes/procurement/payments/foreign/finalPurchases.php');
$fxFinalSummary = $read('routes/procurement/payments/foreign/finalPurchaseSummary.php');
$fxAdvance = $read('routes/procurement/payments/foreign/advancePurchases.php');
$fxAdvanceSummary = $read('routes/procurement/payments/foreign/advancePurchaseSummary.php');
$canonicalRead = $read('includes/procurementRequestCanonicalReadService.php');
$localAdvanceService = $read('includes/procurementLocalAdvancePurchaseService.php');
$migration = $read('database/20260819_procuredesk_register_query_indexes.sql');

$allRegisterSources = implode("\n", [
    $localFinal, $localFinalSummary,
    $localAdvance, $localAdvanceSummary,
    $fxFinal, $fxFinalSummary,
    $fxAdvance, $fxAdvanceSummary,
    $canonicalRead,
]);

$checks = [
    'year_filters_are_sargable_date_ranges' =>
        !str_contains($allRegisterSources, 'YEAR(p.purchase_date) = ?')
        && !str_contains($allRegisterSources, 'YEAR(r.purchase_date) = ?')
        && !str_contains($allRegisterSources, 'YEAR(r.transaction_date) = ?')
        && str_contains($localFinal, 'p.purchase_date >= ? AND p.purchase_date < ?')
        && str_contains($localAdvance, 'r.transaction_date >= ? AND r.transaction_date < ?')
        && str_contains($fxFinal, 'p.purchase_date >= ? AND p.purchase_date < ?')
        && str_contains($fxAdvance, 'r.transaction_date >= ? AND r.transaction_date < ?')
        && str_contains($canonicalRead, 'r.purchase_date >= ? AND r.purchase_date < ?')
        && str_contains($canonicalRead, 'r.transaction_date >= ? AND r.transaction_date < ?'),

    'all_years_behavior_is_preserved' =>
        substr_count($allRegisterSources, 'if ($year > 0)') >= 10
        && str_contains($localFinal, "'year' => \$year ?: null")
        && str_contains($localAdvance, "'year' => \$year ?: null")
        && str_contains($localFinalSummary, "'year' => \$year > 0 ? \$year : null")
        && str_contains($fxFinalSummary, "'year' => \$year ?: null"),

    'existing_search_project_supplier_currency_filters_preserved' =>
        str_contains($localFinal, 'p.project_id = ?')
        && str_contains($localFinal, 'p.supplier_id = ?')
        && str_contains($localAdvance, 'COALESCE(pr.project_id, p.project_id) = ?')
        && str_contains($localAdvance, 'COALESCE(pr.supplier_id, p.supplier_id) = ?')
        && str_contains($fxFinal, 'p.currency = ?')
        && str_contains($fxAdvance, 'r.currency = ?')
        && str_contains($localFinal, 'LIKE ?')
        && str_contains($localAdvance, 'LIKE ?'),

    'pagination_and_sort_contracts_preserved' =>
        str_contains($localFinal, 'LIMIT ? OFFSET ?')
        && str_contains($localAdvance, 'LIMIT ? OFFSET ?')
        && str_contains($fxFinal, 'LIMIT ? OFFSET ?')
        && str_contains($fxAdvance, 'LIMIT ? OFFSET ?')
        && str_contains($localFinal, '$allowedSortFields')
        && str_contains($localAdvance, '$allowedSortFields')
        && str_contains($fxFinal, '$allowedSortFields')
        && str_contains($fxAdvance, '$allowedSortFields'),

    'local_advance_allocations_are_batched_for_register_pages' =>
        str_contains($localAdvanceService, 'function procurementLocalAdvanceAllocatedUnitsForPoIds(')
        && str_contains($localAdvanceService, 'GROUP BY source.po_id')
        && str_contains($localAdvanceService, 'GROUP BY external.po_number_normalized')
        && str_contains($localAdvance, 'procurementLocalAdvanceAllocatedUnitsForPoIds(')
        && str_contains($canonicalRead, 'procurementLocalAdvanceAllocatedUnitsForPoIds('),

    'single_po_allocation_guard_remains_for_write_validation' =>
        str_contains($localAdvanceService, 'function procurementLocalAdvanceAllocatedUnits(mysqli $conn, int $poId')
        && str_contains($localAdvanceService, 'procurementLocalAdvanceAssertAllocationAvailable(')
        && str_contains($localAdvanceService, 'procurementLocalAdvanceAllocatedUnits($conn, $poId, $excludePurchaseId)'),

    'migration_adds_register_and_allocation_indexes_only' =>
        str_contains($migration, 'idx_proc_req_register_created')
        && str_contains($migration, 'idx_proc_req_register_purchase_date')
        && str_contains($migration, 'idx_proc_req_register_transaction_date')
        && str_contains($migration, 'idx_proc_req_advance_allocation')
        && !preg_match('/CREATE\s+TABLE/i', $migration)
        && !preg_match('/ADD\s+COLUMN/i', $migration)
        && !preg_match('/DROP\s+TABLE/i', $migration),

    'migration_is_idempotent_by_index_name' =>
        substr_count($migration, 'information_schema.STATISTICS') >= 5
        && substr_count($migration, 'PREPARE stmt FROM @sql') === 4,

    'fx_and_accounting_behavior_not_rewritten' =>
        str_contains($fxFinalSummary, 'GROUP BY p.currency')
        && str_contains($fxAdvanceSummary, 'GROUP BY r.currency')
        && str_contains($localFinal, "if (\$method === 'POST')")
        && str_contains($localAdvance, "if (\$method === 'POST')")
        && str_contains($fxFinal, "if (\$method === 'POST')")
        && str_contains($fxAdvance, "if (\$method === 'POST')"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
