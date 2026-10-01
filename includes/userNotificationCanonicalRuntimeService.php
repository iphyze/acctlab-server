<?php

declare(strict_types=1);

require_once __DIR__ . '/userNotificationCanonicalMetadataService.php';
require_once __DIR__ . '/databaseIdentityService.php';

const USER_NOTIFICATION_RUNTIME_INSERT_TRIGGER =
    'trg_user_notification_canonical_procurement_ai';
const USER_NOTIFICATION_RUNTIME_UPDATE_TRIGGER =
    'trg_user_notification_canonical_procurement_au';
const USER_NOTIFICATION_RUNTIME_EXPECTED_TRIGGER_COUNT = 2;

function userNotificationCanonicalRuntimeTriggerNames(): array
{
    return [
        USER_NOTIFICATION_RUNTIME_INSERT_TRIGGER,
        USER_NOTIFICATION_RUNTIME_UPDATE_TRIGGER,
    ];
}

function userNotificationCanonicalRuntimeStorageTable(mysqli $conn): string
{
    if (userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_FINAL_CANONICAL_TABLE
    ) === 'BASE TABLE') {
        return USER_NOTIFICATION_FINAL_CANONICAL_TABLE;
    }

    return USER_NOTIFICATION_ACCOUNT_TABLE;
}

function userNotificationCanonicalRuntimeStorageColumns(mysqli $conn): array
{
    return userNotificationConsolidationColumns(
        $conn,
        userNotificationCanonicalRuntimeStorageTable($conn)
    );
}

function userNotificationCanonicalRuntimeIdentityReady(mysqli $conn): bool
{
    $columns = userNotificationCanonicalRuntimeStorageColumns($conn);
    return in_array('inbox_app', $columns, true)
        && in_array('inbox_notification_id', $columns, true);
}

function userNotificationCanonicalRuntimeScalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Canonical notification runtime query failed: ' . $conn->error);
    }
    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function userNotificationCanonicalRuntimeTriggerCount(mysqli $conn): int
{
    $names = userNotificationCanonicalRuntimeTriggerNames();
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN ({$placeholders})"
    );
    $types = str_repeat('s', count($names));
    $stmt->bind_param($types, ...$names);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function userNotificationCanonicalRuntimeDropBridge(mysqli $conn): void
{
    foreach (userNotificationCanonicalRuntimeTriggerNames() as $trigger) {
        if (!$conn->query("DROP TRIGGER IF EXISTS `{$trigger}`")) {
            throw new RuntimeException(
                'Unable to remove notification compatibility trigger: ' . $conn->error
            );
        }
    }
}

function userNotificationCanonicalRuntimeLegacyColumns(): array
{
    return userNotificationConsolidationExpectedInboxColumns();
}

function userNotificationCanonicalRuntimeTriggerSql(
    string $trigger,
    string $event
): string {
    $event = strtoupper($event);
    if (!in_array($event, ['INSERT', 'UPDATE'], true)) {
        throw new InvalidArgumentException('Unsupported notification compatibility trigger event.');
    }

    $columns = userNotificationCanonicalRuntimeLegacyColumns();
    $quotedColumns = implode(', ', array_map(
        static fn(string $column): string => "`{$column}`",
        $columns
    ));
    $values = [];
    foreach ($columns as $column) {
        $values[] = $column === 'id'
            ? 'NEW.inbox_notification_id'
            : "NEW.`{$column}`";
    }
    $updates = implode(",\n            ", array_map(
        static fn(string $column): string => "`{$column}` = VALUES(`{$column}`)",
        array_values(array_filter(
            $columns,
            static fn(string $column): bool => $column !== 'id'
        ))
    ));
    $table = userNotificationCanonicalRuntimeStaticPreparationTable();

    return "CREATE TRIGGER `{$trigger}` AFTER {$event}\n"
        . "ON `{$table}` FOR EACH ROW\n"
        . "BEGIN\n"
        . "    IF BINARY NEW.inbox_app = BINARY 'procuredesk' THEN\n"
        . "        INSERT INTO `" . USER_NOTIFICATION_PROCUREMENT_TABLE . "`\n"
        . "            ({$quotedColumns})\n"
        . "        VALUES (" . implode(', ', $values) . ")\n"
        . "        ON DUPLICATE KEY UPDATE\n            {$updates};\n"
        . "    END IF;\n"
        . "END";
}

