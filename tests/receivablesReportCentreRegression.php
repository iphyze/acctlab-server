<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/accountReceivablesReportService.php');
$centreRoute = $read('routes/receivables/reports.php');
$exportRoute = $read('routes/receivables/exportManagementPack.php');
$index = $read('index.php');

$requestedOrder = "'Management Dashboard',\n                'Ageing Report',\n                'Invoice Register',\n                'Band Details',\n                'Deductions & Tax',";

$checks = [
    'report_centre_routes_are_registered' =>
        str_contains($index, "'/receivables/reports'")
        && str_contains($index, "'/receivables/reports/management-pack'")
        && str_contains($index, 'routes/receivables/reports.php')
        && str_contains($index, 'routes/receivables/exportManagementPack.php'),
    'report_routes_are_admin_protected' =>
        str_contains($centreRoute, 'requireAdmin()')
        && str_contains($exportRoute, 'requireAdmin()'),
    'management_pack_has_exact_requested_sheet_order' =>
        str_contains($service, $requestedOrder)
        && str_contains($service, "foreach (['Ageing Report', 'Invoice Register', 'Band Details', 'Deductions & Tax'] as \$sheetName)"),
    'setup_and_reconciliation_sheets_are_absent' =>
        !str_contains($service, "'Setup & Lists'")
        && !str_contains($service, "'Reconciliation'")
        && !str_contains($service, 'accountReceivablesExcelBuildSetupSheet')
        && !str_contains($service, 'accountReceivablesExcelBuildReconciliation'),
    'invoice_register_contains_live_standalone_formulas' =>
        str_contains($service, 'setCellValue("X{$dataRow}"')
        && str_contains($service, 'setCellValue("AA{$dataRow}"')
        && str_contains($service, 'TODAY()')
        && !str_contains($service, "'Setup & Lists'!"),
    'management_outputs_use_cross_sheet_formulas' =>
        str_contains($service, 'SUMIFS(')
        && str_contains($service, "='Ageing Report'!C")
        && str_contains($service, 'COUNTIF('),
    'management_export_supports_optional_scope_filters' =>
        str_contains($service, 'function accountReceivablesReportPeriod')
        && str_contains($service, "'date_from'")
        && str_contains($service, "'date_to'")
        && str_contains($service, "'project_name'")
        && str_contains($service, "'client_name'")
        && str_contains($service, 'accountReceivablesReportFilterOptions')
        && str_contains($exportRoute, "\$_GET['date_from']")
        && str_contains($exportRoute, "\$_GET['date_to']")
        && str_contains($exportRoute, "\$_GET['project_name']")
        && str_contains($exportRoute, "\$_GET['client_name']")
        && str_contains($exportRoute, 'No Invoice Register entries match the selected report filters.'),
    'ageing_cells_link_to_band_detail_evidence' =>
        str_contains($service, 'function accountReceivablesExcelInternalLink')
        && str_contains($service, "accountReceivablesExcelInternalLink(\$sheet, \"A{\$current}\", 'Band Details'")
        && str_contains($service, "\$drillAnchors['bands']")
        && str_contains($service, 'ALL PROJECT REGISTER ENTRIES')
        && str_contains($service, 'SECTION TOTAL'),
    'workbook_has_management_print_and_navigation_features' =>
        str_contains($service, 'freezePane')
        && str_contains($service, 'setPrintArea')
        && str_contains($service, 'setRowsToRepeatAtTopByStartAndEnd')
        && str_contains($service, 'setAutoFilter'),
    'management_dashboard_includes_chart' =>
        str_contains($service, 'AgeingProfileChart')
        && str_contains($exportRoute, 'setIncludeCharts(true)'),
    'excel_export_preserves_formulas_for_excel_recalculation' =>
        str_contains($exportRoute, 'setPreCalculateFormulas(false)')
        && str_contains($exportRoute, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    'excel_source_text_is_written_explicitly_as_text' =>
        str_contains($service, 'use PhpOffice\\PhpSpreadsheet\\Cell\\DataType;')
        && str_contains($service, 'function accountReceivablesExcelSetText')
        && str_contains($service, 'DataType::TYPE_STRING'),
    'download_header_supports_utf8_filename' =>
        str_contains($exportRoute, "filename*=UTF-8\\'\\'")
        && str_contains($exportRoute, 'rawurlencode($filename)'),
    'management_total_rows_keep_contrast' =>
        str_contains($service, 'if ($totalRow > $firstData)')
        && str_contains($service, 'if ($last >= $first)')
        && str_contains($service, 'if ($ageingTotal > 16)'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
