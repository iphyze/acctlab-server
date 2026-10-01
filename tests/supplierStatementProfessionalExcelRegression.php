<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = file_get_contents($root . '/routes/reports/exportSupplierStatement.php') ?: '';
$service = file_get_contents($root . '/includes/accountSupplierStatementExcelService.php') ?: '';
$index = file_get_contents($root . '/index.php') ?: '';

$checks = [
    'export_endpoint_is_registered' => str_contains($index, "'/reports/supplierStatement/export' => 'routes/reports/exportSupplierStatement.php'"),
    'export_reuses_canonical_supplier_statement_service' => str_contains($route, 'accountSupplierStatementReport($writeConn, $_GET)'),
    'export_uses_phpspreadsheet_xlsx_writer' => str_contains($route, 'new Xlsx($workbook)')
        && str_contains($route, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    'professional_brand_header_is_present' => str_contains($service, 'ACCTLAB  •  SUPPLIER SUB-LEDGER')
        && str_contains($service, "'SUPPLIER STATEMENT'")
        && str_contains($service, 'ACCOUNT_SUPPLIER_XLSX_NAVY'),
    'summary_cards_cover_core_supplier_metrics' => str_contains($service, "'Opening payable'")
        && str_contains($service, "'New obligations'")
        && str_contains($service, "'Payments / settlements'")
        && str_contains($service, "'Closing payable'"),
    'currency_safety_is_explicit_and_no_cross_currency_total_is_created' => str_contains($service, 'Currency-safe statement: balances and running totals on this sheet are calculated only in ')
        && !str_contains($service, 'array_sum($statement[\'currencies\']'),
    'ledger_has_freeze_filter_print_and_repeat_header_configuration' => str_contains($service, "freezePane('A13')")
        && str_contains($service, 'setAutoFilter("A12:M{$closingRow}")')
        && str_contains($service, 'setRowsToRepeatAtTopByStartAndEnd(1, 12)')
        && str_contains($service, 'setFitToWidth(1)'),
    'opening_and_closing_rows_remain_explicit' => str_contains($service, 'Opening Balance B/F')
        && str_contains($service, 'Closing Balance C/F'),
    'status_cells_are_visually_coded' => str_contains($service, 'accountSupplierStatementExcelStyleStatusCell')
        && str_contains($service, 'ACCOUNT_SUPPLIER_XLSX_GREEN_SOFT')
        && str_contains($service, 'ACCOUNT_SUPPLIER_XLSX_AMBER_SOFT')
        && str_contains($service, 'ACCOUNT_SUPPLIER_XLSX_RED_SOFT'),
    'source_columns_are_removed_from_statement_and_allocation_sheets' => !str_contains($service, "'Source', 'Grouped Payment Allocations'")
        && !str_contains($service, "'Description', 'Linked Request'")
        && str_contains($service, 'setAutoFilter("A12:M{$closingRow}")')
        && str_contains($service, 'setAutoFilter("A9:L{$lastRow}")'),
    'excel_typography_is_increased_for_readability' => str_contains($service, "'size' => 22")
        && str_contains($service, "'size' => 16")
        && str_contains($service, "'size' => 10.5")
        && str_contains($service, "'size' => 10"),
    'grouped_payment_allocations_keep_separate_detail_sheets' => str_contains($service, "'PAYMENT ALLOCATION DETAIL'")
        && str_contains($service, '$currency . \' Allocations\'')
        && str_contains($service, 'statement_credit_amount')
        && str_contains($service, 'settlement_amount'),
    'text_cells_are_written_explicitly_to_avoid_formula_injection' => str_contains($service, 'setCellValueExplicit')
        && str_contains($service, 'DataType::TYPE_STRING'),
];

$failed = array_keys(array_filter($checks, static fn(bool $healthy): bool => !$healthy));
if ($failed !== []) {
    fwrite(STDERR, json_encode(['healthy' => false, 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo json_encode(['healthy' => true, 'checks' => $checks, 'failed' => []], JSON_PRETTY_PRINT) . PHP_EOL;