/**
 * The temporary bridge is created before the final canonical table rename, so
 * its trigger target is intentionally the prepared Account notification table.
 */
function userNotificationCanonicalRuntimeStaticPreparationTable(): string
{
    return USER_NOTIFICATION_ACCOUNT_TABLE;
}

function userNotificationCanonicalRuntimeCreateBridge(mysqli $conn): void
{
    userNotificationCanonicalRuntimeDropBridge($conn);
    $definitions = [
        USER_NOTIFICATION_RUNTIME_INSERT_TRIGGER => 'INSERT',
        USER_NOTIFICATION_RUNTIME_UPDATE_TRIGGER => 'UPDATE',
    ];
    foreach ($definitions as $trigger => $event) {
        $sql = userNotificationCanonicalRuntimeTriggerSql($trigger, $event);
        if (!$conn->query($sql)) {
            throw new RuntimeException(
                'Unable to create notification compatibility trigger ' . $trigger
                . ': ' . $conn->error
            );
        }
    }
}

function userNotificationCanonicalRuntimeCopyProcuredeskRows(mysqli $conn): int
{
    $table = userNotificationCanonicalRuntimeStorageTable($conn);
    if ($table !== USER_NOTIFICATION_ACCOUNT_TABLE) {
        throw new RuntimeException(
            'ProcureDesk notification migration is only supported before final table cleanup.'
        );
    }

    $legacyColumns = userNotificationCanonicalRuntimeLegacyColumns();
    $canonicalColumns = array_merge(
        ['inbox_app', 'inbox_notification_id'],
        array_values(array_filter(
            $legacyColumns,
            static fn(string $column): bool => $column !== 'id'
        ))
    );
    $quotedCanonicalColumns = implode(', ', array_map(
        static fn(string $column): string => "`{$column}`",
        $canonicalColumns
    ));
    $selectExpressions = ["'procuredesk'", 'legacy.id'];
    foreach ($legacyColumns as $column) {
        if ($column === 'id') {
            continue;
        }
        $selectExpressions[] = "legacy.`{$column}`";
    }
    $updates = implode(",\n            ", array_map(
        static fn(string $column): string => "`{$column}` = VALUES(`{$column}`)",
        array_values(array_filter(
            $canonicalColumns,
            static fn(string $column): bool => !in_array(
                $column,
                ['inbox_app', 'inbox_notification_id'],
                true
            )
        ))
    ));

    $sql = "INSERT INTO `{$table}` ({$quotedCanonicalColumns})\n"
        . "SELECT " . implode(', ', $selectExpressions) . "\n"
        . "FROM `" . USER_NOTIFICATION_PROCUREMENT_TABLE . "` legacy\n"
        . "ON DUPLICATE KEY UPDATE\n            {$updates}";
    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'Unable to copy ProcureDesk notifications into canonical storage: ' . $conn->error
        );
    }
    return (int) $conn->affected_rows;
}


function userNotificationCanonicalRuntimeRefreshProcuredeskMirror(mysqli $conn): int
{
    $storageTable = userNotificationCanonicalRuntimeStorageTable($conn);
    if (userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_PROCUREMENT_TABLE
    ) !== 'BASE TABLE') {
        throw new RuntimeException(
            'ProcureDesk notification mirror must be a base table before refresh.'
        );
    }

    $columns = userNotificationCanonicalRuntimeLegacyColumns();
    $quotedColumns = implode(', ', array_map(
        static fn(string $column): string => "`{$column}`",
        $columns
    ));
    $select = [];
    foreach ($columns as $column) {
        $select[] = $column === 'id'
            ? 'canonical.inbox_notification_id'
            : "canonical.`{$column}`";
    }
    $updates = implode(",\n            ", array_map(
        static fn(string $column): string => "`{$column}` = VALUES(`{$column}`)",
        array_values(array_filter(
            $columns,
            static fn(string $column): bool => $column !== 'id'
        ))
    ));

    $sql = "INSERT INTO `" . USER_NOTIFICATION_PROCUREMENT_TABLE . "`"
        . " ({$quotedColumns})\n"
        . "SELECT " . implode(', ', $select) . "\n"
        . "FROM `{$storageTable}` canonical\n"
        . "WHERE BINARY canonical.inbox_app = BINARY 'procuredesk'\n"
        . "ON DUPLICATE KEY UPDATE\n            {$updates}";
    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'Unable to refresh the ProcureDesk notification mirror: ' . $conn->error
        );
    }
    return (int) $conn->affected_rows;
}

