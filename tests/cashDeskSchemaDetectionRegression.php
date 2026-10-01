<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = (string) @file_get_contents($root . '/routes/cash/cashHelpers.php');
$migration = (string) @file_get_contents($root . '/database/new/20260920_cash_disbursement_allocation_persistence.sql');

$checks = [
    'cashdesk_schema_uses_direct_table_probe' =>
        str_contains($helpers, 'function cashTableExists')
        && str_contains($helpers, 'SELECT 1 FROM `{$tableName}` LIMIT 0')
        && !preg_match('/function cashRequireSchema\(.*?information_schema/s', $helpers),
    'cashdesk_schema_logs_exact_missing_tables' =>
        str_contains($helpers, 'Cash Desk missing database tables:')
        && str_contains($helpers, 'Missing tables: '),
    'cashdesk_iou_schema_reuses_direct_probe' =>
        str_contains($helpers, "cashTableExists(\$conn, 'cash_iou_actions')")
        && !preg_match('/function cashRequireIouActionsSchema\(.*?information_schema/s', $helpers),
    'missing_table_probe_only_swallows_mysql_1146' =>
        str_contains($helpers, "(int) \$error->getCode() === 1146")
        && str_contains($helpers, 'throw $error;'),
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
