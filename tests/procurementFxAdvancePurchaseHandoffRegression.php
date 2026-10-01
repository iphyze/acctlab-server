<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/procurementFxAdvancePurchaseService.php');
$actions = $read('routes/procurement/payments/foreign/advancePurchaseActions.php');
$sync = $read('includes/procurementRequestCanonicalSyncService.php');
$notifications = $read('includes/procurementNotificationService.php');
$returnRoute = $read('routes/request/fx/returnToProcurement.php');
$delete = $read('routes/request/fx/delete.php');
$edit = $read('routes/request/fx/edit.php');
$list = $read('routes/request/fx/getFilteredRequest.php');
$single = $read('routes/request/fx/getSingle.php');
$process = $read('routes/request/fx/processForPayment.php');
$grouped = $read('routes/request/fx/processGroupedForPayment.php');
$lifecycle = $read('includes/fxFundRequestPaymentLifecycleService.php');
$directCreate = $read('routes/fx/payment/createPayment.php');

$checks = [
    'approval_permission_used' => str_contains($actions, "payments.fx_advance.approve")
        && str_contains($actions, 'procurementFxAdvanceApproveOne'),
    'reverse_permission_used' => str_contains($actions, 'payments.fx_advance.reverse_approval')
        && str_contains($actions, 'procurementFxAdvanceReverseApprovalOne'),
    'retrieve_permission_used' => str_contains($actions, 'payments.fx_advance.retrieve_from_account')
        && str_contains($actions, 'procurementFxAdvanceRetrieveOne'),
    'approval_creates_advance_fx_fund_request' => str_contains($service, "'request_type' => 'Advance'")
        && str_contains($service, 'INSERT INTO fx_fund_request_table')
        && str_contains($service, 'percentage, payable_amount'),
    'approval_preserves_request_currency' => str_contains($service, "'currency' => (string)")
        && str_contains($service, "purchase['currency']")
        && str_contains($service, "payload['currency']"),
    'approval_uses_fx_percentage_guard' => str_contains($service, 'fxFundRequestAcquireAdvancePoLock')
        && str_contains($service, 'fxFundRequestAssertAdvancePercentageAvailable')
        && str_contains($service, 'poLockHeld'),
    'approval_lock_is_held_until_transaction_finishes' => str_contains($service, 'function procurementFxAdvanceApproveOne')
        && str_contains($service, '$poLockName = fxFundRequestAcquireAdvancePoLock')
        && str_contains($service, 'finally {')
        && str_contains($service, 'fxFundRequestReleaseAdvancePoLock($conn, $poLockName);'),
    'approval_validates_expected_payment_against_account_calculation' => str_contains($service, 'FX Advance Purchase totals no longer match the Account FX Fund Request calculation'),
    'handoff_uses_canonical_request_handoffs' => str_contains($service, 'procurementRequestCanonicalUpsertHandoff')
        && str_contains($service, "'fx_fund_request'"),
    'handoff_preserves_po_revision_snapshot' => str_contains($service, '$poRevisionId')
        && str_contains($service, '$poRevisionNumber')
        && str_contains($service, '$poSnapshotJson'),
    'canonical_resolver_supports_fx_advance' => str_contains($sync, 'PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE')
        && str_contains($sync, 'procurementRequestCanonicalSyncFxAdvanceRequest')
        && str_contains($sync, 'PROCUREMENT_REQUEST_TYPE_FX_ADVANCE'),
    'reversal_requires_pending_unprocessed_fund_request' => str_contains($service, 'Approval cannot be reversed after Account has started processing the FX Fund Request.')
        && str_contains($service, "request_type = 'Advance' AND payment_status = 'Pending'"),
    'retrieval_requires_pending_unprocessed_fund_request' => str_contains($service, 'This FX Advance request is already being processed and cannot be returned.')
        && str_contains($service, "'returned_by_account'"),
    'account_return_route_supports_final_and_advance' => str_contains($returnRoute, 'procurementFxFinalRetrieveFundRequestOne')
        && str_contains($returnRoute, 'procurementFxAdvanceRetrieveFundRequestOne')
        && str_contains($returnRoute, 'A return reason is required.'),
    'account_delete_blocks_linked_fx_advance' => str_contains($delete, 'procurementFxAdvanceAssertFundRequestsCanBeDeleted')
        && str_contains($service, 'ProcureDesk-linked FX Advance Fund Requests cannot be deleted in Account'),
    'account_edit_does_not_treat_linked_advance_as_manual' => str_contains($edit, 'procurementFxAdvanceLinkedPurchaseForFundRequest')
        && str_contains($edit, 'ProcureDesk-linked FX Advance Requests must be corrected from the ProcureDesk Advance workflow'),
    'fx_register_exposes_fx_advance_procuredesk_metadata' => str_contains($list, "pr.request_type = 'fx_advance_purchase'")
        && str_contains($list, 'procurement_request_type')
        && str_contains($single, 'procurement_request_type'),
    'single_payment_processing_syncs_fx_advance_procuredesk' => str_contains($process, 'procurementFxAdvanceSyncFundRequestToProcurement'),
    'grouped_payment_processing_syncs_fx_advance_procuredesk' => str_contains($grouped, 'procurementFxAdvanceSyncFundRequestToProcurement'),
    'payment_lifecycle_syncs_fx_advance_procuredesk' => str_contains($lifecycle, 'procurementFxAdvanceSyncFundRequestToProcurement')
        && str_contains($lifecycle, 'procurementFxAdvanceLinkedPurchaseForFundRequest'),
    'unconfirmed_maps_to_processing' => str_contains($service, 'function procurementFxAdvanceMapAccountPaymentStatus')
        && str_contains($service, "'Unconfirmed'")
        && str_contains($service, "return 'Processing';"),
    'procuredesk_notifications_added' => str_contains($notifications, 'procurementNotificationPublishFxAdvanceEvent')
        && str_contains($notifications, "category' => 'foreign_advance_purchase'"),
    'account_notifications_added' => str_contains($notifications, 'procurementNotificationMirrorFxAdvanceEventToAccount')
        && str_contains($notifications, "'route' => '/payments/fund-request/fx'"),
    'direct_fx_payment_creation_remains_unchanged' => str_contains($directCreate, 'fx_instruction_letter_table')
        && !str_contains($directCreate, 'procurementFxAdvancePurchaseService'),
    'no_new_fx_advance_transaction_table' => !str_contains($service . $actions, 'CREATE TABLE')
        && !str_contains($service . $actions, 'ALTER TABLE'),
    'batch2_requires_no_schema_migration' => !str_contains($service . $actions . $returnRoute . $lifecycle, 'ALTER TABLE')
        && !str_contains($service . $actions . $returnRoute . $lifecycle, 'CREATE TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