function userNotificationCanonicalRuntimeTextMismatchSql(
    string $canonicalAlias,
    string $legacyAlias,
    string $column
): string {
    return "NOT (BINARY {$canonicalAlias}.`{$column}` <=> "
        . "BINARY {$legacyAlias}.`{$column}`)";
}

function userNotificationCanonicalRuntimeFieldMismatchCondition(): string
{
    $numericColumns = ['recipient_user_id', 'actor_user_id'];
    $dateColumns = ['read_at', 'created_at'];
    $conditions = [];
    foreach (userNotificationCanonicalRuntimeLegacyColumns() as $column) {
        if ($column === 'id') {
            continue;
        }
        if (in_array($column, $numericColumns, true)
            || in_array($column, $dateColumns, true)) {
            $conditions[] = "NOT (canonical.`{$column}` <=> legacy.`{$column}`)";
        } else {
            $conditions[] = userNotificationCanonicalRuntimeTextMismatchSql(
                'canonical',
                'legacy',
                $column
            );
        }
    }
    return implode("\n                OR ", $conditions);
}


function userNotificationCanonicalRuntimeCompatibilityViewColumns(): array
{
    return userNotificationCanonicalRuntimeLegacyColumns();
}

function userNotificationCanonicalRuntimeCompatibilityProjection(): array
{
    $expressions = [];
    foreach (userNotificationCanonicalRuntimeCompatibilityViewColumns() as $column) {
        $expressions[$column] = $column === 'id'
            ? 'canonical.inbox_notification_id'
            : "canonical.`{$column}`";
    }
    return $expressions;
}

function userNotificationCanonicalRuntimeCompatibilityViewSql(
    string $view,
    string $inboxApp
): string {
    if (!in_array($view, [
        USER_NOTIFICATION_ACCOUNT_TABLE,
        USER_NOTIFICATION_PROCUREMENT_TABLE,
    ], true)) {
        throw new InvalidArgumentException('Unsupported notification compatibility view.');
    }
    if (!in_array($inboxApp, ['acctlab', 'procuredesk'], true)) {
        throw new InvalidArgumentException('Unsupported notification inbox.');
    }

    $select = [];
    foreach (userNotificationCanonicalRuntimeCompatibilityProjection() as $column => $expression) {
        $select[] = "{$expression} AS `{$column}`";
    }

    return "CREATE ALGORITHM=MERGE VIEW `{$view}` AS\n"
        . "SELECT\n    " . implode(",\n    ", $select) . "\n"
        . "FROM `" . USER_NOTIFICATION_FINAL_CANONICAL_TABLE . "` canonical\n"
        . "WHERE BINARY canonical.inbox_app = BINARY '{$inboxApp}'";
}

function userNotificationCanonicalRuntimeCompatibilityViewShapeMatches(
    mysqli $conn,
    string $view
): bool {
    return userNotificationConsolidationColumns($conn, $view)
        === userNotificationCanonicalRuntimeCompatibilityViewColumns();
}

