<?php

declare(strict_types=1);

require_once __DIR__ . '/userNotificationCanonicalRuntimeService.php';

/**
 * Shared AcctLab notification storage and publishing helpers.
 *
 * This service deliberately reuses the existing account_notifications table so
 * payment due notifications and user action notifications remain in one inbox.
 */

function accountNotificationTableExists(mysqli $conn, string $table): bool
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

function accountNotificationColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function accountNotificationEnsureColumn(
    mysqli $conn,
    string $table,
    string $column,
    string $definition
): void {
    if (!accountNotificationColumnExists($conn, $table, $column)
        && !$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        throw new RuntimeException('Unable to update Account notification storage.', 500);
    }
}

function accountNotificationIndexExists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function accountNotificationConnectionInTransaction(mysqli $conn): bool
{
    try {
        $result = $conn->query('SELECT @@session.in_transaction AS active_transaction');
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        return (int) ($row['active_transaction'] ?? 0) === 1;
    } catch (Throwable) {
        return false;
    }
}

function accountNotificationAssertSchemaChangeAllowed(mysqli $conn): void
{
    if (accountNotificationConnectionInTransaction($conn)) {
        throw new RuntimeException(
            'Account notification storage requires deployment before transactional workflows can run.',
            500
        );
    }
}

function accountNotificationEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_FINAL_CANONICAL_TABLE
    ) !== 'BASE TABLE' || !userNotificationCanonicalRuntimeIdentityReady($conn)) {
        throw new RuntimeException(
            'Canonical Account notification storage is not ready. Run the notification deployment before transactional workflows.',
            500
        );
    }

    $ensured = true;
}

function accountNotificationActor(array $actor): array
{
    return [
        'id' => max(0, (int) ($actor['id'] ?? 0)),
        'email' => trim((string) ($actor['email'] ?? 'system')) ?: 'system',
    ];
}

function accountNotificationText(mixed $value, int $max, string $fallback = ''): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return $fallback;
    }
    if (strlen($text) > $max) {
        return substr($text, 0, $max);
    }
    return $text;
}

function accountNotificationRecipientIds(mysqli $conn, array $options = []): array
{
    $explicit = $options['recipient_ids'] ?? null;
    if (is_array($explicit)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $explicit), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT id FROM user_table WHERE id IN ($placeholders) AND status = 'Active'");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $excluded = array_flip(array_map('intval', (array) ($options['exclude_user_ids'] ?? [])));
        return array_values(array_filter(
            array_map(static fn(array $row): int => (int) $row['id'], $rows),
            static fn(int $id): bool => $id > 0 && !isset($excluded[$id])
        ));
    }

    $roles = is_array($options['roles'] ?? null) && $options['roles'] !== []
        ? array_values(array_unique(array_map('strval', $options['roles'])))
        : ['Admin', 'Super_Admin'];
    $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));
    $params = $roles;

    $departmentClause = '';
    if (accountNotificationColumnExists($conn, 'user_table', 'department')) {
        $department = accountNotificationText($options['department'] ?? 'account', 50, 'account');
        $departmentClause = ' AND (LOWER(TRIM(department)) = LOWER(?) OR integrity = \'Super_Admin\')';
        $types .= 's';
        $params[] = $department;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM user_table
         WHERE status = 'Active' AND integrity IN ($rolePlaceholders)$departmentClause
         ORDER BY id ASC"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $excluded = array_flip(array_map('intval', (array) ($options['exclude_user_ids'] ?? [])));
    return array_values(array_filter(
        array_map(static fn(array $row): int => (int) $row['id'], $rows),
        static fn(int $id): bool => $id > 0 && !isset($excluded[$id])
    ));
}

function accountNotificationCanonicalIdentityReady(mysqli $conn): bool
{
    return userNotificationCanonicalRuntimeIdentityReady($conn);
}

/**
 * Publish one notification to every resolved recipient.
 * Manual workflow actions normally leave dedupe_key null so every committed
 * action is represented. Scheduled jobs may provide a dedupe key.
 */
