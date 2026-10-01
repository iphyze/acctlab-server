<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/accountCompassFundRequestService.php');
$whtRoute = $read('routes/request/compass/adjustWht.php');
$paidRoute = $read('routes/request/compass/reversePaidStatus.php');
$statusRoute = $read('routes/request/compass/updateStatus.php');
$returnRoute = $read('routes/request/compass/returnToProcurement.php');
$filterRoute = $read('routes/request/compass/getFilteredRequest.php');
$editRoute = $read('routes/request/compass/edit.php');
$deleteRoute = $read('routes/request/compass/delete.php');
$paymentProcessing = $read('includes/accountPaymentProcessingService.php');
$batch4Migration = $read('database/20260819_procurement_compass_fund_request_parity.sql');

$checks = [
    'batch4_adds_no_new_table_or_migration' => $batch4Migration === '',
    'compass_account_parity_service_is_isolated' => str_contains(
        $service,
        'function accountCompassAdjustWht('
    ) && str_contains($service, 'function accountCompassReversePaidStatus('),
    'wht_adjustment_requires_linked_pending_request' => str_contains(
        $service,
        "INNER JOIN procurement_requests r"
    ) && str_contains($service, "account_request_type = 'compass_fund_request'")
      && str_contains($service, 'WHT can only be adjusted while the Compass Fund Request is Pending.'),
    'wht_adjustment_preserves_advance_revision_semantics' => str_contains(
        $service,
        'supplementary/recovery Compass advances'
    ) && str_contains($service, 'procurementLocalAdvanceRefreshAccountReconciliationAfterPendingWhtAdjustment'),
    'wht_adjustment_synchronizes_both_procurement_origins' => str_contains(
        $service,
        'PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL'
    ) && str_contains($service, 'PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE')
      && str_contains($service, 'procurementRequestCanonicalSyncRequest'),
    'wht_route_is_admin_transactional_and_audited' => str_contains($whtRoute, 'requireAdmin()')
        && str_contains($whtRoute, '$conn->begin_transaction()')
        && str_contains($whtRoute, 'accountCompassAdjustWht(')
        && str_contains($whtRoute, 'INSERT INTO logs'),
    'paid_correction_requires_explicit_confirmation' => str_contains(
        $service,
        'Confirm that the payment was not completed before reversing Paid status.'
    ) && str_contains($service, "['Pending', 'Processing', 'Unconfirmed', 'Failed', 'Cancelled']"),
    'paid_correction_syncs_final_and_advance' => str_contains(
        $service,
        'procurementSyncLocalFinalCompassPaymentDetails('
    ) && str_contains($service, 'procurementSyncLocalAdvanceCompassPaymentDetails('),
    'paid_correction_route_is_admin_transactional_and_audited' => str_contains($paidRoute, 'requireAdmin()')
        && str_contains($paidRoute, '$conn->begin_transaction()')
        && str_contains($paidRoute, 'accountCompassReversePaidStatus(')
        && str_contains($paidRoute, 'INSERT INTO logs'),
    'direct_status_update_protects_only_linked_paid_rows' => str_contains(
        $statusRoute,
        'SELECT id, payment_status, procurement_source, procurement_purchase_id'
    ) && str_contains($statusRoute, "in_array(\$source, ['local_final_purchase', 'local_advance_purchase'], true)")
      && str_contains($statusRoute, 'isset($linkedIds[$id])')
      && str_contains($statusRoute, 'Paid Compass Fund Requests require the authorized Paid-status correction flow'),
    'manual_compass_status_flow_is_still_present' => str_contains($statusRoute, "['Pending', 'Paid', 'Unconfirmed']")
        && str_contains($statusRoute, 'UPDATE compass_fund_request_table')
        && str_contains($statusRoute, 'Manually-created'),
    'return_to_procurement_still_dispatches_by_origin' => str_contains($returnRoute, 'procurementLocalFinalRetrieveCompassRequestOne')
        && str_contains($returnRoute, 'procurementLocalAdvanceRetrieveCompassRequestOne'),
    'linked_commercial_edit_delete_guards_are_preserved' => str_contains($editRoute, 'procurementAssertCompassFundRequestCanBeEdited')
        && str_contains($deleteRoute, 'procurementAssertCompassFundRequestsCanBeDeleted'),
    'compass_register_search_now_includes_procurement_references' => str_contains($filterRoute, 'purchase_number LIKE ?')
        && str_contains($filterRoute, 'po_number LIKE ?')
        && str_contains($filterRoute, 'project_code LIKE ?')
        && str_contains($filterRoute, 'procurement_source LIKE ?'),
    'shared_payment_batch_projection_uses_dedicated_compass_request_type' => str_contains(
        $paymentProcessing,
        'compass_fund_request_table'
    ) && str_contains($paymentProcessing, "CONVERT('compass_fund_request'")
      && str_contains($service, 'ACCOUNT_PAYMENT_TYPE_COMPASS'),
    'fx_flow_is_not_touched_by_parity_service' => !str_contains($service, 'fx_fund_request_table')
        && !str_contains($whtRoute, 'fx_fund_request_table')
        && !str_contains($paidRoute, 'fx_fund_request_table'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
