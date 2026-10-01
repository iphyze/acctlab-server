<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/fxFundRequestReturnToPendingService.php') ?: '';
$route = file_get_contents($root . '/routes/request/fx/returnToPending.php') ?: '';
$index = file_get_contents($root . '/index.php') ?: '';
$delete = file_get_contents($root . '/routes/fx/payment/deletePayment.php') ?: '';
$deleteService = file_get_contents($root . '/includes/fxFundRequestPaymentDeletionService.php') ?: '';
$processing = file_get_contents($root . '/includes/accountPaymentProcessingService.php') ?: '';
$final = file_get_contents($root . '/includes/procurementFxFinalPurchaseService.php') ?: '';
$advance = file_get_contents($root . '/includes/procurementFxAdvancePurchaseService.php') ?: '';

$checks = [];
$checks['return_to_pending_route_registered'] = str_contains($index, "'/request/fx/returnToPending'")
    && str_contains($route, 'fxFundRequestReturnToPending(');
$checks['return_to_pending_requires_admin_and_reason'] = str_contains($route, 'requireAdmin()')
    && str_contains($service, 'fxFundRequestReturnToPendingReason')
    && str_contains($service, 'A reason is required before returning an FX Fund Request to Pending.');
$checks['final_and_advance_share_same_reversal_service'] = str_contains($service, "fx_final_purchase")
    && str_contains($service, "fx_advance_purchase")
    && str_contains($service, 'fx_fund_request_table');
$checks['request_is_detached_and_reopened'] = str_contains($service, "SET payment_status = 'Pending', fx_instruction_letter_id = NULL")
    && str_contains($service, 'payment_currency = NULL, payment_amount = NULL, exchange_rate = NULL')
    && str_contains($service, 'processed_by = NULL, processed_at = NULL');
$checks['paid_payment_becomes_reversed_not_deleted'] = str_contains($service, "? 'Reversed' : 'Cancelled'")
    && !str_contains($service, 'DELETE FROM fx_instruction_letter_table');
$checks['unpaid_payment_becomes_cancelled_not_deleted'] = str_contains($service, "'processing_cancellation'")
    && str_contains($service, '$instructionNewStatus = $isPaidReversal ? \'Reversed\' : \'Cancelled\'');
$checks['canonical_processing_items_are_cancelled_with_audit_reason'] = str_contains($service, "UPDATE account_payment_batch_items")
    && str_contains($service, "SET status = 'Cancelled', status_reason = ?")
    && str_contains($service, 'accountPaymentProcessingUpdateCanonicalBatchStatus');
$checks['paid_amount_history_is_not_zeroed'] = !preg_match('/amount_paid\s*=\s*(?:0|0\.00|NULL)/i', $service)
    && !preg_match('/paid_at\s*=\s*NULL/i', $service);
$checks['active_reminders_are_cancelled'] = str_contains($service, "UPDATE account_payment_reminders")
    && str_contains($service, "lifecycle_status = 'Cancelled'")
    && str_contains($service, "lifecycle_status NOT IN ('Completed','Cancelled')");
$checks['paid_and_post_pending_grouped_payments_are_atomic'] = str_contains($service, 'strcasecmp($instructionStatus, \'Pending\') !== 0')
    && str_contains($service, 'Select every Fund Request linked to this grouped payment');
$checks['pending_group_can_partially_reopen_and_recalculate'] = str_contains($service, 'fxFundRequestReturnToPendingAdjustRemainingInstruction')
    && str_contains($service, 'remainingAmount')
    && str_contains($service, 'amount_words');
$checks['canonical_fx_artifact_is_preserved_as_removed_history'] = str_contains($service, "UPDATE account_payment_artifacts")
    && str_contains($service, "artifact_status = 'Removed'")
    && str_contains($service, 'removed_at = NOW()');
$checks['generated_historical_fx_payment_can_be_deleted_after_audit_preservation'] = str_contains($delete, 'fxFundRequestPaymentDelete(')
    && str_contains($deleteService, 'preserved_artifact_ids')
    && str_contains($deleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?');
$checks['generated_historical_fx_payment_remains_manually_adjustable_until_deleted'] = file_exists($root . '/routes/fx/payment/editPayment.php')
    && !str_contains(file_get_contents($root . '/routes/fx/payment/editPayment.php') ?: '', 'cannot be edited because');
$checks['procuredesk_final_and_advance_are_synchronized'] = str_contains($service, 'procurementFxFinalSyncFundRequestToProcurement')
    && str_contains($service, 'procurementFxAdvanceSyncFundRequestToProcurement')
    && str_contains($service, 'account_payment_reversed_to_pending')
    && str_contains($service, 'account_payment_returned_to_pending');
$checks['procuredesk_audit_preserves_original_instruction_and_reason'] = str_contains($service, "'original_fx_instruction_letter_id'")
    && str_contains($service, "'original_instruction_status'")
    && str_contains($service, "'reason' => \$reason")
    && str_contains($final, 'procurementFxFinalRecordEvent')
    && str_contains($advance, 'procurementFxAdvanceRecordEvent');
$checks['shared_processing_architecture_is_unchanged'] = str_contains($processing, 'account_payment_batches')
    && str_contains($processing, 'account_payment_batch_items')
    && !str_contains($service, 'fx_payment_batches')
    && !str_contains($service, 'fx_payment_batch_items');
$checks['no_schema_expansion_in_reversal_batch'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $service . $route . $delete . $deleteService);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