function userNotificationCanonicalRuntimeCompatibilityIntegrity(
    mysqli $conn,
    string $view,
    string $inboxApp
): array {
    $storageTable = userNotificationCanonicalRuntimeStorageTable($conn);
    $viewType = userNotificationConsolidationObjectType($conn, $view);
    if ($viewType === null || !userNotificationCanonicalRuntimeIdentityReady($conn)) {
        return [
            'healthy' => false,
            'canonical_rows' => null,
            'compatibility_rows' => null,
            'missing_rows' => null,
            'orphan_rows' => null,
            'field_mismatches' => null,
        ];
    }

    $canonicalRows = userNotificationCanonicalRuntimeScalar(
        $conn,
        "SELECT COUNT(*) AS total FROM `{$storageTable}`
         WHERE BINARY inbox_app = BINARY '{$inboxApp}'"
    );
    $compatibilityRows = userNotificationCanonicalRuntimeScalar(
        $conn,
        "SELECT COUNT(*) AS total FROM `{$view}`"
    );
    $missingRows = userNotificationCanonicalRuntimeScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM `{$storageTable}` canonical
         LEFT JOIN `{$view}` compatibility
           ON compatibility.id = canonical.inbox_notification_id
         WHERE BINARY canonical.inbox_app = BINARY '{$inboxApp}'
           AND compatibility.id IS NULL"
    );
    $orphanRows = userNotificationCanonicalRuntimeScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM `{$view}` compatibility
         LEFT JOIN `{$storageTable}` canonical
           ON BINARY canonical.inbox_app = BINARY '{$inboxApp}'
          AND canonical.inbox_notification_id = compatibility.id
         WHERE canonical.id IS NULL"
    );
    $fieldMismatches = userNotificationCanonicalRuntimeScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM `{$storageTable}` canonical
         INNER JOIN `{$view}` legacy
           ON legacy.id = canonical.inbox_notification_id
         WHERE BINARY canonical.inbox_app = BINARY '{$inboxApp}'
           AND (" . userNotificationCanonicalRuntimeFieldMismatchCondition() . ')'
    );

    return [
        'healthy' => $canonicalRows === $compatibilityRows
            && $missingRows === 0
            && $orphanRows === 0
            && $fieldMismatches === 0,
        'canonical_rows' => $canonicalRows,
        'compatibility_rows' => $compatibilityRows,
        'missing_rows' => $missingRows,
        'orphan_rows' => $orphanRows,
        'field_mismatches' => $fieldMismatches,
    ];
}

function userNotificationCanonicalRuntimeStoragePhase(mysqli $conn): string
{
    $canonicalType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_FINAL_CANONICAL_TABLE
    );
    $accountType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_ACCOUNT_TABLE
    );
    $procurementType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_PROCUREMENT_TABLE
    );
    $temporaryType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_PROCUREMENT_CLEANUP_TEMP_TABLE
    );
    $triggerCount = userNotificationCanonicalRuntimeTriggerCount($conn);

    if ($canonicalType === null
        && $accountType === 'BASE TABLE'
        && $procurementType === 'BASE TABLE'
        && $temporaryType === null
        && $triggerCount === USER_NOTIFICATION_RUNTIME_EXPECTED_TRIGGER_COUNT) {
        return 'canonical_with_temporary_procurement_mirror';
    }
    if ($canonicalType === 'BASE TABLE'
        && $accountType === 'VIEW'
        && $procurementType === 'VIEW'
        && $temporaryType === 'BASE TABLE'
        && $triggerCount === 0) {
        return 'canonical_with_compatibility_views_staged';
    }
    if ($canonicalType === 'BASE TABLE'
        && $accountType === 'VIEW'
        && $procurementType === 'VIEW'
        && $temporaryType === null
        && $triggerCount === 0) {
        return 'canonical_with_compatibility_views';
    }
    if ($canonicalType === 'BASE TABLE'
        && $accountType === null
        && $procurementType === null
        && $temporaryType === null
        && $triggerCount === 0) {
        return 'canonical_views_retired';
    }
    if ($canonicalType === null
        && $accountType === 'BASE TABLE'
        && $procurementType === 'BASE TABLE'
        && $temporaryType === null
        && $triggerCount === 0) {
        return 'prepared_legacy_inboxes';
    }
    return 'unexpected';
}

