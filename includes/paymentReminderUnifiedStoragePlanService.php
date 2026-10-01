<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageCanonicalWriteService.php';
require_once __DIR__ . '/accountPaymentStorageCanonicalReadService.php';

const PAYMENT_REMINDER_UNIFIED_EXPECTED_BASE_TABLE_COUNT = 65;
const PAYMENT_REMINDER_UNIFIED_REMINDER_TABLE = 'account_payment_reminders';
const PAYMENT_REMINDER_UNIFIED_RUN_TABLE = 'account_payment_reminder_runs';
const PAYMENT_REMINDER_UNIFIED_NOTIFICATION_TABLE = 'notifications';
const PAYMENT_REMINDER_UNIFIED_CANONICAL_BATCH_TABLE = 'account_payment_batches';
const PAYMENT_REMINDER_UNIFIED_CANONICAL_ITEM_TABLE = 'account_payment_batch_items';

function paymentReminderUnifiedObjectType(mysqli $conn, string $object): ?string
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

function paymentReminderUnifiedBaseTableCount(mysqli $conn): int
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

function paymentReminderUnifiedScalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Payment reminder verification query failed: ' . $conn->error);
    }
    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function paymentReminderUnifiedGroupedCounts(
    mysqli $conn,
    string $sql,
    string $keyColumn,
    string $valueColumn = 'total'
): array {
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Payment reminder grouped query failed: ' . $conn->error);
    }
    $counts = [];
    while ($row = $result->fetch_assoc()) {
        $counts[(string) ($row[$keyColumn] ?? '')] = (int) ($row[$valueColumn] ?? 0);
    }
    return $counts;
}

function paymentReminderUnifiedObjectState(mysqli $conn): array
{
    $objects = [
        PAYMENT_REMINDER_UNIFIED_REMINDER_TABLE,
        PAYMENT_REMINDER_UNIFIED_RUN_TABLE,
        PAYMENT_REMINDER_UNIFIED_NOTIFICATION_TABLE,
        'account_notifications',
        'procurement_notifications',
        PAYMENT_REMINDER_UNIFIED_CANONICAL_BATCH_TABLE,
        PAYMENT_REMINDER_UNIFIED_CANONICAL_ITEM_TABLE,
        'account_payment_artifacts',
        'account_supplier_payment_batches',
        'account_supplier_payment_batch_items',
        'account_supplier_payment_artifacts',
        'account_advance_payment_batches',
        'account_advance_payment_batch_items',
        'account_advance_payment_artifacts',
        'account_supplier_payment_events',
        'account_advance_payment_events',
        'supplier_fund_request_table',
        'advance_payment_request',
        'workflow_events',
    ];
    $state = [];
    foreach ($objects as $object) {
        $state[$object] = paymentReminderUnifiedObjectType($conn, $object);
    }
    return $state;
}

function paymentReminderUnifiedCompatibilityObjectNames(): array
{
    return [
        'account_supplier_payment_batches',
        'account_supplier_payment_batch_items',
        'account_supplier_payment_artifacts',
        'account_advance_payment_batches',
        'account_advance_payment_batch_items',
        'account_advance_payment_artifacts',
        'account_supplier_payment_events',
        'account_advance_payment_events',
    ];
}

function paymentReminderUnifiedCompatibilityObjectPhase(array $objects): string
{
    $names = paymentReminderUnifiedCompatibilityObjectNames();
    $types = array_map(
        static fn(string $name): ?string => $objects[$name] ?? null,
        $names
    );
    $viewCount = count(array_filter(
        $types,
        static fn(?string $type): bool => $type === 'VIEW'
    ));
    $absentCount = count(array_filter(
        $types,
        static fn(?string $type): bool => $type === null
    ));

    if ($viewCount === count($names)) {
        return 'compatibility_views_present';
    }
    if ($absentCount === count($names)) {
        return 'compatibility_views_retired';
    }
    return 'unsupported_mixed_state';
}

