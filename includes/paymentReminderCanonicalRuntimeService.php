<?php

declare(strict_types=1);

require_once __DIR__ . '/paymentReminderUnifiedStoragePlanService.php';
require_once __DIR__ . '/accountPaymentReminderService.php';
require_once __DIR__ . '/accountPaymentReminderSchedulerService.php';
require_once __DIR__ . '/userNotificationCanonicalRuntimeService.php';

const PAYMENT_REMINDER_CANONICAL_RUNTIME_EXPECTED_BASE_TABLE_COUNT = 65;

function paymentReminderCanonicalRuntimeCodeAudit(): array
{
    $reminderPath = __DIR__ . '/accountPaymentReminderService.php';
    $deploymentPath = dirname(__DIR__) . '/cron/verifyPaymentReminderDeployment.php';
    $notificationPath = __DIR__ . '/accountNotificationService.php';

    $reminder = is_file($reminderPath) ? (string) file_get_contents($reminderPath) : '';
    $deployment = is_file($deploymentPath) ? (string) file_get_contents($deploymentPath) : '';
    $notification = is_file($notificationPath) ? (string) file_get_contents($notificationPath) : '';

    $legacyNames = [];
    foreach ([ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL, ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE] as $requestType) {
        $metadata = accountPaymentStorageLegacyMetadata($requestType);
        $legacyNames[] = $metadata['batch_table'];
        $legacyNames[] = $metadata['item_table'];
    }
    $legacyNames = array_values(array_unique($legacyNames));

    $reminderLegacyReferences = [];
    $deploymentLegacyReferences = [];
    foreach ($legacyNames as $name) {
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
        'reminder_service_uses_canonical_batches' =>
            str_contains($reminder, 'FROM account_payment_batch_items')
            && str_contains($reminder, 'INNER JOIN account_payment_batches'),
        'reminder_service_preserves_public_ids' =>
            str_contains($reminder, 'i.legacy_source_id AS batch_item_id')
            && str_contains($reminder, 'b.legacy_source_id AS batch_id'),
        'reminder_item_status_write_is_canonical' =>
            str_contains($reminder, 'UPDATE account_payment_batch_items')
            && str_contains($reminder, 'canonical_batch_item_id'),
        'deployment_verifier_uses_canonical_objects' =>
            str_contains($deployment, "'account_payment_batches'")
            && str_contains($deployment, "'account_payment_batch_items'")
            && str_contains($deployment, "'notifications'"),
        'notification_service_supports_final_canonical_table' =>
            str_contains($notification, 'userNotificationCanonicalRuntimeStorageTable')
            && str_contains($notification, "\$inboxApp = 'acctlab'"),
        'workflow_events_are_canonical' => str_contains($reminder, 'workflowEventRecordPayment'),
    ];
}

function paymentReminderCanonicalRuntimeSourceProbe(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT id, request_type, request_id, batch_id, batch_item_id
         FROM account_payment_reminders
         ORDER BY id ASC"
    );
    if (!$result) {
        throw new RuntimeException('Unable to inspect live payment reminders: ' . $conn->error);
    }

    $total = 0;
    $resolved = 0;
    $missing = 0;
    $publicIdMismatches = 0;
    $canonicalTypeMismatches = 0;
    $samples = [];

    while ($row = $result->fetch_assoc()) {
        $total++;
        $type = (string) ($row['request_type'] ?? '');
        $requestId = (int) ($row['request_id'] ?? 0);
        $batchItemId = (int) ($row['batch_item_id'] ?? 0) ?: null;
        try {
            $source = accountPaymentReminderFetchSource(
                $conn,
                $type,
                $requestId,
                $batchItemId,
                false
            );
        } catch (Throwable $error) {
            $source = null;
            if (count($samples) < 10) {
                $samples[] = [
                    'reminder_id' => (int) ($row['id'] ?? 0),
                    'error' => $error->getMessage(),
                ];
            }
        }

        if ($source === null) {
            $missing++;
            continue;
        }
        $resolved++;

        $storedBatchId = (int) ($row['batch_id'] ?? 0);
        $storedItemId = (int) ($row['batch_item_id'] ?? 0);
        if ($storedBatchId > 0 && (int) ($source['batch_id'] ?? 0) !== $storedBatchId) {
            $publicIdMismatches++;
        }
        if ($storedItemId > 0 && (int) ($source['batch_item_id'] ?? 0) !== $storedItemId) {
            $publicIdMismatches++;
        }

        $expectedCanonicalType = (string) (
            accountPaymentReminderSourceConfig($type)['canonical_request_type']
            ?? ''
        );
        if ((string) ($source['canonical_request_type'] ?? '') !== $expectedCanonicalType) {
            $canonicalTypeMismatches++;
        }
    }

    return [
        'healthy' => $missing === 0
            && $publicIdMismatches === 0
            && $canonicalTypeMismatches === 0,
        'total' => $total,
        'resolved' => $resolved,
        'missing' => $missing,
        'public_id_mismatches' => $publicIdMismatches,
        'canonical_request_type_mismatches' => $canonicalTypeMismatches,
        'samples' => $samples,
    ];
}

