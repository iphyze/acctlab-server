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
require_once __DIR__ . '/../includes/paymentReminderCanonicalRuntimeService.php';

date_default_timezone_set((string) envValue('APP_TIMEZONE', 'Africa/Lagos'));

$options = array_flip(array_slice($argv, 1));
$repair = isset($options['--repair']);
$requireHealthy = isset($options['--require-healthy']);
$maxAgeMinutes = max(5, (int) envValue('PAYMENT_REMINDER_HEALTH_MAX_AGE_MINUTES', 15));
$checks = [];
$failed = false;

$record = static function (
    string $name,
    bool $passed,
    string $message,
    bool $required = true,
    array $details = []
) use (&$checks, &$failed): void {
    $checks[] = [
        'name' => $name,
        'status' => $passed ? 'Passed' : ($required ? 'Failed' : 'Warning'),
        'message' => $message,
        'details' => $details,
    ];
    if (!$passed && $required) {
        $failed = true;
    }
};

$objectType = static function (mysqli $db, string $object): ?string {
    $stmt = $db->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $object);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return $type === null ? null : strtoupper((string) $type);
};

$missingColumns = static function (mysqli $db, string $table, array $columns): array {
    if ($columns === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $types = 's' . str_repeat('s', count($columns));
    $params = array_merge([$table], $columns);
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ?
           AND COLUMN_NAME IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $found = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $stmt->close();
    return array_values(array_diff($columns, $found));
};

$indexExists = static function (mysqli $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
};

try {
    $record(
        'PHP runtime',
        version_compare(PHP_VERSION, '8.1.0', '>='),
        'PHP 8.1 or newer is required.',
        true,
        ['version' => PHP_VERSION]
    );
    $record('mysqli extension', extension_loaded('mysqli'), 'The mysqli extension must be enabled.');
    $record(
        'Vendor autoload',
        is_file(__DIR__ . '/../vendor/autoload.php'),
        'Composer dependencies must be installed in backend/vendor.'
    );

    if ($repair) {
        accountAdvanceEnsurePaymentStorage($conn);
        accountSupplierEnsurePaymentStorage($conn);
        accountPaymentReminderEnsureStorage($conn);
        accountPaymentReminderSchedulerEnsureStorage($conn);
        accountNotificationEnsureStorage($conn);
        $record('Storage repair', true, 'Reminder, scheduler and notification storage was initialized.');
    }

    $requirements = [
        'user_table' => ['id', 'integrity', 'department', 'status'],
        'advance_payment_request' => [
            'id', 'payment_status', 'processing_method', 'processing_reference',
            'expected_completion_at', 'completion_mode', 'payment_confirmation_status',
            'payment_updated_at',
        ],
        'supplier_fund_request_table' => [
            'id', 'payment_status', 'processing_method', 'processing_reference',
            'expected_completion_at', 'completion_mode', 'payment_confirmation_status',
            'payment_updated_at',
        ],
        'account_payment_batches' => [
            'id', 'request_type', 'legacy_source_table', 'legacy_source_id',
            'processing_method', 'processing_reference', 'expected_completion_at',
            'completion_mode', 'status',
        ],
        'account_payment_batch_items' => [
            'id', 'batch_id', 'request_type', 'request_id', 'legacy_source_table',
            'legacy_source_id', 'status', 'expected_completion_at', 'updated_by',
        ],
        'notifications' => [
            'id', 'inbox_app', 'inbox_notification_id', 'recipient_user_id',
            'notification_type', 'dedupe_key', 'read_at', 'created_at',
        ],
        'account_payment_reminders' => [
            'id', 'idempotency_key', 'request_type', 'request_id', 'batch_id',
            'batch_item_id', 'lifecycle_status', 'delivery_status', 'next_reminder_at',
            'attempt_count', 'delivery_count', 'failure_count', 'lease_token',
            'lease_expires_at', 'completed_at',
        ],
        'account_payment_reminder_runs' => [
            'id', 'run_reference', 'status', 'process_limit', 'started_at', 'finished_at',
            'duration_ms', 'summary_json', 'error_message',
        ],
        'workflow_events' => [
            'id', 'source_app', 'event_scope', 'request_type', 'entity_type',
            'entity_id', 'batch_id', 'event_type', 'source_event_id', 'created_at',
        ],
    ];

    foreach ($requirements as $table => $columns) {
        $type = $objectType($conn, $table);
        $isBaseTable = $type === 'BASE TABLE';
        $record(
            "Base table: $table",
            $isBaseTable,
            $isBaseTable ? 'Required canonical table exists.' : 'Required canonical table is missing.',
            true,
            ['object_type' => $type]
        );
        if (!$isBaseTable) {
            continue;
        }
        $missing = $missingColumns($conn, $table, $columns);
        $record(
            "Columns: $table",
            $missing === [],
            $missing === [] ? 'Required columns exist.' : 'Required columns are missing.',
            true,
            ['missing' => $missing]
        );
    }

    $paymentCompatibilityObjects = paymentReminderUnifiedObjectState($conn);
    $record(
        'Payment compatibility view retirement',
        paymentReminderUnifiedCompatibilityObjectsSupported($paymentCompatibilityObjects),
        'Payment compatibility objects must be either fully present as views or fully retired; mixed states are not supported.',
        true,
        ['phase' => paymentReminderUnifiedCompatibilityObjectPhase($paymentCompatibilityObjects)]
    );

    foreach ([
        ['account_payment_batches', 'uq_account_payment_batch_legacy'],
        ['account_payment_batch_items', 'uq_account_payment_item_legacy'],
        ['notifications', 'uq_user_notification_inbox_public_id'],
        ['notifications', 'uq_user_notification_inbox_dedupe'],
        ['account_payment_reminders', 'uq_account_payment_reminder_idempotency'],
        ['account_payment_reminders', 'idx_account_payment_reminder_due'],
        ['account_payment_reminder_runs', 'uq_account_payment_reminder_run_reference'],
        ['account_payment_reminder_runs', 'idx_account_payment_reminder_run_status'],
    ] as [$table, $index]) {
        $exists = $objectType($conn, $table) === 'BASE TABLE' && $indexExists($conn, $table, $index);
        $record(
            "Index: $index",
            $exists,
            $exists ? 'Required index exists.' : "Required index is missing from $table."
        );
    }

    $runtime = paymentReminderCanonicalRuntimeVerify($conn);
    $record(
        'Canonical reminder runtime',
        (bool) ($runtime['healthy'] ?? false),
        (bool) ($runtime['healthy'] ?? false)
            ? 'Reminder reads, writes, notifications and events use canonical storage.'
            : 'Canonical reminder runtime verification failed.',
        true,
        ['verification' => $runtime]
    );

    $recipientIds = accountNotificationRecipientIds($conn, [
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
    ]);
    $record(
        'Account notification recipients',
        $recipientIds !== [],
        $recipientIds !== []
            ? 'At least one active Account Admin or Super Admin can receive reminders.'
            : 'No active Account Admin or Super Admin is available for reminder delivery.',
        true,
        ['recipient_count' => count($recipientIds)]
    );

    $lockName = 'acctlab-payment-reminder-deployment-check';
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $lockAcquired = (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $stmt->close();
    if ($lockAcquired) {
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $stmt->close();
    }
    $record('Database scheduler lock', $lockAcquired, 'MySQL named locks must be available.');

    $timeResult = $conn->query('SELECT @@session.time_zone AS session_time_zone, NOW() AS database_now');
    $timeRow = $timeResult->fetch_assoc() ?: [];
    $record(
        'Database timezone',
        (string) ($timeRow['session_time_zone'] ?? '') !== '',
        'The database session timezone is configured.',
        true,
        [
            'application_timezone' => date_default_timezone_get(),
            'database_timezone' => $timeRow['session_time_zone'] ?? null,
            'database_now' => $timeRow['database_now'] ?? null,
        ]
    );

    $health = accountPaymentReminderSchedulerHealth($conn, $maxAgeMinutes);
    $record(
        'Scheduler heartbeat',
        (bool) ($health['healthy'] ?? false),
        (string) ($health['message'] ?? 'Scheduler health could not be determined.'),
        $requireHealthy,
        ['health' => $health]
    );
} catch (Throwable $error) {
    $record('Deployment verification', false, $error->getMessage());
}

$payload = [
    'status' => $failed ? 'Failed' : 'Success',
    'repair_requested' => $repair,
    'scheduler_health_required' => $requireHealthy,
    'checks' => $checks,
    'checked_at' => date(DATE_ATOM),
];
$stream = $failed ? STDERR : STDOUT;
fwrite($stream, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failed ? 1 : 0);
