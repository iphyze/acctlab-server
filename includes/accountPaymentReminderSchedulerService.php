<?php

declare(strict_types=1);

const ACCOUNT_PAYMENT_REMINDER_SCHEDULER_STATUSES = [
    'Running',
    'Success',
    'Skipped',
    'Failed',
];

function accountPaymentReminderSchedulerEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $query = "CREATE TABLE IF NOT EXISTS account_payment_reminder_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_reference VARCHAR(80) NOT NULL,
        host_name VARCHAR(255) NULL,
        process_id INT UNSIGNED NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Running',
        process_limit INT UNSIGNED NOT NULL DEFAULT 50,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        duration_ms INT UNSIGNED NULL,
        summary_json LONGTEXT NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_account_payment_reminder_run_reference (run_reference),
        INDEX idx_account_payment_reminder_run_status (status, started_at),
        INDEX idx_account_payment_reminder_run_finished (finished_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($query)) {
        throw new RuntimeException(
            'Payment reminder scheduler history could not be initialized. Apply the reminder lifecycle migration.',
            500
        );
    }

    $ensured = true;
}

function accountPaymentReminderSchedulerReference(): string
{
    return sprintf(
        'PAYREM-RUN-%s-%s',
        date('YmdHis'),
        strtoupper(bin2hex(random_bytes(6)))
    );
}

function accountPaymentReminderSchedulerStart(mysqli $conn, int $processLimit): array
{
    accountPaymentReminderSchedulerEnsureStorage($conn);

    $reference = accountPaymentReminderSchedulerReference();
    $hostName = substr((string) (gethostname() ?: php_uname('n')), 0, 255);
    $processId = max(0, (int) getmypid());
    $processLimit = max(1, min(100, $processLimit));
    $status = 'Running';

    $stmt = $conn->prepare(
        'INSERT INTO account_payment_reminder_runs
            (run_reference, host_name, process_id, status, process_limit, started_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param('ssisi', $reference, $hostName, $processId, $status, $processLimit);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'reference' => $reference,
        'started_monotonic' => microtime(true),
    ];
}

function accountPaymentReminderSchedulerFinish(
    mysqli $conn,
    int $runId,
    string $status,
    array $summary = [],
    ?string $errorMessage = null,
    ?int $durationMs = null
): void {
    if ($runId <= 0) {
        return;
    }
    if (!in_array($status, ACCOUNT_PAYMENT_REMINDER_SCHEDULER_STATUSES, true) || $status === 'Running') {
        throw new InvalidArgumentException('Payment reminder scheduler completion status is invalid.');
    }

    $summaryJson = $summary === []
        ? null
        : json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($summaryJson === false) {
        throw new RuntimeException('Unable to encode payment reminder scheduler summary.', 500);
    }

    $errorMessage = trim((string) $errorMessage);
    $errorMessage = $errorMessage !== '' ? substr($errorMessage, 0, 4000) : null;
    $durationMs = max(0, (int) ($durationMs ?? 0));

    $stmt = $conn->prepare(
        'UPDATE account_payment_reminder_runs
         SET status = ?, finished_at = NOW(), duration_ms = ?, summary_json = ?, error_message = ?
         WHERE id = ? AND status = \'Running\''
    );
    $stmt->bind_param('sissi', $status, $durationMs, $summaryJson, $errorMessage, $runId);
    $stmt->execute();
    $stmt->close();
}

function accountPaymentReminderSchedulerRecentRuns(mysqli $conn, int $limit = 20): array
{
    accountPaymentReminderSchedulerEnsureStorage($conn);
    $limit = max(1, min(100, $limit));
    $result = $conn->query(
        "SELECT id, run_reference, host_name, process_id, status, process_limit,
                started_at, finished_at, duration_ms, summary_json, error_message
         FROM account_payment_reminder_runs
         ORDER BY id DESC
         LIMIT $limit"
    );

    $runs = [];
    while ($row = $result->fetch_assoc()) {
        $summary = json_decode((string) ($row['summary_json'] ?? ''), true);
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['process_id'] = isset($row['process_id']) ? (int) $row['process_id'] : null;
        $row['process_limit'] = (int) ($row['process_limit'] ?? 0);
        $row['duration_ms'] = isset($row['duration_ms']) ? (int) $row['duration_ms'] : null;
        $row['summary'] = is_array($summary) ? $summary : [];
        unset($row['summary_json']);
        $runs[] = $row;
    }

    return $runs;
}

function accountPaymentReminderSchedulerDate(mixed $value): ?DateTimeImmutable
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($text, new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable) {
        return null;
    }
}

