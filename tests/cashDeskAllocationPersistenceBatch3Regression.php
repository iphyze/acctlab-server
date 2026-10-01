<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$helpers = $read('routes/cash/cashHelpers.php');
$disburse = $read('routes/cash/disburseCash.php');
$update = $read('routes/cash/updateTransaction.php');
$reverse = $read('routes/cash/reverseTransaction.php');
$migration = $read('database/new/20260920_cash_disbursement_allocation_persistence.sql');

$checks = [
    'normalized_ledger_and_project_tables_exist' =>
        str_contains($migration, 'cash_disbursement_ledger_allocations')
        && str_contains($migration, 'cash_disbursement_project_allocations')
        && str_contains($migration, 'UNIQUE KEY uq_cash_disb_ledger_transaction_ledger')
        && str_contains($migration, 'UNIQUE KEY uq_cash_disb_project_ledger_project'),
    'cashdesk_schema_requires_allocation_tables' =>
        str_contains($helpers, "'cash_disbursement_ledger_allocations'")
        && str_contains($helpers, "'cash_disbursement_project_allocations'"),
    'allocation_helpers_persist_and_fetch_nested_data' =>
        str_contains($helpers, 'function cashSaveDisbursementAllocations')
        && str_contains($helpers, 'function cashFetchDisbursementAllocations')
        && str_contains($helpers, "\$row['projects'] = \$projects"),
    'batch2_metadata_remains_readable' =>
        str_contains($helpers, 'function cashLegacyExpenseAllocationsFromMetadata')
        && str_contains($helpers, "expense_allocations_draft"),
    'new_disbursements_save_normalized_allocations' =>
        str_contains($disburse, 'cashSaveDisbursementAllocations(')
        && str_contains($disburse, "expense_allocations_version'] = 2"),
    'transaction_details_include_allocations' =>
        str_contains($helpers, "\$transaction['expense_allocations'] = cashFetchDisbursementAllocations")
        && str_contains($helpers, "\$transaction['expense_allocation_total']"),
    'admin_corrections_replace_allocations_transactionally' =>
        str_contains($update, 'cashNormalizeExpenseAllocations(')
        && str_contains($update, 'cashSaveDisbursementAllocations(')
        && str_contains($update, 'Existing expense allocations cannot be cleared')
        && str_contains($update, "'expense_allocations' => \$isDisbursement"),
    'reversals_mark_allocations_reversed' =>
        str_contains($reverse, 'cashMarkDisbursementAllocationsReversed(')
        && str_contains($helpers, 'function cashMarkDisbursementAllocationsReversed'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
