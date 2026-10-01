<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

require_once $root . '/includes/procurementCompassHandoffService.php';

$migration = $read('database/20260819_procurement_compass_handoff_foundation.sql');
$runtime = $read('includes/procurementRequestCanonicalRuntimeService.php');
$finalService = $read('includes/procurementLocalFinalPurchaseService.php');
$advanceService = $read('includes/procurementLocalAdvancePurchaseService.php');

$fxRejected = false;
try {
    procurementCompassResolveLocalAccountRequestType('fx_final_purchase', 440, 'Compass Power Solutions Ltd');
} catch (InvalidArgumentException) {
    $fxRejected = true;
}

$checks = [
    'no_new_compass_or_procurement_transaction_table' => !preg_match('/CREATE\s+TABLE/i', $migration),
    'existing_compass_table_is_extended' => str_contains($migration, 'ALTER TABLE `compass_fund_request_table`')
        && str_contains($migration, '`procurement_source` VARCHAR(40)')
        && str_contains($migration, '`procurement_purchase_id` BIGINT UNSIGNED')
        && str_contains($migration, '`procurement_revision` INT UNSIGNED'),
    'compass_gets_payment_processing_foundation' => str_contains($migration, '`processing_method` VARCHAR(30)')
        && str_contains($migration, '`payment_confirmation_status` VARCHAR(40)')
        && str_contains($migration, '`amount_paid` DECIMAL(18,2)')
        && str_contains($migration, '`payment_batch_id` BIGINT UNSIGNED'),
    'compass_gets_advance_po_metadata_without_new_table' => str_contains($migration, '`procurement_root_po_id` BIGINT UNSIGNED')
        && str_contains($migration, '`procurement_po_revision_id` BIGINT UNSIGNED')
        && str_contains($migration, '`procurement_po_snapshot_json` LONGTEXT'),
    'compass_procurement_link_is_unique_per_source_purchase' => str_contains($migration, 'uq_compass_procurement_purchase')
        && str_contains($migration, '(`procurement_source`, `procurement_purchase_id`)'),
    'runtime_account_destination_constants_exist' => str_contains($runtime, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER = 'supplier_fund_request'")
        && str_contains($runtime, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE = 'advance_payment_request'")
        && str_contains($runtime, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS = 'compass_fund_request'"),
    'compass_supplier_detected_by_stable_id' => procurementCompassIsSupplier(440, 'Different display text'),
    'compass_supplier_detected_by_normalized_name_fallback' => procurementCompassIsSupplier(0, '  COMPASS Power Solutions Limited  '),
    'unrelated_supplier_is_not_compass' => !procurementCompassIsSupplier(999, 'Another Supplier Ltd'),
    'local_final_compass_resolves_to_compass' => procurementCompassResolveLocalAccountRequestType(
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        440,
        PROCUREMENT_COMPASS_SUPPLIER_NAME
    ) === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS,
    'local_advance_compass_resolves_to_compass' => procurementCompassResolveLocalAccountRequestType(
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        440,
        PROCUREMENT_COMPASS_SUPPLIER_NAME
    ) === PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS,
    'normal_local_final_keeps_supplier_destination' => procurementCompassResolveLocalAccountRequestType(
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        999,
        'Another Supplier Ltd'
    ) === PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER,
    'normal_local_advance_keeps_advance_destination' => procurementCompassResolveLocalAccountRequestType(
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        999,
        'Another Supplier Ltd'
    ) === PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE,
    'fx_is_explicitly_outside_compass_local_router' => $fxRejected,
    'insert_trigger_preserves_explicit_compass_destination' => substr_count(
        $migration,
        "WHEN NEW.account_request_type = 'compass_fund_request' THEN 'compass_fund_request'"
    ) >= 2,
    'existing_default_destinations_remain_in_triggers' => str_contains($migration, "ELSE 'supplier_fund_request'")
        && str_contains($migration, "ELSE 'advance_payment_request'"),
    'foundation_supports_safe_local_final_activation' => str_contains($finalService, 'INSERT INTO supplier_fund_request_table')
        && (!str_contains($finalService, 'INSERT INTO compass_fund_request_table')
            || (str_contains($finalService, 'procurementCompassResolveLocalAccountRequestType')
                && str_contains($finalService, 'PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS'))),
    'foundation_supports_safe_local_advance_activation' => str_contains($advanceService, 'INSERT INTO advance_payment_request')
        && (!str_contains($advanceService, 'INSERT INTO compass_fund_request_table')
            || (str_contains($advanceService, 'procurementCompassResolveLocalAccountRequestType')
                && str_contains($advanceService, 'PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS'))),
    'fx_tables_and_fx_routes_are_not_touched' => !str_contains($migration, 'fx_fund_request_table')
        && !str_contains($migration, 'fx_final_purchase')
        && !str_contains($migration, 'fx_advance_purchase'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
