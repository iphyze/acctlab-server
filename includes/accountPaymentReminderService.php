<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/accountNotificationService.php';
require_once __DIR__ . '/accountPaymentStorageCanonicalWriteService.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';
require_once __DIR__ . '/fxFundRequestPaymentLifecycleService.php';

const ACCOUNT_PAYMENT_REMINDER_TYPES = ['Advance', 'Supplier', 'Compass', 'FX Final', 'FX Advance'];
const ACCOUNT_PAYMENT_REMINDER_LIFECYCLE_STATUSES = [
    'Pending',
    'Awaiting Action',
    'Retry Scheduled',
    'Delivering',
    'Completed',
    'Cancelled',
];
const ACCOUNT_PAYMENT_REMINDER_DELIVERY_STATUSES = ['Pending', 'Delivered', 'Failed'];
const ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES = 1440;

function accountPaymentReminderNormalizeRequestType(mixed $requestType): string
{
    $value = strtolower(trim((string) $requestType));
    return match ($value) {
        'advance', 'advance fund request', 'advance_payment_request', 'local_advance_purchase' => 'Advance',
        'supplier', 'supplier fund request', 'supplier_fund_request', 'local_final_purchase' => 'Supplier',
        'compass', 'compass fund request', 'compass_fund_request' => 'Compass',
        'fx final', 'fx_final', 'fx final purchase', 'fx_final_purchase' => 'FX Final',
        'fx advance', 'fx_advance', 'fx advance purchase', 'fx_advance_purchase' => 'FX Advance',
        default => throw new RuntimeException('Payment reminder request type is invalid.', 400),
    };
}

function accountPaymentReminderIdempotencyKey(
    mixed $requestType,
    int $requestId,
    ?int $batchId = null,
    ?int $batchItemId = null
): string {
    $type = accountPaymentReminderNormalizeRequestType($requestType);
    if ($requestId <= 0) {
        throw new RuntimeException('Payment reminder request is invalid.', 400);
    }

    return hash('sha256', implode(':', [
        strtolower($type),
        $requestId,
        max(0, (int) $batchId),
        max(0, (int) $batchItemId),
        'payment-confirmation',
    ]));
}

function accountPaymentReminderReference(
    mixed $requestType,
    int $requestId,
    ?int $batchId = null,
    ?int $batchItemId = null
): string {
    $type = accountPaymentReminderNormalizeRequestType($requestType);
    if ($requestId <= 0) {
        throw new RuntimeException('Payment reminder request is invalid.', 400);
    }

    $prefix = match ($type) {
        'Advance' => 'ADV',
        'Supplier' => 'SUP',
        'Compass' => 'CMP',
        'FX Final' => 'FXF',
        'FX Advance' => 'FXA',
        default => 'PAY',
    };
    return sprintf(
        'PAYREM-%s-%08d-%08d-%08d',
        $prefix,
        $requestId,
        max(0, (int) $batchId),
        max(0, (int) $batchItemId)
    );
}

function accountPaymentReminderTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function accountPaymentReminderEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $query = "CREATE TABLE IF NOT EXISTS account_payment_reminders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reminder_reference VARCHAR(80) NOT NULL,
        idempotency_key CHAR(64) NOT NULL,
        request_type VARCHAR(20) NOT NULL,
        request_id INT NOT NULL,
        batch_id BIGINT UNSIGNED NULL,
        batch_item_id BIGINT UNSIGNED NULL,
        reminder_kind VARCHAR(60) NOT NULL DEFAULT 'Payment Confirmation',
        lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        delivery_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        scheduled_at DATETIME NOT NULL,
        next_reminder_at DATETIME NOT NULL,
        reminder_interval_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        delivery_count INT UNSIGNED NOT NULL DEFAULT 0,
        failure_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt_at DATETIME NULL,
        last_delivered_at DATETIME NULL,
        last_error TEXT NULL,
        escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
        escalated_at DATETIME NULL,
        source_expected_completion_at DATETIME NULL,
        source_status_snapshot VARCHAR(40) NULL,
        context_json LONGTEXT NULL,
        lease_token VARCHAR(80) NULL,
        lease_expires_at DATETIME NULL,
        completed_at DATETIME NULL,
        completion_reason VARCHAR(255) NULL,
        completed_by INT NULL,
        cancelled_at DATETIME NULL,
        cancellation_reason VARCHAR(255) NULL,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_account_payment_reminder_reference (reminder_reference),
        UNIQUE KEY uq_account_payment_reminder_idempotency (idempotency_key),
        INDEX idx_account_payment_reminder_due (lifecycle_status, next_reminder_at),
        INDEX idx_account_payment_reminder_request (request_type, request_id, lifecycle_status),
        INDEX idx_account_payment_reminder_batch (request_type, batch_id, lifecycle_status),
        INDEX idx_account_payment_reminder_delivery (delivery_status, next_reminder_at),
        INDEX idx_account_payment_reminder_lease (lease_expires_at, lifecycle_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($query)) {
        throw new RuntimeException(
            'Payment reminder storage could not be initialized. Apply database/account_payment_reminder_lifecycle_migration.sql.',
            500
        );
    }

    $ensured = true;
}


