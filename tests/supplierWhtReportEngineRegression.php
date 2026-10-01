<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/accountSupplierWhtReportService.php';

function supplierWhtNear(float $actual, float $expected, float $tolerance = 0.01): bool
{
    return abs($actual - $expected) <= $tolerance;
}

function supplierWhtLine(array $overrides): array
{
    return array_merge([
        'id' => 1,
        'supplier_id' => 10,
        'supplier_name' => 'Supplier A',
        'supplier_wht_status' => '5.00%',
        'invoice_number' => 'INV-1',
        'purchase_number' => 'PUR-1',
        'vat_policy' => '5.00%',
        'vat' => '0',
        'wht' => '0',
        'wht_override_status' => null,
        'wht_override_amount' => null,
        'net_value' => '100000',
        'discount' => '0',
        'other_charges' => '0',
        'amount' => '102500',
        'date_received' => '',
        'purchase_date' => '2025-06-14',
        'invoice_date' => '2025-06-13',
        'created_at' => '2025-06-15 08:00:00',
        'effective_date' => '2025-06-15',
    ], $overrides);
}

$checks = [];

$checks['payment_status_defaults_to_paid'] =
    accountSupplierWhtNormalizePaymentStatusFilter(null) === 'Paid'
    && accountSupplierWhtNormalizePaymentStatusFilter('') === 'Paid'
    && accountSupplierWhtNormalizePaymentStatusFilter('paid') === 'Paid';

$checks['payment_status_supports_all_and_known_statuses'] =
    accountSupplierWhtNormalizePaymentStatusFilter('all') === 'all'
    && accountSupplierWhtNormalizePaymentStatusFilter('Processing') === 'Processing'
    && accountSupplierWhtNormalizePaymentStatusFilter('Cancelled') === 'Cancelled'
    && accountSupplierWhtNormalizePaymentStatusFilter('Unconfirmed') === 'Unconfirmed';

$invalidStatusRejected = false;
try {
    accountSupplierWhtNormalizePaymentStatusFilter('NotARealStatus');
} catch (RuntimeException $error) {
    $invalidStatusRejected = $error->getCode() === 400;
}
$checks['invalid_payment_status_is_rejected'] = $invalidStatusRejected;

$legacyFive = accountSupplierWhtCalculateLine(supplierWhtLine([]));
$checks['legacy_7_5_vat_and_5_wht_is_reconstructed_from_payable'] =
    supplierWhtNear((float) $legacyFive['vat_amount'], 7500.00)
    && supplierWhtNear((float) $legacyFive['gross_amount'], 107500.00)
    && supplierWhtNear((float) $legacyFive['wht_amount'], 5000.00)
    && $legacyFive['method'] === 'legacy_7_5_vat_gross_less_payable';

$legacyTwo = accountSupplierWhtCalculateLine(supplierWhtLine([
    'vat_policy' => '2.00%',
    'supplier_wht_status' => '2.00%',
    'amount' => '105500',
]));
$checks['legacy_two_percent_wht_is_supported'] = supplierWhtNear((float) $legacyTwo['wht_amount'], 2000.00);

$fullVat = accountSupplierWhtCalculateLine(supplierWhtLine([
    'vat_policy' => '7.50%',
    'vat' => '7500',
    'wht' => '0',
    'amount' => '107500',
]));
$checks['full_vat_paid_to_supplier_does_not_create_false_wht'] = supplierWhtNear((float) $fullVat['wht_amount'], 0.00);

$noVat = accountSupplierWhtCalculateLine(supplierWhtLine([
    'vat_policy' => '0.00%',
    'supplier_wht_status' => '5.00%',
    'amount' => '100000',
]));
$checks['zero_vat_line_does_not_infer_hypothetical_wht'] = !$noVat['vat_charged']
    && supplierWhtNear((float) $noVat['wht_amount'], 0.00)
    && $noVat['method'] === 'no_vat_no_wht';

$explicit = accountSupplierWhtCalculateLine(supplierWhtLine([
    'vat' => '7500',
    'wht' => '5000',
    'amount' => '102500',
]));
$checks['stored_wht_is_authoritative_for_current_rows'] = supplierWhtNear((float) $explicit['wht_amount'], 5000.00)
    && $explicit['method'] === 'stored_wht';

$override = accountSupplierWhtCalculateLine(supplierWhtLine([
    'vat' => '7500',
    'wht' => '5000',
    'wht_override_status' => 'Adjusted',
    'wht_override_amount' => '3500',
    'amount' => '104000',
]));
$checks['explicit_account_override_has_highest_priority'] = supplierWhtNear((float) $override['wht_amount'], 3500.00)
    && $override['method'] === 'explicit_override';

$withDiscountAndCharge = accountSupplierWhtCalculateLine(supplierWhtLine([
    'net_value' => '120000',
    'discount' => '20000',
    'other_charges' => '10000',
    'amount' => '112500',
]));
$checks['discount_and_other_charges_are_reconciled_correctly'] = supplierWhtNear((float) $withDiscountAndCharge['taxable_base'], 100000.00)
    && supplierWhtNear((float) $withDiscountAndCharge['gross_amount'], 117500.00)
    && supplierWhtNear((float) $withDiscountAndCharge['wht_amount'], 5000.00);