function paymentReminderUnifiedCompatibilityObjectsSupported(array $objects): bool
{
    return paymentReminderUnifiedCompatibilityObjectPhase($objects) !== 'unsupported_mixed_state';
}

function paymentReminderUnifiedReminderMetrics(mysqli $conn): array
{
    $summaryResult = $conn->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN lifecycle_status IN ('Pending', 'Awaiting Action', 'Retry Scheduled')
                              AND next_reminder_at <= NOW() THEN 1 ELSE 0 END) AS due,
                SUM(CASE WHEN lifecycle_status = 'Delivering'
                              AND lease_expires_at IS NOT NULL
                              AND lease_expires_at < NOW() THEN 1 ELSE 0 END) AS stale_leases,
                SUM(CASE WHEN lifecycle_status NOT IN
                              ('Pending', 'Awaiting Action', 'Retry Scheduled', 'Delivering', 'Completed', 'Cancelled')
                         THEN 1 ELSE 0 END) AS invalid_lifecycle,
                SUM(CASE WHEN delivery_status NOT IN ('Pending', 'Delivered', 'Failed')
                         THEN 1 ELSE 0 END) AS invalid_delivery,
                SUM(CASE WHEN request_type NOT IN ('Advance', 'Supplier', 'Compass', 'FX Final', 'FX Advance')
                         THEN 1 ELSE 0 END) AS invalid_request_type,
                SUM(CASE WHEN batch_item_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_to_batch_item,
                SUM(CASE WHEN batch_item_id IS NULL THEN 1 ELSE 0 END) AS standalone
         FROM account_payment_reminders"
    );
    if (!$summaryResult) {
        throw new RuntimeException('Unable to inspect payment reminder lifecycle: ' . $conn->error);
    }
    $summary = $summaryResult->fetch_assoc() ?: [];

    return [
        'total' => (int) ($summary['total'] ?? 0),
        'due' => (int) ($summary['due'] ?? 0),
        'stale_leases' => (int) ($summary['stale_leases'] ?? 0),
        'invalid_lifecycle_statuses' => (int) ($summary['invalid_lifecycle'] ?? 0),
        'invalid_delivery_statuses' => (int) ($summary['invalid_delivery'] ?? 0),
        'invalid_request_types' => (int) ($summary['invalid_request_type'] ?? 0),
        'linked_to_batch_item' => (int) ($summary['linked_to_batch_item'] ?? 0),
        'standalone' => (int) ($summary['standalone'] ?? 0),
        'by_request_type' => paymentReminderUnifiedGroupedCounts(
            $conn,
            'SELECT request_type, COUNT(*) AS total
             FROM account_payment_reminders
             GROUP BY request_type ORDER BY request_type',
            'request_type'
        ),
        'by_lifecycle_status' => paymentReminderUnifiedGroupedCounts(
            $conn,
            'SELECT lifecycle_status, COUNT(*) AS total
             FROM account_payment_reminders
             GROUP BY lifecycle_status ORDER BY lifecycle_status',
            'lifecycle_status'
        ),
        'by_delivery_status' => paymentReminderUnifiedGroupedCounts(
            $conn,
            'SELECT delivery_status, COUNT(*) AS total
             FROM account_payment_reminders
             GROUP BY delivery_status ORDER BY delivery_status',
            'delivery_status'
        ),
    ];
}

function paymentReminderUnifiedRequestOrphans(mysqli $conn): array
{
    return [
        'advance' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             LEFT JOIN advance_payment_request request_row
               ON request_row.id = reminder.request_id
             WHERE BINARY reminder.request_type = BINARY 'Advance'
               AND request_row.id IS NULL"
        ),
        'supplier' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             LEFT JOIN supplier_fund_request_table request_row
               ON request_row.id = reminder.request_id
             WHERE BINARY reminder.request_type = BINARY 'Supplier'
               AND request_row.id IS NULL"
        ),
        'compass' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             LEFT JOIN compass_fund_request_table request_row
               ON request_row.id = reminder.request_id
             WHERE BINARY reminder.request_type = BINARY 'Compass'
               AND request_row.id IS NULL"
        ),
        'fx_final' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             LEFT JOIN fx_fund_request_table request_row
               ON request_row.id = reminder.request_id
              AND BINARY request_row.request_type = BINARY 'Final'
             WHERE BINARY reminder.request_type = BINARY 'FX Final'
               AND request_row.id IS NULL"
        ),
        'fx_advance' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             LEFT JOIN fx_fund_request_table request_row
               ON request_row.id = reminder.request_id
              AND BINARY request_row.request_type = BINARY 'Advance'
             WHERE BINARY reminder.request_type = BINARY 'FX Advance'
               AND request_row.id IS NULL"
        ),
    ];
}

