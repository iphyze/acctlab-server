<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$route = (string) file_get_contents($root . '/routes/procurement/reports/supplierActivity.php');
$service = (string) file_get_contents($root . '/includes/procurementSupplierActivityReportService.php');

$checks = [
    'supplier_activity_route_registered' => str_contains(
        $index,
        "'/procurement/reports/supplier-activity' => 'routes/procurement/reports/supplierActivity.php'"
    ),
    'backend_foundation_is_get_only' => str_contains($route, "REQUEST_METHOD")
        && str_contains($route, 'Method not allowed.')
        && !str_contains($route, 'procurementRequireCsrfToken'),
    'report_is_permission_aware_across_all_four_scopes' =>
        str_contains($service, "'local_final' => ['request_type' => 'local_final_purchase', 'permission' => 'payments.local_final.view'")
        && str_contains($service, "'local_advance' => ['request_type' => 'local_advance_purchase', 'permission' => 'payments.local_advance.view'")
        && str_contains($service, "'fx_final' => ['request_type' => 'fx_final_purchase', 'permission' => 'payments.fx_final.view'")
        && str_contains($service, "'fx_advance' => ['request_type' => 'fx_advance_purchase', 'permission' => 'payments.fx_advance.view'")
        && str_contains($route, 'procurementRequireAnyPermission'),
    'all_requested_filters_are_supported' =>
        str_contains($service, "query['date_from']")
        && str_contains($service, "query['date_to']")
        && str_contains($service, "query['supplier_id']")
        && str_contains($service, "query['scope']")
        && str_contains($service, "query['status']")
        && str_contains($service, "query['payment_status']")
        && str_contains($service, "query['currency']")
        && str_contains($service, "query['date_basis']"),
    'status_filters_support_multiple_values_and_all' =>
        str_contains($service, 'procurementSupplierActivityReportParseMultiValue')
        && str_contains($service, "strcasecmp(\$part, 'all')")
        && str_contains($service, "IN ({\$placeholders})"),
    'currency_and_date_basis_offer_all' =>
        str_contains($service, "['value' => 'all', 'label' => 'All']")
        && str_contains($service, "'currencies' => array_merge(['All']")
        && str_contains($service, "'date_basis' => \$filters['date_basis']"),
    'date_basis_all_is_non_duplicating_or_logic' =>
        str_contains($service, "default => ['report.request_date', 'report.received_date', 'report.payment_date']")
        && str_contains($service, "implode(' OR ', \$dateParts)"),
    'advance_identity_resolves_from_po_and_revision' =>
        str_contains($service, 'procurement_local_advance_pos ap')
        && str_contains($service, 'procurement_local_advance_po_revisions apr')
        && str_contains($service, 'COALESCE(apr.supplier_id, ap.supplier_id, r.supplier_id)')
        && str_contains($service, 'COALESCE(NULLIF(TRIM(apr.po_number)'),
    'local_currency_is_forced_to_ngn_and_fx_stays_native' =>
        str_contains($service, "WHEN r.request_type IN ('local_final_purchase', 'local_advance_purchase') THEN 'NGN'")
        && str_contains($service, "UPPER(COALESCE(NULLIF(TRIM(r.currency)"),
    'financial_summary_is_grouped_by_currency' =>
        str_contains($service, 'GROUP BY report.currency')
        && str_contains($service, "'procurement_value'")
        && str_contains($service, "'payable'")
        && str_contains($service, "'paid'")
        && str_contains($service, "'outstanding'")
        && str_contains($service, "'wht'")
        && !preg_match('/SELECT\s+SUM\([^\)]*(?:payable_amount|paid_amount|procurement_value)[^\)]*\)\s+AS\s+total_(?:payable|paid|value)(?!.*GROUP BY report\.currency)/is', $service),
    'paid_and_outstanding_are_canonical_and_cancel_safe' =>
        str_contains($service, 'r.account_amount_paid')
        && str_contains($service, "report.payment_status = 'Cancelled' THEN 0.00")
        && str_contains($service, 'GREATEST('),
    'pagination_is_server_side_and_capped' =>
        str_contains($service, 'min(100, max(1')
        && str_contains($service, 'LIMIT ? OFFSET ?')
        && str_contains($service, "'total_pages' => (int) ceil(\$total / \$limit)"),
    'supplier_options_use_full_supplier_master_not_only_procuredesk_history' =>
        str_contains($service, 'procurementSupplierActivityReportMasterSuppliers')
        && str_contains($service, 'FROM suppliers_table')
        && !str_contains($service, 'supplier_number BETWEEN 40000000 AND 70000000')
        && str_contains($service, 'including suppliers with no prior')
        && !str_contains($service, 'GROUP BY report.supplier_id'),
    'report_rows_link_back_to_source_purchase' =>
        str_contains($service, '"/payments/local/final/{$publicId}"')
        && str_contains($service, '"/payments/local/advance/{$publicId}"')
        && str_contains($service, '"/payments/foreign/final/{$publicId}"')
        && str_contains($service, '"/payments/foreign/advance/{$publicId}"'),
    'foundation_creates_no_tables_or_migrations' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE')
        && !str_contains($service, 'DROP TABLE')
        && !str_contains($route, 'CREATE TABLE')
        && !str_contains($route, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
