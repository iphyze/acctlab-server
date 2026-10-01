<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$advance = $read('includes/procurementLocalAdvancePurchaseService.php');
$runtime = $read('includes/procurementRequestCanonicalRuntimeService.php');
$compassService = $read('includes/procurementCompassHandoffService.php');
$compassStatus = $read('routes/request/compass/updateStatus.php');
$compassReturn = $read('routes/request/compass/returnToProcurement.php');
$compassEdit = $read('routes/request/compass/edit.php');
$compassDelete = $read('routes/request/compass/delete.php');
$final = $read('includes/procurementLocalFinalPurchaseService.php');
$batch3Migration = $read('database/20260819_procurement_compass_local_advance_routing.sql');

$checks = [
    'batch3_adds_no_new_transaction_table_or_migration' => $batch3Migration === '',
    'local_advance_requires_compass_router' => str_contains(
        $advance,
        "require_once __DIR__ . '/procurementCompassHandoffService.php'"
    ),
    'approval_resolves_destination_from_selected_supplier' => str_contains(
        $advance,
        'procurementCompassResolveLocalAccountRequestType('
    ) && str_contains($advance, "PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE")
      && str_contains($advance, "\$purchase['supplier_id'] ?? null")
      && str_contains($advance, "\$purchase['supplier_name'] ?? null"),
    'compass_advance_uses_existing_compass_fund_request_table' => str_contains(
        $advance,
        'function procurementLocalAdvanceCreateCompassFundRequest'
    ) && str_contains($advance, 'INSERT INTO compass_fund_request_table')
      && str_contains($advance, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS"),
    'normal_supplier_advance_path_is_preserved' => str_contains(
        $advance,
        'function procurementLocalAdvanceCreateAdvancePaymentRequest'
    ) && str_contains($advance, 'INSERT INTO advance_payment_request')
      && str_contains($advance, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE"),
    'approval_persists_dynamic_account_destination' => str_contains(
        $advance,
        'handoff_revision = ?, account_request_type = ?,'
    ) && str_contains($advance, 'account_request_id = ?'),
    'canonical_advance_read_exposes_account_request_type' => str_contains(
        $runtime,
        "account_request_type,\n            account_request_id AS advance_payment_request_id"
    ),
    'advance_reads_both_possible_account_destinations' => str_contains(
        $advance,
        'LEFT JOIN compass_fund_request_table cfr'
    ) && str_contains($advance, "r.account_request_type = 'compass_fund_request'")
      && str_contains($advance, 'COALESCE(apr.payment_status, cfr.payment_status)'),
    'compass_advance_reuse_guard_prevents_duplicate_handoffs' => str_contains(
        $advance,
        'function procurementLocalAdvanceFindReusableCompassFundRequest'
    ) && str_contains($advance, 'More than one Compass Fund Request is linked to this Local Advance Purchase.')
      && str_contains($advance, 'more than one matching unlinked Compass advance request already exists'),
    'reverse_and_retrieve_are_destination_aware' => str_contains(
        $advance,
        'function procurementLocalAdvanceAccountRequestTable'
    ) && str_contains($advance, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS => 'compass_fund_request_table'")
      && str_contains($advance, 'procurementLocalAdvanceDeletePendingAccountRequest')
      && str_contains($advance, 'removed_compass_fund_request_id'),
    'account_can_return_compass_final_or_advance_by_origin' => str_contains(
        $compassService,
        'function procurementCompassLinkedProcurementRequest'
    ) && str_contains($compassReturn, 'procurementLocalFinalRetrieveCompassRequestOne')
      && str_contains($compassReturn, 'procurementLocalAdvanceRetrieveCompassRequestOne')
      && str_contains($compassReturn, 'PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE'),
    'compass_payment_status_syncs_both_local_flows' => str_contains(
        $compassStatus,
        'procurementSyncLocalFinalCompassPaymentDetails'
    ) && str_contains($compassStatus, 'procurementSyncLocalAdvanceCompassPaymentDetails')
      && str_contains($advance, 'function procurementSyncLocalAdvanceCompassPaymentDetails')
      && str_contains($advance, "r.account_request_type = 'compass_fund_request'"),
    'normal_advance_payment_sync_is_type_isolated' => str_contains(
        $advance,
        "AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')"
    ),
    'po_allocation_summary_includes_compass_account_activity' => str_contains(
        $advance,
        'COALESCE(apr.advance_payment, cfr.amount'
    ) && str_contains($advance, 'COALESCE(apr.amount_paid, cfr.amount_paid')
      && str_contains($advance, 'cfr.processing_started_at IS NOT NULL')
      && str_contains($advance, 'cfr.payment_batch_id IS NOT NULL'),
    'pending_po_amendment_updates_compass_request_in_place' => str_contains(
        $advance,
        'UPDATE compass_fund_request_table'
    ) && str_contains($advance, "payment_status = 'Pending'")
      && str_contains($advance, 'procurement_current_po_revision_id'),
    'supplementary_request_uses_same_supplier_destination_router' => str_contains(
        $advance,
        'procurementLocalAdvanceCreateSupplementaryPurchaseAndRequest'
    ) && str_contains($advance, 'procurementLocalAdvanceCreateAccountFundRequest(')
      && str_contains($advance, "'account_request_type' => \$accountRequestType"),
    'supplementary_reconciliation_reads_compass_payment_state' => str_contains(
        $advance,
        'function procurementLocalAdvanceRefreshSupplementaryReconciliation'
    ) && str_contains($advance, 'COALESCE(apr.payment_status, cfr.payment_status) AS account_payment_status')
      && str_contains($advance, 'COALESCE(apr.advance_payment, cfr.amount) AS advance_payment'),
    'po_revision_metadata_propagates_to_compass_requests' => str_contains(
        $advance,
        '$compassCurrentRevisionUpdate'
    ) && str_contains($advance, 'SET cfr.procurement_root_po_id = purchase.po_id')
      && str_contains($advance, 'cfr.procurement_current_po_revision_number = ?'),
    'linked_compass_edit_delete_protection_covers_both_local_types' => str_contains(
        $final,
        'procurementCompassLinkedProcurementRequest($conn, $compassRequestId)'
    ) && str_contains($compassEdit, 'procurementAssertCompassFundRequestCanBeEdited')
      && str_contains($compassDelete, 'procurementAssertCompassFundRequestsCanBeDeleted'),
    'normal_advance_direct_edit_delete_guards_ignore_compass_id_collisions' => str_contains(
        $advance,
        "AND (account_request_type IS NULL OR account_request_type = 'advance_payment_request')"
    ) && str_contains($advance, "AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')"),
    'fx_handoff_is_not_part_of_local_advance_compass_router' => !str_contains(
        $advance,
        'fx_fund_request_table'
    ) && !str_contains($compassStatus, 'fx_fund_request_table')
      && !str_contains($compassReturn, 'fx_fund_request_table'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
