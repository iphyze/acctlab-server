<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$router = $read('includes/procurementCompassHandoffService.php');
$runtime = $read('includes/procurementRequestCanonicalRuntimeService.php');
$final = $read('includes/procurementLocalFinalPurchaseService.php');
$advance = $read('includes/procurementLocalAdvancePurchaseService.php');
$compass = $read('includes/accountCompassFundRequestService.php');
$processing = $read('includes/accountPaymentProcessingService.php');
$paymentStorage = $read('includes/accountPaymentStorageRuntimeSupportService.php');
$paymentRead = $read('includes/accountPaymentStorageRuntimeReadService.php');
$reminder = $read('includes/accountPaymentReminderService.php');
$reminderPlan = $read('includes/paymentReminderUnifiedStoragePlanService.php');
$cycle = $read('cron/processPaymentReminderCycle.php');
$verifier = $read('cron/verifyProcurementCompassIntegration.php');
$migration = $read('database/20260819_procurement_compass_handoff_foundation.sql');

$checks = [
    'compass_identity_is_centralized_and_id_first' => str_contains($router, 'PROCUREMENT_COMPASS_SUPPLIER_ID = 440')
        && str_contains($router, 'function procurementCompassIsSupplier(')
        && str_contains($router, 'procurementCompassNormalizeSupplierName'),
    'only_local_final_and_advance_can_resolve_to_compass' => str_contains($router, 'procurementRequestCanonicalDefaultLocalAccountRequestType($requestType)')
        && str_contains($router, 'Compass handoff routing only supports Local Final and Local Advance requests.'),
    'canonical_account_destination_is_preserved' => str_contains($runtime, "const PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS = 'compass_fund_request'")
        && str_contains($migration, "WHEN NEW.account_request_type = 'compass_fund_request' THEN 'compass_fund_request'"),
    'local_final_routes_compass_without_replacing_normal_supplier_flow' => str_contains($final, 'procurementLocalFinalCreateCompassFundRequest(')
        && str_contains($final, 'procurementLocalFinalCreateSupplierFundRequest(')
        && str_contains($final, 'procurementCompassResolveLocalAccountRequestType('),
    'local_advance_routes_compass_without_replacing_normal_advance_flow' => str_contains($advance, 'procurementLocalAdvanceCreateCompassFundRequest(')
        && str_contains($advance, 'procurementLocalAdvanceCreateAdvancePaymentRequest(')
        && str_contains($advance, 'procurementCompassResolveLocalAccountRequestType('),
    'compass_uses_existing_fund_request_table_only' => str_contains($migration, 'ALTER TABLE `compass_fund_request_table`')
        && !preg_match('/CREATE\s+TABLE\s+`?compass/i', $migration),
    'compass_payment_namespace_is_dedicated' => str_contains($paymentStorage, "const ACCOUNT_PAYMENT_TYPE_COMPASS = 'compass_fund_request'")
        && str_contains($paymentRead, 'ACCOUNT_PAYMENT_TYPE_COMPASS')
        && str_contains($paymentRead, "'compass_fund_request_id'"),
    'shared_processing_workspace_includes_compass_under_local_scope' => str_contains($processing, "'local_final_purchase','local_advance_purchase','compass_fund_request'")
        && str_contains($processing, 'ACCOUNT_PAYMENT_TYPE_COMPASS'),
    'compass_completion_lifecycle_supports_all_modes' => str_contains($compass, "['Manual', 'Notify', 'Automatic', 'Immediate']")
        && str_contains($compass, 'accountCompassProcessDueBatches(')
        && str_contains($cycle, 'accountCompassProcessDueBatches($conn)'),
    'compass_payment_sync_dispatches_to_both_procurement_origins' => str_contains($compass, 'procurementSyncLocalFinalCompassPaymentDetails(')
        && str_contains($compass, 'procurementSyncLocalAdvanceCompassPaymentDetails('),
    'compass_reminders_use_shared_reminder_storage' => str_contains($reminder, "'Compass' => 'CMP'")
        && str_contains($reminder, "'request_table' => 'compass_fund_request_table'")
        && str_contains($reminderPlan, "'reminder_type' => 'Compass'")
        && str_contains($reminderPlan, "'canonical_type' => ACCOUNT_PAYMENT_TYPE_COMPASS"),
    'final_verifier_is_read_only_and_checks_cross_flow_integrity' => str_contains($verifier, 'No orphan Compass handoffs')
        && str_contains($verifier, 'Only Compass supplier is routed to Compass')
        && str_contains($verifier, 'FX and unrelated request types excluded')
        && str_contains($verifier, 'Compass payment batches are type-isolated')
        && str_contains($verifier, 'No orphan Compass payment reminders')
        && !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)\b\s+/i', preg_replace('/\'.*?\'/s', "''", $verifier) ?? $verifier),
    'compass_payment_service_comment_matches_canonical_runtime' => str_contains($compass, 'shared canonical payment tables under the dedicated')
        && str_contains($compass, 'compass_fund_request request_type')
        && !str_contains($compass, 'intentionally do not insert Compass IDs into the Supplier/Advance canonical payment-batch rows'),
    'fx_routing_remains_outside_compass_router' => str_contains($router, 'FX is intentionally unsupported here')
        && !str_contains($router, 'fx_final_purchase')
        && !str_contains($router, 'fx_advance_purchase'),
    'final_batch_adds_no_schema_or_transaction_table' => !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $verifier . $compass),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