function paymentReminderUnifiedCanonicalMappingMetrics(mysqli $conn): array
{
    $definitions = [
        'advance' => [
            'reminder_type' => 'Advance',
            'canonical_type' => ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
        ],
        'supplier' => [
            'reminder_type' => 'Supplier',
            'canonical_type' => ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
        ],
        'compass' => [
            'reminder_type' => 'Compass',
            'canonical_type' => ACCOUNT_PAYMENT_TYPE_COMPASS,
        ],
        'fx_final' => [
            'reminder_type' => 'FX Final',
            'canonical_type' => ACCOUNT_PAYMENT_TYPE_FX_FINAL,
        ],
        'fx_advance' => [
            'reminder_type' => 'FX Advance',
            'canonical_type' => ACCOUNT_PAYMENT_TYPE_FX_ADVANCE,
        ],
    ];
    $metrics = [];

    foreach ($definitions as $key => $definition) {
        $reminderType = $conn->real_escape_string($definition['reminder_type']);
        $canonicalType = $conn->real_escape_string($definition['canonical_type']);
        $metrics[$key] = [
            'linked_reminders' => paymentReminderUnifiedScalar(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM account_payment_reminders
                 WHERE BINARY request_type = BINARY '{$reminderType}'
                   AND batch_item_id IS NOT NULL"
            ),
            'missing_item_mappings' => paymentReminderUnifiedScalar(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM account_payment_reminders reminder
                 LEFT JOIN account_payment_batch_items item
                   ON BINARY item.request_type = BINARY '{$canonicalType}'
                  AND item.request_id = reminder.request_id
                  AND item.legacy_source_id = reminder.batch_item_id
                 WHERE BINARY reminder.request_type = BINARY '{$reminderType}'
                   AND reminder.batch_item_id IS NOT NULL
                   AND item.id IS NULL"
            ),
            'batch_public_id_mismatches' => paymentReminderUnifiedScalar(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM account_payment_reminders reminder
                 INNER JOIN account_payment_batch_items item
                   ON BINARY item.request_type = BINARY '{$canonicalType}'
                  AND item.request_id = reminder.request_id
                  AND item.legacy_source_id = reminder.batch_item_id
                 INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
                 WHERE BINARY reminder.request_type = BINARY '{$reminderType}'
                   AND reminder.batch_item_id IS NOT NULL
                   AND reminder.batch_id IS NOT NULL
                   AND batch.legacy_source_id <> reminder.batch_id"
            ),
            'request_type_mismatches' => paymentReminderUnifiedScalar(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM account_payment_reminders reminder
                 INNER JOIN account_payment_batch_items item
                   ON item.legacy_source_id = reminder.batch_item_id
                  AND item.request_id = reminder.request_id
                 INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
                 WHERE BINARY reminder.request_type = BINARY '{$reminderType}'
                   AND reminder.batch_item_id IS NOT NULL
                   AND (BINARY item.request_type <> BINARY '{$canonicalType}'
                        OR BINARY batch.request_type <> BINARY '{$canonicalType}')"
            ),
        ];
    }

    $metrics['totals'] = [
        'linked_reminders' => array_sum(array_column($metrics, 'linked_reminders')),
        'missing_item_mappings' => array_sum(array_column($metrics, 'missing_item_mappings')),
        'batch_public_id_mismatches' => array_sum(array_column($metrics, 'batch_public_id_mismatches')),
        'request_type_mismatches' => array_sum(array_column($metrics, 'request_type_mismatches')),
    ];
    return $metrics;
}

