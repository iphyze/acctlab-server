<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/archiveFrameworkService.php';

$cashHelpers = file_get_contents(__DIR__ . '/../routes/cash/cashHelpers.php') ?: '';
$service = file_get_contents(__DIR__ . '/../includes/archiveFrameworkService.php') ?: '';

$view = archiveFrameworkBuildViewSql(
    'lambert2_acctlab_read',
    'lambert2_acctlab_db',
    'lambert2_acctlab_archive',
    'cash_transactions',
    ['id', 'account_id', 'idempotency_key', 'amount'],
    'combined',
    ['id']
);

$sql = (string) ($view['sql'] ?? '');

$checks = [
    'current_balance_forces_active_connection' => str_contains(
        $cashHelpers,
        '$balanceConn = $asOfDate === null && function_exists(\'databaseActiveConnection\')'
    ),
    'current_pending_mutilated_forces_active_connection' => str_contains(
        $cashHelpers,
        '$pendingConn = $asOfDate === null && function_exists(\'databaseActiveConnection\')'
    ),
    'cash_read_view_hides_active_cutover_rows' => str_contains(
        $sql,
        "a.`idempotency_key` NOT LIKE 'archive-cutover:%'"
    ),
    'cash_read_view_hides_archive_cutover_rows' => str_contains(
        $sql,
        "ar.`idempotency_key` NOT LIKE 'archive-cutover:%'"
    ),
    'cash_dedupe_ignores_hidden_cutover_rows' => str_contains(
        $sql,
        "a2.`idempotency_key` NOT LIKE 'archive-cutover:%'"
    ),
    'future_cutovers_date_carry_forward_before_cutoff' => str_contains(
        $service,
        "DATE_SUB(' . \$cutoffDateLiteral . ', INTERVAL 1 DAY), \\'OPENING_BALANCE\\'"
    ),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