function accountPaymentReminderSchedulerAssess(
    array $runs,
    int $maxAgeMinutes = 15,
    ?DateTimeImmutable $now = null
): array {
    $maxAgeMinutes = max(5, $maxAgeMinutes);
    $now ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $latest = $runs[0] ?? null;

    if (!is_array($latest)) {
        return [
            'healthy' => false,
            'status' => 'Never Run',
            'message' => 'No payment reminder scheduler run has been recorded.',
            'last_run' => null,
            'last_success' => null,
            'max_age_minutes' => $maxAgeMinutes,
        ];
    }

    $latestStatus = (string) ($latest['status'] ?? 'Unknown');
    $latestStarted = accountPaymentReminderSchedulerDate($latest['started_at'] ?? null);
    if ($latestStatus === 'Failed') {
        return [
            'healthy' => false,
            'status' => 'Failed',
            'message' => trim((string) ($latest['error_message'] ?? '')) ?: 'The latest scheduler run failed.',
            'last_run' => $latest,
            'last_success' => null,
            'max_age_minutes' => $maxAgeMinutes,
        ];
    }

    if ($latestStatus === 'Running' && $latestStarted !== null) {
        $runningMinutes = (int) floor(max(0, $now->getTimestamp() - $latestStarted->getTimestamp()) / 60);
        if ($runningMinutes > $maxAgeMinutes) {
            return [
                'healthy' => false,
                'status' => 'Stuck',
                'message' => 'The latest scheduler run has remained active beyond the allowed window.',
                'last_run' => $latest,
                'last_success' => null,
                'max_age_minutes' => $maxAgeMinutes,
            ];
        }
    }

    $lastSuccess = null;
    foreach ($runs as $run) {
        if (is_array($run) && ($run['status'] ?? null) === 'Success') {
            $lastSuccess = $run;
            break;
        }
    }

    if ($lastSuccess === null) {
        return [
            'healthy' => false,
            'status' => 'No Successful Run',
            'message' => 'The scheduler has not completed a successful reminder cycle.',
            'last_run' => $latest,
            'last_success' => null,
            'max_age_minutes' => $maxAgeMinutes,
        ];
    }

    $successFinished = accountPaymentReminderSchedulerDate($lastSuccess['finished_at'] ?? null);
    if ($successFinished === null) {
        return [
            'healthy' => false,
            'status' => 'Invalid History',
            'message' => 'The latest successful scheduler run has no valid completion time.',
            'last_run' => $latest,
            'last_success' => $lastSuccess,
            'max_age_minutes' => $maxAgeMinutes,
        ];
    }

    $ageMinutes = (int) floor(max(0, $now->getTimestamp() - $successFinished->getTimestamp()) / 60);
    $healthy = $ageMinutes <= $maxAgeMinutes;

    return [
        'healthy' => $healthy,
        'status' => $healthy ? 'Healthy' : 'Stale',
        'message' => $healthy
            ? 'The payment reminder scheduler is running normally.'
            : 'The last successful payment reminder cycle is older than the allowed window.',
        'last_run' => $latest,
        'last_success' => $lastSuccess,
        'last_success_age_minutes' => $ageMinutes,
        'max_age_minutes' => $maxAgeMinutes,
    ];
}

function accountPaymentReminderSchedulerHealth(mysqli $conn, int $maxAgeMinutes = 15): array
{
    return accountPaymentReminderSchedulerAssess(
        accountPaymentReminderSchedulerRecentRuns($conn),
        $maxAgeMinutes
    );
}
