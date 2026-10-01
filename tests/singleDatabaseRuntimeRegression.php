<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$connection = file_get_contents($root . '/includes/connection.php') ?: '';
$identity = file_get_contents($root . '/includes/databaseIdentityService.php') ?: '';
$workflowWrite = file_get_contents($root . '/includes/workflowEventCanonicalWriteService.php') ?: '';
$notificationRuntime = file_get_contents($root . '/includes/userNotificationCanonicalRuntimeService.php') ?: '';
$paymentWrite = file_get_contents($root . '/includes/accountPaymentStorageCanonicalWriteService.php') ?: '';
$bankRecon = file_get_contents($root . '/routes/bank-recon/getReconciliation.php') ?: '';
$cash = file_get_contents($root . '/routes/cash/cashHelpers.php') ?: '';
$fxVerify = file_get_contents($root . '/cron/verifyFxFundRequestFoundation.php') ?: '';
$procurementVerify = file_get_contents($root . '/cron/verifyProcurementFxFinalFoundation.php') ?: '';
$rehydrate = file_get_contents($root . '/database/20260809_single_database_rehydrate_local.sql') ?: '';
$cleanup = file_get_contents($root . '/database/20260809_single_database_remove_archive_read.sql') ?: '';

$checks = [
    'connection_uses_only_db_name' => str_contains($connection, "envValue('DB_NAME'")
        && !str_contains($connection, 'DB_ARCHIVE_NAME')
        && !str_contains($connection, 'DB_READ_NAME')
        && !str_contains($connection, 'DB_READ_HOST')
        && substr_count($connection, 'new mysqli(') === 1,
    'all_requests_use_active_source' => str_contains($connection, "\$databaseReadSource = 'active';")
        && str_contains($connection, "header('X-Data-Source: active')"),
    'active_connection_helper_is_single_db' => str_contains($connection, 'function databaseActiveConnection(mysqli $fallback): mysqli')
        && str_contains($connection, 'return $fallback;'),
    'identity_allocator_is_single_db' => str_contains($identity, 'databaseIdentityNextScopedId')
        && str_contains($identity, 'TABLE_SCHEMA = DATABASE()')
        && !str_contains($identity, 'archive'),
    'canonical_writers_use_single_db_identity' => str_contains($workflowWrite, "require_once __DIR__ . '/databaseIdentityService.php';")
        && str_contains($notificationRuntime, "require_once __DIR__ . '/databaseIdentityService.php';")
        && str_contains($paymentWrite, "require_once __DIR__ . '/databaseIdentityService.php';")
        && !str_contains($workflowWrite, 'archiveIdentityNextScopedId')
        && !str_contains($notificationRuntime, 'archiveIdentityNextScopedId')
        && !str_contains($paymentWrite, 'archiveIdentityNextScopedId'),
    'bank_reconciliation_is_single_db' => !str_contains($bankRecon, '$isArchivedOnly')
        && str_contains($bankRecon, "\$r['record_source'] = 'active';")
        && str_contains($bankRecon, "\$r['read_only'] = false;"),
    'cash_balances_use_canonical_connection' => str_contains($cash, '$balanceConn = $conn;')
        && str_contains($cash, '$pendingConn = $conn;')
        && !str_contains($cash, 'unified read facade'),
    'fx_verifier_is_single_db' => !str_contains($fxVerify, 'DB_ARCHIVE_NAME')
        && !str_contains($fxVerify, 'DB_READ_NAME')
        && str_contains($fxVerify, "'single_database_table_exists'"),
    'procurement_fx_verifier_is_single_db' => !str_contains($procurementVerify, 'DB_ARCHIVE_NAME')
        && !str_contains($procurementVerify, 'DB_READ_NAME')
        && str_contains($procurementVerify, "'single_database_procurement_requests_exists'"),
    'rehydration_preserves_unified_history_before_cleanup' => str_contains($rehydrate, 'INSERT IGNORE INTO `lambert2_acctlab_db`')
        && str_contains($rehydrate, 'lambert2_acctlab_read')
        && str_contains($rehydrate, "`idempotency_key` LIKE 'archive-cutover:%'")
        && str_contains($rehydrate, 'Single-DB rehydration count mismatch'),
    'cleanup_drops_only_obsolete_databases_after_preflight' => str_contains($cleanup, 'sp_single_database_cleanup_preflight')
        && str_contains($cleanup, 'DROP DATABASE IF EXISTS `lambert2_acctlab_read`')
        && str_contains($cleanup, 'DROP DATABASE IF EXISTS `lambert2_acctlab_archive`')
        && !str_contains($cleanup, 'DROP DATABASE IF EXISTS `lambert2_acctlab_db`'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
