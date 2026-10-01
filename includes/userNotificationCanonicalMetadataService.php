<?php

declare(strict_types=1);

/**
 * Final notification storage metadata shared by the live runtime and cleanup
 * tooling.  This deliberately contains no SQL against compatibility views.
 */

if (!defined('USER_NOTIFICATION_ACCOUNT_TABLE')) {
    define('USER_NOTIFICATION_ACCOUNT_TABLE', 'account_notifications');
}
if (!defined('USER_NOTIFICATION_PROCUREMENT_TABLE')) {
    define('USER_NOTIFICATION_PROCUREMENT_TABLE', 'procurement_notifications');
}
if (!defined('USER_NOTIFICATION_FINAL_CANONICAL_TABLE')) {
    define('USER_NOTIFICATION_FINAL_CANONICAL_TABLE', 'notifications');
}
if (!defined('USER_NOTIFICATION_REMINDER_TABLE')) {
    define('USER_NOTIFICATION_REMINDER_TABLE', 'account_payment_reminders');
}
if (!defined('USER_NOTIFICATION_REMINDER_RUN_TABLE')) {
    define('USER_NOTIFICATION_REMINDER_RUN_TABLE', 'account_payment_reminder_runs');
}
if (!defined('USER_NOTIFICATION_EXPECTED_BASE_TABLE_COUNT')) {
    define('USER_NOTIFICATION_EXPECTED_BASE_TABLE_COUNT', 66);
}
if (!defined('USER_NOTIFICATION_EXPECTED_FINAL_BASE_TABLE_COUNT')) {
    define('USER_NOTIFICATION_EXPECTED_FINAL_BASE_TABLE_COUNT', 65);
}
if (!defined('USER_NOTIFICATION_PROCUREMENT_CLEANUP_TEMP_TABLE')) {
    define(
        'USER_NOTIFICATION_PROCUREMENT_CLEANUP_TEMP_TABLE',
        'procurement_notifications__legacy_cleanup'
    );
}

if (!function_exists('userNotificationConsolidationObjectType')) {
    function userNotificationConsolidationObjectType(mysqli $conn, string $object): ?string
    {
        $stmt = $conn->prepare(
            'SELECT TABLE_TYPE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $object);
        $stmt->execute();
        $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
        $stmt->close();
        return $type === null ? null : (string) $type;
    }
}

if (!function_exists('userNotificationConsolidationBaseTableCount')) {
    function userNotificationConsolidationBaseTableCount(mysqli $conn): int
    {
        $result = $conn->query(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = @active_database_name AND TABLE_TYPE = 'BASE TABLE'"
        );
        if (!$result) {
            throw new RuntimeException('Unable to count current base tables: ' . $conn->error);
        }
        return (int) ($result->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('userNotificationConsolidationColumns')) {
    function userNotificationConsolidationColumns(mysqli $conn, string $object): array
    {
        $stmt = $conn->prepare(
            'SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->bind_param('s', $object);
        $stmt->execute();
        $result = $stmt->get_result();
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string) $row['COLUMN_NAME'];
        }
        $stmt->close();
        return $columns;
    }
}

if (!function_exists('userNotificationConsolidationExpectedInboxColumns')) {
    function userNotificationConsolidationExpectedInboxColumns(): array
    {
        return [
            'id', 'recipient_user_id', 'source_app', 'notification_type', 'category',
            'severity', 'action_key', 'title', 'message', 'actor_user_id',
            'actor_email', 'entity_type', 'entity_id', 'route', 'payload_json',
            'dedupe_key', 'read_at', 'created_at',
        ];
    }
}

if (!function_exists('userNotificationConsolidationExpectedReminderColumns')) {
    function userNotificationConsolidationExpectedReminderColumns(): array
    {
        return [
            USER_NOTIFICATION_REMINDER_TABLE => [
                'id', 'reminder_reference', 'idempotency_key', 'request_type',
                'request_id', 'batch_id', 'batch_item_id', 'reminder_kind',
                'lifecycle_status', 'delivery_status', 'scheduled_at',
                'next_reminder_at', 'reminder_interval_minutes', 'attempt_count',
                'delivery_count', 'failure_count', 'last_attempt_at',
                'last_delivered_at', 'last_error', 'escalation_level', 'escalated_at',
                'source_expected_completion_at', 'source_status_snapshot',
                'context_json', 'lease_token', 'lease_expires_at', 'completed_at',
                'completion_reason', 'completed_by', 'cancelled_at',
                'cancellation_reason', 'created_by', 'created_at', 'updated_by',
                'updated_at',
            ],
            USER_NOTIFICATION_REMINDER_RUN_TABLE => [
                'id', 'run_reference', 'host_name', 'process_id', 'status',
                'process_limit', 'started_at', 'finished_at', 'duration_ms',
                'summary_json', 'error_message', 'created_at',
            ],
        ];
    }
}
