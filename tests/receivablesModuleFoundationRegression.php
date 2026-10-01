<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$foundation = $read('database/new/20260919_account_receivables_foundation.sql');
$cleanup = $read('database/new/20260919_account_receivables_remove_setup_reconciliation.sql');
$service = $read('includes/accountReceivablesService.php');
$route = $read('routes/receivables/bootstrap.php');
$index = $read('index.php');

$checks = [
    'foundation_and_cleanup_migrations_exist' => $foundation !== '' && $cleanup !== '',
    'source_of_truth_invoice_register_exists' => str_contains($foundation, 'account_receivable_invoices'),
    'temporary_setup_structures_are_removed_by_cleanup' =>
        str_contains($cleanup, 'DROP TABLE IF EXISTS account_receivable_projects')
        && str_contains($cleanup, 'DROP TABLE IF EXISTS account_receivable_ageing_bands')
        && str_contains($cleanup, 'DROP TABLE IF EXISTS account_receivable_settings')
        && str_contains($cleanup, 'DROP COLUMN project_id'),
    'standard_ageing_bands_are_application_rules' =>
        str_contains($service, 'ACCOUNT_RECEIVABLES_STANDARD_AGEING_BANDS')
        && str_contains($service, "'Not yet due'")
        && str_contains($service, "'1 - 30 days'")
        && str_contains($service, "'Over 365 days'"),
    'invoice_register_preserves_core_deduction_fields' =>
        str_contains($foundation, 'retention')
        && str_contains($foundation, 'advance_amortisation')
        && str_contains($foundation, 'vat_deducted_at_source')
        && str_contains($foundation, 'wht_credit_note_outstanding'),
    'bootstrap_only_requires_invoice_register_table' =>
        str_contains($service, "const ACCOUNT_RECEIVABLES_REQUIRED_TABLES = [\n    'account_receivable_invoices',\n];")
        && str_contains($service, 'missing_tables'),
    'report_context_is_derived_without_setup_tables' =>
        str_contains($service, 'function accountReceivablesLatestUsdNgnRate')
        && str_contains($service, "'reporting_date' => date('Y-m-d')")
        && !str_contains($service, 'FROM account_receivable_settings')
        && !str_contains($service, 'FROM account_receivable_ageing_bands')
        && !str_contains($service, 'FROM account_receivable_projects'),
    'route_is_admin_protected' => str_contains($route, 'requireAdmin()'),
    'route_is_registered' => str_contains($index, "'/receivables/bootstrap'")
        && str_contains($index, 'routes/receivables/bootstrap.php'),
    'procuredesk_tables_are_not_touched' => !str_contains($cleanup, 'procurement_'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