function accountPaymentReminderPublicSummary(array $row): array
{
    $context = accountPaymentReminderContext($row['context_json'] ?? null);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'reference' => (string) ($row['reminder_reference'] ?? ''),
        'request_type' => (string) ($row['request_type'] ?? ''),
        'lifecycle_status' => (string) ($row['lifecycle_status'] ?? ''),
        'delivery_status' => (string) ($row['delivery_status'] ?? ''),
        'scheduled_at' => $row['scheduled_at'] ?? null,
        'next_reminder_at' => $row['next_reminder_at'] ?? null,
        'reminder_interval_minutes' => (int) ($row['reminder_interval_minutes'] ?? 0),
        'attempt_count' => (int) ($row['attempt_count'] ?? 0),
        'delivery_count' => (int) ($row['delivery_count'] ?? 0),
        'failure_count' => (int) ($row['failure_count'] ?? 0),
        'last_attempt_at' => $row['last_attempt_at'] ?? null,
        'last_delivered_at' => $row['last_delivered_at'] ?? null,
        'last_error' => $row['last_error'] ?? null,
        'escalation_level' => (int) ($row['escalation_level'] ?? 0),
        'escalated_at' => $row['escalated_at'] ?? null,
        'source_expected_completion_at' => $row['source_expected_completion_at'] ?? null,
        'source_status_snapshot' => $row['source_status_snapshot'] ?? null,
        'completed_at' => $row['completed_at'] ?? null,
        'completion_reason' => $row['completion_reason'] ?? null,
        'cancelled_at' => $row['cancelled_at'] ?? null,
        'cancellation_reason' => $row['cancellation_reason'] ?? null,
        'manual_reinitiation_count' => (int) ($context['manual_reinitiation_count'] ?? 0),
        'last_manual_reinitiation' => is_array($context['last_manual_reinitiation'] ?? null)
            ? $context['last_manual_reinitiation']
            : null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function accountPaymentReminderSummariesForRequests(
    mysqli $conn,
    mixed $requestType,
    array $requestIds
): array {
    accountPaymentReminderEnsureStorage($conn);
    $type = accountPaymentReminderNormalizeRequestType($requestType);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $requestIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 's' . str_repeat('i', count($ids));
    $params = array_merge([$type], $ids);
    $stmt = $conn->prepare(
        "SELECT *
         FROM account_payment_reminders
         WHERE request_type = ? AND request_id IN ($placeholders)
         ORDER BY request_id ASC,
                  (lifecycle_status NOT IN ('Completed', 'Cancelled')) DESC,
                  id DESC"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $summaries = [];
    while ($row = $result->fetch_assoc()) {
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($requestId <= 0 || isset($summaries[$requestId])) {
            continue;
        }
        $summaries[$requestId] = accountPaymentReminderPublicSummary($row);
    }
    $stmt->close();
    return $summaries;
}

function accountPaymentReminderAttachSummaries(
    mysqli $conn,
    mixed $requestType,
    array $rows
): array {
    if ($rows === []) {
        return [];
    }

    $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows);
    $summaries = accountPaymentReminderSummariesForRequests($conn, $requestType, $ids);
    foreach ($rows as &$row) {
        $requestId = (int) ($row['id'] ?? 0);
        $row['payment_reminder'] = $summaries[$requestId] ?? null;
    }
    unset($row);
    return $rows;
}

function accountPaymentReminderBackfillOverdue(mysqli $conn): array
{
    accountPaymentReminderEnsureStorage($conn);

    $beforeResult = $conn->query('SELECT COUNT(*) AS total FROM account_payment_reminders');
    $before = $beforeResult instanceof mysqli_result
        ? (int) ($beforeResult->fetch_assoc()['total'] ?? 0)
        : 0;

    $queries = [];
    $canonicalReady = accountPaymentReminderTableExists($conn, 'account_payment_batches')
        && accountPaymentReminderTableExists($conn, 'account_payment_batch_items');

    if (accountPaymentReminderTableExists($conn, 'advance_payment_request')) {
        if ($canonicalReady) {
            $config = accountPaymentReminderSourceConfig('Advance');
            $canonicalType = $conn->real_escape_string($config['canonical_request_type']);
            $itemSource = $conn->real_escape_string($config['item_legacy_source_table']);
            $batchSource = $conn->real_escape_string($config['batch_legacy_source_table']);
            $queries[] = "INSERT INTO account_payment_reminders
                (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
                 lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
                 reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
                 context_json, created_by, updated_by)
                SELECT
                    CONCAT('PAYREM-ADV-', LPAD(apr.id, 8, '0'), '-',
                           LPAD(b.legacy_source_id, 8, '0'), '-', LPAD(i.legacy_source_id, 8, '0')),
                    SHA2(CONCAT('advance:', apr.id, ':', b.legacy_source_id, ':',
                                i.legacy_source_id, ':payment-confirmation'), 256),
                    'Advance', apr.id, b.legacy_source_id, i.legacy_source_id,
                    CASE WHEN apr.payment_confirmation_status = 'Due' OR i.status = 'Awaiting Confirmation'
                         THEN 'Awaiting Action' ELSE 'Pending' END,
                    'Pending',
                    COALESCE(i.expected_completion_at, apr.expected_completion_at, b.expected_completion_at, NOW()),
                    NOW(),
                    " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                    COALESCE(i.expected_completion_at, apr.expected_completion_at, b.expected_completion_at),
                    apr.payment_status,
                    JSON_OBJECT(
                        'processing_method', apr.processing_method,
                        'processing_reference', apr.processing_reference,
                        'payment_confirmation_status', apr.payment_confirmation_status,
                        'backfilled', TRUE,
                        'canonical_payment_storage', TRUE
                    ),
                    0, 0
                FROM account_payment_batch_items i
                INNER JOIN account_payment_batches b ON b.id = i.batch_id
                INNER JOIN advance_payment_request apr ON apr.id = i.request_id
                WHERE BINARY i.request_type = BINARY '{$canonicalType}'
                  AND BINARY i.legacy_source_table = BINARY '{$itemSource}'
                  AND BINARY b.request_type = BINARY '{$canonicalType}'
                  AND BINARY b.legacy_source_table = BINARY '{$batchSource}'
                  AND i.legacy_source_id IS NOT NULL
                  AND b.legacy_source_id IS NOT NULL
                  AND apr.payment_status = 'Processing'
                  AND b.completion_mode = 'Notify'
                  AND i.status IN ('Processing', 'Delayed', 'Awaiting Confirmation')
                  AND COALESCE(i.expected_completion_at, apr.expected_completion_at, b.expected_completion_at) <= NOW()
                ON DUPLICATE KEY UPDATE
                    next_reminder_at = CASE
                        WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                        WHEN attempt_count = 0 AND delivery_count = 0 AND failure_count = 0
                            THEN LEAST(next_reminder_at, NOW())
                        ELSE next_reminder_at
                    END,
                    source_status_snapshot = VALUES(source_status_snapshot),
                    context_json = VALUES(context_json),
                    updated_by = 0";
        }

        $queries[] = "INSERT INTO account_payment_reminders
            (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
             lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
             reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
             context_json, created_by, updated_by)
            SELECT
                CONCAT('PAYREM-ADV-', LPAD(apr.id, 8, '0'), '-00000000-00000000'),
                SHA2(CONCAT('advance:', apr.id, ':0:0:payment-confirmation'), 256),
                'Advance', apr.id, NULL, NULL,
                CASE WHEN apr.payment_confirmation_status = 'Due' THEN 'Awaiting Action' ELSE 'Pending' END,
                'Pending', apr.expected_completion_at, NOW(),
                " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                apr.expected_completion_at, apr.payment_status,
                JSON_OBJECT(
                    'processing_method', apr.processing_method,
                    'processing_reference', apr.processing_reference,
                    'payment_confirmation_status', apr.payment_confirmation_status,
                    'backfilled', TRUE,
                    'standalone', TRUE
                ),
                0, 0
            FROM advance_payment_request apr
            WHERE apr.payment_status = 'Processing'
              AND apr.completion_mode = 'Notify'
              AND apr.expected_completion_at IS NOT NULL
              AND apr.expected_completion_at <= NOW()
              AND NOT EXISTS (
                  SELECT 1
                  FROM account_payment_reminders reminder
                  WHERE reminder.request_type = 'Advance'
                    AND reminder.request_id = apr.id
                    AND reminder.lifecycle_status NOT IN ('Completed', 'Cancelled')
              )
            ON DUPLICATE KEY UPDATE
                next_reminder_at = CASE
                    WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                    ELSE LEAST(next_reminder_at, NOW())
                END,
                source_status_snapshot = VALUES(source_status_snapshot),
                context_json = VALUES(context_json),
                updated_by = 0";
    }

    if (accountPaymentReminderTableExists($conn, 'supplier_fund_request_table')) {
        if ($canonicalReady) {
            $config = accountPaymentReminderSourceConfig('Supplier');
            $canonicalType = $conn->real_escape_string($config['canonical_request_type']);
            $itemSource = $conn->real_escape_string($config['item_legacy_source_table']);
            $batchSource = $conn->real_escape_string($config['batch_legacy_source_table']);
            $queries[] = "INSERT INTO account_payment_reminders
                (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
                 lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
                 reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
                 context_json, created_by, updated_by)
                SELECT
                    CONCAT('PAYREM-SUP-', LPAD(sfr.id, 8, '0'), '-',
                           LPAD(b.legacy_source_id, 8, '0'), '-', LPAD(i.legacy_source_id, 8, '0')),
                    SHA2(CONCAT('supplier:', sfr.id, ':', b.legacy_source_id, ':',
                                i.legacy_source_id, ':payment-confirmation'), 256),
                    'Supplier', sfr.id, b.legacy_source_id, i.legacy_source_id,
                    CASE WHEN sfr.payment_confirmation_status = 'Due' OR i.status = 'Awaiting Confirmation'
                         THEN 'Awaiting Action' ELSE 'Pending' END,
                    'Pending',
                    COALESCE(i.expected_completion_at, sfr.expected_completion_at, b.expected_completion_at, NOW()),
                    NOW(),
                    " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                    COALESCE(i.expected_completion_at, sfr.expected_completion_at, b.expected_completion_at),
                    sfr.payment_status,
                    JSON_OBJECT(
                        'processing_method', sfr.processing_method,
                        'processing_reference', sfr.processing_reference,
                        'payment_confirmation_status', sfr.payment_confirmation_status,
                        'backfilled', TRUE,
                        'canonical_payment_storage', TRUE
                    ),
                    0, 0
                FROM account_payment_batch_items i
                INNER JOIN account_payment_batches b ON b.id = i.batch_id
                INNER JOIN supplier_fund_request_table sfr ON sfr.id = i.request_id
                WHERE BINARY i.request_type = BINARY '{$canonicalType}'
                  AND BINARY i.legacy_source_table = BINARY '{$itemSource}'
                  AND BINARY b.request_type = BINARY '{$canonicalType}'
                  AND BINARY b.legacy_source_table = BINARY '{$batchSource}'
                  AND i.legacy_source_id IS NOT NULL
                  AND b.legacy_source_id IS NOT NULL
                  AND sfr.payment_status = 'Processing'
                  AND b.completion_mode = 'Notify'
                  AND i.status IN ('Processing', 'Delayed', 'Awaiting Confirmation')
                  AND COALESCE(i.expected_completion_at, sfr.expected_completion_at, b.expected_completion_at) <= NOW()
                ON DUPLICATE KEY UPDATE
                    next_reminder_at = CASE
                        WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                        WHEN attempt_count = 0 AND delivery_count = 0 AND failure_count = 0
                            THEN LEAST(next_reminder_at, NOW())
                        ELSE next_reminder_at
                    END,
                    source_status_snapshot = VALUES(source_status_snapshot),
                    context_json = VALUES(context_json),
                    updated_by = 0";
        }

        $queries[] = "INSERT INTO account_payment_reminders
            (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
             lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
             reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
             context_json, created_by, updated_by)
            SELECT
                CONCAT('PAYREM-SUP-', LPAD(sfr.id, 8, '0'), '-00000000-00000000'),
                SHA2(CONCAT('supplier:', sfr.id, ':0:0:payment-confirmation'), 256),
                'Supplier', sfr.id, NULL, NULL,
                CASE WHEN sfr.payment_confirmation_status = 'Due' THEN 'Awaiting Action' ELSE 'Pending' END,
                'Pending', sfr.expected_completion_at, NOW(),
                " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                sfr.expected_completion_at, sfr.payment_status,
                JSON_OBJECT(
                    'processing_method', sfr.processing_method,
                    'processing_reference', sfr.processing_reference,
                    'payment_confirmation_status', sfr.payment_confirmation_status,
                    'backfilled', TRUE,
                    'standalone', TRUE
                ),
                0, 0
            FROM supplier_fund_request_table sfr
            WHERE sfr.payment_status = 'Processing'
              AND sfr.completion_mode = 'Notify'
              AND sfr.expected_completion_at IS NOT NULL
              AND sfr.expected_completion_at <= NOW()
              AND NOT EXISTS (
                  SELECT 1
                  FROM account_payment_reminders reminder
                  WHERE reminder.request_type = 'Supplier'
                    AND reminder.request_id = sfr.id
                    AND reminder.lifecycle_status NOT IN ('Completed', 'Cancelled')
              )
            ON DUPLICATE KEY UPDATE
                next_reminder_at = CASE
                    WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                    ELSE LEAST(next_reminder_at, NOW())
                END,
                source_status_snapshot = VALUES(source_status_snapshot),
                context_json = VALUES(context_json),
                updated_by = 0";
    }


    if (accountPaymentReminderTableExists($conn, 'compass_fund_request_table')) {
        if ($canonicalReady) {
            $config = accountPaymentReminderSourceConfig('Compass');
            $canonicalType = $conn->real_escape_string($config['canonical_request_type']);
            $itemSource = $conn->real_escape_string($config['item_legacy_source_table']);
            $batchSource = $conn->real_escape_string($config['batch_legacy_source_table']);
            $queries[] = "INSERT INTO account_payment_reminders
                (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
                 lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
                 reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
                 context_json, created_by, updated_by)
                SELECT
                    CONCAT('PAYREM-CMP-', LPAD(cfr.id, 8, '0'), '-',
                           LPAD(b.legacy_source_id, 8, '0'), '-', LPAD(i.legacy_source_id, 8, '0')),
                    SHA2(CONCAT('compass:', cfr.id, ':', b.legacy_source_id, ':',
                                i.legacy_source_id, ':payment-confirmation'), 256),
                    'Compass', cfr.id, b.legacy_source_id, i.legacy_source_id,
                    CASE WHEN cfr.payment_confirmation_status = 'Due' OR i.status = 'Awaiting Confirmation'
                         THEN 'Awaiting Action' ELSE 'Pending' END,
                    'Pending',
                    COALESCE(i.expected_completion_at, cfr.expected_completion_at, b.expected_completion_at, NOW()),
                    NOW(),
                    " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                    COALESCE(i.expected_completion_at, cfr.expected_completion_at, b.expected_completion_at),
                    cfr.payment_status,
                    JSON_OBJECT(
                        'processing_method', cfr.processing_method,
                        'processing_reference', cfr.processing_reference,
                        'payment_confirmation_status', cfr.payment_confirmation_status,
                        'backfilled', TRUE,
                        'canonical_payment_storage', TRUE,
                        'compass', TRUE
                    ),
                    0, 0
                FROM account_payment_batch_items i
                INNER JOIN account_payment_batches b ON b.id = i.batch_id
                INNER JOIN compass_fund_request_table cfr ON cfr.id = i.request_id
                WHERE BINARY i.request_type = BINARY '{$canonicalType}'
                  AND BINARY i.legacy_source_table = BINARY '{$itemSource}'
                  AND BINARY b.request_type = BINARY '{$canonicalType}'
                  AND BINARY b.legacy_source_table = BINARY '{$batchSource}'
                  AND i.legacy_source_id IS NOT NULL
                  AND b.legacy_source_id IS NOT NULL
                  AND cfr.payment_status = 'Processing'
                  AND b.completion_mode = 'Notify'
                  AND i.status IN ('Processing', 'Delayed', 'Awaiting Confirmation')
                  AND COALESCE(i.expected_completion_at, cfr.expected_completion_at, b.expected_completion_at) <= NOW()
                ON DUPLICATE KEY UPDATE
                    next_reminder_at = CASE
                        WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                        WHEN attempt_count = 0 AND delivery_count = 0 AND failure_count = 0
                            THEN LEAST(next_reminder_at, NOW())
                        ELSE next_reminder_at
                    END,
                    source_status_snapshot = VALUES(source_status_snapshot),
                    context_json = VALUES(context_json),
                    updated_by = 0";
        }

        $queries[] = "INSERT INTO account_payment_reminders
            (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
             lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
             reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
             context_json, created_by, updated_by)
            SELECT
                CONCAT('PAYREM-CMP-', LPAD(cfr.id, 8, '0'), '-00000000-00000000'),
                SHA2(CONCAT('compass:', cfr.id, ':0:0:payment-confirmation'), 256),
                'Compass', cfr.id, NULL, NULL,
                CASE WHEN cfr.payment_confirmation_status = 'Due' THEN 'Awaiting Action' ELSE 'Pending' END,
                'Pending', cfr.expected_completion_at, NOW(),
                " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                cfr.expected_completion_at, cfr.payment_status,
                JSON_OBJECT(
                    'processing_method', cfr.processing_method,
                    'processing_reference', cfr.processing_reference,
                    'payment_confirmation_status', cfr.payment_confirmation_status,
                    'backfilled', TRUE,
                    'standalone', TRUE,
                    'compass', TRUE
                ),
                0, 0
            FROM compass_fund_request_table cfr
            WHERE cfr.payment_status = 'Processing'
              AND cfr.completion_mode = 'Notify'
              AND cfr.expected_completion_at IS NOT NULL
              AND cfr.expected_completion_at <= NOW()
              AND NOT EXISTS (
                  SELECT 1
                  FROM account_payment_reminders reminder
                  WHERE reminder.request_type = 'Compass'
                    AND reminder.request_id = cfr.id
                    AND reminder.lifecycle_status NOT IN ('Completed', 'Cancelled')
              )
            ON DUPLICATE KEY UPDATE
                next_reminder_at = CASE
                    WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                    ELSE LEAST(next_reminder_at, NOW())
                END,
                source_status_snapshot = VALUES(source_status_snapshot),
                context_json = VALUES(context_json),
                updated_by = 0";
    }


    if ($canonicalReady && accountPaymentReminderTableExists($conn, 'fx_fund_request_table')) {
        $queries[] = "INSERT INTO account_payment_reminders
            (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
             lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
             reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
             context_json, created_by, updated_by)
            SELECT
                CONCAT(
                    'PAYREM-',
                    CASE WHEN i.request_type = 'fx_advance_purchase' THEN 'FXA' ELSE 'FXF' END,
                    '-',
                    LPAD(fx.id, 8, '0'), '-',
                    LPAD(b.legacy_source_id, 8, '0'), '-',
                    LPAD(i.legacy_source_id, 8, '0')
                ),
                SHA2(CONCAT(
                    CASE WHEN i.request_type = 'fx_advance_purchase' THEN 'fx advance' ELSE 'fx final' END,
                    ':', fx.id, ':', b.legacy_source_id, ':',
                    i.legacy_source_id, ':payment-confirmation'
                ), 256),
                CASE WHEN i.request_type = 'fx_advance_purchase' THEN 'FX Advance' ELSE 'FX Final' END,
                fx.id,
                b.legacy_source_id,
                i.legacy_source_id,
                CASE WHEN i.status = 'Awaiting Confirmation' THEN 'Awaiting Action' ELSE 'Pending' END,
                'Pending',
                COALESCE(i.expected_completion_at, b.expected_completion_at, NOW()),
                NOW(),
                " . ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES . ",
                COALESCE(i.expected_completion_at, b.expected_completion_at),
                fx.payment_status,
                JSON_OBJECT(
                    'processing_method', b.processing_method,
                    'processing_reference', b.processing_reference,
                    'backfilled', TRUE,
                    'canonical_payment_storage', TRUE,
                    'fx_instruction_letter_id', fx.fx_instruction_letter_id
                ),
                0,
                0
            FROM account_payment_batch_items i
            INNER JOIN account_payment_batches b
              ON b.id = i.batch_id
             AND b.request_type = i.request_type
            INNER JOIN fx_fund_request_table fx
              ON fx.id = i.request_id
             AND (
                    (i.request_type = 'fx_final_purchase' AND fx.request_type = 'Final')
                 OR (i.request_type = 'fx_advance_purchase' AND fx.request_type = 'Advance')
             )
            WHERE i.request_type IN ('fx_final_purchase', 'fx_advance_purchase')
              AND i.legacy_source_id IS NOT NULL
              AND b.legacy_source_id IS NOT NULL
              AND fx.payment_status = 'Processing'
              AND b.completion_mode = 'Notify'
              AND i.status IN ('Processing', 'Delayed', 'Awaiting Confirmation')
              AND COALESCE(i.expected_completion_at, b.expected_completion_at) <= NOW()
            ON DUPLICATE KEY UPDATE
                next_reminder_at = CASE
                    WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                    WHEN attempt_count = 0 AND delivery_count = 0 AND failure_count = 0
                        THEN LEAST(next_reminder_at, NOW())
                    ELSE next_reminder_at
                END,
                source_status_snapshot = VALUES(source_status_snapshot),
                context_json = VALUES(context_json),
                updated_by = 0";
    }

    $conn->begin_transaction();
    try {
        foreach ($queries as $query) {
            $conn->query($query);
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $afterResult = $conn->query('SELECT COUNT(*) AS total FROM account_payment_reminders');
    $after = (int) (($afterResult?->fetch_assoc()['total'] ?? 0));

    $counts = ['Advance' => 0, 'Supplier' => 0, 'Compass' => 0, 'FX Final' => 0, 'FX Advance' => 0];
    $countResult = $conn->query(
        "SELECT request_type, COUNT(*) AS total
         FROM account_payment_reminders
         WHERE context_json LIKE '%\"backfilled\": true%'
            OR context_json LIKE '%\"backfilled\":true%'
         GROUP BY request_type"
    );
    if ($countResult) {
        while ($row = $countResult->fetch_assoc()) {
            $type = (string) ($row['request_type'] ?? '');
            if (array_key_exists($type, $counts)) {
                $counts[$type] = (int) ($row['total'] ?? 0);
            }
        }
    }

    return [
        'created' => max(0, $after - $before),
        'total' => $after,
        'advance_backfilled' => $counts['Advance'],
        'supplier_backfilled' => $counts['Supplier'],
        'compass_backfilled' => $counts['Compass'],
        'fx_final_backfilled' => $counts['FX Final'],
        'fx_advance_backfilled' => $counts['FX Advance'],
        'canonical_payment_storage' => $canonicalReady,
    ];
}


const ACCOUNT_PAYMENT_REMINDER_RETRY_MINUTES = 15;
const ACCOUNT_PAYMENT_REMINDER_MAX_RETRY_MINUTES = 360;
const ACCOUNT_PAYMENT_REMINDER_LEASE_MINUTES = 10;
const ACCOUNT_PAYMENT_REMINDER_ESCALATE_AFTER_DELIVERIES = 3;
const ACCOUNT_PAYMENT_REMINDER_MAX_PROCESS_LIMIT = 100;

function accountPaymentReminderActor(array $actor): array
{
    return [
        'id' => max(0, (int) ($actor['id'] ?? 0)),
        'email' => trim((string) ($actor['email'] ?? 'system')) ?: 'system',
    ];
}

function accountPaymentReminderContext(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function accountPaymentReminderSourceConfig(mixed $requestType): array
{
    $type = accountPaymentReminderNormalizeRequestType($requestType);

    if ($type === 'Advance') {
        $canonicalType = ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE;
        $legacy = accountPaymentStorageLegacyMetadata($canonicalType);
        return [
            'type' => 'Advance',
            'canonical_request_type' => $canonicalType,
            'request_table' => 'advance_payment_request',
            'item_legacy_source_table' => $legacy['item_table'],
            'batch_legacy_source_table' => $legacy['batch_table'],
            'route' => '/payments/payment-processing',
            'category' => 'advance_payment_reminder',
            'entity_type' => 'advance_payment_request',
            'label' => 'Advance Fund Request',
            'is_fx' => false,
            'fx_request_type' => null,
        ];
    }

    if ($type === 'Supplier') {
        $canonicalType = ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL;
        $legacy = accountPaymentStorageLegacyMetadata($canonicalType);
        return [
            'type' => 'Supplier',
            'canonical_request_type' => $canonicalType,
            'request_table' => 'supplier_fund_request_table',
            'item_legacy_source_table' => $legacy['item_table'],
            'batch_legacy_source_table' => $legacy['batch_table'],
            'route' => '/payments/payment-processing',
            'category' => 'supplier_payment_reminder',
            'entity_type' => 'supplier_fund_request',
            'label' => 'Supplier Fund Request',
            'is_fx' => false,
            'fx_request_type' => null,
        ];
    }

    if ($type === 'Compass') {
        $canonicalType = ACCOUNT_PAYMENT_TYPE_COMPASS;
        $legacy = accountPaymentStorageLegacyMetadata($canonicalType);
        return [
            'type' => 'Compass',
            'canonical_request_type' => $canonicalType,
            'request_table' => 'compass_fund_request_table',
            'item_legacy_source_table' => $legacy['item_table'],
            'batch_legacy_source_table' => $legacy['batch_table'],
            'route' => '/payments/payment-processing',
            'category' => 'compass_payment_reminder',
            'entity_type' => 'compass_fund_request',
            'label' => 'Compass Fund Request',
            'is_fx' => false,
            'fx_request_type' => null,
        ];
    }

    $canonicalType = $type === 'FX Advance'
        ? ACCOUNT_PAYMENT_TYPE_FX_ADVANCE
        : ACCOUNT_PAYMENT_TYPE_FX_FINAL;
    $legacy = accountPaymentStorageLegacyMetadata($canonicalType);
    return [
        'type' => $type,
        'canonical_request_type' => $canonicalType,
        'request_table' => 'fx_fund_request_table',
        'item_legacy_source_table' => $legacy['item_table'],
        'batch_legacy_source_table' => $legacy['batch_table'],
        'route' => '/payments/payment-processing',
        'category' => 'fx_payment_reminder',
        'entity_type' => 'fx_fund_request',
        'label' => $type . ' Fund Request',
        'is_fx' => true,
        'fx_request_type' => $type === 'FX Advance' ? 'Advance' : 'Final',
    ];
}

function accountPaymentReminderFetchSource(
    mysqli $conn,
    mixed $requestType,
    int $requestId,
    ?int $batchItemId = null,
    bool $forUpdate = false
): ?array {
    $config = accountPaymentReminderSourceConfig($requestType);
    if ($requestId <= 0) {
        throw new RuntimeException('Payment reminder request is invalid.', 400);
    }

    $requestTable = $config['request_table'];
    $canonicalType = $config['canonical_request_type'];
    $itemSource = $config['item_legacy_source_table'];
    $batchSource = $config['batch_legacy_source_table'];
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    if (!empty($config['is_fx'])) {
        $fxType = (string) $config['fx_request_type'];
        if (($batchItemId ?? 0) > 0) {
            $sql = "SELECT r.id AS request_id, r.payment_status,
                           NULL AS payment_confirmation_status,
                           NULL AS request_completion_mode,
                           NULL AS request_expected_completion_at,
                           NULL AS request_processing_method,
                           NULL AS request_processing_reference,
                           r.po_number, r.suppliers_name,
                           i.id AS canonical_batch_item_id,
                           i.legacy_source_id AS batch_item_id,
                           b.id AS canonical_batch_id,
                           b.legacy_source_id AS batch_id,
                           i.status AS item_status,
                           i.expected_completion_at AS item_expected_completion_at,
                           b.expected_completion_at AS batch_expected_completion_at,
                           b.batch_reference, b.completion_mode AS batch_completion_mode,
                           b.processing_method AS batch_processing_method,
                           b.processing_reference AS batch_processing_reference
                    FROM `$requestTable` r
                    LEFT JOIN account_payment_batch_items i
                      ON i.legacy_source_id = ?
                     AND i.request_id = r.id
                     AND BINARY i.request_type = BINARY ?
                     AND BINARY i.legacy_source_table = BINARY ?
                    LEFT JOIN account_payment_batches b
                      ON b.id = i.batch_id
                     AND BINARY b.request_type = BINARY ?
                     AND BINARY b.legacy_source_table = BINARY ?
                    WHERE r.id = ? AND BINARY r.request_type = BINARY ?$lock";
            $stmt = $conn->prepare($sql);
            $itemId = (int) $batchItemId;
            $stmt->bind_param(
                'issssis',
                $itemId,
                $canonicalType,
                $itemSource,
                $canonicalType,
                $batchSource,
                $requestId,
                $fxType
            );
        } else {
            $sql = "SELECT r.id AS request_id, r.payment_status,
                           NULL AS payment_confirmation_status,
                           NULL AS request_completion_mode,
                           NULL AS request_expected_completion_at,
                           NULL AS request_processing_method,
                           NULL AS request_processing_reference,
                           r.po_number, r.suppliers_name,
                           i.id AS canonical_batch_item_id,
                           i.legacy_source_id AS batch_item_id,
                           b.id AS canonical_batch_id,
                           b.legacy_source_id AS batch_id,
                           i.status AS item_status,
                           i.expected_completion_at AS item_expected_completion_at,
                           b.expected_completion_at AS batch_expected_completion_at,
                           b.batch_reference, b.completion_mode AS batch_completion_mode,
                           b.processing_method AS batch_processing_method,
                           b.processing_reference AS batch_processing_reference
                    FROM `$requestTable` r
                    LEFT JOIN account_payment_batch_items i ON i.id = (
                        SELECT latest.id
                        FROM account_payment_batch_items latest
                        WHERE latest.request_id = r.id
                          AND BINARY latest.request_type = BINARY ?
                          AND BINARY latest.legacy_source_table = BINARY ?
                          AND latest.status IN ('Processing', 'Awaiting Confirmation', 'Delayed', 'Failed')
                        ORDER BY latest.id DESC
                        LIMIT 1
                    )
                    LEFT JOIN account_payment_batches b
                      ON b.id = i.batch_id
                     AND BINARY b.request_type = BINARY ?
                     AND BINARY b.legacy_source_table = BINARY ?
                    WHERE r.id = ? AND BINARY r.request_type = BINARY ?$lock";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'ssssis',
                $canonicalType,
                $itemSource,
                $canonicalType,
                $batchSource,
                $requestId,
                $fxType
            );
        }
    } else {
        if (($batchItemId ?? 0) > 0) {
            $sql = "SELECT r.id AS request_id, r.payment_status, r.payment_confirmation_status,
                           r.completion_mode AS request_completion_mode,
                           r.expected_completion_at AS request_expected_completion_at,
                           r.processing_method AS request_processing_method,
                           r.processing_reference AS request_processing_reference,
                           r.po_number, r.suppliers_name,
                           i.id AS canonical_batch_item_id,
                           i.legacy_source_id AS batch_item_id,
                           b.id AS canonical_batch_id,
                           b.legacy_source_id AS batch_id,
                           i.status AS item_status,
                           i.expected_completion_at AS item_expected_completion_at,
                           b.expected_completion_at AS batch_expected_completion_at,
                           b.batch_reference, b.completion_mode AS batch_completion_mode,
                           b.processing_method AS batch_processing_method,
                           b.processing_reference AS batch_processing_reference
                    FROM `$requestTable` r
                    LEFT JOIN account_payment_batch_items i
                      ON i.legacy_source_id = ?
                     AND i.request_id = r.id
                     AND BINARY i.request_type = BINARY ?
                     AND BINARY i.legacy_source_table = BINARY ?
                    LEFT JOIN account_payment_batches b
                      ON b.id = i.batch_id
                     AND BINARY b.request_type = BINARY ?
                     AND BINARY b.legacy_source_table = BINARY ?
                    WHERE r.id = ?$lock";
            $stmt = $conn->prepare($sql);
            $itemId = (int) $batchItemId;
            $stmt->bind_param(
                'issssi',
                $itemId,
                $canonicalType,
                $itemSource,
                $canonicalType,
                $batchSource,
                $requestId
            );
        } else {
            $sql = "SELECT r.id AS request_id, r.payment_status, r.payment_confirmation_status,
                           r.completion_mode AS request_completion_mode,
                           r.expected_completion_at AS request_expected_completion_at,
                           r.processing_method AS request_processing_method,
                           r.processing_reference AS request_processing_reference,
                           r.po_number, r.suppliers_name,
                           i.id AS canonical_batch_item_id,
                           i.legacy_source_id AS batch_item_id,
                           b.id AS canonical_batch_id,
                           b.legacy_source_id AS batch_id,
                           i.status AS item_status,
                           i.expected_completion_at AS item_expected_completion_at,
                           b.expected_completion_at AS batch_expected_completion_at,
                           b.batch_reference, b.completion_mode AS batch_completion_mode,
                           b.processing_method AS batch_processing_method,
                           b.processing_reference AS batch_processing_reference
                    FROM `$requestTable` r
                    LEFT JOIN account_payment_batch_items i ON i.id = (
                        SELECT latest.id
                        FROM account_payment_batch_items latest
                        WHERE latest.request_id = r.id
                          AND BINARY latest.request_type = BINARY ?
                          AND BINARY latest.legacy_source_table = BINARY ?
                          AND latest.status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
                        ORDER BY latest.id DESC
                        LIMIT 1
                    )
                    LEFT JOIN account_payment_batches b
                      ON b.id = i.batch_id
                     AND BINARY b.request_type = BINARY ?
                     AND BINARY b.legacy_source_table = BINARY ?
                    WHERE r.id = ?$lock";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'ssssi',
                $canonicalType,
                $itemSource,
                $canonicalType,
                $batchSource,
                $requestId
            );
        }
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row === null) {
        return null;
    }

    $row['request_type'] = $config['type'];
    $row['canonical_request_type'] = $canonicalType;
    $row['effective_completion_mode'] = trim((string) (
        $row['batch_completion_mode']
        ?? $row['request_completion_mode']
        ?? ''
    ));
    $row['effective_expected_completion_at'] = $row['item_expected_completion_at']
        ?? $row['request_expected_completion_at']
        ?? $row['batch_expected_completion_at']
        ?? null;
    $row['effective_processing_method'] = trim((string) (
        $row['batch_processing_method']
        ?? $row['request_processing_method']
        ?? ''
    ));
    $row['effective_processing_reference'] = trim((string) (
        $row['batch_processing_reference']
        ?? $row['request_processing_reference']
        ?? ''
    ));
    return $row;
}

function accountPaymentReminderRecordSourceEvent(
    mysqli $conn,
    mixed $requestType,
    int $requestId,
    ?int $batchId,
    string $eventType,
    array $actor,
    array $details = []
): void {
    $type = accountPaymentReminderNormalizeRequestType($requestType);
    $actor = accountPaymentReminderActor($actor);
    $json = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $config = accountPaymentReminderSourceConfig($type);
    workflowEventRecordPayment(
        $conn,
        (string) $config['canonical_request_type'],
        $requestId,
        $batchId,
        $eventType,
        $actor['id'],
        $actor['email'],
        $json
    );
}

function accountPaymentReminderSchedule(
    mysqli $conn,
    mixed $requestType,
    int $requestId,
    ?int $batchId,
    ?int $batchItemId,
    ?string $scheduledAt,
    array $context = [],
    array $actor = []
): int {
    accountPaymentReminderEnsureStorage($conn);
    $type = accountPaymentReminderNormalizeRequestType($requestType);
    $actor = accountPaymentReminderActor($actor);
    $reference = accountPaymentReminderReference($type, $requestId, $batchId, $batchItemId);
    $key = accountPaymentReminderIdempotencyKey($type, $requestId, $batchId, $batchItemId);
    $scheduled = trim((string) $scheduledAt);
    if ($scheduled === '') {
        $scheduled = date('Y-m-d H:i:s');
    }
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($contextJson === false) {
        $contextJson = '{}';
    }

    $stmt = $conn->prepare(
        "INSERT INTO account_payment_reminders
            (reminder_reference, idempotency_key, request_type, request_id, batch_id, batch_item_id,
             lifecycle_status, delivery_status, scheduled_at, next_reminder_at,
             reminder_interval_minutes, source_expected_completion_at, source_status_snapshot,
             context_json, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'Pending', ?, ?, ?, ?, 'Processing', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             source_expected_completion_at = VALUES(source_expected_completion_at),
             source_status_snapshot = VALUES(source_status_snapshot),
             context_json = VALUES(context_json),
             next_reminder_at = CASE
                 WHEN lifecycle_status IN ('Completed', 'Cancelled') THEN next_reminder_at
                 ELSE LEAST(next_reminder_at, VALUES(next_reminder_at))
             END,
             updated_by = VALUES(updated_by)"
    );
    $interval = ACCOUNT_PAYMENT_REMINDER_DEFAULT_INTERVAL_MINUTES;
    $batchIdValue = ($batchId ?? 0) > 0 ? (int) $batchId : null;
    $batchItemIdValue = ($batchItemId ?? 0) > 0 ? (int) $batchItemId : null;
    $stmt->bind_param(
        'sssiiississii',
        $reference,
        $key,
        $type,
        $requestId,
        $batchIdValue,
        $batchItemIdValue,
        $scheduled,
        $scheduled,
        $interval,
        $scheduled,
        $contextJson,
        $actor['id'],
        $actor['id']
    );
    $stmt->execute();
    $stmt->close();

    $find = $conn->prepare('SELECT id FROM account_payment_reminders WHERE idempotency_key = ? LIMIT 1');
    $find->bind_param('s', $key);
    $find->execute();
    $id = (int) ($find->get_result()->fetch_assoc()['id'] ?? 0);
    $find->close();
    if ($id <= 0) {
        throw new RuntimeException('Payment reminder could not be scheduled.', 500);
    }
    return $id;
}

function accountPaymentReminderInitialDelivery(
    mysqli $conn,
    array $reminderIds,
    int $recipientCount,
    array $actor = []
): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $reminderIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return;
    }
    $actor = accountPaymentReminderActor($actor);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $select = $conn->prepare(
        "SELECT id, request_type, request_id, batch_id, attempt_count, delivery_count, failure_count
         FROM account_payment_reminders
         WHERE id IN ($placeholders)
         FOR UPDATE"
    );
    $select->bind_param($types, ...$ids);
    $select->execute();
    $reminders = $select->get_result()->fetch_all(MYSQLI_ASSOC);
    $select->close();

    if ($recipientCount > 0) {
        $sql = "UPDATE account_payment_reminders
                SET lifecycle_status = 'Awaiting Action', delivery_status = 'Delivered',
                    attempt_count = attempt_count + 1, delivery_count = delivery_count + 1,
                    last_attempt_at = NOW(), last_delivered_at = NOW(), last_error = NULL,
                    next_reminder_at = DATE_ADD(NOW(), INTERVAL reminder_interval_minutes MINUTE),
                    lease_token = NULL, lease_expires_at = NULL, updated_by = ?
                WHERE id IN ($placeholders) AND lifecycle_status NOT IN ('Completed', 'Cancelled')";
    } else {
        $sql = "UPDATE account_payment_reminders
                SET lifecycle_status = 'Retry Scheduled', delivery_status = 'Failed',
                    attempt_count = attempt_count + 1, failure_count = failure_count + 1,
                    last_attempt_at = NOW(), last_error = 'No eligible Account notification recipients were found.',
                    next_reminder_at = DATE_ADD(NOW(), INTERVAL " . ACCOUNT_PAYMENT_REMINDER_RETRY_MINUTES . " MINUTE),
                    lease_token = NULL, lease_expires_at = NULL, updated_by = ?
                WHERE id IN ($placeholders) AND lifecycle_status NOT IN ('Completed', 'Cancelled')";
    }
    $stmt = $conn->prepare($sql);
    $params = array_merge([$actor['id']], $ids);
    $bindTypes = 'i' . $types;
    $stmt->bind_param($bindTypes, ...$params);
    $stmt->execute();
    $stmt->close();

    foreach ($reminders as $reminder) {
        $requestType = (string) ($reminder['request_type'] ?? '');
        $requestId = (int) ($reminder['request_id'] ?? 0);
        if ($requestId <= 0 || !in_array($requestType, ACCOUNT_PAYMENT_REMINDER_TYPES, true)) {
            continue;
        }
        $batchId = (int) ($reminder['batch_id'] ?? 0) ?: null;
        $reminderId = (int) ($reminder['id'] ?? 0);
        if ($recipientCount > 0) {
            accountPaymentReminderRecordSourceEvent(
                $conn,
                $requestType,
                $requestId,
                $batchId,
                'payment_reminder_delivered',
                $actor,
                [
                    'reminder_id' => $reminderId,
                    'attempt_count' => (int) ($reminder['attempt_count'] ?? 0) + 1,
                    'delivery_count' => (int) ($reminder['delivery_count'] ?? 0) + 1,
                    'recipient_count' => $recipientCount,
                    'created_notification_count' => $recipientCount,
                    'delivery_channel' => 'batch_confirmation_due',
                    'initial_delivery' => true,
                ]
            );
        } else {
            accountPaymentReminderRecordSourceEvent(
                $conn,
                $requestType,
                $requestId,
                $batchId,
                'payment_reminder_failed',
                $actor,
                [
                    'reminder_id' => $reminderId,
                    'attempt_count' => (int) ($reminder['attempt_count'] ?? 0) + 1,
                    'failure_count' => (int) ($reminder['failure_count'] ?? 0) + 1,
                    'delivery_channel' => 'batch_confirmation_due',
                    'reason' => 'No eligible Account notification recipients were found.',
                    'initial_delivery' => true,
                ]
            );
        }
    }
}

function accountPaymentReminderRetryMinutes(int $failureCount): int
{
    $failure = max(1, $failureCount);
    $minutes = ACCOUNT_PAYMENT_REMINDER_RETRY_MINUTES * (2 ** min(5, $failure - 1));
    return min(ACCOUNT_PAYMENT_REMINDER_MAX_RETRY_MINUTES, $minutes);
}

function accountPaymentReminderEscalationLevel(int $deliveryCount): int
{
    return intdiv(max(0, $deliveryCount), ACCOUNT_PAYMENT_REMINDER_ESCALATE_AFTER_DELIVERIES);
}

function accountPaymentReminderAcquireDue(mysqli $conn, int $limit = 50): array
{
    accountPaymentReminderEnsureStorage($conn);
    $limit = max(1, min(ACCOUNT_PAYMENT_REMINDER_MAX_PROCESS_LIMIT, $limit));
    $leaseToken = 'reminder-' . bin2hex(random_bytes(16));
    $leaseExpiresAt = date('Y-m-d H:i:s', time() + (ACCOUNT_PAYMENT_REMINDER_LEASE_MINUTES * 60));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT * FROM account_payment_reminders
             WHERE lifecycle_status IN ('Pending', 'Awaiting Action', 'Retry Scheduled')
               AND next_reminder_at <= NOW()
               AND (lease_expires_at IS NULL OR lease_expires_at < NOW())
             ORDER BY next_reminder_at ASC, id ASC
             LIMIT ? FOR UPDATE"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($rows !== []) {
            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = 'ss' . str_repeat('i', count($ids));
            $update = $conn->prepare(
                "UPDATE account_payment_reminders
                 SET lifecycle_status = 'Delivering', lease_token = ?, lease_expires_at = ?
                 WHERE id IN ($placeholders)
                   AND lifecycle_status IN ('Pending', 'Awaiting Action', 'Retry Scheduled')"
            );
            $params = array_merge([$leaseToken, $leaseExpiresAt], $ids);
            $update->bind_param($types, ...$params);
            $update->execute();
            $update->close();
            foreach ($rows as &$row) {
                $row['lease_token'] = $leaseToken;
            }
            unset($row);
        }
        $conn->commit();
        return $rows;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function accountPaymentReminderUpdateSourceAwaitingAction(mysqli $conn, array $source): void
{
    $config = accountPaymentReminderSourceConfig($source['request_type'] ?? '');
    $requestId = (int) ($source['request_id'] ?? 0);
    $canonicalType = (string) $config['canonical_request_type'];

    if (empty($config['is_fx'])) {
        $requestTable = $config['request_table'];
        $requestUpdate = $conn->prepare(
            "UPDATE `$requestTable`
             SET payment_confirmation_status = 'Due', payment_updated_at = NOW()
             WHERE id = ? AND payment_status = 'Processing'"
        );
        $requestUpdate->bind_param('i', $requestId);
        $requestUpdate->execute();
        $requestUpdate->close();
    }

    $canonicalItemId = (int) ($source['canonical_batch_item_id'] ?? 0);
    $canonicalBatchId = (int) ($source['canonical_batch_id'] ?? 0);
    if ($canonicalItemId > 0) {
        $itemUpdate = $conn->prepare(
            "UPDATE account_payment_batch_items
             SET status = 'Awaiting Confirmation', updated_by = 0
             WHERE id = ?
               AND request_id = ?
               AND BINARY request_type = BINARY ?
               AND status IN ('Processing', 'Delayed')"
        );
        $itemUpdate->bind_param('iis', $canonicalItemId, $requestId, $canonicalType);
        $itemUpdate->execute();
        $itemUpdate->close();
    }

    if ($canonicalBatchId > 0) {
        $batchUpdate = $conn->prepare(
            "UPDATE account_payment_batches
             SET status = CASE
                    WHEN EXISTS (
                        SELECT 1 FROM account_payment_batch_items item
                        WHERE item.batch_id = account_payment_batches.id
                          AND item.status = 'Awaiting Confirmation'
                    ) THEN 'Awaiting Confirmation'
                    ELSE status
                 END,
                 updated_by = 0
             WHERE id = ? AND BINARY request_type = BINARY ?"
        );
        $batchUpdate->bind_param('is', $canonicalBatchId, $canonicalType);
        $batchUpdate->execute();
        $batchUpdate->close();
    }

    if (!empty($config['is_fx'])) {
        // FX confirmation state lives on the shared canonical batch item, not
        // on fx_fund_request_table. Re-sync after the item transition so
        // ProcureDesk receives account_confirmation_status = Due.
        if (function_exists('procurementFxFinalSyncFundRequestToProcurement')) {
            procurementFxFinalSyncFundRequestToProcurement(
                $conn,
                $requestId,
                0,
                'system',
                'account_payment_confirmation_due'
            );
        }
        if (function_exists('procurementFxAdvanceSyncFundRequestToProcurement')) {
            procurementFxAdvanceSyncFundRequestToProcurement(
                $conn,
                $requestId,
                0,
                'system',
                'account_payment_confirmation_due'
            );
        }
    }
}

function accountPaymentReminderComplete(
    mysqli $conn,
    int $reminderId,
    string $reason,
    int $actorId = 0,
    string $status = 'Completed'
): void {
    $lifecycle = $status === 'Cancelled' ? 'Cancelled' : 'Completed';
    $stmt = $conn->prepare(
        "UPDATE account_payment_reminders
         SET lifecycle_status = ?, delivery_status = CASE WHEN delivery_count > 0 THEN 'Delivered' ELSE delivery_status END,
             completed_at = CASE WHEN ? = 'Completed' THEN NOW() ELSE completed_at END,
             completion_reason = CASE WHEN ? = 'Completed' THEN ? ELSE completion_reason END,
             cancelled_at = CASE WHEN ? = 'Cancelled' THEN NOW() ELSE cancelled_at END,
             cancellation_reason = CASE WHEN ? = 'Cancelled' THEN ? ELSE cancellation_reason END,
             completed_by = ?, lease_token = NULL, lease_expires_at = NULL, updated_by = ?
         WHERE id = ?"
    );
    $stmt->bind_param(
        'sssssssiii',
        $lifecycle,
        $lifecycle,
        $lifecycle,
        $reason,
        $lifecycle,
        $lifecycle,
        $reason,
        $actorId,
        $actorId,
        $reminderId
    );
    $stmt->execute();
    $stmt->close();
}

function accountPaymentReminderMarkFailure(
    mysqli $conn,
    int $reminderId,
    string $leaseToken,
    Throwable $error
): array {
    $find = $conn->prepare(
        'SELECT failure_count FROM account_payment_reminders WHERE id = ? AND lease_token = ? LIMIT 1'
    );
    $find->bind_param('is', $reminderId, $leaseToken);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$row) {
        return [
            'failure_count' => 0,
            'retry_minutes' => 0,
            'next_reminder_at' => null,
            'error' => trim($error->getMessage()) ?: 'Reminder delivery failed.',
        ];
    }
    $newFailureCount = (int) ($row['failure_count'] ?? 0) + 1;
    $retryMinutes = accountPaymentReminderRetryMinutes($newFailureCount);
    $message = trim($error->getMessage()) ?: 'Reminder delivery failed.';
    if (strlen($message) > 1000) {
        $message = substr($message, 0, 1000);
    }
    $escalation = $newFailureCount >= ACCOUNT_PAYMENT_REMINDER_ESCALATE_AFTER_DELIVERIES ? 1 : 0;
    $stmt = $conn->prepare(
        "UPDATE account_payment_reminders
         SET lifecycle_status = 'Retry Scheduled', delivery_status = 'Failed',
             attempt_count = attempt_count + 1, failure_count = failure_count + 1,
             last_attempt_at = NOW(), last_error = ?,
             next_reminder_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
             escalation_level = GREATEST(escalation_level, ?),
             escalated_at = CASE WHEN ? > 0 AND escalated_at IS NULL THEN NOW() ELSE escalated_at END,
             lease_token = NULL, lease_expires_at = NULL, updated_by = 0
         WHERE id = ? AND lease_token = ?"
    );
    $stmt->bind_param('siiiis', $message, $retryMinutes, $escalation, $escalation, $reminderId, $leaseToken);
    $stmt->execute();
    $stmt->close();

    return [
        'failure_count' => $newFailureCount,
        'retry_minutes' => $retryMinutes,
        'next_reminder_at' => date('Y-m-d H:i:s', time() + ($retryMinutes * 60)),
        'error' => $message,
    ];
}