function userNotificationCanonicalRuntimeVerify(mysqli $conn): array
{
    $storageTable = userNotificationCanonicalRuntimeStorageTable($conn);
    $storageType = userNotificationConsolidationObjectType($conn, $storageTable);
    $canonicalType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_FINAL_CANONICAL_TABLE
    );
    $accountType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_ACCOUNT_TABLE
    );
    $procurementType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_PROCUREMENT_TABLE
    );
    $temporaryType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_PROCUREMENT_CLEANUP_TEMP_TABLE
    );
    $reminderType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_REMINDER_TABLE
    );
    $runType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_REMINDER_RUN_TABLE
    );
    $phase = userNotificationCanonicalRuntimeStoragePhase($conn);
    $triggerCount = userNotificationCanonicalRuntimeTriggerCount($conn);
    $baseTableCount = userNotificationConsolidationBaseTableCount($conn);
    $identityReady = userNotificationCanonicalRuntimeIdentityReady($conn);
    $compatibilityViewPhase = in_array($phase, [
        'canonical_with_compatibility_views_staged',
        'canonical_with_compatibility_views',
    ], true);

    $expectedTriggerCount = $phase === 'canonical_with_temporary_procurement_mirror'
        ? USER_NOTIFICATION_RUNTIME_EXPECTED_TRIGGER_COUNT
        : (in_array($phase, [
            'canonical_with_compatibility_views_staged',
            'canonical_with_compatibility_views',
            'canonical_views_retired',
        ], true) ? 0 : null);
    $expectedBaseTableCount = in_array($phase, [
        'canonical_with_compatibility_views',
        'canonical_views_retired',
    ], true)
        ? USER_NOTIFICATION_EXPECTED_FINAL_BASE_TABLE_COUNT
        : USER_NOTIFICATION_EXPECTED_BASE_TABLE_COUNT;

    $acctlabCanonicalRows = $identityReady
        ? userNotificationCanonicalRuntimeScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM `{$storageTable}`
             WHERE BINARY inbox_app = BINARY 'acctlab'"
        )
        : 0;
    $procuredeskCanonicalRows = $identityReady
        ? userNotificationCanonicalRuntimeScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM `{$storageTable}`
             WHERE BINARY inbox_app = BINARY 'procuredesk'"
        )
        : 0;

    $acctlabCompatibility = $compatibilityViewPhase
        ? userNotificationCanonicalRuntimeCompatibilityIntegrity(
            $conn,
            USER_NOTIFICATION_ACCOUNT_TABLE,
            'acctlab'
        )
        : [
            'healthy' => true,
            'canonical_rows' => $acctlabCanonicalRows,
            'compatibility_rows' => $acctlabCanonicalRows,
            'missing_rows' => 0,
            'orphan_rows' => 0,
            'field_mismatches' => 0,
        ];
    $procuredeskCompatibility = in_array($phase, [
        'canonical_with_temporary_procurement_mirror',
        'canonical_with_compatibility_views_staged',
        'canonical_with_compatibility_views',
    ], true)
        ? userNotificationCanonicalRuntimeCompatibilityIntegrity(
            $conn,
            USER_NOTIFICATION_PROCUREMENT_TABLE,
            'procuredesk'
        )
        : [
            'healthy' => $phase === 'canonical_views_retired',
            'canonical_rows' => $procuredeskCanonicalRows,
            'compatibility_rows' => $phase === 'canonical_views_retired' ? $procuredeskCanonicalRows : null,
            'missing_rows' => $phase === 'canonical_views_retired' ? 0 : null,
            'orphan_rows' => $phase === 'canonical_views_retired' ? 0 : null,
            'field_mismatches' => $phase === 'canonical_views_retired' ? 0 : null,
        ];

    $missingPublicIdentity = $identityReady
        ? userNotificationCanonicalRuntimeScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM `{$storageTable}`
             WHERE inbox_notification_id IS NULL
                OR TRIM(inbox_app) = ''
                OR LOWER(TRIM(inbox_app)) NOT IN ('acctlab', 'procuredesk')"
        )
        : null;
    $duplicatePublicIds = $identityReady
        ? userNotificationCanonicalRuntimeScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM (
                 SELECT inbox_app, inbox_notification_id
                 FROM `{$storageTable}`
                 GROUP BY inbox_app, inbox_notification_id
                 HAVING COUNT(*) > 1
             ) duplicate_rows"
        )
        : null;
    $duplicateDedupeKeys = $identityReady
        ? userNotificationCanonicalRuntimeScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM (
                 SELECT inbox_app, recipient_user_id, dedupe_key
                 FROM `{$storageTable}`
                 WHERE dedupe_key IS NOT NULL
                 GROUP BY inbox_app, recipient_user_id, dedupe_key
                 HAVING COUNT(*) > 1
             ) duplicate_rows"
        )
        : null;

    $supportedPhase = in_array($phase, [
        'canonical_with_temporary_procurement_mirror',
        'canonical_with_compatibility_views_staged',
        'canonical_with_compatibility_views',
        'canonical_views_retired',
    ], true);
    $viewShapesMatch = !$compatibilityViewPhase || (
        userNotificationCanonicalRuntimeCompatibilityViewShapeMatches(
            $conn,
            USER_NOTIFICATION_ACCOUNT_TABLE
        )
        && userNotificationCanonicalRuntimeCompatibilityViewShapeMatches(
            $conn,
            USER_NOTIFICATION_PROCUREMENT_TABLE
        )
    );
    $checks = [
        'supported_runtime_phase' => $supportedPhase,
        'canonical_storage_is_base_table' => $storageType === 'BASE TABLE',
        'canonical_identity_columns_ready' => $identityReady,
        'reminder_operational_tables_retained' =>
            $reminderType === 'BASE TABLE' && $runType === 'BASE TABLE',
        'expected_compatibility_object_types' =>
            ($phase === 'canonical_with_temporary_procurement_mirror'
                && $canonicalType === null
                && $accountType === 'BASE TABLE'
                && $procurementType === 'BASE TABLE'
                && $temporaryType === null)
            || ($compatibilityViewPhase
                && $canonicalType === 'BASE TABLE'
                && $accountType === 'VIEW'
                && $procurementType === 'VIEW')
            || ($phase === 'canonical_views_retired'
                && $canonicalType === 'BASE TABLE'
                && $accountType === null
                && $procurementType === null
                && $temporaryType === null),
        'expected_temporary_bridge_state' =>
            $expectedTriggerCount !== null && $triggerCount === $expectedTriggerCount,
        'compatibility_view_shapes_match' => $viewShapesMatch,
        'acctlab_compatibility_integrity_healthy' =>
            (bool) ($acctlabCompatibility['healthy'] ?? false),
        'procuredesk_compatibility_integrity_healthy' =>
            (bool) ($procuredeskCompatibility['healthy'] ?? false),
        'all_canonical_rows_have_public_identity' => $missingPublicIdentity === 0,
        'no_duplicate_public_ids_per_inbox' => $duplicatePublicIds === 0,
        'no_duplicate_dedupe_keys_per_inbox' => $duplicateDedupeKeys === 0,
        // Runtime health must not fail when unrelated, explicitly approved schema
        // additions increase the application's base-table count. The historical
        // count remains a minimum safety floor; notification-specific object
        // type/identity checks above still enforce the consolidation contract.
        'expected_base_table_count' => $baseTableCount >= $expectedBaseTableCount,
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'healthy' => $healthy,
        'ready_for_runtime_testing' => $healthy,
        'storage_phase' => $phase,
        'checks' => $checks,
        'runtime_sources' => [
            'canonical_table' => $storageTable,
            'acctlab_inbox_filter' => 'acctlab',
            'procuredesk_inbox_filter' => 'procuredesk',
            'temporary_procuredesk_mirror' =>
                $phase === 'canonical_with_temporary_procurement_mirror'
                    ? USER_NOTIFICATION_PROCUREMENT_TABLE
                    : null,
            'procuredesk_recovery_snapshot' =>
                $phase === 'canonical_with_compatibility_views_staged'
                    ? USER_NOTIFICATION_PROCUREMENT_CLEANUP_TEMP_TABLE
                    : null,
            'payment_reminders' => USER_NOTIFICATION_REMINDER_TABLE,
            'payment_reminder_runs' => USER_NOTIFICATION_REMINDER_RUN_TABLE,
        ],
        'object_types' => [
            'canonical_storage' => $storageType,
            'notifications' => $canonicalType,
            'account_notifications' => $accountType,
            'procurement_notifications' => $procurementType,
            'procurement_recovery_snapshot' => $temporaryType,
            'payment_reminders' => $reminderType,
            'payment_reminder_runs' => $runType,
        ],
        'trigger_count' => $triggerCount,
        'expected_trigger_count' => $expectedTriggerCount,
        'counts' => [
            'acctlab_canonical_rows' => $acctlabCanonicalRows,
            'acctlab_compatibility_rows' =>
                $acctlabCompatibility['compatibility_rows'] ?? null,
            'procuredesk_canonical_rows' => $procuredeskCanonicalRows,
            'procuredesk_compatibility_rows' =>
                $procuredeskCompatibility['compatibility_rows'] ?? null,
            'canonical_total' => $acctlabCanonicalRows + $procuredeskCanonicalRows,
        ],
        'integrity' => [
            'acctlab' => $acctlabCompatibility,
            'procuredesk' => $procuredeskCompatibility,
            'missing_public_identity' => $missingPublicIdentity,
            'duplicate_public_ids' => $duplicatePublicIds,
            'duplicate_scoped_dedupe_keys' => $duplicateDedupeKeys,
        ],
        'base_table_count' => $baseTableCount,
        'expected_base_table_count' => $expectedBaseTableCount,
        'table_count_change' =>
            $phase === 'canonical_with_compatibility_views' ? -1 : 0,
    ];
}