function paymentReminderUnifiedActiveSourceMetrics(mysqli $conn): array
{
    $active = "reminder.lifecycle_status IN ('Pending', 'Awaiting Action', 'Retry Scheduled', 'Delivering')";
    return [
        'active_advance_not_processing' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             INNER JOIN advance_payment_request request_row ON request_row.id = reminder.request_id
             WHERE BINARY reminder.request_type = BINARY 'Advance'
               AND {$active}
               AND BINARY request_row.payment_status <> BINARY 'Processing'"
        ),
        'active_supplier_not_processing' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             INNER JOIN supplier_fund_request_table request_row ON request_row.id = reminder.request_id
             WHERE BINARY reminder.request_type = BINARY 'Supplier'
               AND {$active}
               AND BINARY request_row.payment_status <> BINARY 'Processing'"
        ),
        'active_compass_not_processing' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             INNER JOIN compass_fund_request_table request_row ON request_row.id = reminder.request_id
             WHERE BINARY reminder.request_type = BINARY 'Compass'
               AND {$active}
               AND BINARY request_row.payment_status <> BINARY 'Processing'"
        ),
        'active_fx_final_not_processing' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             INNER JOIN fx_fund_request_table request_row
               ON request_row.id = reminder.request_id
              AND BINARY request_row.request_type = BINARY 'Final'
             WHERE BINARY reminder.request_type = BINARY 'FX Final'
               AND {$active}
               AND BINARY request_row.payment_status <> BINARY 'Processing'"
        ),
        'active_fx_advance_not_processing' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             INNER JOIN fx_fund_request_table request_row
               ON request_row.id = reminder.request_id
              AND BINARY request_row.request_type = BINARY 'Advance'
             WHERE BINARY reminder.request_type = BINARY 'FX Advance'
               AND {$active}
               AND BINARY request_row.payment_status <> BINARY 'Processing'"
        ),
        'active_linked_batches_not_notify' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total
             FROM account_payment_reminders reminder
             INNER JOIN account_payment_batch_items item
               ON item.legacy_source_id = reminder.batch_item_id
              AND item.request_id = reminder.request_id
              AND (
                    (BINARY reminder.request_type = BINARY 'Advance'
                     AND BINARY item.request_type = BINARY 'local_advance_purchase')
                 OR (BINARY reminder.request_type = BINARY 'Supplier'
                     AND BINARY item.request_type = BINARY 'local_final_purchase')
                 OR (BINARY reminder.request_type = BINARY 'Compass'
                     AND BINARY item.request_type = BINARY 'compass_fund_request')
                 OR (BINARY reminder.request_type = BINARY 'FX Final'
                     AND BINARY item.request_type = BINARY 'fx_final_purchase')
                 OR (BINARY reminder.request_type = BINARY 'FX Advance'
                     AND BINARY item.request_type = BINARY 'fx_advance_purchase')
              )
             INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
             WHERE reminder.batch_item_id IS NOT NULL
               AND {$active}
               AND BINARY batch.completion_mode <> BINARY 'Notify'"
        ),
        'active_standalone_requests_not_notify' => paymentReminderUnifiedScalar(
            $conn,
            "SELECT COUNT(*) AS total FROM (
                 SELECT reminder.id
                 FROM account_payment_reminders reminder
                 INNER JOIN advance_payment_request request_row ON request_row.id = reminder.request_id
                 WHERE BINARY reminder.request_type = BINARY 'Advance'
                   AND reminder.batch_item_id IS NULL
                   AND {$active}
                   AND BINARY request_row.completion_mode <> BINARY 'Notify'
                 UNION ALL
                 SELECT reminder.id
                 FROM account_payment_reminders reminder
                 INNER JOIN supplier_fund_request_table request_row ON request_row.id = reminder.request_id
                 WHERE BINARY reminder.request_type = BINARY 'Supplier'
                   AND reminder.batch_item_id IS NULL
                   AND {$active}
                   AND BINARY request_row.completion_mode <> BINARY 'Notify'
                 UNION ALL
                 SELECT reminder.id
                 FROM account_payment_reminders reminder
                 INNER JOIN compass_fund_request_table request_row ON request_row.id = reminder.request_id
                 WHERE BINARY reminder.request_type = BINARY 'Compass'
                   AND reminder.batch_item_id IS NULL
                   AND {$active}
                   AND BINARY request_row.completion_mode <> BINARY 'Notify'
             ) invalid_rows"
        ),
    ];
}

