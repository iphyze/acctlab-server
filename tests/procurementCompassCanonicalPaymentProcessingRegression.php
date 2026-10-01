<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$support = $read('includes/accountPaymentStorageRuntimeSupportService.php');
$readService = $read('includes/accountPaymentStorageRuntimeReadService.php');
$writeService = $read('includes/accountPaymentStorageCanonicalWriteService.php');
$processing = $read('includes/accountPaymentProcessingService.php');
$compass = $read('includes/accountCompassFundRequestService.php');
$route = $read('routes/request/compass/paymentBatches.php');
$index = $read('index.php');

$checks = [
    'compass_has_dedicated_canonical_request_type' => str_contains($support, "ACCOUNT_PAYMENT_TYPE_COMPASS = 'compass_fund_request'")
        && str_contains($readService, "ACCOUNT_PAYMENT_TYPE_COMPASS => 'compass_fund_request_id'"),
    'compass_uses_canonical_tables_without_new_payment_table' => str_contains($writeService, "'batch_table' => 'account_compass_payment_batches'")
        && str_contains($writeService, "'item_table' => 'account_compass_payment_batch_items'")
        && !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE/i', $writeService . $compass . $route),
    'source_projection_isolated_by_request_type' => str_contains($processing, "CONVERT('compass_fund_request' USING utf8mb4)")
        && str_contains($processing, 'FROM compass_fund_request_table cfr')
        && str_contains($processing, 'r.request_type = i.request_type'),
    'local_scope_includes_compass_without_touching_fx' => str_contains($processing, "'local_final_purchase','local_advance_purchase','compass_fund_request'")
        && str_contains($processing, "'fx_final_purchase','fx_advance_purchase'"),
    'compass_batch_creation_is_pending_only_and_collision_safe' => str_contains($compass, 'function accountCompassCreatePaymentBatch(')
        && str_contains($compass, "ACCOUNT_PAYMENT_TYPE_COMPASS")
        && str_contains($compass, "payment_status = 'Pending'")
        && str_contains($compass, 'payment_batch_id IS NULL'),
    'compass_processing_actions_support_paid_delay_failure_cancel' => str_contains($compass, 'function accountCompassMarkPaymentItems(')
        && str_contains($compass, "['paid', 'failed', 'delayed', 'cancelled']")
        && str_contains($processing, 'accountCompassMarkPaymentItems'),
    'compass_processing_syncs_both_procuredesk_origins' => str_contains($compass, 'procurementSyncLocalFinalCompassPaymentDetails(')
        && str_contains($compass, 'procurementSyncLocalAdvanceCompassPaymentDetails('),
    'compass_batch_route_is_admin_only' => str_contains($route, 'requireAdmin()')
        && str_contains($route, 'accountCompassCreatePaymentBatch')
        && str_contains($index, "'/request/compass/payment-batches'"),
    'compass_completion_modes_extend_without_parallel_payment_tables' => str_contains($compass, "['Manual', 'Notify', 'Automatic', 'Immediate']")
        && !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE/i', $compass . $route),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
