<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$route = (string) file_get_contents($root . '/routes/procurement/reports/supplierActivityExport.php');
$service = (string) file_get_contents($root . '/includes/procurementSupplierActivityReportService.php');

$checks = [
    'excel_export_route_is_registered' => str_contains(
        $index,
        "'/procurement/reports/supplier-activity/export' => 'routes/procurement/reports/supplierActivityExport.php'"
    ),
    'export_is_get_only_and_permission_aware' =>
        str_contains($route, 'REQUEST_METHOD')
        && str_contains($route, 'procurementRequireAnyPermission')
        && str_contains($route, 'procurementSupplierActivityReportPermissionCodes'),
    'export_reuses_exact_supplier_activity_filter_engine' =>
        str_contains($route, 'procurementSupplierActivityReportPrepareFilters($_GET, $visibleScopes)')
        && str_contains($route, 'procurementSupplierActivityReportBuildWhere($filters)')
        && str_contains($route, 'procurementSupplierActivityReportBaseSql($filters[\'request_types\'])')
        && str_contains($route, 'procurementSupplierActivityReportSerializeRow'),
    'export_is_not_paginated_and_has_safety_cap' =>
        str_contains($route, 'PROCUREMENT_REPORT_MAX_EXPORT_ROWS = 50000')
        && str_contains($route, 'more than ')
        && str_contains($route, 'Narrow the filters before exporting.'),
    'cash_desk_reference_palette_is_reused' =>
        str_contains($route, "PROCUREMENT_REPORT_NAVY = '0A1D29'")
        && str_contains($route, "PROCUREMENT_REPORT_TEAL = '18A79D'")
        && str_contains($route, "PROCUREMENT_REPORT_TEAL_DARK = '0B6D68'")
        && str_contains($route, "PROCUREMENT_REPORT_BORDER = 'D8E5E9'"),
    'professional_summary_sheet_contains_filters_counts_and_currency_financials' =>
        str_contains($route, "setTitle('Summary')")
        && str_contains($route, 'REPORT FILTERS')
        && str_contains($route, 'ACTIVITY SNAPSHOT')
        && str_contains($route, 'FINANCIAL POSITION BY CURRENCY')
        && str_contains($route, "['Currency', 'Requests', 'Procurement Value', 'Payable', 'Paid', 'Outstanding', 'WHT']"),
    'all_transactions_sheet_is_exported' =>
        str_contains($route, "procurementReportBuildTransactionSheet(\$spreadsheet, 'All Transactions'")
        && str_contains($route, "'PO / Purchase No.'")
        && str_contains($route, "'Advance %'")
        && str_contains($route, "'Payment Status'")
        && str_contains($route, "'Account Status'"),
    'currency_sheets_are_created_only_when_multiple_currencies_exist' =>
        str_contains($route, 'if (count($byCurrency) > 1)')
        && str_contains($route, '$byCurrency[$currency][] = $row'),
    'money_is_numeric_and_currency_is_kept_as_its_own_column' =>
        str_contains($route, 'procurementReportMoneyFormat')
        && str_contains($route, "'Currency'")
        && str_contains($route, "(float) (\$row['procurement_value'] ?? 0)")
        && str_contains($route, "(float) (\$row['paid_amount'] ?? 0)")
        && !preg_match('/SUM\([^\)]*currency[^\)]*\)/i', $route),
    'percentage_format_follows_shared_display_rule' =>
        str_contains($route, '0.##"%"')
        && str_contains($route, 'procurementReportPercentFormat'),
    'workbook_is_frozen_filtered_print_ready_and_downloadable' =>
        str_contains($route, 'freezePane')
        && str_contains($route, 'setAutoFilter')
        && str_contains($route, 'setPrintArea')
        && str_contains($route, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        && str_contains($route, 'Content-Disposition: attachment'),
    'export_does_not_change_storage_or_workflows' =>
        !str_contains($route, 'INSERT INTO')
        && !str_contains($route, 'UPDATE ')
        && !str_contains($route, 'DELETE FROM')
        && !str_contains($route, 'CREATE TABLE')
        && !str_contains($route, 'ALTER TABLE')
        && !str_contains($service, 'CREATE TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