function paymentReminderUnifiedNotificationMetrics(mysqli $conn): array
{
    $total = paymentReminderUnifiedScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE dedupe_key LIKE 'payment-reminder-%'"
    );
    $wrongInbox = paymentReminderUnifiedScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE dedupe_key LIKE 'payment-reminder-%'
           AND BINARY inbox_app <> BINARY 'acctlab'"
    );
    $orphanReminderReferences = paymentReminderUnifiedScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM notifications notification_row
         LEFT JOIN account_payment_reminders reminder
           ON reminder.id = CAST(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(notification_row.dedupe_key, 'payment-reminder-', -1),
                    '-', 1
                ) AS UNSIGNED
              )
         WHERE notification_row.dedupe_key REGEXP '^payment-reminder-[0-9]+-(attempt|escalation)-'
           AND reminder.id IS NULL"
    );

    return [
        'total_reminder_notifications' => $total,
        'wrong_destination_inbox' => $wrongInbox,
        'orphan_reminder_references' => $orphanReminderReferences,
        'by_inbox' => paymentReminderUnifiedGroupedCounts(
            $conn,
            "SELECT inbox_app, COUNT(*) AS total
             FROM notifications
             WHERE dedupe_key LIKE 'payment-reminder-%'
             GROUP BY inbox_app ORDER BY inbox_app",
            'inbox_app'
        ),
    ];
}

function paymentReminderUnifiedSchedulerMetrics(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'Running' THEN 1 ELSE 0 END) AS running,
                SUM(CASE WHEN status = 'Running'
                              AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                         THEN 1 ELSE 0 END) AS stale_running,
                SUM(CASE WHEN status = 'Success' THEN 1 ELSE 0 END) AS successful,
                SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) AS failed
         FROM account_payment_reminder_runs"
    );
    if (!$result) {
        throw new RuntimeException('Unable to inspect reminder scheduler history: ' . $conn->error);
    }
    $row = $result->fetch_assoc() ?: [];
    return [
        'total_runs' => (int) ($row['total'] ?? 0),
        'running' => (int) ($row['running'] ?? 0),
        'stale_running' => (int) ($row['stale_running'] ?? 0),
        'successful' => (int) ($row['successful'] ?? 0),
        'failed' => (int) ($row['failed'] ?? 0),
    ];
}

function paymentReminderUnifiedRuntimeDependencyAudit(): array
{
    $reminderPath = __DIR__ . '/accountPaymentReminderService.php';
    $deploymentPath = dirname(__DIR__) . '/cron/verifyPaymentReminderDeployment.php';
    $notificationPath = __DIR__ . '/accountNotificationService.php';
    $reminder = is_file($reminderPath) ? (string) file_get_contents($reminderPath) : '';
    $deployment = is_file($deploymentPath) ? (string) file_get_contents($deploymentPath) : '';
    $notification = is_file($notificationPath) ? (string) file_get_contents($notificationPath) : '';

    $legacyPaymentNames = [
        'account_advance_payment_batches',
        'account_advance_payment_batch_items',
        'account_supplier_payment_batches',
        'account_supplier_payment_batch_items',
    ];
    $reminderLegacyReferences = [];
    $deploymentLegacyReferences = [];
    foreach ($legacyPaymentNames as $name) {
        if (str_contains($reminder, $name)) {
            $reminderLegacyReferences[] = $name;
        }
        if (str_contains($deployment, $name)) {
            $deploymentLegacyReferences[] = $name;
        }
    }

    return [
        'reminder_service_legacy_payment_object_references' => $reminderLegacyReferences,
        'deployment_verifier_legacy_payment_object_references' => $deploymentLegacyReferences,
        'reminder_runtime_requires_canonical_cutover' => $reminderLegacyReferences !== [],
        'deployment_verifier_requires_refresh' => $deploymentLegacyReferences !== []
            || str_contains($deployment, 'uq_account_notification_dedupe'),
        'notification_service_supports_final_canonical_table' =>
            str_contains($notification, 'userNotificationCanonicalRuntimeStorageTable')
            && str_contains($notification, "\$inboxApp = 'acctlab'"),
        'reminder_events_use_unified_workflow_events' =>
            str_contains($reminder, 'workflowEventRecordPayment'),
        'runtime_source_id_contract' => [
            'reminder_batch_id' => 'canonical batch legacy_source_id (public compatibility ID)',
            'reminder_batch_item_id' => 'canonical item legacy_source_id (public compatibility ID)',
            'canonical_internal_ids_are_not_exposed' => true,
        ],
    ];
}

