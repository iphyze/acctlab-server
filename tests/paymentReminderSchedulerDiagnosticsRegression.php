<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cycle = file_get_contents($root . '/cron/processPaymentReminderCycle.php') ?: '';
require_once $root . '/includes/accountPaymentReminderSchedulerService.php';
require_once $root . '/includes/accountPaymentStorageRuntimeReadService.php';

$startPos = strpos($cycle, '$run = accountPaymentReminderSchedulerStart($conn, $limit);');
$lockPos = strpos($cycle, '$lockAcquired = accountPaymentReminderCycleAcquireLock($conn);');
$advanceStoragePos = strpos($cycle, 'accountAdvanceEnsurePaymentStorage($conn);');
$supplierStoragePos = strpos($cycle, 'accountSupplierEnsurePaymentStorage($conn);');
$reminderStoragePos = strpos($cycle, 'accountPaymentReminderEnsureStorage($conn);');

$failedRun = [
    'id' => 10,
    'run_reference' => 'PAYREM-RUN-TEST',
    'status' => 'Failed',
    'started_at' => '2026-10-01 20:00:00',
    'finished_at' => '2026-10-01 20:00:01',
    'error_message' => '[storage.supplier_payments] Canonical payment storage is incomplete.',
];

$canonicalDiagnostic = accountPaymentStorageCanonicalVerificationFailureMessage([
    'object_types' => [
        'account_payment_batches' => 'BASE TABLE',
        'account_payment_batch_items' => null,
        'account_payment_artifacts' => 'VIEW',
    ],
    'missing_columns' => [
        'account_payment_batches' => ['completion_mode'],
    ],
    'integrity' => [
        'orphan_items' => 2,
    ],
    'checks' => [],
]);
$advanceService = file_get_contents($root . '/includes/accountAdvancePaymentService.php') ?: '';
$supplierService = file_get_contents($root . '/includes/accountSupplierPaymentService.php') ?: '';

$health = accountPaymentReminderSchedulerAssess(
    [$failedRun],
    15,
    new DateTimeImmutable('2026-10-01 20:05:00', new DateTimeZone(date_default_timezone_get()))
);

$checks = [
    'scheduler_run_starts_before_dependency_validation' => $startPos !== false
        && $advanceStoragePos !== false
        && $supplierStoragePos !== false
        && $reminderStoragePos !== false
        && $startPos < $advanceStoragePos
        && $startPos < $supplierStoragePos
        && $startPos < $reminderStoragePos,
    'scheduler_lock_is_acquired_before_dependency_validation' => $lockPos !== false
        && $advanceStoragePos !== false
        && $lockPos < $advanceStoragePos,
    'startup_failures_record_diagnostic_phase' => str_contains($cycle, '\'phase\' => $phase')
        && str_contains($cycle, 'sprintf(\'[%s] %s\', $phase, $error->getMessage())')
        && str_contains($cycle, '\'exception\' => get_class($error)'),
    'failed_scheduler_attempt_is_finished_as_failed' => str_contains($cycle, "'Failed',")
        && str_contains($cycle, '$diagnosticMessage'),
    'health_reports_failed_instead_of_never_run' => ($health['healthy'] ?? true) === false
        && ($health['status'] ?? '') === 'Failed'
        && ($health['message'] ?? '') === '[storage.supplier_payments] Canonical payment storage is incomplete.',
    'canonical_storage_errors_name_the_actual_problem' => str_contains($canonicalDiagnostic, 'account_payment_batch_items is missing')
        && str_contains($canonicalDiagnostic, 'account_payment_artifacts must be a BASE TABLE (found VIEW)')
        && str_contains($canonicalDiagnostic, 'account_payment_batches missing columns: completion_mode')
        && str_contains($canonicalDiagnostic, 'orphan_items=2')
        && str_contains($advanceService, 'accountPaymentStorageCanonicalVerificationFailureMessage($canonicalPaymentStorage)')
        && str_contains($supplierService, 'accountPaymentStorageCanonicalVerificationFailureMessage($canonicalPaymentStorage)'),
    'payment_processing_functions_remain_present' => str_contains($cycle, 'accountAdvanceProcessDueBatches($conn)')
        && str_contains($cycle, 'accountSupplierProcessDueBatches($conn)')
        && str_contains($cycle, 'accountCompassProcessDueBatches($conn)')
        && str_contains($cycle, 'accountPaymentProcessingProcessDueFxBatches($conn)')
        && str_contains($cycle, 'manualFxPaymentProcessingProcessDue($conn)')
        && str_contains($cycle, 'accountPaymentReminderBackfillOverdue($conn)')
        && str_contains($cycle, 'accountPaymentReminderProcessDue($conn, $limit)'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
