<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/accountReceivablesService.php';

$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$index = $read('index.php');
$route = $read('routes/receivables/invoices.php');
$migration = $read('database/new/20260919_account_receivables_invoice_register_crud.sql');
$service = $read('includes/accountReceivablesService.php');

$settings = [
    'reporting_date' => '2026-09-19',
    'ageing_basis' => 'invoice_date',
    'default_credit_days' => 30,
    'usd_ngn_rate' => 1550,
    'settled_threshold' => 0.005,
];
$bands = [
    ['from_days' => -999999, 'to_days' => -1, 'label' => 'Not yet due'],
    ['from_days' => 0, 'to_days' => 30, 'label' => '1 - 30 days'],
    ['from_days' => 31, 'to_days' => 60, 'label' => '31 - 60 days'],
    ['from_days' => 61, 'to_days' => 90, 'label' => '61 - 90 days'],
    ['from_days' => 91, 'to_days' => 120, 'label' => '91 - 120 days'],
    ['from_days' => 121, 'to_days' => 180, 'label' => '121 - 180 days'],
    ['from_days' => 181, 'to_days' => 365, 'label' => '181 - 365 days'],
    ['from_days' => 366, 'to_days' => null, 'label' => 'Over 365 days'],
];
$row = [
    'id' => 1,
    'project_id' => null,
    'project_name' => 'ICC MAIN JOB - JB',
    'client_name' => 'Julius Berger Nigeria',
    'invoice_number' => '3326',
    'invoice_date' => '2025-08-05',
    'credit_days' => 30,
    'due_date' => '2025-09-04',
    'currency' => 'NGN',
    'line_type' => 'Invoice',
    'invoice_value_net' => 1568736600.27,
    'retention' => 0,
    'advance_amortisation' => 0,
    'admin_other_charges' => 0,
    'vat_charged' => 117655245.02025,
    'invoice_value_gross' => 1686391845.29025,
    'wht' => 31374732.0054,
    'vat_deducted_at_source' => 0,
    'ncd_levy' => 0,
    'stamp_duty' => 0,
    'bank_charges' => 0,
    'other_deductions' => 0,
    'amount_received' => 0,
    'wht_credit_note_outstanding' => 31374732.0054,
    'fx_rate_used' => null,
    'position' => 'Open',
    'date_basis_note' => null,
    'source_reference' => null,
    'remarks' => null,
];
$decorated = accountReceivablesDecorateInvoice($row, $settings, $bands);

$checks = [
    'crud_route_is_registered' => str_contains($index, "'/receivables/invoices'") && str_contains($index, 'routes/receivables/invoices.php'),
    'crud_route_is_admin_protected' => str_contains($route, 'requireAdmin()'),
    'crud_route_supports_all_methods' => str_contains($route, "if (\$method === 'GET')")
        && str_contains($route, "if (\$method === 'POST')")
        && str_contains($route, "if (\$method === 'PUT')")
        && str_contains($route, "if (\$method === 'DELETE')"),
    'workbook_line_types_are_canonical' => ACCOUNT_RECEIVABLES_LINE_TYPES === ['Invoice', 'Receipt', 'Adjustment', 'Balance b/f'],
    'workbook_positions_are_canonical' => in_array('Review - not in reported receivable', ACCOUNT_RECEIVABLES_POSITIONS, true),
    'position_column_supports_full_review_label' => str_contains($migration, 'position VARCHAR(60)'),
    'soft_delete_is_preserved' => str_contains($service, 'deleted_at = NOW()') && str_contains($service, 'deleted_by = ?'),
    'due_date_is_server_derived' => str_contains($service, 'modify("+{$creditDays} days")'),
    'total_deductions_match_workbook_logic' => abs($decorated['total_deductions'] - 31374732.01) < 0.01,
    'net_receivable_matches_workbook_logic' => abs($decorated['net_receivable'] - 1655017113.28) < 0.01,
    'outstanding_matches_workbook_logic' => abs($decorated['outstanding'] - 1655017113.28) < 0.01,
    'ageing_is_derived_from_reporting_date' => $decorated['status'] === 'Overdue' && $decorated['ageing_band'] === 'Over 365 days',
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
