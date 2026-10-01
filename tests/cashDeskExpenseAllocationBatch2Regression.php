<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$index = $read('index.php');
$options = $read('routes/cash/getAllocationOptions.php');
$helpers = $read('routes/cash/cashHelpers.php');
$disburse = $read('routes/cash/disburseCash.php');

$checks = [
    'allocation_options_route_is_registered' =>
        str_contains($index, "'/cash/allocation-options' => 'routes/cash/getAllocationOptions.php'"),
    'allocation_options_respect_cashdesk_access' =>
        str_contains($options, 'cashCurrentUser()')
        && str_contains($options, 'cashResolveAccount(')
        && str_contains($options, 'cashAssertWriteAccess('),
    'existing_expense_ledgers_are_the_ledger_source' =>
        str_contains($options, 'FROM expense_ledger')
        && str_contains($options, 'supplier_name')
        && str_contains($options, 'supplier_number'),
    'existing_projects_are_the_project_source' =>
        str_contains($options, 'FROM location_table')
        && str_contains($options, 'code')
        && str_contains($options, 'location'),
    'allocation_payload_is_normalized_and_validated' =>
        str_contains($helpers, 'function cashNormalizeExpenseAllocations')
        && str_contains($helpers, 'The same expense ledger cannot be added more than once.')
        && str_contains($helpers, 'A project can only appear once under the same expense ledger.')
        && str_contains($helpers, 'Project allocations under')
        && str_contains($helpers, 'Expense ledger allocations must equal the total disbursement amount.'),
    'master_data_snapshots_are_preserved' =>
        str_contains($helpers, "'ledger_name'")
        && str_contains($helpers, "'ledger_number'")
        && str_contains($helpers, "'project_code'")
        && str_contains($helpers, "'project_name'"),
    'allocation_payload_is_preserved_without_changing_cash_posting' =>
        str_contains($disburse, 'cashNormalizeExpenseAllocations(')
        && str_contains($disburse, "\$transactionMetadata['expense_allocations_version'] = 2")
        && str_contains($disburse, 'cashSaveDisbursementAllocations(')
        && str_contains($disburse, 'cashSaveDisbursementFunding('),
    'older_clients_remain_backward_compatible' =>
        str_contains($helpers, "if (\$value === null || \$value === '' || \$value === [])")
        && str_contains($helpers, 'return [];'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
