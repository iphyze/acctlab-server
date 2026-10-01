<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$index = $read('index.php');
$ledger = $read('routes/cash/createExpenseLedger.php');
$project = $read('routes/cash/createProject.php');

$checks = [
    'cash_routes_register_quick_create_endpoints' =>
        str_contains($index, "'/cash/expense-ledgers/create' => 'routes/cash/createExpenseLedger.php'")
        && str_contains($index, "'/cash/projects/create' => 'routes/cash/createProject.php'"),
    'expense_ledger_quick_create_uses_existing_master_table' =>
        str_contains($ledger, 'INSERT INTO expense_ledger')
        && str_contains($ledger, 'cashAssertManageAccess')
        && str_contains($ledger, 'supplier_name')
        && str_contains($ledger, 'supplier_number'),
    'project_quick_create_uses_existing_project_master_table' =>
        str_contains($project, 'INSERT INTO location_table')
        && str_contains($project, 'cashAssertManageAccess')
        && str_contains($project, "'project' =>"),
    'both_quick_creates_reject_duplicates' =>
        str_contains($ledger, 'already exists')
        && str_contains($project, 'already exists'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
