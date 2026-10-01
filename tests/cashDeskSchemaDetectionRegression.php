<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = (string) @file_get_contents($root . '/routes/cash/cashHelpers.php');
$migration = (string) @file_get_contents($root . '/database/new/20260920_cash_disbursement_allocation_persistence.sql');

$checks = [
    'cashdesk_schema_uses_selected_database' =>
        str_contains($helpers, 'WHERE TABLE_SCHEMA = DATABASE()')
        && !preg_match('/cashRequireSchema\(.*?@active_database_name/s', $helpers),
    'cashdesk_iou_schema_uses_selected_database' =>
        str_contains($helpers, "table_name = 'cash_iou_actions'")
        && !preg_match('/cashRequireIouActionsSchema\(.*?@active_database_name/s', $helpers),
    'allocation_migration_still_defines_required_tables' =>
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS cash_disbursement_ledger_allocations')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS cash_disbursement_project_allocations'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