function paymentReminderUnifiedStoragePlan(mysqli $conn): array
{
    $objects = paymentReminderUnifiedObjectState($conn);
    $baseTableCount = paymentReminderUnifiedBaseTableCount($conn);
    $canonicalReads = accountPaymentCanonicalReadVerify($conn);
    $canonicalWriteMode = accountPaymentStorageCanonicalWriteMode();
    $canonicalWrites = [
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => accountPaymentStorageCanonicalWritesEnabled(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL
        ),
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => accountPaymentStorageCanonicalWritesEnabled(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE
        ),
    ];
    $reminders = paymentReminderUnifiedReminderMetrics($conn);
    $requestOrphans = paymentReminderUnifiedRequestOrphans($conn);
    $canonicalMappings = paymentReminderUnifiedCanonicalMappingMetrics($conn);
    $activeSources = paymentReminderUnifiedActiveSourceMetrics($conn);
    $notificationMetrics = paymentReminderUnifiedNotificationMetrics($conn);
    $schedulerMetrics = paymentReminderUnifiedSchedulerMetrics($conn);
    $runtimeDependencies = paymentReminderUnifiedRuntimeDependencyAudit();

    $checks = [
        'current_base_table_count_is_65' =>
            $baseTableCount === PAYMENT_REMINDER_UNIFIED_EXPECTED_BASE_TABLE_COUNT,
        'reminder_lifecycle_tables_are_base_tables' =>
            ($objects[PAYMENT_REMINDER_UNIFIED_REMINDER_TABLE] ?? null) === 'BASE TABLE'
            && ($objects[PAYMENT_REMINDER_UNIFIED_RUN_TABLE] ?? null) === 'BASE TABLE',
        'canonical_payment_storage_is_active' =>
            ($objects[PAYMENT_REMINDER_UNIFIED_CANONICAL_BATCH_TABLE] ?? null) === 'BASE TABLE'
            && ($objects[PAYMENT_REMINDER_UNIFIED_CANONICAL_ITEM_TABLE] ?? null) === 'BASE TABLE'
            && ($canonicalReads['healthy'] ?? false) === true
            && !in_array(false, $canonicalWrites, true),
        'payment_compatibility_objects_are_in_supported_phase' =>
            paymentReminderUnifiedCompatibilityObjectsSupported($objects),
        'canonical_notification_storage_is_active' =>
            ($objects[PAYMENT_REMINDER_UNIFIED_NOTIFICATION_TABLE] ?? null) === 'BASE TABLE'
            && (
                (($objects['account_notifications'] ?? null) === 'VIEW'
                    && ($objects['procurement_notifications'] ?? null) === 'VIEW')
                || (($objects['account_notifications'] ?? null) === null
                    && ($objects['procurement_notifications'] ?? null) === null)
            ),
        'reminder_request_types_are_valid' =>
            $reminders['invalid_request_types'] === 0,
        'reminder_lifecycle_values_are_valid' =>
            $reminders['invalid_lifecycle_statuses'] === 0
            && $reminders['invalid_delivery_statuses'] === 0,
        'reminder_requests_have_source_rows' =>
            array_sum($requestOrphans) === 0,
        'linked_reminders_have_canonical_item_mappings' =>
            (int) ($canonicalMappings['totals']['missing_item_mappings'] ?? 1) === 0,
        'reminder_public_batch_ids_match_canonical_storage' =>
            (int) ($canonicalMappings['totals']['batch_public_id_mismatches'] ?? 1) === 0,
        'reminder_payment_request_types_match_canonical_storage' =>
            (int) ($canonicalMappings['totals']['request_type_mismatches'] ?? 1) === 0,
        'active_reminder_sources_are_processing_and_notify' =>
            array_sum($activeSources) === 0,
        'reminder_notifications_use_canonical_acctlab_inbox' =>
            $notificationMetrics['wrong_destination_inbox'] === 0
            && $notificationMetrics['orphan_reminder_references'] === 0,
        'scheduler_has_no_stale_running_cycle' =>
            $schedulerMetrics['stale_running'] === 0,
        'workflow_events_are_canonical' =>
            ($objects['workflow_events'] ?? null) === 'BASE TABLE'
            && $runtimeDependencies['reminder_events_use_unified_workflow_events'] === true,
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'status' => $healthy ? 'success' : 'blocked',
        'mode' => 'plan',
        'verification' => [
            'healthy' => $healthy,
            'ready_for_canonical_reminder_runtime_batch' => $healthy,
            'checks' => $checks,
            'object_types' => $objects,
            'base_table_count' => $baseTableCount,
            'canonical_payment_reads' => [
                'healthy' => $canonicalReads['healthy'] ?? false,
                'mismatches' => $canonicalReads['mismatches'] ?? [],
            ],
            'canonical_payment_writes' => [
                'mode' => $canonicalWriteMode,
                'enabled' => $canonicalWrites,
            ],
            'reminders' => $reminders,
            'request_orphans' => $requestOrphans,
            'canonical_payment_mappings' => $canonicalMappings,
            'active_source_integrity' => $activeSources,
            'notification_delivery' => $notificationMetrics,
            'scheduler_runs' => $schedulerMetrics,
        ],
        'runtime_dependency_audit' => $runtimeDependencies,
        'planned_runtime_changes' => [
            'replace reminder backfill reads with account_payment_batches and account_payment_batch_items',
            'resolve reminder sources directly from canonical payment storage while preserving public batch and item IDs',
            'update Awaiting Confirmation item writes directly in account_payment_batch_items',
            'retain request-level payment status and confirmation updates in the existing request tables',
            'refresh payment reminder deployment verification for canonical payment and notification objects',
            'retain account_payment_reminders and account_payment_reminder_runs as operational lifecycle tables',
            'make no database schema or base-table changes',
        ],
        'required_retests' => [
            'supplier reminder initial delivery from a Notify payment batch',
            'advance reminder initial delivery from a Notify payment batch',
            'overdue backfill for Supplier, Advance and Compass requests',
            'failed delivery exponential retry and later successful recovery',
            'manual reminder reinitiation after an expired or failed cycle',
            'escalation after repeated successful reminder deliveries',
            'automatic completion when payment status becomes Paid',
            'automatic cancellation when the request or Notify configuration is no longer eligible',
            'scheduler lease acquisition, stale lease recovery and run history',
            'canonical AcctLab notification delivery and dedupe idempotency',
            'canonical workflow events for delivery, failure, escalation, completion and reinitiation',
        ],
        'expected_base_table_count_before' => PAYMENT_REMINDER_UNIFIED_EXPECTED_BASE_TABLE_COUNT,
        'expected_base_table_count_after' => PAYMENT_REMINDER_UNIFIED_EXPECTED_BASE_TABLE_COUNT,
        'expected_base_table_reduction' => 0,
        'runtime_cutover' => false,
        'destructive_changes' => false,
        'database_changes_applied' => false,
        'table_count_change' => 0,
    ];
}
