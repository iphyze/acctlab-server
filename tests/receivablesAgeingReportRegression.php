<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/accountReceivablesAgeingService.php');
$summaryRoute = $read('routes/receivables/ageing.php');
$detailRoute = $read('routes/receivables/ageingDetail.php');
$index = $read('index.php');

$checks = [
    'ageing_routes_are_registered' =>
        str_contains($index, "'/receivables/ageing'")
        && str_contains($index, "'/receivables/ageing/detail'")
        && str_contains($index, 'routes/receivables/ageing.php')
        && str_contains($index, 'routes/receivables/ageingDetail.php'),
    'ageing_routes_are_admin_protected' =>
        str_contains($summaryRoute, 'requireAdmin()')
        && str_contains($detailRoute, 'requireAdmin()'),
    'ageing_reuses_canonical_receivables_calculations' =>
        str_contains($service, 'accountReceivablesDecorateInvoice')
        && str_contains($service, 'accountReceivablesSettings')
        && str_contains($service, 'accountReceivablesAgeingBands'),
    'ageing_only_uses_open_register_rows' =>
        str_contains($service, 'position = \'Open\'')
        && str_contains($service, 'deleted_at IS NULL'),
    'ageing_bands_drive_report_columns' =>
        str_contains($service, 'accountReceivablesAgeingEmptyBandTotals')
        && str_contains($service, '(string) $band[\'label\']'),
    'project_level_report_contains_workbook_controls' =>
        str_contains($service, "'percentage_of_currency_total'")
        && str_contains($service, "'open_invoices'")
        && str_contains($service, "'unaged_open_count'")
        && str_contains($service, "'band_totals'"),
    'band_detail_contains_invoice_evidence_without_reconciliation_module' =>
        str_contains($service, "'listed_total'")
        && str_contains($service, "'wht_credit_note_outstanding'")
        && !str_contains($service, "'reconciliation_difference'"),
    'detail_defaults_to_one_currency' =>
        str_contains($service, '$requestedCurrency = \'NGN\'')
        && str_contains($service, '$detailQuery[\'currency\'] = $requestedCurrency'),
    'batch_requires_no_new_database_tables' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
