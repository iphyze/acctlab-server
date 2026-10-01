<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path) ?: '';

$service = $read('includes/fxFundRequestPaymentLifecycleService.php');
$statusRoute = $read('routes/request/fx/updateStatus.php');
$requestDelete = $read('routes/request/fx/delete.php');
$paymentDelete = $read('routes/fx/payment/deletePayment.php');
$paymentDeleteService = $read('includes/fxFundRequestPaymentDeletionService.php');
$requestEdit = $read('routes/request/fx/edit.php');
$index = $read('index.php');

$checks = [
    'request_status_route_registered' => str_contains($index, "'/request/fx/updateStatus' => 'routes/request/fx/updateStatus.php'"),
    'request_status_supports_pending_processing_paid_unconfirmed' => str_contains($service, "['Pending', 'Processing', 'Paid', 'Unconfirmed']"),
    'pending_reopens_direct_acctlab_request' => str_contains($service, 'fxFundRequestLifecycleReopenManualRequests')
        && str_contains($service, "payment_status = 'Pending', fx_instruction_letter_id = NULL")
        && str_contains($service, 'processed_by = NULL, processed_at = NULL'),
    'pending_clears_payment_conversion_audit' => str_contains($service, 'payment_currency = NULL, payment_amount = NULL, exchange_rate = NULL'),
    'partial_group_reopen_recalculates_instruction_total' => str_contains($service, 'Remaining grouped FX payment amount must be greater than zero')
        && str_contains($service, 'UPDATE fx_instruction_letter_table SET amount_figure = ?, amount_words = ?'),
    'last_group_line_reopen_deletes_instruction' => str_contains($service, 'DELETE FROM fx_instruction_letter_table WHERE id = ?'),
    'procuredesk_linked_request_cannot_be_detached' => str_contains($service, 'cannot be reopened or detached from its payment directly in AcctLab'),
    'paid_reopen_requires_reason' => str_contains($service, 'Enter a correction reason before reopening'),
    'paid_group_correction_requires_full_group' => str_contains($service, 'Paid grouped FX payment can only be corrected by reopening every Fund Request linked to that payment'),
    'processing_uses_pending_instruction_semantics' => str_contains($service, "\$instructionTarget = \$target === 'Processing' ? 'Pending' : \$target"),
    'shared_group_status_requires_full_selection' => str_contains($service, 'Select every request linked to that payment before changing its shared status'),
    'manual_processed_request_delete_reopens_first' => str_contains($requestDelete, 'fxFundRequestLifecycleReopenManualRequests')
        && str_contains($requestDelete, 'Direct AcctLab FX Fund Request deleted after payment preparation was cancelled.'),
    'manual_generated_payment_delete_reopens_requests' => str_contains($paymentDelete, 'fxFundRequestPaymentDelete(')
        && str_contains($paymentDeleteService, 'fxFundRequestReturnToPending(')
        && str_contains($paymentDeleteService, 'reopened_request_ids'),
    'direct_payment_delete_still_exists' => str_contains($paymentDeleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?')
        && str_contains($paymentDeleteService, '$direct[] = $paymentId'),
    'processed_manual_request_can_edit_safe_fields' => str_contains($requestEdit, '$isProcessed =')
        && str_contains($requestEdit, 'Direct AcctLab requests remain editable after payment preparation'),
    'processed_manual_financial_changes_require_pending_reset' => str_contains($requestEdit, 'Set this direct AcctLab FX Fund Request back to Pending before changing payment values'),
    'procuredesk_processed_edit_remains_blocked' => str_contains($requestEdit, 'Processed ProcureDesk-linked FX Final Requests cannot be edited in AcctLab.'),
    'no_schema_migration_required' => !str_contains($service, 'ALTER TABLE')
        && !str_contains($statusRoute, 'ALTER TABLE')
        && !str_contains($requestDelete, 'ALTER TABLE')
        && !str_contains($paymentDelete, 'ALTER TABLE')
        && !str_contains($paymentDeleteService, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
