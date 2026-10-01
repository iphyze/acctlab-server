<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementNotificationService.php';

function procurementNotificationBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }
    $stmt->bind_param($types, ...$params);
}

try {
    procurementEnsureAuthenticationTables($conn);
    procurementNotificationEnsureStorage($conn);
    $user = procurementAuthenticateUser($conn);
    $userId = (int) $user['id'];
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $canonicalRuntime = userNotificationCanonicalRuntimeEnabled($conn);
    $notificationTable = $canonicalRuntime
        ? userNotificationCanonicalRuntimeStorageTable($conn)
        : USER_NOTIFICATION_PROCUREMENT_TABLE;
    $publicIdExpression = $canonicalRuntime
        ? 'inbox_notification_id'
        : 'id';

    if ($method === 'GET') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
        $unreadOnly = filter_var($_GET['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $readStatus = strtolower(trim((string) ($_GET['read_status'] ?? 'all')));
        $sourceApp = strtolower(trim((string) ($_GET['source_app'] ?? 'all')));
        $category = strtolower(trim((string) ($_GET['category'] ?? 'all')));
        $severity = strtolower(trim((string) ($_GET['severity'] ?? 'all')));
        $search = trim((string) ($_GET['search'] ?? ''));

        $conditions = ['recipient_user_id = ?'];
        $types = 'i';
        $params = [$userId];
        if ($canonicalRuntime) {
            $conditions[] = "BINARY inbox_app = BINARY 'procuredesk'";
        }

        if ($unreadOnly || $readStatus === 'unread') {
            $conditions[] = 'read_at IS NULL';
        } elseif ($readStatus === 'read') {
            $conditions[] = 'read_at IS NOT NULL';
        }
        if ($sourceApp !== '' && $sourceApp !== 'all') {
            $conditions[] = 'LOWER(source_app) = ?';
            $types .= 's';
            $params[] = $sourceApp;
        }
        if ($category !== '' && $category !== 'all') {
            $conditions[] = 'LOWER(category) = ?';
            $types .= 's';
            $params[] = $category;
        }
        if ($severity !== '' && $severity !== 'all') {
            $conditions[] = 'LOWER(severity) = ?';
            $types .= 's';
            $params[] = $severity;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(title LIKE ? OR message LIKE ? OR actor_email LIKE ? OR entity_id LIKE ?)';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $conditions);
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM `{$notificationTable}` WHERE {$whereSql}"
        );
        procurementNotificationBind($countStmt, $types, $params);
        $countStmt->execute();
        $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $pages = max(1, (int) ceil($total / $limit));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $limit;
        }

        $dataSql = "SELECT {$publicIdExpression} AS id, source_app, notification_type,
                           category, severity, action_key, title, message,
                           actor_user_id, actor_email, entity_type, entity_id,
                           route, payload_json, read_at, created_at
                    FROM `{$notificationTable}`
                    WHERE {$whereSql}
                    ORDER BY created_at DESC, {$publicIdExpression} DESC
                    LIMIT ? OFFSET ?";
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $conn->prepare($dataSql);
        procurementNotificationBind($stmt, $dataTypes, $dataParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['actor_user_id'] = (int) ($row['actor_user_id'] ?? 0);
            $row['payload'] = $row['payload_json']
                ? json_decode((string) $row['payload_json'], true)
                : [];
            if (!is_array($row['payload'])) {
                $row['payload'] = [];
            }
            if (!empty($row['route']) && empty($row['payload']['route'])) {
                $row['payload']['route'] = $row['route'];
            }
            unset($row['payload_json']);
        }
        unset($row);

        $statsInboxSql = $canonicalRuntime
            ? " AND BINARY inbox_app = BINARY 'procuredesk'"
            : '';
        $stats = $conn->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN LOWER(source_app) = 'procuredesk' THEN 1 ELSE 0 END) AS procuredesk,
                    SUM(CASE WHEN LOWER(source_app) = 'acctlab' THEN 1 ELSE 0 END) AS acctlab
             FROM `{$notificationTable}`
             WHERE recipient_user_id = ?{$statsInboxSql}"
        );
        $stats->bind_param('i', $userId);
        $stats->execute();
        $statsRow = $stats->get_result()->fetch_assoc() ?: [];
        $stats->close();

        $unread = (int) ($statsRow['unread'] ?? 0);
        jsonResponse([
            'status' => 'Success',
            'data' => $rows,
            'meta' => [
                'unread' => $unread,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pages,
                'stats' => [
                    'total' => (int) ($statsRow['total'] ?? 0),
                    'unread' => $unread,
                    'procuredesk' => (int) ($statsRow['procuredesk'] ?? 0),
                    'acctlab' => (int) ($statsRow['acctlab'] ?? 0),
                ],
            ],
        ]);
    }

    if (in_array($method, ['PATCH', 'PUT'], true)) {
        procurementRequireCsrfToken();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $inboxUpdateSql = $canonicalRuntime
            ? " AND BINARY inbox_app = BINARY 'procuredesk'"
            : '';
        if (($data['all'] ?? false) === true) {
            $stmt = $conn->prepare(
                "UPDATE `{$notificationTable}`
                 SET read_at = COALESCE(read_at, NOW())
                 WHERE recipient_user_id = ?{$inboxUpdateSql}"
            );
            $stmt->bind_param('i', $userId);
        } else {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array) ($data['ids'] ?? [])),
                static fn(int $id): bool => $id > 0
            )));
            if ($ids === []) {
                throw new RuntimeException('Select at least one notification.', 400);
            }
            if (count($ids) > 100) {
                throw new RuntimeException('A maximum of 100 notifications can be updated at once.', 400);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = 'i' . str_repeat('i', count($ids));
            $params = array_merge([$userId], $ids);
            $stmt = $conn->prepare(
                "UPDATE `{$notificationTable}`
                 SET read_at = COALESCE(read_at, NOW())
                 WHERE recipient_user_id = ?{$inboxUpdateSql}
                   AND {$publicIdExpression} IN ({$placeholders})"
            );
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $updated = $stmt->affected_rows;
        $stmt->close();
        jsonResponse([
            'status' => 'Success',
            'message' => "{$updated} notification(s) marked as read.",
        ]);
    }

    throw new RuntimeException('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('ProcureDesk notification error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to process ProcureDesk notifications.'
            : $error->getMessage(),
    ], $status);
}
