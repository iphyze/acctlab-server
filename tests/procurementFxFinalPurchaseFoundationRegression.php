<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$migration = $read('database/20260809_procurement_fx_final_purchase_foundation.sql');
$runtime = $read('includes/procurementRequestCanonicalRuntimeService.php');
$service = $read('includes/procurementFxFinalPurchaseService.php');
$auth = $read('includes/procurementAuthService.php');
$index = $read('index.php');
$route = $read('routes/procurement/payments/foreign/finalPurchases.php');
$actions = $read('routes/procurement/payments/foreign/finalPurchaseActions.php');
$summary = $read('routes/procurement/payments/foreign/finalPurchaseSummary.php');

$checks = [
    'migration_adds_currency_to_canonical_procurement_requests' => str_contains($migration, 'ALTER TABLE `lambert2_acctlab_db`.`procurement_requests`')
        && str_contains($migration, 'ADD COLUMN `currency` CHAR(3)'),
    'migration_does_not_create_fx_procurement_transaction_table' => !preg_match('/CREATE\s+TABLE[^;]*fx.*final/i', $migration),
    'fx_final_request_type_is_canonical' => str_contains($runtime, "PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE = 'fx_final_purchase'"),
    'supported_currencies_are_exact' => str_contains($service, "['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR']"),
    'currency_required_by_api' => str_contains($service, 'procurementFxFinalNormalizeCurrency'),
    'currency_required_by_storage_guard' => str_contains($migration, 'chk_procurement_fx_final_currency_required'),
    'fx_final_reads_only_procurement_requests' => str_contains($runtime, "FROM procurement_requests\n        WHERE request_type = 'fx_final_purchase'"),
    'crud_route_registered' => str_contains($index, '/procurement/payments/foreign/final-purchases'),
    'options_and_summary_routes_registered' => str_contains($index, '/procurement/payments/foreign/final-purchases/options')
        && str_contains($index, '/procurement/payments/foreign/final-purchases/summary'),
    'fx_final_permissions_seeded' => str_contains($auth, 'payments.fx_final.view')
        && str_contains($auth, 'payments.fx_final.create')
        && str_contains($auth, 'payments.fx_final.update')
        && str_contains($auth, 'payments.fx_final.delete'),
    'crud_follows_local_final_commercial_validation' => str_contains($service, 'procurementLocalFinalBuildPayload'),
    'purchase_number_duplicate_guard_is_scoped_to_fx_final' => str_contains($service, 'procurementFxFinalAssertPurchaseNumberAvailable')
        && str_contains($runtime, "request_type = 'fx_final_purchase'"),
    'canonical_crud_route_does_not_create_account_handoff_directly' => !str_contains($route, 'INSERT INTO fx_fund_request_table'),
    'approval_handoff_is_isolated_to_action_service' => str_contains($actions, "\$action === 'approve'")
        && str_contains($service, 'procurementFxFinalApproveOne'),
    'fx_summary_avoids_mixed_currency_total' => str_contains($summary, 'GROUP BY p.currency')
        && str_contains($summary, 'must never be summed into a single mixed-currency value'),
    'existing_local_request_types_are_preserved' => str_contains($runtime, "PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE = 'local_final_purchase'")
        && str_contains($runtime, "PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_TYPE = 'local_advance_purchase'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
