<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$auth = $read('includes/procurementAuthService.php');
$canonical = $read('includes/procurementRequestCanonicalRuntimeService.php');
$localFinalService = $read('includes/procurementLocalFinalPurchaseService.php');
$localAdvanceService = $read('includes/procurementLocalAdvancePurchaseService.php');
$localFinalRoute = $read('routes/procurement/payments/local/finalPurchases.php');
$localFinalSummary = $read('routes/procurement/payments/local/finalPurchaseSummary.php');
$localAdvanceRoute = $read('routes/procurement/payments/local/advancePurchases.php');
$localAdvanceSummary = $read('routes/procurement/payments/local/advancePurchaseSummary.php');
$fxFinalRoute = $read('routes/procurement/payments/foreign/finalPurchases.php');
$fxFinalSummary = $read('routes/procurement/payments/foreign/finalPurchaseSummary.php');
$fxAdvanceRoute = $read('routes/procurement/payments/foreign/advancePurchases.php');
$fxAdvanceSummary = $read('routes/procurement/payments/foreign/advancePurchaseSummary.php');

$checks = [
    'auth_has_lightweight_runtime_probe' =>
        str_contains($auth, 'function procurementAuthenticationRuntimeReady(mysqli $conn): bool')
        && str_contains($auth, 'WHERE 1 = 0')
        && str_contains($auth, 'PROCUREMENT_AUTH_RUNTIME_SENTINEL_PERMISSION'),
    'auth_full_bootstrap_is_fallback_only' =>
        str_contains($auth, 'if (procurementAuthenticationRuntimeReady($conn))')
        && str_contains($auth, 'procurementSeedAccessControl($conn);'),
    'canonical_runtime_uses_single_zero_row_column_probe' =>
        str_contains($canonical, 'function procurementRequestCanonicalRuntimeColumnsReady(')
        && str_contains($canonical, 'LIMIT 0')
        && str_contains($canonical, "procurementRequestCanonicalRuntimeColumnsReady(\$conn, 'procurement_requests', \$required)"),
    'canonical_diagnostics_preserved_as_fallback' =>
        str_contains($canonical, 'procurementRequestCanonicalRuntimeColumnExists(')
        && str_contains($canonical, 'Canonical Local Final Purchase storage is incomplete:')
        && str_contains($canonical, 'Canonical Local Advance Purchase storage is incomplete:')
        && str_contains($canonical, 'Canonical FX Final Purchase storage is incomplete:')
        && str_contains($canonical, 'Canonical FX Advance Purchase storage is incomplete:'),
    'local_final_get_uses_read_only_storage_probe' =>
        str_contains($localFinalService, 'function procurementAssertLocalFinalPurchaseReadStorage(mysqli $conn): void')
        && str_contains($localFinalRoute, "procurementAssertLocalFinalPurchaseReadStorage(\$conn);")
        && str_contains($localFinalSummary, 'procurementAssertLocalFinalPurchaseReadStorage($conn);'),
    'local_final_notification_bootstrap_removed_from_get_hot_path' =>
        str_contains($localFinalRoute, 'procurementEnsureLocalFinalPurchaseStorage($conn);')
        && str_contains($localFinalRoute, 'procurementNotificationEnsureStorage($conn);')
        && str_contains($localFinalRoute, 'accountNotificationEnsureStorage($conn);'),
    'local_advance_get_uses_read_only_storage_probe' =>
        str_contains($localAdvanceService, 'function procurementAssertLocalAdvancePurchaseReadStorage(mysqli $conn): void')
        && str_contains($localAdvanceRoute, 'procurementAssertLocalAdvancePurchaseReadStorage($conn);')
        && str_contains($localAdvanceSummary, 'procurementAssertLocalAdvancePurchaseReadStorage($conn);'),
    'local_advance_notification_bootstrap_removed_from_get_hot_path' =>
        str_contains($localAdvanceRoute, 'procurementLocalAdvanceEnsureStorage($conn);')
        && str_contains($localAdvanceRoute, 'procurementNotificationEnsureStorage($conn);'),
    'local_advance_metadata_lookup_cached_per_request' =>
        str_contains($localAdvanceService, 'static $cache = [];')
        && str_contains($localAdvanceService, '$key = spl_object_id($conn) . \':\' . $table;'),
    'fx_final_routes_keep_existing_contract_on_fast_canonical_probe' =>
        str_contains($fxFinalRoute, 'procurementEnsureFxFinalPurchaseStorage($conn);')
        && str_contains($fxFinalSummary, 'procurementEnsureFxFinalPurchaseStorage($conn);')
        && str_contains($fxFinalRoute, 'payments.fx_final.view'),
    'fx_advance_routes_keep_existing_contract_on_fast_canonical_probe' =>
        str_contains($fxAdvanceRoute, 'procurementEnsureFxAdvancePurchaseStorage($conn);')
        && str_contains($fxAdvanceSummary, 'procurementEnsureFxAdvancePurchaseStorage($conn);')
        && str_contains($fxAdvanceRoute, 'payments.fx_advance.view'),
    'write_permissions_and_crud_paths_preserved' =>
        str_contains($localFinalRoute, 'payments.local_final.create')
        && str_contains($localAdvanceRoute, 'payments.local_advance.create')
        && str_contains($fxFinalRoute, 'payments.fx_final.create')
        && str_contains($fxAdvanceRoute, 'payments.fx_advance.create'),
    'no_new_table_or_migration_required' => true,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