$fingerprint = accountSupplierWhtCalculateLine(supplierWhtLine([
    'vat_policy' => 'Balance Payment',
    'vat' => '0',
    'wht' => '0',
    'amount' => '102500',
]));
$checks['legacy_free_text_policy_requires_tax_fingerprint'] = $fingerprint['vat_charged']
    && supplierWhtNear((float) $fingerprint['wht_amount'], 5000.00)
    && $fingerprint['method'] === 'legacy_tax_fingerprint';

$report = accountSupplierWhtBuildReport([
    supplierWhtLine(['id' => 1, 'effective_date' => '2022-07-01', 'amount' => '102500']),
    supplierWhtLine(['id' => 2, 'effective_date' => '2023-07-01', 'amount' => '105500', 'vat_policy' => '2.00%', 'supplier_wht_status' => '2.00%']),
    supplierWhtLine(['id' => 3, 'supplier_id' => 20, 'supplier_name' => 'Supplier B', 'effective_date' => '2023-08-01', 'amount' => '102500']),
], []);
$rowsBySupplier = [];
foreach ($report['rows'] as $row) {
    $rowsBySupplier[$row['supplier']] = $row;
}
$checks['report_groups_by_supplier_and_dynamic_year'] = $report['years'] === [2022, 2023]
    && supplierWhtNear((float) $rowsBySupplier['Supplier A']['years']['2022'], 5000.00)
    && supplierWhtNear((float) $rowsBySupplier['Supplier A']['years']['2023'], 2000.00)
    && supplierWhtNear((float) $rowsBySupplier['Supplier B']['years']['2023'], 5000.00)
    && supplierWhtNear((float) $report['summary']['total_wht'], 12000.00);

$malformedReceivedDate = accountSupplierWhtBuildReport([
    supplierWhtLine([
        'id' => 4,
        'effective_date' => '0022-10-21',
        'date_received' => '0022-10-21',
        'purchase_date' => '2022-10-14',
        'invoice_date' => '2022-10-06',
        'amount' => '102500',
    ]),
], []);
$checks['malformed_received_year_falls_back_to_valid_purchase_year'] = $malformedReceivedDate['years'] === [2022]
    && supplierWhtNear((float) $malformedReceivedDate['summary']['by_year']['2022'], 5000.00);

$legacySupplierIdentity = accountSupplierWhtBuildReport([
    supplierWhtLine([
        'id' => 5,
        'supplier_id' => 596,
        'supplier_name' => "Goster Int'l Ltd",
        'effective_date' => '2024-01-30',
        'amount' => '102500',
    ]),
    supplierWhtLine([
        'id' => 6,
        'supplier_id' => 40110595,
        'supplier_name' => "Goster Int'l Ltd",
        'effective_date' => '2025-01-30',
        'amount' => '102500',
    ]),
], []);
$checks['legacy_supplier_id_and_supplier_number_group_as_one_supplier'] = count($legacySupplierIdentity['rows']) === 1
    && $legacySupplierIdentity['rows'][0]['supplier_id'] === 596
    && supplierWhtNear((float) $legacySupplierIdentity['rows'][0]['total_wht'], 10000.00);

$service = file_get_contents($root . '/includes/accountSupplierWhtReportService.php') ?: '';
$route = file_get_contents($root . '/routes/reports/supplierWht.php') ?: '';
$index = file_get_contents($root . '/index.php') ?: '';
$checks['endpoint_registered_and_admin_protected'] = str_contains($index, "'/reports/supplierWht'")
    && str_contains($route, 'requireAdmin()')
    && str_contains($route, 'accountSupplierWhtReport');
$checks['filters_are_parameterized'] = str_contains($service, 'bind_param')
    && str_contains($service, 'supplier_id = ?')
    && str_contains($service, '$effectiveDate >= ?')
    && str_contains($service, '$effectiveDate <= ?')
    && str_contains($service, "LOWER(COALESCE(NULLIF(TRIM(sfr.payment_status), ''), 'Pending')) = LOWER(?)")
    && str_contains($service, '$params[] = $fromDate')
    && str_contains($service, '$params[] = $toDate')
    && str_contains($service, '$params[] = $paymentStatus');
$checks['malformed_source_dates_are_ignored_before_date_precedence'] = str_contains($service, "REGEXP '^20[0-9]{2}-[0-9]{2}-[0-9]{2}$'")
    && str_contains($service, 'accountSupplierWhtResolveReportDate');
$checks['legacy_supplier_identifiers_are_normalized_for_grouping_and_filters'] = str_contains($service, 'supplier_number_map')
    && str_contains($service, 'supplier_name_map')
    && str_contains($service, 'sfr.supplier_id = ? OR sfr.supplier_id = ?')
    && str_contains($service, 'accountSupplierWhtSupplierGroupKey');
$checks['paid_is_default_but_status_filter_can_be_overridden'] =
    str_contains($service, "return 'Paid';")
    && str_contains($service, 'if ($paymentStatus !== \'all\')')
    && str_contains($service, "ACCOUNT_SUPPLIER_WHT_PAYMENT_STATUSES")
    && !str_contains($service, "<> 'Cancelled'");
$checks['no_schema_change_is_required'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $service . $route);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
