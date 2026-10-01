<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$index = $read('index.php');
$reportService = $read('includes/accountReceivablesReportService.php');
$service = $read('includes/accountReceivablesService.php');
$cleanup = $read('database/new/20260919_account_receivables_remove_setup_reconciliation.sql');
$routes = [
    '/receivables/bootstrap' => 'routes/receivables/bootstrap.php',
    '/receivables/invoices' => 'routes/receivables/invoices.php',
    '/receivables/dashboard' => 'routes/receivables/dashboard.php',
    '/receivables/ageing' => 'routes/receivables/ageing.php',
    '/receivables/ageing/detail' => 'routes/receivables/ageingDetail.php',
    '/receivables/deductions' => 'routes/receivables/deductions.php',
    '/receivables/deductions/detail' => 'routes/receivables/deductionsDetail.php',
    '/receivables/reports' => 'routes/receivables/reports.php',
    '/receivables/reports/management-pack' => 'routes/receivables/exportManagementPack.php',
];

$routesRegistered = true;
$routesProtected = true;
foreach ($routes as $path => $file) {
    $source = $read($file);
    $routesRegistered = $routesRegistered
        && str_contains($index, "'{$path}'")
        && str_contains($index, $file);
    $routesProtected = $routesProtected && str_contains($source, 'requireAdmin()');
}

$receivablesSource = '';
foreach (glob($root . '/includes/accountReceivables*.php') ?: [] as $file) {
    $receivablesSource .= (string) @file_get_contents($file);
}
foreach (glob($root . '/routes/receivables/*.php') ?: [] as $file) {
    $receivablesSource .= (string) @file_get_contents($file);
}

$checks = [
    'all_receivables_api_routes_are_registered' => $routesRegistered,
    'all_receivables_api_routes_are_admin_protected' => $routesProtected,
    'setup_and_controls_api_routes_are_removed' =>
        !str_contains($index, "'/receivables/configuration'")
        && !str_contains($index, "'/receivables/controls'"),
    'module_remains_isolated_from_procuredesk' =>
        !str_contains(strtolower($receivablesSource), 'procuredesk')
        && !str_contains(strtolower($receivablesSource), 'procurement_'),
    'required_receivables_schema_migrations_are_present' =>
        is_file($root . '/database/new/20260919_account_receivables_foundation.sql')
        && is_file($root . '/database/new/20260919_account_receivables_invoice_register_crud.sql')
        && is_file($root . '/database/new/20260919_account_receivables_percentage_calculations.sql')
        && is_file($root . '/database/new/20260919_account_receivables_remove_setup_reconciliation.sql'),
    'standalone_service_does_not_query_removed_setup_tables' =>
        !str_contains($service, 'account_receivable_settings')
        && !str_contains($service, 'account_receivable_ageing_bands')
        && !str_contains($service, 'account_receivable_projects'),
    'cleanup_migration_removes_setup_tables_and_project_link' =>
        str_contains($cleanup, 'DROP TABLE IF EXISTS account_receivable_projects')
        && str_contains($cleanup, 'DROP TABLE IF EXISTS account_receivable_ageing_bands')
        && str_contains($cleanup, 'DROP TABLE IF EXISTS account_receivable_settings')
        && str_contains($cleanup, 'DROP COLUMN project_id'),
    'management_export_keeps_live_formulas' =>
        str_contains($reportService, 'setCellValue("AA{$dataRow}"')
        && str_contains($reportService, 'SUMIFS(')
        && str_contains($reportService, "='Ageing Report'!C"),
    'management_export_hardens_source_text' =>
        str_contains($reportService, 'accountReceivablesExcelSetText')
        && str_contains($reportService, 'DataType::TYPE_STRING'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
