<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php') ?: '';
$route = file_get_contents($root . '/routes/reports/exportSupplierWht.php') ?: '';
$service = file_get_contents($root . '/includes/accountSupplierWhtReportExcelService.php') ?: '';

$checks = [
    'styled_export_endpoint_is_registered' => str_contains($index, "'/reports/supplierWht/export' => 'routes/reports/exportSupplierWht.php'"),
    'export_reuses_canonical_wht_report_engine_with_details' => str_contains($route, 'accountSupplierWhtExportReport($writeConn, $_GET)'),
    'export_is_admin_protected_and_uses_phpspreadsheet' => str_contains($route, 'requireAdmin()')
        && str_contains($route, 'new Xlsx($workbook)')
        && str_contains($route, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    'professional_brand_header_matches_finance_exports' => str_contains($service, 'ACCTLAB  •  SUPPLIER TAX REPORTING')
        && str_contains($service, "SUPPLIER'S WITHHOLDING TAX REPORT")
        && str_contains($service, 'ACCOUNT_SUPPLIER_WHT_XLSX_NAVY')
        && str_contains($service, 'ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_DARK'),
    'summary_cards_surface_management_metrics' => str_contains($service, "'Suppliers'")
        && str_contains($service, "'Report years'")
        && str_contains($service, "'WHT lines'")
        && str_contains($service, "'Total WHT'"),
    'schedule_keeps_dynamic_year_columns_and_total' => str_contains($service, "['S/N', 'Supplier', ...array_map")
        && str_contains($service, "['by_year'][(string) \$year]")
        && str_contains($service, "accountSupplierWhtExcelText(\$sheet, 'A' . \$totalRow, 'TOTAL')"),
    'report_context_is_printed_in_workbook' => str_contains($service, 'Supplier scope: ')
        && str_contains($service, 'Payment status: ')
        && str_contains($service, 'Reporting period: '),
    'workbook_has_professional_sheet_behaviour' => str_contains($service, "freezePane('C11')")
        && str_contains($service, 'setAutoFilter')
        && str_contains($service, 'setRowsToRepeatAtTopByStartAndEnd(1, 10)')
        && str_contains($service, 'setFitToWidth(1)')
        && str_contains($service, 'setPrintArea'),
    'table_uses_zebra_rows_and_currency_formatting' => str_contains($service, 'ACCOUNT_SUPPLIER_WHT_XLSX_ROW_ALT')
        && str_contains($service, 'accountSupplierWhtExcelMoneyFormat()')
        && str_contains($service, 'Border::BORDER_HAIR'),
    'supplier_text_is_written_explicitly_for_excel_safety' => str_contains($service, 'setCellValueExplicit')
        && str_contains($service, 'DataType::TYPE_STRING'),
    'workbook_contains_grouped_wht_detail_sheet' => str_contains($service, "new Worksheet(\$workbook, 'WHT Detail')")
        && str_contains($service, 'WHT SOURCE LINES  •  GROUPED BY SUPPLIER')
        && str_contains($service, 'WHT SUBTOTAL')
        && str_contains($service, 'GRAND TOTAL WHT'),
    'detail_sheet_contains_fund_request_fields' => str_contains($service, "'Invoice Number'")
        && str_contains($service, "'Purchase Number'")
        && str_contains($service, "'PO Number'")
        && str_contains($service, "'Project Code'")
        && str_contains($service, "'VAT Status / Rate'")
        && str_contains($service, "'WHT Amount'")
        && str_contains($service, "'Payment Status'")
        && str_contains($service, "'Amount Eventually Paid'")
        && str_contains($service, "'Account Remarks'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $healthy): bool => !$healthy));
if ($failed !== []) {
    fwrite(STDERR, json_encode(['healthy' => false, 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo json_encode(['healthy' => true, 'checks' => $checks, 'failed' => []], JSON_PRETTY_PRINT) . PHP_EOL;