function paymentReminderCanonicalRuntimeVerify(mysqli $conn): array
{
    $objects = paymentReminderUnifiedObjectState($conn);
    $baseTableCount = paymentReminderUnifiedBaseTableCount($conn);
    $canonicalReads = accountPaymentCanonicalReadVerify($conn);
    $canonicalWrites = [
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => accountPaymentStorageCanonicalWritesEnabled(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL
        ),
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => accountPaymentStorageCanonicalWritesEnabled(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE
        ),
        ACCOUNT_PAYMENT_TYPE_FX_FINAL => accountPaymentStorageCanonicalWritesEnabled(
            $conn,
            ACCOUNT_PAYMENT_TYPE_FX_FINAL
        ),
        ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => accountPaymentStorageCanonicalWritesEnabled(
            $conn,
            ACCOUNT_PAYMENT_TYPE_FX_ADVANCE
        ),
    ];
    $reminders = paymentReminderUnifiedReminderMetrics($conn);
    $requestOrphans = paymentReminderUnifiedRequestOrphans($conn);
    $canonicalMappings = paymentReminderUnifiedCanonicalMappingMetrics($conn);
    $activeSources = paymentReminderUnifiedActiveSourceMetrics($conn);
    $notifications = paymentReminderUnifiedNotificationMetrics($conn);
    $scheduler = paymentReminderUnifiedSchedulerMetrics($conn);
    $codeAudit = paymentReminderCanonicalRuntimeCodeAudit();
    $sourceProbe = paymentReminderCanonicalRuntimeSourceProbe($conn);
    $notificationRuntime = userNotificationCanonicalRuntimeVerify($conn);

    $checks = [
        'current_base_table_count_is_65' =>
            $baseTableCount === PAYMENT_REMINDER_CANONICAL_RUNTIME_EXPECTED_BASE_TABLE_COUNT,
        'reminder_lifecycle_tables_are_retained' =>
            ($objects[PAYMENT_REMINDER_UNIFIED_REMINDER_TABLE] ?? null) === 'BASE TABLE'
            && ($objects[PAYMENT_REMINDER_UNIFIED_RUN_TABLE] ?? null) === 'BASE TABLE',
        'canonical_payment_storage_is_active' =>
            ($objects[PAYMENT_REMINDER_UNIFIED_CANONICAL_BATCH_TABLE] ?? null) === 'BASE TABLE'
            && ($objects[PAYMENT_REMINDER_UNIFIED_CANONICAL_ITEM_TABLE] ?? null) === 'BASE TABLE'
            && ($canonicalReads['healthy'] ?? false) === true
            && !in_array(false, $canonicalWrites, true),
        'payment_compatibility_objects_are_not_runtime_requirements' =>
            paymentReminderUnifiedCompatibilityObjectsSupported($objects),
        'canonical_notification_storage_is_active' =>
            ($objects[PAYMENT_REMINDER_UNIFIED_NOTIFICATION_TABLE] ?? null) === 'BASE TABLE'
            && ($notificationRuntime['healthy'] ?? false) === true,
        'reminder_runtime_has_no_legacy_payment_object_reads_or_writes' =>
            $codeAudit['reminder_service_legacy_payment_object_references'] === [],
        'deployment_verifier_has_no_legacy_payment_object_requirements' =>
            $codeAudit['deployment_verifier_legacy_payment_object_references'] === [],
        'reminder_runtime_uses_canonical_payment_sources' =>
            $codeAudit['reminder_service_uses_canonical_batches'] === true
            && $codeAudit['reminder_service_preserves_public_ids'] === true
            && $codeAudit['reminder_item_status_write_is_canonical'] === true,
        'deployment_verifier_uses_canonical_objects' =>
            $codeAudit['deployment_verifier_uses_canonical_objects'] === true,
        'notification_service_supports_final_canonical_table' =>
            $codeAudit['notification_service_supports_final_canonical_table'] === true,
        'live_reminder_sources_resolve_from_canonical_storage' =>
            $sourceProbe['healthy'] === true,
        'reminder_request_and_mapping_integrity_is_healthy' =>
            array_sum($requestOrphans) === 0
            && (int) ($canonicalMappings['totals']['missing_item_mappings'] ?? 1) === 0
            && (int) ($canonicalMappings['totals']['batch_public_id_mismatches'] ?? 1) === 0
            && (int) ($canonicalMappings['totals']['request_type_mismatches'] ?? 1) === 0,
        'active_reminders_remain_processing_and_notify' => array_sum($activeSources) === 0,
        'reminder_lifecycle_values_are_valid' =>
            $reminders['invalid_request_types'] === 0
            && $reminders['invalid_lifecycle_statuses'] === 0
            && $reminders['invalid_delivery_statuses'] === 0,
        'canonical_notification_delivery_is_healthy' =>
            $notifications['wrong_destination_inbox'] === 0
            && $notifications['orphan_reminder_references'] === 0,
        'scheduler_has_no_stale_running_cycle' => $scheduler['stale_running'] === 0,
        'workflow_events_are_canonical' =>
            ($objects['workflow_events'] ?? null) === 'BASE TABLE'
            && $codeAudit['workflow_events_are_canonical'] === true,
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'healthy' => $healthy,
        'ready_for_runtime_testing' => $healthy,
        'checks' => $checks,
        'runtime_sources' => [
            'payment_batches' => 'account_payment_batches',
            'payment_batch_items' => 'account_payment_batch_items',
            'notifications' => 'notifications',
            'workflow_events' => 'workflow_events',
            'reminders' => PAYMENT_REMINDER_UNIFIED_REMINDER_TABLE,
            'scheduler_runs' => PAYMENT_REMINDER_UNIFIED_RUN_TABLE,
            'public_id_contract' => [
                'batch_id' => 'account_payment_batches.legacy_source_id',
                'batch_item_id' => 'account_payment_batch_items.legacy_source_id',
                'canonical_internal_ids_exposed' => false,
            ],
        ],
        'object_types' => $objects,
        'payment_compatibility_object_phase' =>
            paymentReminderUnifiedCompatibilityObjectPhase($objects),
        'base_table_count' => $baseTableCount,
        'canonical_payment_reads' => [
            'healthy' => $canonicalReads['healthy'] ?? false,
            'mismatches' => $canonicalReads['mismatches'] ?? [],
            'compatibility_phase' => $canonicalReads['compatibility_phase'] ?? [],
            'compatibility_views_retired' =>
                ($canonicalReads['compatibility_views_retired'] ?? false) === true,
        ],
        'canonical_payment_writes' => [
            'mode' => accountPaymentStorageCanonicalWriteMode(),
            'enabled' => $canonicalWrites,
        ],
        'code_audit' => $codeAudit,
        'source_probe' => $sourceProbe,
        'reminders' => $reminders,
        'canonical_payment_mappings' => $canonicalMappings,
        'active_source_integrity' => $activeSources,
        'notification_delivery' => $notifications,
        'scheduler_runs' => $scheduler,
        'table_count_change' => 0,
    ];
}

