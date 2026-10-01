<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/procurementFxFinalPurchaseService.php');
$actions = $read('routes/procurement/payments/foreign/finalPurchaseActions.php');
$auth = $read('includes/procurementAuthService.php');
$canonical = $read('includes/procurementRequestCanonicalSyncService.php');
$lifecycle = $read('includes/fxFundRequestPaymentLifecycleService.php');
$singleProcess = $read('routes/request/fx/processForPayment.php');
$groupProcess = $read('routes/request/fx/processGroupedForPayment.php');
$fxDelete = $read('routes/request/fx/delete.php');
$returnRoute = $read('routes/request/fx/returnToProcurement.php');
$index = $read('index.php');
$notifications = $read('includes/procurementNotificationService.php');

$checks = [
    'approval_permission_seeded' => str_contains($auth, 'payments.fx_final.approve'),
    'reverse_permission_seeded' => str_contains($auth, 'payments.fx_final.reverse_approval'),
    'retrieve_permission_seeded' => str_contains($auth, 'payments.fx_final.retrieve_from_account'),
    'approval_action_enabled' => str_contains($actions, "if (\$action === 'approve')")
        && str_contains($actions, 'procurementFxFinalApproveOne'),
    'approval_creates_fx_fund_request' => str_contains($service, 'INSERT INTO fx_fund_request_table')
        && str_contains($service, "'Final'"),
    'approval_preserves_request_currency' => str_contains($service, 'procurementFxFinalNormalizeCurrency')
        && str_contains($service, "currency = '" ) === false,
    'handoff_uses_canonical_request_handoffs' => str_contains($service, 'procurementRequestCanonicalUpsertHandoff')
        && str_contains($service, "'fx_fund_request'"),
    'canonical_resolver_supports_fx_final' => str_contains($canonical, 'PROCUREMENT_REQUEST_TYPE_FX_FINAL')
        && str_contains($canonical, 'procurementRequestCanonicalSyncFxFinalRequest'),
    'reversal_requires_pending_unprocessed_request' => str_contains($service, "payment_status'] !== 'Pending'")
        && str_contains($service, "fx_instruction_letter_id'] !== null")
        && str_contains($service, "processed_at'] !== null"),
    'retrieval_deletes_only_pending_unprocessed_request' => str_contains($service, "DELETE FROM fx_fund_request_table WHERE id = ? AND payment_status = 'Pending'")
        && str_contains($service, 'processed_at IS NULL'),
    'account_return_route_registered' => str_contains($index, '/request/fx/returnToProcurement')
        && str_contains($returnRoute, 'procurementFxFinalRetrieveFundRequestOne'),
    'direct_account_delete_blocks_procuredesk_linked_requests' => str_contains($fxDelete, 'procurementFxFinalAssertFundRequestsCanBeDeleted'),
    'single_payment_processing_syncs_procuredesk' => str_contains($singleProcess, 'procurementFxFinalSyncFundRequestToProcurement')
        && str_contains($singleProcess, "'account_processing_started'"),
    'grouped_payment_processing_syncs_each_procuredesk_request' => str_contains($groupProcess, 'procurementFxFinalSyncFundRequestToProcurement')
        && str_contains($groupProcess, "'account_processing_started'"),
    'payment_lifecycle_syncs_procuredesk_status' => str_contains($lifecycle, 'procurementFxFinalSyncFundRequestToProcurement')
        && str_contains($lifecycle, "'account_payment_status_updated'"),
    'unconfirmed_maps_to_processing_for_procuredesk' => str_contains($service, "strcasecmp(\$status, 'Unconfirmed')")
        && str_contains($service, "return 'Processing'"),
    'procuredesk_notifications_added' => str_contains($notifications, 'procurementNotificationPublishFxFinalEvent')
        && str_contains($notifications, "'foreign_final_purchase'"),
    'account_notifications_added' => str_contains($notifications, 'procurementNotificationMirrorFxFinalEventToAccount')
        && str_contains($notifications, "'/payments/fund-request/fx'"),
    'direct_fx_payment_create_route_not_modified_by_handoff' => !str_contains($service, '/fx/payment/createPayment'),
    'no_new_procuredesk_transaction_table' => !preg_match('/CREATE\s+TABLE[^;]*fx.*final/i', $service . $actions . $canonical),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
