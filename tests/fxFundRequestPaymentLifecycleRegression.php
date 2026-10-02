<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoloadPath = $root . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

$servicePath = $root . '/includes/fxFundRequestPaymentLifecycleService.php';
$updateStatusPath = $root . '/routes/fx/payment/updateStatus.php';
$editPaymentPath = $root . '/routes/fx/payment/editPayment.php';
$deletePaymentPath = $root . '/routes/fx/payment/deletePayment.php';
$deleteServicePath = $root . '/includes/fxFundRequestPaymentDeletionService.php';
$canonicalStatusPath = $root . '/includes/fxFundRequestPaymentCanonicalStatusService.php';
$processPath = $root . '/routes/request/fx/processForPayment.php';
$directCreatePath = $root . '/routes/fx/payment/createPayment.php';

require_once $servicePath;

$service = file_get_contents($servicePath) ?: '';
$updateStatus = file_get_contents($updateStatusPath) ?: '';
$editPayment = file_get_contents($editPaymentPath) ?: '';
$deletePayment = file_get_contents($deletePaymentPath) ?: '';
$deleteService = file_get_contents($deleteServicePath) ?: '';
$canonicalStatus = file_get_contents($canonicalStatusPath) ?: '';
$process = file_get_contents($processPath) ?: '';
$directCreate = file_get_contents($directCreatePath) ?: '';

$checks = [];
$checks['pending_instruction_maps_to_processing_request'] = fxFundRequestLifecycleMapInstructionStatus('Pending') === 'Processing';
$checks['paid_instruction_maps_to_paid_request'] = fxFundRequestLifecycleMapInstructionStatus('Paid') === 'Paid';
$checks['unconfirmed_instruction_maps_to_unconfirmed_request'] = fxFundRequestLifecycleMapInstructionStatus('Unconfirmed') === 'Unconfirmed';
$checks['status_mapping_is_case_tolerant'] = fxFundRequestLifecycleMapInstructionStatus('paid') === 'Paid';
$checks['linked_requests_resolved_by_instruction_id'] = str_contains($service, 'WHERE fx_instruction_letter_id = ?')
    && str_contains($service, 'fxFundRequestLifecycleLinkedRequestsForInstruction');
$checks['direct_fx_payments_are_explicit_noop'] = str_contains($service, 'Direct FX payment: no Fund Request relationship, so do nothing.')
    && str_contains($service, 'return [];');
$checks['linked_status_updates_active_fund_request'] = str_contains($service, 'UPDATE fx_fund_request_table')
    && str_contains($service, 'payment_status = ?')
    && str_contains($service, 'updated_by = ?');
$checks['linked_status_sync_is_audited'] = str_contains($service, 'fxFundRequestInsertLog(')
    && str_contains($service, 'synchronized FX Fund Request #');
$checks['grouped_instruction_status_syncs_all_linked_requests'] = str_contains($service, 'foreach ($linkedRequests as $linked)');
$checks['bulk_status_route_uses_active_connection'] = str_contains($updateStatus, '$writeConn = function_exists(\'databaseActiveConnection\') ? databaseActiveConnection($conn) : $conn;');
$checks['bulk_status_route_is_transactional'] = str_contains($updateStatus, 'begin_transaction()')
    && str_contains($updateStatus, 'commit()')
    && str_contains($updateStatus, 'rollback()');
$checks['bulk_status_locks_instructions_before_sync'] = str_contains($updateStatus, 'fxFundRequestLifecycleLockInstructionStatuses')
    && str_contains($updateStatus, 'fxFundRequestLifecycleSyncMany');
$checks['edit_route_uses_active_connection'] = str_contains($editPayment, '$writeConn = function_exists(\'databaseActiveConnection\') ? databaseActiveConnection($conn) : $conn;');
$checks['edit_route_is_transactional'] = str_contains($editPayment, 'begin_transaction()')
    && str_contains($editPayment, 'commit()')
    && str_contains($editPayment, 'rollback()');
$checks['edit_route_syncs_linked_status'] = str_contains($editPayment, 'fxFundRequestLifecycleSyncLinkedInstructionStatus');
$checks['linked_payment_delete_reopens_requests'] = str_contains($deletePayment, 'fxFundRequestPaymentDelete(')
    && str_contains($deleteService, 'fxFundRequestReturnToPending(')
    && str_contains($deleteService, 'reopened_request_ids');
$checks['paid_generated_payment_delete_is_allowed_with_reason'] = str_contains($deleteService, 'A reason is required before deleting a Paid FX payment.')
    && str_contains($deleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?');
$checks['direct_payment_delete_path_preserved'] = str_contains($deleteService, 'DELETE FROM fx_instruction_letter_table WHERE id = ?')
    && str_contains($deleteService, '$direct[] = $paymentId');
$checks['manual_status_changes_keep_canonical_processing_in_sync'] = str_contains($service, 'fxFundRequestCanonicalSyncInstructionStatus')
    && str_contains($canonicalStatus, 'account_payment_batch_items')
    && str_contains($canonicalStatus, 'account_payment_batches');
$checks['processing_audit_log_remains_present'] = str_contains($process, 'fxFundRequestInsertLog(')
    && str_contains($process, 'processed FX Fund Request #');
$checks['direct_fx_creation_remains_independent'] = str_contains($directCreate, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($directCreate, 'fxFundRequestPaymentLifecycleService')
    && !str_contains($directCreate, 'fx_fund_request_table');
$checks['lifecycle_changes_require_no_schema_migration'] = !str_contains($service, 'ALTER TABLE')
    && !str_contains($updateStatus, 'ALTER TABLE')
    && !str_contains($editPayment, 'ALTER TABLE')
    && !str_contains($deletePayment, 'ALTER TABLE');
$checks['sync_failure_rolls_back_payment_update'] = str_contains($updateStatus, 'fxFundRequestLifecycleSyncMany')
    && strpos($updateStatus, 'fxFundRequestLifecycleSyncMany') < strpos($updateStatus, '$writeConn->commit()')
    && str_contains($updateStatus, '$writeConn->rollback()');
$checks['edit_sync_failure_rolls_back_payment_edit'] = str_contains($editPayment, 'fxFundRequestLifecycleSyncLinkedInstructionStatus')
    && strpos($editPayment, 'fxFundRequestLifecycleSyncLinkedInstructionStatus') < strpos($editPayment, '$writeConn->commit()')
    && str_contains($editPayment, '$writeConn->rollback()');

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