function paymentReminderCanonicalRuntimePlan(mysqli $conn): array
{
    $verification = paymentReminderCanonicalRuntimeVerify($conn);
    return [
        'status' => ($verification['healthy'] ?? false) ? 'success' : 'blocked',
        'mode' => 'plan',
        'verification' => $verification,
        'planned_apply' => [
            'deploy updated reminder runtime files',
            'run canonical overdue backfill without changing schema',
            'reverify source mappings, notifications, workflow events and scheduler state',
        ],
        'required_retests' => [
            'Supplier reminder initial delivery from a Notify payment batch',
            'Advance reminder initial delivery from a Notify payment batch',
            'overdue backfill for Supplier and Advance requests',
            'failed delivery exponential retry and later successful recovery',
            'manual reminder reinitiation after an expired or failed cycle',
            'escalation after repeated successful reminder deliveries',
            'automatic completion when payment status becomes Paid',
            'automatic cancellation when Notify configuration is removed',
            'scheduler lease acquisition, stale lease recovery and run history',
            'canonical AcctLab notification delivery and dedupe idempotency',
            'canonical workflow events for delivery, failure, escalation, completion and reinitiation',
        ],
        'expected_base_table_count_before' => PAYMENT_REMINDER_CANONICAL_RUNTIME_EXPECTED_BASE_TABLE_COUNT,
        'expected_base_table_count_after' => PAYMENT_REMINDER_CANONICAL_RUNTIME_EXPECTED_BASE_TABLE_COUNT,
        'runtime_cutover' => false,
        'destructive_changes' => false,
        'database_changes_applied' => false,
        'table_count_change' => 0,
    ];
}

function paymentReminderCanonicalRuntimeApply(mysqli $conn): array
{
    $pre = paymentReminderCanonicalRuntimeVerify($conn);
    if (($pre['healthy'] ?? false) !== true) {
        return [
            'status' => 'blocked',
            'mode' => 'apply',
            'pre_verification' => $pre,
            'runtime_cutover' => false,
            'destructive_changes' => false,
            'database_changes_applied' => false,
            'table_count_change' => 0,
        ];
    }

    $backfill = accountPaymentReminderBackfillOverdue($conn);
    $post = paymentReminderCanonicalRuntimeVerify($conn);

    return [
        'status' => ($post['healthy'] ?? false) ? 'success' : 'failed',
        'mode' => 'apply',
        'pre_verification' => $pre,
        'canonical_backfill' => $backfill,
        'verification' => $post,
        'runtime_cutover' => ($post['healthy'] ?? false) === true,
        'destructive_changes' => false,
        'database_changes_applied' => true,
        'table_count_change' => 0,
    ];
}
