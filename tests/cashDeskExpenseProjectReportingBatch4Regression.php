<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$helpers = $read('routes/cash/cashReportHelpers.php');
$export = $read('routes/cash/exportReports.php');

$checks = [
    'report_builder_uses_persisted_or_legacy_nested_allocations' =>
        str_contains($helpers, 'function cashBuildExpenseProjectAllocationReport')
        && str_contains($helpers, 'cashFetchDisbursementAllocations($conn, $transactionId)'),
    'report_only_uses_posted_direct_and_iou_disbursements' =>
        str_contains($helpers, "transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT')")
        && str_contains($helpers, "status = 'POSTED'"),
    'existing_cash_report_payload_gets_additive_allocation_section' =>
        str_contains($helpers, "'expense_project_allocations' => \$expenseProjectAllocations")
        && str_contains($helpers, "'summary' => \$summary")
        && str_contains($helpers, "'cash_position' => \$cashPosition"),
    'full_workbook_gets_additive_expense_project_sheet' =>
        str_contains($export, "setTitle('Expense & Projects')")
        && str_contains($export, "'EXPENSE & PROJECT ALLOCATIONS'")
        && str_contains($export, "'Project Allocation'"),
    'existing_workbook_sheets_are_not_replaced' =>
        str_contains($export, "setTitle('Daily Cash Report')")
        && str_contains($export, "setTitle('Cash Position')")
        && str_contains($export, "setTitle('Cashbook')")
        && str_contains($export, "setTitle('IOU Register')")
        && str_contains($export, "setTitle('Expense Analysis')"),
    'allocation_sheet_reconciles_disbursement_and_project_totals' =>
        str_contains($helpers, "'project_allocation_total'")
        && str_contains($helpers, "'difference' => round(\$allocatedDisbursementTotal - \$totalAllocated, 2)"),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
