<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$migration = $read('database/20260810_procurement_fx_advance_shared_po_foundation.sql');
$runtime = $read('includes/procurementRequestCanonicalRuntimeService.php');
$service = $read('includes/procurementFxAdvancePurchaseService.php');
$localService = $read('includes/procurementLocalAdvancePurchaseService.php');
$index = $read('index.php');
$auth = $read('includes/procurementAuthService.php');
$options = $read('routes/procurement/payments/foreign/advancePurchaseOptions.php');
$summary = $read('routes/procurement/payments/foreign/advancePurchaseSummary.php');
$actions = $read('routes/procurement/payments/foreign/advancePurchaseActions.php');

$checks = [
    'migration_creates_no_new_fx_advance_table' => !str_contains(strtoupper($migration), 'CREATE TABLE `PROCUREMENT_FX_ADVANCE_'),
    'existing_advance_po_tables_are_reused' => str_contains($migration, 'ALTER TABLE `procurement_local_advance_pos`')
        && str_contains($migration, 'ALTER TABLE `procurement_local_advance_po_revisions`')
        && str_contains($migration, 'ALTER TABLE `procurement_local_advance_po_revision_reconciliations`'),
    'shared_po_storage_gets_scope_and_currency' => str_contains($migration, '`request_scope` VARCHAR(40)')
        && str_contains($migration, '`currency` CHAR(3)'),
    'existing_local_rows_default_to_local_ngn' => str_contains($migration, "DEFAULT 'local_advance_purchase'")
        && str_contains($migration, "DEFAULT 'NGN'"),
    'po_uniqueness_is_scoped' => str_contains($migration, 'uq_advance_po_scope_number')
        && str_contains($migration, '(`request_scope`, `po_number_normalized`)'),
    'fx_advance_request_currency_is_required' => str_contains($migration, 'chk_procurement_fx_advance_currency_required')
        && str_contains($migration, "request_type` <> 'fx_advance_purchase'"),
    'fx_advance_canonical_type_is_registered' => str_contains($runtime, "PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE = 'fx_advance_purchase'")
        && str_contains($runtime, "PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE = 'canonical_fx_advance_purchase'"),
    'fx_advance_uses_procurement_requests_as_request_storage' => str_contains($runtime, "FROM procurement_requests\n        WHERE request_type = 'fx_advance_purchase'"),
    'supported_currencies_are_exact' => str_contains($service, "['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR']"),
    'local_advance_po_lookup_is_scope_isolated' => str_contains($localService, "request_scope = 'local_advance_purchase' AND po_number_normalized = ?")
        && str_contains($localService, "request_scope = 'local_advance_purchase' AND id = ?"),
    'fx_advance_po_lookup_is_scope_isolated' => str_contains($service, 'WHERE request_scope = ? AND po_number_normalized = ?')
        && str_contains($service, "const PROCUREMENT_FX_ADVANCE_SCOPE = 'fx_advance_purchase'"),
    'cumulative_percentage_guard_is_preserved' => str_contains($service, 'PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX')
        && str_contains($service, 'cannot exceed 100%'),
    'crud_options_summary_routes_registered' => str_contains($index, "/procurement/payments/foreign/advance-purchases'")
        && str_contains($index, "/procurement/payments/foreign/advance-purchases/options'")
        && str_contains($index, "/procurement/payments/foreign/advance-purchases/summary'"),
    'fx_advance_permissions_include_amendment_future_parity' => str_contains($auth, 'payments.fx_advance.amend_paid_po')
        && str_contains($auth, 'payments.fx_advance.approve_po_amendment')
        && str_contains($auth, 'payments.fx_advance.resolve_po_reconciliation'),
    'summary_does_not_mix_currency_totals' => str_contains($summary, 'GROUP BY r.currency'),
    'po_options_are_currency_aware' => str_contains($options, "if (\$type === 'currencies')")
        && str_contains($options, 'PROCUREMENT_FX_ADVANCE_CURRENCIES'),
    'batch2_enables_account_handoff' => str_contains($actions, "if (\$action === 'approve')")
        && str_contains($actions, 'procurementFxAdvanceApproveOne')
        && str_contains($actions, 'procurementFxAdvanceRetrieveOne'),
    'single_database_runtime_preserved' => !str_contains($service, 'lambert2_acctlab_archive')
        && !str_contains($service, 'lambert2_acctlab_read'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
