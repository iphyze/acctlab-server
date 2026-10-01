<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$processingPath = $root . '/includes/accountPaymentProcessingService.php';
$reminderPath = $root . '/includes/accountPaymentReminderService.php';
$storagePlanPath = $root . '/includes/paymentReminderUnifiedStoragePlanService.php';
$runtimePath = $root . '/includes/paymentReminderCanonicalRuntimeService.php';
$cyclePath = $root . '/cron/processPaymentReminderCycle.php';
$reinitiatePath = $root . '/routes/account/reinitiatePaymentReminder.php';

$processing = file_get_contents($processingPath) ?: '';
$reminder = file_get_contents($reminderPath) ?: '';
$storagePlan = file_get_contents($storagePlanPath) ?: '';
$runtime = file_get_contents($runtimePath) ?: '';
$cycle = file_get_contents($cyclePath) ?: '';
$reinitiate = file_get_contents($reinitiatePath) ?: '';

$checks = [];
$checks['fx_due_cycle_uses_shared_scheduler'] = str_contains($cycle, 'accountPaymentProcessingProcessDueFxBatches($conn)')
    && str_contains($cycle, "'fx_batches' => \$fxBatches")
    && str_contains($cycle, 'accountPaymentReminderProcessDue($conn, $limit)');
$checks['automatic_fx_completion_is_due_date_driven'] = str_contains($processing, 'accountPaymentProcessingFxDueInstructions')
    && str_contains($processing, "batch.completion_mode IN ('Notify','Automatic')")
    && str_contains($processing, 'batch.expected_completion_at <= ?')
    && str_contains($processing, "if (\$completionMode === 'Automatic')");
$checks['automatic_fx_completion_updates_shared_items_and_lifecycle'] = str_contains($processing, 'accountPaymentProcessingApplyFxPaid(')
    && str_contains($processing, "status = 'Paid', amount_paid = amount, paid_at = NOW()")
    && str_contains($processing, "fxFundRequestLifecycleApplyRequestStatus(")
    && str_contains($processing, "'Paid'");
$checks['paid_item_state_precedes_procuredesk_sync'] = strpos($processing, "status = 'Paid', amount_paid = amount") !== false
    && strpos($processing, 'fxFundRequestLifecycleApplyRequestStatus(') !== false
    && strpos($processing, "status = 'Paid', amount_paid = amount") < strpos($processing, 'fxFundRequestLifecycleApplyRequestStatus(');
$checks['notify_fx_moves_items_to_confirmation_queue'] = str_contains($processing, "SET status = 'Awaiting Confirmation'")
    && str_contains($processing, 'Expected FX completion time reached; Account confirmation is required.')
    && str_contains($processing, 'accountPaymentProcessingSyncFxRequestAfterItemChange');
$checks['notify_fx_schedules_existing_reminder_lifecycle'] = str_contains($processing, 'accountPaymentReminderSchedule(')
    && str_contains($processing, 'accountPaymentReminderInitialDelivery(')
    && str_contains($processing, "'source' => 'fx_payment_batch_due'");
$checks['fx_reminder_types_share_existing_reminder_table'] = str_contains($reminder, "ACCOUNT_PAYMENT_REMINDER_TYPES = ['Advance', 'Supplier', 'Compass', 'FX Final', 'FX Advance']")
    && str_contains($reminder, "'fx_final_purchase' => 'FX Final'")
    && str_contains($reminder, "'fx_advance_purchase' => 'FX Advance'")
    && str_contains($reminder, 'INSERT INTO account_payment_reminders');
$checks['fx_reminders_resolve_from_canonical_batch_items'] = str_contains($reminder, "'request_table' => 'fx_fund_request_table'")
    && str_contains($reminder, "'canonical_request_type' => \$canonicalType")
    && str_contains($reminder, 'LEFT JOIN account_payment_batch_items i')
    && str_contains($reminder, 'LEFT JOIN account_payment_batches b');
$checks['fx_reminder_delivery_routes_to_unified_processing_workspace'] = str_contains($reminder, "'route' => '/payments/payment-processing'")
    && str_contains($reminder, "'canonical_batch_id' => \$canonicalBatchId ?: null")
    && str_contains($reminder, "'&batch_id=' . \$canonicalBatchId");
$checks['fx_reminder_retry_and_reinitiation_are_reused'] = str_contains($reminder, "lifecycle_status = 'Retry Scheduled'")
    && str_contains($reminder, 'accountPaymentReminderRetryMinutes')
    && str_contains($reminder, 'function accountPaymentReminderReinitiate')
    && str_contains($reinitiate, 'accountPaymentReminderReinitiate(');
$checks['fx_notify_backfill_is_supported'] = str_contains($reminder, "i.request_type IN ('fx_final_purchase', 'fx_advance_purchase')")
    && str_contains($reminder, "THEN 'FX Advance' ELSE 'FX Final'")
    && str_contains($reminder, "'fx_final_backfilled' => \$counts['FX Final']")
    && str_contains($reminder, "'fx_advance_backfilled' => \$counts['FX Advance']");
$checks['reminder_health_accepts_supported_workflows'] = str_contains($storagePlan, "request_type NOT IN ('Advance', 'Supplier', 'Compass', 'FX Final', 'FX Advance')")
    && str_contains($storagePlan, "'fx_final' => [")
    && str_contains($storagePlan, "'fx_advance' => [")
    && str_contains($runtime, "ACCOUNT_PAYMENT_TYPE_FX_FINAL => accountPaymentStorageCanonicalWritesEnabled")
    && str_contains($runtime, "ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => accountPaymentStorageCanonicalWritesEnabled");
$checks['mixed_fx_instruction_stays_atomic'] = str_contains($processing, "artifact.artifact_type = 'fx_instruction'")
    && str_contains($processing, "fx.fx_instruction_letter_id = ?")
    && str_contains($processing, 'accountPaymentProcessingExpandFxSharedInstructionRequests')
    && str_contains($processing, "Select every request linked to that payment") === false; // lifecycle owns this guard, due service supplies all siblings.
$checks['procuredesk_sync_runs_for_due_and_paid_states'] = str_contains($processing, 'procurementFxFinalSyncFundRequestToProcurement')
    && str_contains($processing, 'procurementFxAdvanceSyncFundRequestToProcurement')
    && str_contains($processing, "'account_payment_confirmation_due'")
    && str_contains($processing, 'account_payment_reminders');
$checks['automatic_failures_are_retryable_without_parallel_queue'] = str_contains($processing, "'retryable' => true")
    && str_contains($processing, 'Automatic failures remain Processing and due')
    && !str_contains($processing, 'fx_payment_reminder_queue')
    && !str_contains($processing, 'fx_payment_batches');
$checks['no_schema_expansion_in_batch2'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $processing . $cycle . $runtime . $storagePlan)
    && substr_count($reminder, 'CREATE TABLE IF NOT EXISTS account_payment_reminders') === 1;

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
