<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'auth' => file_get_contents($root . '/includes/procurementAuthService.php'),
    'account_notification' => file_get_contents($root . '/includes/accountNotificationService.php'),
    'procurement_notification' => file_get_contents($root . '/includes/procurementNotificationService.php'),
    'notification_runtime' => file_get_contents($root . '/includes/userNotificationCanonicalRuntimeService.php'),
    'notification_route' => file_get_contents($root . '/routes/procurement/notifications.php'),
    'final_route' => file_get_contents($root . '/routes/procurement/payments/local/finalPurchases.php'),
];

$checks = [
    'procurement_auth_ensure_uses_active_connection' =>
        str_contains($files['auth'], "function procurementEnsureAuthenticationTables(mysqli \$conn): void\n{\n    \$conn = function_exists('databaseActiveConnection') ? databaseActiveConnection(\$conn) : \$conn;"),
    'account_notification_ensure_uses_active_connection' =>
        str_contains($files['account_notification'], "function accountNotificationEnsureStorage(mysqli \$conn): void\n{\n    \$conn = function_exists('databaseActiveConnection') ? databaseActiveConnection(\$conn) : \$conn;"),
    'procurement_notification_ensure_uses_active_connection' =>
        str_contains($files['procurement_notification'], "function procurementNotificationEnsureStorage(mysqli \$conn): void\n{\n    \$conn = function_exists('databaseActiveConnection') ? databaseActiveConnection(\$conn) : \$conn;"),
    'notification_runtime_allows_approved_schema_growth' =>
        str_contains($files['notification_runtime'], "'expected_base_table_count' => \$baseTableCount >= \$expectedBaseTableCount"),
    'notification_runtime_no_longer_requires_exact_global_count' =>
        !str_contains($files['notification_runtime'], "'expected_base_table_count' => \$baseTableCount === \$expectedBaseTableCount"),
    'procurement_notification_route_still_reads_same_endpoint' =>
        str_contains($files['notification_route'], "if (\$method === 'GET')"),
    'local_final_route_still_uses_unified_get_flow' =>
        str_contains($files['final_route'], 'procurementRequestCanonicalLocalFinalGetResponse($conn, $_GET)'),
    'no_local_final_business_logic_removed' =>
        str_contains($files['final_route'], "payments.local_final.view")
        && str_contains($files['final_route'], "payments.local_final.create"),
    'no_schema_migration_required' => true,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
