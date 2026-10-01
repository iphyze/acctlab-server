<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$fixtureSupplier = [
    'supplier_name' => 'Air Conditioning Technologies Nig Ltd',
    'supplier_number' => '4011335',
];

$legacyLowerBound = 40000000;
$fixtureWasExcludedByLegacyRange = (int) $fixtureSupplier['supplier_number'] < $legacyLowerBound;

$files = [
    'legacy_supplier_lookup' => 'routes/data/fetchSuppliers.php',
    'local_advance_supplier_options' => 'routes/procurement/payments/local/advancePurchaseOptions.php',
    'local_final_supplier_options' => 'routes/procurement/payments/local/finalPurchaseOptions.php',
    'fx_advance_supplier_options' => 'routes/procurement/payments/foreign/advancePurchaseOptions.php',
    'fx_final_supplier_options' => 'routes/procurement/payments/foreign/finalPurchaseOptions.php',
    'supplier_report_lookup' => 'includes/procurementSupplierActivityReportService.php',
    'fx_fund_request_validation' => 'includes/fxFundRequestService.php',
];

$sources = [];
foreach ($files as $key => $relativePath) {
    $sources[$key] = (string) file_get_contents($root . '/' . $relativePath);
}

$legacyRange = 'supplier_number BETWEEN 40000000 AND 70000000';

$checks = [
    'fixture_reproduces_original_bug' => $fixtureWasExcludedByLegacyRange,
    'legacy_lookup_reads_full_supplier_register' =>
        str_contains($sources['legacy_supplier_lookup'], 'FROM suppliers_table')
        && str_contains($sources['legacy_supplier_lookup'], 'WHERE 1=1')
        && !str_contains($sources['legacy_supplier_lookup'], $legacyRange),
    'legacy_lookup_can_search_fixture_name_or_number' =>
        str_contains($sources['legacy_supplier_lookup'], 'supplier_name LIKE ?')
        && str_contains($sources['legacy_supplier_lookup'], 'supplier_number LIKE ?')
        && str_contains($fixtureSupplier['supplier_name'], 'Air Conditioning')
        && str_starts_with($fixtureSupplier['supplier_number'], '4011335'),
    'local_purchase_supplier_options_have_no_number_range' =>
        !str_contains($sources['local_advance_supplier_options'], $legacyRange)
        && !str_contains($sources['local_final_supplier_options'], $legacyRange)
        && str_contains($sources['local_advance_supplier_options'], 'FROM suppliers_table')
        && str_contains($sources['local_final_supplier_options'], 'FROM suppliers_table'),
    'fx_purchase_supplier_options_have_no_number_range' =>
        !str_contains($sources['fx_advance_supplier_options'], $legacyRange)
        && !str_contains($sources['fx_final_supplier_options'], $legacyRange)
        && str_contains($sources['fx_advance_supplier_options'], 'FROM suppliers_table')
        && str_contains($sources['fx_final_supplier_options'], 'FROM suppliers_table'),
    'supplier_report_filter_uses_full_supplier_master' =>
        !str_contains($sources['supplier_report_lookup'], $legacyRange)
        && str_contains($sources['supplier_report_lookup'], 'procurementSupplierActivityReportMasterSuppliers')
        && str_contains($sources['supplier_report_lookup'], 'FROM suppliers_table'),
    'fx_save_validation_accepts_any_registered_supplier_id' =>
        !str_contains($sources['fx_fund_request_validation'], $legacyRange)
        && str_contains($sources['fx_fund_request_validation'], 'WHERE id = ?'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode([
    'healthy' => $failed === [],
    'fixture' => $fixtureSupplier,
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
