<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deleteService = file_get_contents($root . '/includes/fxFundRequestPaymentDeletionService.php') ?: '';
$canonicalStatus = file_get_contents($root . '/includes/fxFundRequestPaymentCanonicalStatusService.php') ?: '';
$lifecycle = file_get_contents($root . '/includes/fxFundRequestPaymentLifecycleService.php') ?: '';
$deleteRoute = file_get_contents($root . '/routes/fx/payment/deletePayment.php') ?: '';
$editRoute = file_get_contents($root . '/routes/fx/payment/editPayment.php') ?: '';
$statusRoute = file_get_contents($root . '/routes/fx/payment/updateStatus.php') ?: '';
$returnService = file_get_contents($root . '/includes/fxFundRequestReturnToPendingService.php') ?: '';
$processing = file_get_contents($root . '/includes/accountPaymentProcessingService.php') ?: '';

$checks = [];
$checks['linked_fx_payments_are_deletable_through_audit_service'] = str_contains($deleteRoute, 'fxFundRequestPaymentDelete(')
    && !str_contains($deleteRoute, 'cannot be deleted')
    && !str_contains($deleteRoute, 'Correct the linked direct AcctLab Fund Request(s) back to Pending first');
$checks['paid_fx_payment_deletion_requires_reason'] = str_contains($deleteService, 'A reason is required before deleting a Paid FX payment.')
    && str_contains($deleteService, '$isPaid');
$checks['linked_delete_returns_fund_requests_to_pending_first'] = str_contains($deleteService, 'fxFundRequestReturnToPending(')
    && str_contains($returnService, "SET payment_status = 'Pending', fx_instruction_letter_id = NULL");
$checks['grouped_payment_delete_reopens_all_instruction_siblings'] = str_contains($deleteService, 'fxFundRequestLifecycleLinkedRequestsForInstruction')
    && str_contains($deleteService, '$linkedRequestIds')
    && str_contains($deleteService, 'fxFundRequestReturnToPending(');
$checks['actual_fx_instruction_row_is_deleted_after_reopen'] = str_contains($deleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?')
    && strpos($deleteService, 'fxFundRequestReturnToPending(') < strpos($deleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?');
$checks['canonical_artifact_survives_payment_row_deletion'] = str_contains($deleteService, 'account_payment_artifacts')
    && str_contains($deleteService, 'preserved_artifact_ids')
    && !str_contains($deleteService, 'DELETE FROM account_payment_artifacts');
$checks['canonical_item_preserves_payment_audit_snapshot'] = str_contains($deleteService, 'FX payment deletion audit')
    && str_contains($deleteService, 'Original status:')
    && str_contains($deleteService, 'Deleted by:')
    && str_contains($deleteService, 'Reason:')
    && str_contains($deleteService, "SET status = 'Cancelled', status_reason = ?");
$checks['paid_amount_and_paid_at_are_not_erased_on_delete'] = !preg_match('/amount_paid\s*=\s*(?:0|0\.00|NULL)/i', $deleteService)
    && !preg_match('/paid_at\s*=\s*NULL/i', $deleteService);
$checks['procuredesk_sync_is_reused_before_delete'] = str_contains($returnService, 'procurementFxFinalSyncFundRequestToProcurement')
    && str_contains($returnService, 'procurementFxAdvanceSyncFundRequestToProcurement');
$checks['linked_fx_payment_edit_remains_allowed'] = str_contains($editRoute, 'UPDATE fx_instruction_letter_table SET')
    && str_contains($editRoute, 'fxFundRequestLifecycleSyncLinkedInstructionStatus')
    && !str_contains($editRoute, 'cannot be edited because');
$checks['payment_status_can_be_changed_independently'] = str_contains($statusRoute, "Allowed: Pending, Paid, Unconfirmed")
    && str_contains($statusRoute, 'UPDATE fx_instruction_letter_table')
    && str_contains($statusRoute, 'fxFundRequestLifecycleSyncMany');
$checks['fund_request_status_changes_update_payment_and_canonical_items'] = str_contains($lifecycle, 'UPDATE fx_instruction_letter_table SET payment_status = ?')
    && str_contains($lifecycle, 'fxFundRequestCanonicalSyncInstructionStatus');
$checks['canonical_processing_status_tracks_manual_payment_status'] = str_contains($canonicalStatus, "return 'Paid'")
    && str_contains($canonicalStatus, "return 'Awaiting Confirmation'")
    && str_contains($canonicalStatus, "return 'Processing'")
    && str_contains($canonicalStatus, 'account_payment_batch_items')
    && str_contains($canonicalStatus, 'account_payment_batches');
$checks['direct_fx_payment_delete_remains_supported'] = str_contains($deleteService, '$direct[] = $paymentId')
    && str_contains($deleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?');
$checks['shared_processing_architecture_remains_single'] = str_contains($processing, 'account_payment_batches')
    && str_contains($processing, 'account_payment_batch_items')
    && !str_contains($deleteService . $canonicalStatus, 'fx_payment_batches')
    && !str_contains($deleteService . $canonicalStatus, 'fx_payment_batch_items');
$checks['no_schema_expansion_in_batch1'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $deleteService . $canonicalStatus . $deleteRoute . $lifecycle);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
