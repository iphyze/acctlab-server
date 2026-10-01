<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$index = $read('index.php');
$helpers = $read('routes/cash/cashHelpers.php');
$disburse = $read('routes/cash/disburseCash.php');
$update = $read('routes/cash/updateTransaction.php');
$reverse = $read('routes/cash/reverseTransaction.php');
$reportHelpers = $read('routes/cash/cashReportHelpers.php');
$export = $read('routes/cash/exportReports.php');
$migrationFunding = $read('database/new/20260920_cash_disbursement_mutilated_funding.sql');
$migrationAllocations = $read('database/new/20260920_cash_disbursement_allocation_persistence.sql');

$checks = [
    'allocation_options_route_is_registered' =>
        str_contains($index, "'/cash/allocation-options' => 'routes/cash/getAllocationOptions.php'"),
    'mutilated_funding_schema_and_usage_history_exist' =>
        str_contains($migrationFunding, 'cash_disbursement_funding')
        && str_contains($migrationFunding, 'cash_mutilated_cash_usages')
        && str_contains($migrationFunding, 'remaining_amount'),
    'normalized_allocation_schema_exists' =>
        str_contains($migrationAllocations, 'cash_disbursement_ledger_allocations')
        && str_contains($migrationAllocations, 'cash_disbursement_project_allocations'),
    'new_disbursements_validate_funding_and_nested_allocations' =>
        str_contains($disburse, 'cashNormalizeExpenseAllocations')
        && str_contains($disburse, 'cashSaveDisbursementFunding')
        && str_contains($disburse, 'cashSaveDisbursementAllocations')
        && str_contains($disburse, 'cashConsumeMutilatedCashForDisbursement'),
    'allocation_validator_reconciles_ledgers_projects_and_transaction' =>
        str_contains($helpers, 'function cashNormalizeExpenseAllocations')
        && str_contains($helpers, 'Project allocations under {$ledgerName} must equal the ledger amount.')
        && str_contains($helpers, 'Expense ledger allocations must equal the total disbursement amount.'),
    'corrections_preserve_allocation_and_funding_controls' =>
        str_contains($update, 'cashSaveDisbursementAllocations')
        && str_contains($update, 'cashSaveDisbursementFunding')
        && str_contains($update, 'Update the ledger/project allocations when changing the disbursement amount.'),
    'reversal_restores_mutilated_reserve_and_marks_allocations_reversed' =>
        str_contains($reverse, 'cashRestoreMutilatedCashForReversedDisbursement')
        && str_contains($reverse, 'cashMarkDisbursementAllocationsReversed'),
    'allocation_reporting_excludes_reversed_disbursements' =>
        str_contains($reportHelpers, "transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT')")
        && str_contains($reportHelpers, "status = 'POSTED'"),
    'existing_workbook_sheets_are_preserved_and_allocation_sheet_is_additive' =>
        str_contains($export, "setTitle('Daily Cash Report')")
        && str_contains($export, "setTitle('Cash Position')")
        && str_contains($export, "setTitle('Cashbook')")
        && str_contains($export, "setTitle('IOU Register')")
        && str_contains($export, "setTitle('Expense Analysis')")
        && str_contains($export, "setTitle('Expense & Projects')"),
    'allocation_report_contains_reconciliation_difference' =>
        str_contains($reportHelpers, "'difference' => round(\$allocatedDisbursementTotal - \$totalAllocated, 2)"),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
