<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/accountPaymentReminderSchedulerService.php';

date_default_timezone_set((string) envValue('APP_TIMEZONE', 'Africa/Lagos'));

$configuredMaxAge = (int) envValue('PAYMENT_REMINDER_HEALTH_MAX_AGE_MINUTES', 15);
$maxAgeMinutes = max(5, (int) ($argv[1] ?? $configuredMaxAge));

try {
    $health = accountPaymentReminderSchedulerHealth($conn, $maxAgeMinutes);

    $metrics = [
        'due_reminders' => 0,
        'failed_reminders' => 0,
        'expired_leases' => 0,
    ];
    $result = $conn->query(
        "SELECT
            SUM(CASE
                WHEN lifecycle_status IN ('Pending', 'Awaiting Action', 'Retry Scheduled')
                 AND next_reminder_at <= NOW() THEN 1 ELSE 0 END) AS due_reminders,
            SUM(CASE WHEN delivery_status = 'Failed' THEN 1 ELSE 0 END) AS failed_reminders,
            SUM(CASE
                WHEN lifecycle_status = 'Delivering'
                 AND lease_expires_at IS NOT NULL
                 AND lease_expires_at <= NOW() THEN 1 ELSE 0 END) AS expired_leases
         FROM account_payment_reminders"
    );
    $row = $result->fetch_assoc() ?: [];
    foreach (array_keys($metrics) as $key) {
        $metrics[$key] = (int) ($row[$key] ?? 0);
    }

    $payload = [
        'status' => $health['healthy'] ? 'Healthy' : 'Unhealthy',
        'health' => $health,
        'metrics' => $metrics,
        'checked_at' => date(DATE_ATOM),
    ];
    $stream = $health['healthy'] ? STDOUT : STDERR;
    fwrite($stream, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($health['healthy'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
        'checked_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
