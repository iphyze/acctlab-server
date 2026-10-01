<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../includes/accountScumlReportService.php');
$export = file_get_contents(__DIR__ . '/../routes/reports/exportScumlReport.php');
$index = file_get_contents(__DIR__ . '/../index.php');
$jsonRoute = file_get_contents(__DIR__ . '/../routes/reports/scumlReport.php');

$checks = [
    'export_route_is_registered' => str_contains($index, "'/reports/scumlReport/export' => 'routes/reports/exportScumlReport.php'"),
    'json_and_excel_share_the_same_data_service' => str_contains($jsonRoute, 'accountScumlFetchData($conn, $periodFrom, $periodTo)')
        && str_contains($export, 'accountScumlFetchData($conn, $periodFrom, $periodTo)'),
    'excel_uses_php_spreadsheet' => str_contains($export, 'new Xlsx($spreadsheet)')
        && str_contains($service, 'function accountBuildScumlWorkbook'),
    'cash_recon_palette_is_reused' => str_contains($service, "const ACCOUNT_SCUML_NAVY = '0A1D29';")
        && str_contains($service, "const ACCOUNT_SCUML_TEAL_DARK = '0B6D68';")
        && str_contains($service, "const ACCOUNT_SCUML_TEAL = '18A79D';"),
    'batch_total_rows_are_explicit' => str_contains($service, "strtoupper(accountScumlBatchLabel(\$group['batch'])) . ' TOTAL'")
        && str_contains($service, "'BATCH TOTAL'"),
    'batch_total_rows_have_high_contrast_amount_cell' => str_contains($service, 'function accountScumlStyleSubtotal')
        && str_contains($service, "'startColor' => ['rgb' => ACCOUNT_SCUML_TOTAL_SOFT]")
        && str_contains($service, "'startColor' => ['rgb' => ACCOUNT_SCUML_TEAL_DARK]")
        && str_contains($service, "'color' => ['rgb' => ACCOUNT_SCUML_WHITE]"),
    'batch_total_rows_have_medium_separators' => substr_count($service, "Border::BORDER_MEDIUM") >= 4,
    'detail_rows_are_zebra_striped' => str_contains($service, 'ACCOUNT_SCUML_ROW_ALT')
        && str_contains($service, 'accountScumlStyleDetailRow'),
    'summary_counts_batch_groups' => str_contains($service, 'count(accountScumlGroupRows($rows))'),
    'account_numbers_are_exported_as_text' => str_contains($service, 'setCellValueExplicit')
        && str_contains($service, 'DataType::TYPE_STRING'),
    'amounts_use_accounting_friendly_number_format' => str_contains($service, "#,##0.00;[Red]-#,##0.00;–"),
    'period_validation_still_caps_at_31_days' => str_contains($service, 'Date range cannot exceed one month (31 days)'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) $failed[] = $name;
}

echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