function accountNotificationPublishDetailed(mysqli $conn, array $notification): array
{
    accountNotificationEnsureStorage($conn);

    $type = accountNotificationText($notification['type'] ?? '', 60);
    $title = accountNotificationText($notification['title'] ?? '', 180);
    $message = trim((string) ($notification['message'] ?? ''));
    if ($type === '' || $title === '' || $message === '') {
        throw new RuntimeException('Notification type, title and message are required.', 500);
    }

    $actor = accountNotificationActor((array) ($notification['actor'] ?? []));
    $sourceApp = accountNotificationText($notification['source_app'] ?? 'acctlab', 30, 'acctlab');
    $category = accountNotificationText($notification['category'] ?? 'workflow', 60, 'workflow');
    $severity = strtolower(accountNotificationText($notification['severity'] ?? 'info', 20, 'info'));
    if (!in_array($severity, ['info', 'success', 'warning', 'error'], true)) {
        $severity = 'info';
    }
    $actionKey = accountNotificationText($notification['action_key'] ?? $type, 80, $type);
    $entityType = accountNotificationText($notification['entity_type'] ?? '', 80);
    $entityId = accountNotificationText($notification['entity_id'] ?? '', 120);
    $route = accountNotificationText($notification['route'] ?? '', 255);
    $dedupePrefix = accountNotificationText($notification['dedupe_key'] ?? '', 150);

    $payload = is_array($notification['payload'] ?? null) ? $notification['payload'] : [];
    $payload = array_merge($payload, [
        'source_app' => $sourceApp,
        'category' => $category,
        'severity' => $severity,
        'action_key' => $actionKey,
        'actor' => $actor,
        'entity_type' => $entityType !== '' ? $entityType : null,
        'entity_id' => $entityId !== '' ? $entityId : null,
        'route' => $route !== '' ? $route : ($payload['route'] ?? null),
    ]);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        throw new RuntimeException('Unable to encode notification context.', 500);
    }

    $recipients = accountNotificationRecipientIds($conn, $notification);
    if ($recipients === []) {
        return ['resolved' => 0, 'created' => 0, 'existing' => 0];
    }

    if (!userNotificationCanonicalRuntimeEnabled($conn)) {
        throw new RuntimeException('Canonical Account notification runtime is not healthy.', 500);
    }
    $storageTable = userNotificationCanonicalRuntimeStorageTable($conn);
    $managesTransaction = false;
    $publicIdLock = null;
    if (!accountNotificationConnectionInTransaction($conn)) {
        $conn->begin_transaction();
        $managesTransaction = true;
    }

    $created = 0;
    $stmt = null;
    try {
        $publicIdLock = userNotificationCanonicalAcquirePublicIdLock($conn, 'acctlab');
        $nextPublicId = userNotificationCanonicalNextPublicId($conn, 'acctlab');
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO `{$storageTable}`
                (inbox_app, inbox_notification_id, recipient_user_id, source_app,
                 notification_type, category, severity, action_key, title, message,
                 actor_user_id, actor_email, entity_type, entity_id, route,
                 payload_json, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare Account notification.', 500);
        }

        foreach ($recipients as $recipientId) {
            $dedupe = $dedupePrefix !== '' ? $dedupePrefix . ':' . $recipientId : null;
            $inboxApp = 'acctlab';
            $publicId = $nextPublicId++;
            $stmt->bind_param(
                'siisssssssissssss',
                $inboxApp,
                $publicId,
                $recipientId,
                $sourceApp,
                $type,
                $category,
                $severity,
                $actionKey,
                $title,
                $message,
                $actor['id'],
                $actor['email'],
                $entityType,
                $entityId,
                $route,
                $payloadJson,
                $dedupe
            );
            $stmt->execute();
            $created += max(0, $stmt->affected_rows);
        }

        if ($managesTransaction) {
            $conn->commit();
        }
    } catch (Throwable $error) {
        if ($managesTransaction) {
            $conn->rollback();
        }
        throw $error;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        if (is_string($publicIdLock) && $publicIdLock !== '') {
            userNotificationCanonicalReleasePublicIdLock($conn, $publicIdLock);
        }
    }

    $resolved = count($recipients);
    return [
        'resolved' => $resolved,
        'created' => $created,
        'existing' => max(0, $resolved - $created),
    ];
}

function accountNotificationPublish(mysqli $conn, array $notification): int
{
    return (int) accountNotificationPublishDetailed($conn, $notification)['created'];
}

function accountNotificationPublishSupplierAction(
    mysqli $conn,
    string $actionKey,
    string $title,
    string $message,
    array $requestIds,
    array $actor,
    string $route,
    string $severity = 'info',
    array $payload = [],
    ?string $dedupeKey = null
): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn(int $id): bool => $id > 0)));
    $entityId = count($ids) === 1 ? (string) $ids[0] : (count($ids) . '-requests');

    return accountNotificationPublish($conn, [
        'type' => $actionKey,
        'action_key' => $actionKey,
        'category' => 'supplier_fund_request',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actor,
        'entity_type' => 'supplier_fund_request',
        'entity_id' => $entityId,
        'route' => $route,
        'payload' => array_merge($payload, ['request_ids' => $ids]),
        'dedupe_key' => $dedupeKey,
    ]);
}
