<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/procurementFxFinalPurchaseService.php');
$fxService = $read('includes/fxFundRequestService.php');
$edit = $read('routes/request/fx/edit.php');
$list = $read('routes/request/fx/getFilteredRequest.php');
$single = $read('routes/request/fx/getSingle.php');
$returnRoute = $read('routes/request/fx/returnToProcurement.php');
$delete = $read('routes/request/fx/delete.php');
$process = $read('routes/request/fx/processForPayment.php');
$grouped = $read('routes/request/fx/processGroupedForPayment.php');
$lifecycle = $read('includes/fxFundRequestPaymentLifecycleService.php');
$directCreate = $read('routes/fx/payment/createPayment.php');

$checks = [
    'fx_register_exposes_procuredesk_link_metadata' => str_contains($list, 'procurement_purchase_id')
        && str_contains($list, "pr.request_type = 'fx_final_purchase'")
        && str_contains($list, "pr.handoff_status = 'In Account'"),
    'fx_single_exposes_procuredesk_link_metadata' => str_contains($single, 'procurement_purchase_id')
        && str_contains($single, "pr.approval_status = 'Approved'"),
    'formatted_fx_rows_identify_procuredesk_links' => str_contains($fxService, "procuredesk_linked")
        && str_contains($fxService, "procurement_purchase_id"),
    'account_edit_locks_linked_procurement_request' => str_contains($edit, 'procurementFxFinalLinkedPurchaseForFundRequest')
        && str_contains($service, 'LIMIT 1{$lock}'),
    'linked_request_type_cannot_change_from_final' => str_contains($edit, 'ProcureDesk-linked FX Final Purchases must remain Final requests.'),
    'linked_commercial_edit_requires_pending_state' => str_contains($edit, 'Commercial details can only be updated before Account starts processing the payment.')
        && str_contains($service, 'Commercial details can only be changed before Account starts processing payment.'),
    'account_edit_synchronizes_procurement_atomically' => str_contains($edit, 'procurementSyncFxFinalPurchaseFromFundRequest')
        && strpos($edit, 'procurementSyncFxFinalPurchaseFromFundRequest') > strpos($edit, '$writeConn->begin_transaction()')
        && strpos($edit, 'procurementSyncFxFinalPurchaseFromFundRequest') < strpos($edit, '$writeConn->commit()'),
    'commercial_sync_preserves_currency' => str_contains($service, 'SET currency = ?, po_number = ?, purchase_number = ?')
        && str_contains($service, 'procurementFxFinalNormalizeCurrency'),
    'commercial_sync_updates_supplier_project_and_references' => str_contains($service, 'project_id = ?, project_code = ?, project_name = ?')
        && str_contains($service, 'supplier_id = ?, supplier_name = ?, supplier_ledger = ?')
        && str_contains($service, 'invoice_number = ?, invoice_date = ?, purchase_date = ?'),
    'commercial_sync_updates_tax_and_payable_values' => str_contains($service, 'purchase_subtotal = ?, purchase_discount = ?, purchase_other_charges = ?')
        && str_contains($service, 'purchase_vat_status = ?, purchase_vat_rate = ?, purchase_vat_amount = ?')
        && str_contains($service, 'wht_status = ?, wht_rate = ?, wht_amount = ?')
        && str_contains($service, 'account_payable_amount = ?'),
    'commercial_sync_revalidates_totals_server_side' => str_contains($service, 'FX Fund Request commercial totals are inconsistent')
        && str_contains($service, 'procurementLocalFinalRateAmount'),
    'commercial_sync_is_audited_and_notified' => str_contains($service, "'account_updated_sent_purchase'")
        && str_contains($service, 'procurementFxFinalRecordEvent'),
    'return_to_procurement_route_remains_available' => str_contains($returnRoute, 'procurementFxFinalRetrieveFundRequestOne')
        && str_contains($returnRoute, 'A return reason is required.'),
    'return_preserves_handoff_history' => str_contains($service, 'procurementFxFinalArchiveHandoff')
        && str_contains($service, "'returned_by_account'"),
    'linked_fx_delete_remains_blocked' => str_contains($delete, 'procurementFxFinalAssertFundRequestsCanBeDeleted'),
    'single_and_grouped_processing_still_sync_procurement' => str_contains($process, 'procurementFxFinalSyncFundRequestToProcurement')
        && str_contains($grouped, 'procurementFxFinalSyncFundRequestToProcurement'),
    'payment_lifecycle_still_syncs_procurement' => str_contains($lifecycle, 'procurementFxFinalSyncFundRequestToProcurement'),
    'direct_fx_payment_creation_remains_unchanged' => str_contains($directCreate, 'fx_instruction_letter_table')
        && !str_contains($directCreate, 'procurementSyncFxFinalPurchaseFromFundRequest'),
    'parity_batch_requires_no_schema_change' => !str_contains($service . $edit . $list . $single, 'ALTER TABLE')
        && !str_contains($service . $edit . $list . $single, 'CREATE TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