function accountPaymentReminderDeliver(mysqli $conn, array $reminder): array
{
    $reminderId = (int) ($reminder['id'] ?? 0);
    $leaseToken = (string) ($reminder['lease_token'] ?? '');
    if ($reminderId <= 0 || $leaseToken === '') {
        throw new RuntimeException('Payment reminder lease is invalid.', 500);
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare(
            "SELECT * FROM account_payment_reminders
             WHERE id = ? AND lifecycle_status = 'Delivering' AND lease_token = ? FOR UPDATE"
        );
        $lock->bind_param('is', $reminderId, $leaseToken);
        $lock->execute();
        $fresh = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$fresh) {
            $conn->rollback();
            return ['status' => 'Skipped', 'reminder_id' => $reminderId, 'reason' => 'Lease is no longer active.'];
        }

        $source = accountPaymentReminderFetchSource(
            $conn,
            (string) $fresh['request_type'],
            (int) $fresh['request_id'],
            (int) ($fresh['batch_item_id'] ?? 0) ?: null,
            true
        );
        if ($source === null) {
            accountPaymentReminderComplete($conn, $reminderId, 'The linked payment request no longer exists.', 0, 'Cancelled');
            $conn->commit();
            return ['status' => 'Cancelled', 'reminder_id' => $reminderId, 'reason' => 'Request not found.'];
        }

        if (strcasecmp((string) ($source['payment_status'] ?? ''), 'Processing') !== 0) {
            $reason = 'Reminder completed because the payment status changed to ' . (string) ($source['payment_status'] ?? 'another status') . '.';
            accountPaymentReminderComplete($conn, $reminderId, $reason);
            accountPaymentReminderRecordSourceEvent(
                $conn,
                $source['request_type'],
                (int) $source['request_id'],
                (int) ($source['batch_id'] ?? 0) ?: null,
                'payment_reminder_completed',
                ['id' => 0, 'email' => 'system'],
                ['reminder_id' => $reminderId, 'reason' => $reason]
            );
            $conn->commit();
            return ['status' => 'Completed', 'reminder_id' => $reminderId, 'reason' => $reason];
        }

        if (strcasecmp((string) ($source['effective_completion_mode'] ?? ''), 'Notify') !== 0) {
            $reason = 'Reminder cancelled because the payment no longer uses notification confirmation.';
            accountPaymentReminderComplete($conn, $reminderId, $reason, 0, 'Cancelled');
            $conn->commit();
            return ['status' => 'Cancelled', 'reminder_id' => $reminderId, 'reason' => $reason];
        }

        $expectedAt = trim((string) ($source['effective_expected_completion_at'] ?? ''));
        if ($expectedAt !== '' && strtotime($expectedAt) > time()) {
            $stmt = $conn->prepare(
                "UPDATE account_payment_reminders
                 SET lifecycle_status = 'Pending', delivery_status = 'Pending', next_reminder_at = ?,
                     source_expected_completion_at = ?, source_status_snapshot = 'Processing',
                     lease_token = NULL, lease_expires_at = NULL, updated_by = 0
                 WHERE id = ?"
            );
            $stmt->bind_param('ssi', $expectedAt, $expectedAt, $reminderId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            return ['status' => 'Rescheduled', 'reminder_id' => $reminderId, 'next_reminder_at' => $expectedAt];
        }

        accountPaymentReminderUpdateSourceAwaitingAction($conn, $source);
        $config = accountPaymentReminderSourceConfig($source['request_type']);
        $nextAttempt = (int) ($fresh['attempt_count'] ?? 0) + 1;
        $nextDelivery = (int) ($fresh['delivery_count'] ?? 0) + 1;
        $previousFailures = (int) ($fresh['failure_count'] ?? 0);
        $previousEscalation = (int) ($fresh['escalation_level'] ?? 0);
        $deliveryEscalation = accountPaymentReminderEscalationLevel($nextDelivery);
        $failureEscalation = $previousFailures >= ACCOUNT_PAYMENT_REMINDER_ESCALATE_AFTER_DELIVERIES ? 1 : 0;
        $newEscalation = max($previousEscalation, $deliveryEscalation, $failureEscalation);
        $isRecoveredFailureEscalation = $failureEscalation > 0
            && strcasecmp((string) ($fresh['delivery_status'] ?? ''), 'Failed') === 0;
        $isEscalation = $newEscalation > $previousEscalation || $isRecoveredFailureEscalation;
        $method = trim((string) ($source['effective_processing_method'] ?? 'Payment')) ?: 'Payment';
        $poNumber = trim((string) ($source['po_number'] ?? '')) ?: 'N/A';
        $supplier = trim((string) ($source['suppliers_name'] ?? '')) ?: 'Unknown supplier';
        $batchId = (int) ($source['batch_id'] ?? 0);
        $canonicalBatchId = (int) ($source['canonical_batch_id'] ?? 0);
        $route = $config['route'] . '?request_id=' . (int) $source['request_id']
            . '&status=Awaiting%20Confirmation';
        if ($canonicalBatchId > 0) {
            $route .= '&batch_id=' . $canonicalBatchId;
        } elseif ($batchId > 0) {
            $route .= '&legacy_batch_id=' . $batchId
                . '&request_type=' . rawurlencode((string) $source['canonical_request_type']);
        }
        if ($method !== '') {
            $route .= '&processing_method=' . rawurlencode($method);
        }
        $title = $newEscalation > 0
            ? 'Overdue payment confirmation reminder'
            : 'Payment confirmation reminder';
        $recoveryText = $previousFailures > 0
            ? sprintf(' Delivery recovered after %d failed attempt(s).', $previousFailures)
            : '';
        $message = sprintf(
            '%s for %s (%s, PO %s) is still Processing and requires an Account action. Reminder delivery %d.%s',
            $method,
            $supplier,
            $config['label'],
            $poNumber,
            $nextDelivery,
            $recoveryText
        );
        $payload = [
            'reminder_id' => $reminderId,
            'reminder_reference' => $fresh['reminder_reference'],
            'request_type' => $source['request_type'],
            'request_id' => (int) $source['request_id'],
            'batch_id' => $batchId ?: null,
            'canonical_batch_id' => $canonicalBatchId ?: null,
            'batch_item_id' => (int) ($source['batch_item_id'] ?? 0) ?: null,
            'attempt_count' => $nextAttempt,
            'delivery_count' => $nextDelivery,
            'failure_count' => $previousFailures,
            'escalation_level' => $newEscalation,
            'po_number' => $poNumber,
            'supplier' => $supplier,
            'processing_method' => $method,
            'processing_reference' => $source['effective_processing_reference'] ?? null,
            'expected_completion_at' => $expectedAt !== '' ? $expectedAt : null,
            'next_reminder_in_minutes' => (int) $fresh['reminder_interval_minutes'],
            'route' => $route,
        ];
        $delivery = accountNotificationPublishDetailed($conn, [
            'type' => 'payment_confirmation_reminder',
            'action_key' => 'payment_confirmation_reminder',
            'category' => $config['category'],
            'severity' => $newEscalation >= 2 ? 'error' : 'warning',
            'title' => $title,
            'message' => $message,
            'actor' => ['id' => 0, 'email' => 'system'],
            'entity_type' => $config['entity_type'],
            'entity_id' => (string) $source['request_id'],
            'route' => $route,
            'roles' => ['Admin', 'Super_Admin'],
            'department' => 'account',
            'payload' => $payload,
            'dedupe_key' => 'payment-reminder-' . $reminderId . '-attempt-' . $nextAttempt,
        ]);
        if ((int) $delivery['resolved'] <= 0) {
            throw new RuntimeException('No eligible Account notification recipients were found.', 503);
        }

        $escalationDelivery = ['resolved' => 0, 'created' => 0, 'existing' => 0];
        if ($isEscalation) {
            $escalationDelivery = accountNotificationPublishDetailed($conn, [
                'type' => 'payment_confirmation_escalation',
                'action_key' => 'payment_confirmation_escalation',
                'category' => $config['category'],
                'severity' => $newEscalation >= 2 ? 'error' : 'warning',
                'title' => 'Payment confirmation escalation',
                'message' => sprintf(
                    '%s for %s (%s, PO %s) remains Processing after %d reminder deliveries and now requires management attention.',
                    $method,
                    $supplier,
                    $config['label'],
                    $poNumber,
                    $nextDelivery
                ),
                'actor' => ['id' => 0, 'email' => 'system'],
                'entity_type' => $config['entity_type'],
                'entity_id' => (string) $source['request_id'],
                'route' => $route,
                'roles' => ['Admin', 'Super_Admin'],
                'department' => 'account',
                'payload' => array_merge($payload, ['escalated' => true]),
                'dedupe_key' => 'payment-reminder-' . $reminderId . '-escalation-' . $newEscalation,
            ]);
            if ((int) $escalationDelivery['resolved'] <= 0) {
                throw new RuntimeException('No eligible Account escalation recipients were found.', 503);
            }
        }

        $stmt = $conn->prepare(
            "UPDATE account_payment_reminders
             SET lifecycle_status = 'Awaiting Action', delivery_status = 'Delivered',
                 attempt_count = attempt_count + 1, delivery_count = delivery_count + 1,
                 last_attempt_at = NOW(), last_delivered_at = NOW(), last_error = NULL,
                 next_reminder_at = DATE_ADD(NOW(), INTERVAL reminder_interval_minutes MINUTE),
                 escalation_level = GREATEST(escalation_level, ?),
                 escalated_at = CASE WHEN ? > 0 AND escalated_at IS NULL THEN NOW() ELSE escalated_at END,
                 source_status_snapshot = 'Processing', lease_token = NULL, lease_expires_at = NULL,
                 updated_by = 0
             WHERE id = ? AND lease_token = ?"
        );
        $stmt->bind_param('iiis', $newEscalation, $newEscalation, $reminderId, $leaseToken);
        $stmt->execute();
        $stmt->close();
        accountPaymentReminderRecordSourceEvent(
            $conn,
            $source['request_type'],
            (int) $source['request_id'],
            $batchId ?: null,
            'payment_reminder_delivered',
            ['id' => 0, 'email' => 'system'],
            [
                'reminder_id' => $reminderId,
                'attempt_count' => $nextAttempt,
                'delivery_count' => $nextDelivery,
                'recipient_count' => (int) $delivery['resolved'],
                'created_notification_count' => (int) $delivery['created'],
                'existing_notification_count' => (int) $delivery['existing'],
                'recovered_failure_count' => $previousFailures,
                'next_reminder_at_minutes' => (int) $fresh['reminder_interval_minutes'],
            ]
        );
        if ($isEscalation) {
            accountPaymentReminderRecordSourceEvent(
                $conn,
                $source['request_type'],
                (int) $source['request_id'],
                $batchId ?: null,
                'payment_reminder_escalated',
                ['id' => 0, 'email' => 'system'],
                [
                    'reminder_id' => $reminderId,
                    'delivery_count' => $nextDelivery,
                    'failure_count' => $previousFailures,
                    'escalation_level' => $newEscalation,
                    'recipient_count' => (int) $escalationDelivery['resolved'],
                    'created_notification_count' => (int) $escalationDelivery['created'],
                    'existing_notification_count' => (int) $escalationDelivery['existing'],
                ]
            );
        }
        $conn->commit();
        return [
            'status' => 'Delivered',
            'reminder_id' => $reminderId,
            'request_type' => $source['request_type'],
            'request_id' => (int) $source['request_id'],
            'recipient_count' => (int) $delivery['resolved'],
            'created_notification_count' => (int) $delivery['created'],
            'delivery_count' => $nextDelivery,
            'escalation_level' => $newEscalation,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        $failure = accountPaymentReminderMarkFailure($conn, $reminderId, $leaseToken, $error);
        error_log(sprintf(
            'Payment reminder %d delivery failed; retry %s in %d minute(s): %s',
            $reminderId,
            (string) ($failure['failure_count'] ?? 0),
            (int) ($failure['retry_minutes'] ?? 0),
            (string) ($failure['error'] ?? $error->getMessage())
        ));
        if (isset($source) && is_array($source)) {
            try {
                accountPaymentReminderRecordSourceEvent(
                    $conn,
                    $source['request_type'],
                    (int) $source['request_id'],
                    (int) ($source['batch_id'] ?? 0) ?: null,
                    'payment_reminder_delivery_failed',
                    ['id' => 0, 'email' => 'system'],
                    [
                        'reminder_id' => $reminderId,
                        'failure_count' => (int) ($failure['failure_count'] ?? 0),
                        'retry_minutes' => (int) ($failure['retry_minutes'] ?? 0),
                        'next_reminder_at' => $failure['next_reminder_at'] ?? null,
                        'error' => $failure['error'] ?? $error->getMessage(),
                    ]
                );
            } catch (Throwable $eventError) {
                error_log('Unable to record payment reminder failure event: ' . $eventError->getMessage());
            }
        }
        return [
            'status' => 'Retry Scheduled',
            'reminder_id' => $reminderId,
            'reason' => $failure['error'] ?? $error->getMessage(),
            'failure_count' => (int) ($failure['failure_count'] ?? 0),
            'retry_minutes' => (int) ($failure['retry_minutes'] ?? 0),
            'next_reminder_at' => $failure['next_reminder_at'] ?? null,
        ];
    }
}

function accountPaymentReminderProcessDue(mysqli $conn, int $limit = 50): array
{
    accountPaymentReminderEnsureStorage($conn);
    $due = accountPaymentReminderAcquireDue($conn, $limit);
    $result = [
        'acquired' => count($due),
        'delivered' => [],
        'completed' => [],
        'cancelled' => [],
        'rescheduled' => [],
        'retry_scheduled' => [],
        'skipped' => [],
    ];
    foreach ($due as $reminder) {
        $item = accountPaymentReminderDeliver($conn, $reminder);
        $bucket = match ($item['status'] ?? '') {
            'Delivered' => 'delivered',
            'Completed' => 'completed',
            'Cancelled' => 'cancelled',
            'Rescheduled' => 'rescheduled',
            'Retry Scheduled' => 'retry_scheduled',
            default => 'skipped',
        };
        $result[$bucket][] = $item;
    }
    return $result;
}

function accountPaymentReminderReinitiate(
    mysqli $conn,
    mixed $requestType,
    int $requestId,
    string $reason,
    array $actor
): array {
    accountPaymentReminderEnsureStorage($conn);
    $type = accountPaymentReminderNormalizeRequestType($requestType);
    $actor = accountPaymentReminderActor($actor);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required to reinitiate the reminder.', 400);
    }
    if (strlen($reason) > 500) {
        throw new RuntimeException('Reminder reason must not exceed 500 characters.', 400);
    }

    $stmt = $conn->prepare(
        "SELECT * FROM account_payment_reminders
         WHERE request_type = ? AND request_id = ?
         ORDER BY (lifecycle_status NOT IN ('Completed', 'Cancelled')) DESC, id DESC
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('si', $type, $requestId);
    $stmt->execute();
    $reminder = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $source = accountPaymentReminderFetchSource(
        $conn,
        $type,
        $requestId,
        (int) ($reminder['batch_item_id'] ?? 0) ?: null,
        true
    );
    if ($source === null) {
        throw new RuntimeException("$type payment request was not found.", 404);
    }
    if (strcasecmp((string) ($source['payment_status'] ?? ''), 'Processing') !== 0) {
        throw new RuntimeException('Only Processing payment requests can have reminders reinitiated.', 409);
    }
    if (strcasecmp((string) ($source['effective_completion_mode'] ?? ''), 'Notify') !== 0) {
        throw new RuntimeException('This payment request is not configured for reminder confirmation.', 409);
    }

    $batchId = (int) ($source['batch_id'] ?? 0) ?: null;
    $batchItemId = (int) ($source['batch_item_id'] ?? 0) ?: null;

    if ($reminder === null) {
        $reminderId = accountPaymentReminderSchedule(
            $conn,
            $type,
            $requestId,
            $batchId,
            $batchItemId,
            date('Y-m-d H:i:s'),
            [
                'manual_reinitiation' => true,
                'manual_reinitiation_reason' => $reason,
                'manual_reinitiated_by' => $actor['email'],
                'manual_reinitiated_at' => date('Y-m-d H:i:s'),
            ],
            $actor
        );
    } else {
        $reminderId = (int) $reminder['id'];
        $context = accountPaymentReminderContext($reminder['context_json'] ?? null);
        $context['manual_reinitiation_count'] = (int) ($context['manual_reinitiation_count'] ?? 0) + 1;
        $context['last_manual_reinitiation'] = [
            'reason' => $reason,
            'user_id' => $actor['id'],
            'user_email' => $actor['email'],
            'at' => date('Y-m-d H:i:s'),
        ];
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $update = $conn->prepare(
            "UPDATE account_payment_reminders
             SET lifecycle_status = 'Retry Scheduled', delivery_status = 'Pending',
                 next_reminder_at = NOW(), last_error = NULL,
                 lease_token = NULL, lease_expires_at = NULL,
                 completed_at = NULL, completion_reason = NULL, completed_by = NULL,
                 cancelled_at = NULL, cancellation_reason = NULL,
                 source_status_snapshot = 'Processing', context_json = ?, updated_by = ?
             WHERE id = ?"
        );
        $update->bind_param('sii', $contextJson, $actor['id'], $reminderId);
        $update->execute();
        $update->close();
    }

    accountPaymentReminderUpdateSourceAwaitingAction($conn, $source);
    accountPaymentReminderRecordSourceEvent(
        $conn,
        $type,
        $requestId,
        $batchId,
        'payment_reminder_reinitiated',
        $actor,
        [
            'reminder_id' => $reminderId,
            'reason' => $reason,
            'next_reminder_at' => date('Y-m-d H:i:s'),
        ]
    );

    $find = $conn->prepare('SELECT * FROM account_payment_reminders WHERE id = ? LIMIT 1');
    $find->bind_param('i', $reminderId);
    $find->execute();
    $row = $find->get_result()->fetch_assoc() ?: [];
    $find->close();
    return $row;
}