function userNotificationCanonicalRuntimeEnabled(mysqli $conn): bool
{
    static $cache = [];
    $key = spl_object_id($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $cache[$key] = (userNotificationCanonicalRuntimeVerify($conn)['healthy'] ?? false) === true;
    } catch (Throwable) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function userNotificationCanonicalRuntimePlan(mysqli $conn): array
{
    $canonicalType = userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_FINAL_CANONICAL_TABLE
    );

    // Once final canonical storage is active, historical preparation assets are
    // intentionally no longer required.  Report the active state without trying
    // to replay the old two-table migration plan.
    if ($canonicalType === 'BASE TABLE') {
        $verification = userNotificationCanonicalRuntimeVerify($conn);
        $healthy = ($verification['healthy'] ?? false) === true;
        return [
            'status' => $healthy ? 'success' : 'blocked',
            'mode' => 'plan',
            'verification' => [
                'healthy' => $healthy,
                'ready_for_runtime_cutover' => false,
                'runtime_already_active' => true,
                'historical_preparation_assets_required' => false,
                'runtime_verification' => $verification,
                'base_table_count' => userNotificationConsolidationBaseTableCount($conn),
            ],
            'runtime_cutover' => [
                'canonical_table' => USER_NOTIFICATION_FINAL_CANONICAL_TABLE,
                'acctlab_inbox' => 'acctlab',
                'procuredesk_inbox' => 'procuredesk',
                'already_active' => true,
                'public_id_allocation' => 'per-inbox named lock and scoped maximum',
                'account_endpoint_unchanged' => '/account/notifications',
                'procurement_endpoint_unchanged' => '/procurement/notifications',
            ],
            'destructive_changes' => false,
            'database_changes_applied' => false,
            'table_count_change' => 0,
        ];
    }

    return [
        'status' => 'blocked',
        'mode' => 'plan',
        'verification' => [
            'healthy' => false,
            'ready_for_runtime_cutover' => false,
            'runtime_already_active' => false,
            'historical_preparation_assets_required' => true,
            'message' => 'Historical notification preparation assets have been retired after final canonical activation.',
            'base_table_count' => userNotificationConsolidationBaseTableCount($conn),
        ],
        'destructive_changes' => false,
        'database_changes_applied' => false,
        'table_count_change' => 0,
    ];
}

