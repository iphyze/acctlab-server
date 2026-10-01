<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deletion = file_get_contents($root . '/includes/fxFundRequestPaymentDeletionService.php') ?: '';
$returnService = file_get_contents($root . '/includes/fxFundRequestReturnToPendingService.php') ?: '';
$lifecycle = file_get_contents($root . '/includes/fxFundRequestPaymentLifecycleService.php') ?: '';
$canonical = file_get_contents($root . '/includes/fxFundRequestPaymentCanonicalStatusService.php') ?: '';
$finalService = file_get_contents($root . '/includes/procurementFxFinalPurchaseService.php') ?: '';
$advanceService = file_get_contents($root . '/includes/procurementFxAdvancePurchaseService.php') ?: '';
$statusRoute = file_get_contents($root . '/routes/fx/payment/updateStatus.php') ?: '';
$deleteRoute = file_get_contents($root . '/routes/fx/payment/deletePayment.php') ?: '';

$checks = [];
$checks['grouped_delete_expands_every_linked_request_before_delete'] = str_contains($deletion, 'fxFundRequestLifecycleLinkedRequestsForInstruction')
    && str_contains($deletion, '$linkedRequestIds')
    && str_contains($deletion, 'fxFundRequestReturnToPending(')
    && strpos($deletion, 'fxFundRequestReturnToPending(') < strpos($deletion, 'DELETE FROM fx_instruction_letter_table WHERE id = ?');
$checks['mixed_final_advance_group_delete_uses_shared_reopen_service'] = str_contains($returnService, 'procurementFxFinalSyncFundRequestToProcurement')
    && str_contains($returnService, 'procurementFxAdvanceSyncFundRequestToProcurement')
    && str_contains($returnService, "request_type IN ('fx_final_purchase','fx_advance_purchase')");
$checks['paid_group_delete_requires_reason_and_preserves_history'] = str_contains($deletion, 'A reason is required before deleting a Paid FX payment.')
    && str_contains($deletion, 'FX payment deletion audit')
    && !preg_match('/DELETE\s+FROM\s+account_payment_artifacts/i', $deletion);
$checks['procuredesk_gets_deletion_specific_audit_after_reopen'] = str_contains($deletion, 'fxFundRequestPaymentDeletionRecordProcurementAudit')
    && str_contains($deletion, "account_payment_deleted_after_reopen_audit")
    && str_contains($deletion, 'deleted_fx_instruction_letter_id')
    && str_contains($deletion, 'deleted_payment_reference')
    && str_contains($deletion, 'grouped_request_ids')
    && str_contains($deletion, 'grouped_request_count');
$checks['procuredesk_deletion_audit_supports_final_and_advance'] = str_contains($deletion, 'procurementFxFinalLinkedPurchaseForFundRequest')
    && str_contains($deletion, 'procurementFxFinalRecordEvent')
    && str_contains($deletion, 'procurementFxAdvanceLinkedPurchaseForFundRequest')
    && str_contains($deletion, 'procurementFxAdvanceRecordEvent');
$checks['payment_status_update_remains_group_atomic'] = str_contains($statusRoute, 'fxFundRequestLifecycleSyncMany')
    && str_contains($lifecycle, 'fxFundRequestLifecycleSyncLinkedInstructionStatus')
    && str_contains($lifecycle, 'fxFundRequestLifecycleLinkedRequestsForInstruction');
$checks['payment_status_update_syncs_canonical_processing'] = str_contains($lifecycle, 'fxFundRequestCanonicalSyncInstructionStatus')
    && str_contains($canonical, 'account_payment_batch_items')
    && str_contains($canonical, 'account_payment_batches');
$checks['fund_request_status_update_still_drives_payment_status'] = str_contains($lifecycle, 'UPDATE fx_instruction_letter_table SET payment_status = ?')
    && str_contains($lifecycle, "'Processing' ? 'Pending'")
    && str_contains($lifecycle, 'fxFundRequestLifecycleSyncMany');
$checks['procuredesk_final_and_advance_status_mapping_remains_intact'] = str_contains($finalService, 'function procurementFxFinalMapAccountPaymentStatus')
    && str_contains($advanceService, 'function procurementFxAdvanceMapAccountPaymentStatus')
    && str_contains($finalService, "strcasecmp(\$status, 'Unconfirmed') === 0")
    && str_contains($advanceService, "strcasecmp(\$status, 'Unconfirmed') === 0");
$checks['delete_route_returns_reopened_and_procurement_context'] = str_contains($deleteRoute, "'reopened_request_ids'")
    && str_contains($deletion, "'procurement_purchase_ids'");
$checks['direct_fx_payment_delete_stays_independent'] = str_contains($deletion, '$direct[] = $paymentId')
    && str_contains($deletion, 'if ($linkedRequests !== [])')
    && str_contains($deletion, 'fxFundRequestPaymentDeletionRecordProcurementAudit');
$checks['single_canonical_processing_architecture_is_preserved'] = !str_contains($deletion . $lifecycle . $canonical, 'fx_payment_batches')
    && !str_contains($deletion . $lifecycle . $canonical, 'fx_payment_batch_items');
$checks['batch3_requires_no_schema_change'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $deletion . $returnService . $lifecycle . $canonical);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
