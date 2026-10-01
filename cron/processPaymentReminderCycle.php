<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/accountAdvancePaymentService.php';
require_once __DIR__ . '/../includes/accountSupplierPaymentService.php';
require_once __DIR__ . '/../includes/accountPaymentReminderService.php';
require_once __DIR__ . '/../includes/accountPaymentReminderSchedulerService.php';
require_once __DIR__ . '/../includes/accountPaymentProcessingService.php';
require_once __DIR__ . '/../includes/manualFxPaymentProcessingService.php';

date_default_timezone_set((string) envValue('APP_TIMEZONE', 'Africa/Lagos'));

const ACCOUNT_PAYMENT_REMINDER_CYCLE_LOCK = 'acctlab-payment-reminder-cycle';

function accountPaymentReminderCycleLimit(array $arguments): int
{
    $configured = envValue('PAYMENT_REMINDER_PROCESS_LIMIT', 50);
    $value = $arguments[1] ?? $configured;
    return max(1, min(100, (int) $value));
}

function accountPaymentReminderCycleAcquireLock(mysqli $conn): bool
{
    $lockName = ACCOUNT_PAYMENT_REMINDER_CYCLE_LOCK;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $acquired = (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $stmt->close();
    return $acquired;
}

function accountPaymentReminderCycleReleaseLock(mysqli $conn): void
{
    $lockName = ACCOUNT_PAYMENT_REMINDER_CYCLE_LOCK;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}

$lockAcquired = false;
$startedAt = microtime(true);
$run = null;
$response = [];
$exitCode = 0;
$outputStream = STDOUT;
$phase = 'initializing';

try {
    $limit = accountPaymentReminderCycleLimit($argv);

    // Record the scheduler attempt before validating the workflow dependencies so
    // production startup failures appear as Failed runs instead of "Never Run".
    $phase = 'scheduler_storage';
    accountPaymentReminderSchedulerEnsureStorage($conn);
    $phase = 'scheduler_start';
    $run = accountPaymentReminderSchedulerStart($conn, $limit);

    $phase = 'scheduler_lock';
    $lockAcquired = accountPaymentReminderCycleAcquireLock($conn);

    if (!$lockAcquired) {
        $response = [
            'status' => 'Skipped',
            'message' => 'A payment reminder cycle is already running.',
            'phase' => $phase,
        ];
        accountPaymentReminderSchedulerFinish(
            $conn,
            (int) $run['id'],
            'Skipped',
            $response,
            null,
            (int) round((microtime(true) - $startedAt) * 1000)
        );
    } else {
        $phase = 'storage.advance_payments';
        accountAdvanceEnsurePaymentStorage($conn);
        $phase = 'storage.supplier_payments';
        accountSupplierEnsurePaymentStorage($conn);
        $phase = 'storage.payment_reminders';
        accountPaymentReminderEnsureStorage($conn);

        $phase = 'processing.advance_batches';
        $advanceBatches = accountAdvanceProcessDueBatches($conn);
        $phase = 'processing.supplier_batches';
        $supplierBatches = accountSupplierProcessDueBatches($conn);
        $phase = 'processing.compass_batches';
        $compassBatches = accountCompassProcessDueBatches($conn);
        $phase = 'processing.fx_batches';
        $fxBatches = accountPaymentProcessingProcessDueFxBatches($conn);
        $phase = 'processing.manual_fx_payments';
        $manualFxPayments = manualFxPaymentProcessingProcessDue($conn);
        $phase = 'processing.reminder_backfill';
        $backfill = accountPaymentReminderBackfillOverdue($conn);
        $phase = 'processing.reminders';
        $reminders = accountPaymentReminderProcessDue($conn, $limit);
        $phase = 'completed';

        $response = [
            'status' => 'Success',
            'data' => [
                'advance_batches' => $advanceBatches,
                'supplier_batches' => $supplierBatches,
                'compass_batches' => $compassBatches,
                'fx_batches' => $fxBatches,
                'manual_fx_payments' => $manualFxPayments,
                'backfill' => $backfill,
                'reminders' => $reminders,
                'process_limit' => $limit,
            ],
        ];
        accountPaymentReminderSchedulerFinish(
            $conn,
            (int) $run['id'],
            'Success',
            $response['data'],
            null,
            (int) round((microtime(true) - $startedAt) * 1000)
        );
    }
} catch (Throwable $error) {
    $diagnosticMessage = sprintf('[%s] %s', $phase, $error->getMessage());
    $response = [
        'status' => 'Failed',
        'phase' => $phase,
        'message' => $error->getMessage(),
    ];
    $exitCode = 1;
    $outputStream = STDERR;

    if (is_array($run) && (int) ($run['id'] ?? 0) > 0) {
        try {
            accountPaymentReminderSchedulerFinish(
                $conn,
                (int) $run['id'],
                'Failed',
                [
                    'phase' => $phase,
                    'exception' => get_class($error),
                ],
                $diagnosticMessage,
                (int) round((microtime(true) - $startedAt) * 1000)
            );
        } catch (Throwable $historyError) {
            error_log('Unable to record failed payment reminder cycle: ' . $historyError->getMessage());
        }
    }
} finally {
    if ($lockAcquired) {
        try {
            accountPaymentReminderCycleReleaseLock($conn);
        } catch (Throwable $releaseError) {
            error_log('Unable to release payment reminder cycle lock: ' . $releaseError->getMessage());
        }
    }
}

$response['run_reference'] = is_array($run) ? ($run['reference'] ?? null) : null;
$response['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
fwrite($outputStream, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($exitCode);