function userNotificationCanonicalRuntimeApply(mysqli $conn): array
{
    if (userNotificationConsolidationObjectType(
        $conn,
        USER_NOTIFICATION_FINAL_CANONICAL_TABLE
    ) === 'BASE TABLE') {
        $verification = userNotificationCanonicalRuntimeVerify($conn);
        if (($verification['healthy'] ?? false) !== true) {
            throw new RuntimeException(
                'Final canonical notification runtime is present but unhealthy.'
            );
        }
        return [
            'status' => 'success',
            'mode' => 'apply',
            'verification' => $verification,
            'runtime_cutover' => false,
            'already_active' => true,
            'destructive_changes' => false,
            'database_changes_applied' => false,
            'table_count_change' => 0,
        ];
    }

    throw new RuntimeException(
        'Historical notification runtime activation is no longer available after migration asset retirement.'
    );
}

function userNotificationCanonicalAcquirePublicIdLock(
    mysqli $conn,
    string $inboxApp
): string {
    $normalized = strtolower(trim($inboxApp));
    if (!in_array($normalized, ['acctlab', 'procuredesk'], true)) {
        throw new InvalidArgumentException('Unsupported notification inbox.');
    }
    $lockName = 'user_notification_public_id_' . $normalized;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $acquired = (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0);
    $stmt->close();
    if ($acquired !== 1) {
        throw new RuntimeException('Unable to allocate a notification public ID.', 500);
    }
    return $lockName;
}

function userNotificationCanonicalReleasePublicIdLock(
    mysqli $conn,
    string $lockName
): void {
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?) AS released');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}

function userNotificationCanonicalNextPublicId(
    mysqli $conn,
    string $inboxApp
): int {
    // Scope the public ID within the single canonical notification table.
    $table = userNotificationCanonicalRuntimeStorageTable($conn);
    return databaseIdentityNextScopedId(
        $conn,
        $table,
        'inbox_notification_id',
        'inbox_app',
        $inboxApp
    );
}
