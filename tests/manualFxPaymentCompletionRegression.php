<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path) ?: '';

$create = $read('routes/fx/payment/createPayment.php');
$edit = $read('routes/fx/payment/editPayment.php');
$status = $read('routes/fx/payment/updateStatus.php');
$delete = $read('includes/fxFundRequestPaymentDeletionService.php');
$manual = $read('includes/manualFxPaymentProcessingService.php');
$support = $read('includes/accountPaymentStorageRuntimeSupportService.php');
$runtime = $read('includes/accountPaymentStorageRuntimeReadService.php');
$cron = $read('cron/processPaymentReminderCycle.php');

$checks = [
    'manual_fx_has_canonical_request_type' => str_contains($support, "ACCOUNT_PAYMENT_TYPE_FX_MANUAL = 'manual_fx_payment'")
        && str_contains($runtime, 'ACCOUNT_PAYMENT_TYPE_FX_MANUAL'),
    'manual_fx_create_accepts_completion_policy' => str_contains($create, 'manualFxPaymentCompletionPayload($data)')
        && str_contains($create, "'completion_mode'")
        && str_contains($create, "'processing_business_days'"),
    'legacy_direct_client_remains_compatible' => str_contains($manual, "'enabled' => false")
        && str_contains($manual, "'payment_status' => 'Pending'"),
    'notify_immediate_automatic_are_supported' => str_contains($manual, "['Notify', 'Immediate', 'Automatic']")
        && str_contains($manual, "'payment_status' => \$mode === 'Immediate' ? 'Paid' : 'Pending'"),
    'manual_fx_uses_shared_batch_tables_only' => str_contains($manual, 'accountPaymentStorageCreateBatch')
        && str_contains($manual, 'accountPaymentStorageCreateItem')
        && str_contains($manual, 'ACCOUNT_PAYMENT_TYPE_FX_MANUAL')
        && !str_contains($manual, 'CREATE TABLE')
        && !str_contains($manual, 'ALTER TABLE'),
    'manual_fx_artifact_is_not_fund_request_artifact' => str_contains($manual, "'artifact_type' => 'manual_fx_instruction'")
        && !str_contains($manual, 'procurementFxFinal')
        && !str_contains($manual, 'procurementFxAdvance'),
    'manual_fx_is_not_procuredesk_linked' => !str_contains($create, 'procurement')
        && !str_contains($manual, 'ProcureDesk sync')
        && str_contains($manual, 'independent of Fund Requests and ProcureDesk'),
    'notify_due_creates_account_confirmation_notification' => str_contains($manual, 'manual_fx_payment_confirmation_due')
        && str_contains($manual, "status = 'Awaiting Confirmation'")
        && str_contains($manual, '/payments/fx-payments/payments?payment_id='),
    'automatic_due_marks_manual_payment_paid' => str_contains($manual, 'manual_fx_payment_auto_completed')
        && str_contains($manual, "UPDATE fx_instruction_letter_table SET payment_status = 'Paid'"),
    'shared_scheduler_runs_manual_fx_due_cycle' => str_contains($cron, 'manualFxPaymentProcessingProcessDue($conn)')
        && str_contains($cron, "'manual_fx_payments' => \$manualFxPayments"),
    'manual_payment_edit_stays_free_and_syncs_processing' => str_contains($edit, 'manualFxPaymentProcessingSyncStatus(')
        && !str_contains($edit, 'Manual FX payments cannot be edited'),
    'manual_payment_status_stays_independent_and_syncs_processing' => str_contains($status, 'manualFxPaymentProcessingSyncStatus(')
        && str_contains($status, "['Pending', 'Paid', 'Unconfirmed']"),
    'manual_payment_delete_stays_available_and_closes_processing' => str_contains($delete, 'manualFxPaymentProcessingCancel(')
        && str_contains($delete, 'DELETE FROM fx_instruction_letter_table WHERE id = ?'),
    'no_schema_migration_required' => !str_contains($manual, 'ALTER TABLE')
        && !str_contains($create, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
