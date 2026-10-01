<?php

declare(strict_types=1);

require_once __DIR__ . '/workflowEventCanonicalReadService.php';
require_once __DIR__ . '/databaseIdentityService.php';

const WORKFLOW_EVENT_CANONICAL_WRITE_MODE_ENV = 'WORKFLOW_EVENT_CANONICAL_WRITE_MODE';

function workflowEventCanonicalWriteMode(): string
{
    $configured = function_exists('envValue')
        ? envValue(WORKFLOW_EVENT_CANONICAL_WRITE_MODE_ENV, 'legacy')
        : ($_ENV[WORKFLOW_EVENT_CANONICAL_WRITE_MODE_ENV]
            ?? getenv(WORKFLOW_EVENT_CANONICAL_WRITE_MODE_ENV)
            ?: 'legacy');

    $mode = strtolower(trim((string) $configured));
    return in_array($mode, ['legacy', 'auto', 'canonical'], true) ? $mode : 'legacy';
}

function workflowEventCanonicalWritesEnabled(mysqli $conn): bool
{
    static $cache = [];

    $mode = workflowEventCanonicalWriteMode();
    if ($mode === 'legacy') {
        if (!workflowEventLegacyBaseTablesReady($conn)) {
            throw new RuntimeException(
                'Legacy workflow event writes are unavailable after event-table cleanup.',
                503
            );
        }
        return false;
    }

    $state = workflowEventSourceObjectState($conn);
    $phase = (string) ($state['phase'] ?? 'mixed_or_incomplete');
    $cacheKey = spl_object_id($conn) . ':' . $mode . ':' . $phase;
    if (!array_key_exists($cacheKey, $cache)) {
        $read = workflowEventCanonicalReadVerify($conn);
        $healthy = false;

        if ($phase === 'bridged_legacy_tables') {
            $healthy = false;
        } elseif (in_array(
            $phase,
            [
                'canonical_with_compatibility_views',
                'canonical_with_partial_compatibility_views',
                'canonical_only',
            ],
            true
        )) {
            $healthy = ($read['healthy'] ?? false) === true
                && workflowEventCanonicalLegacyBridgeExistingTriggers($conn) === [];
        }

        if ($mode === 'canonical' && !$healthy) {
            throw new RuntimeException(
                'Canonical workflow event writes were forced, but workflow event storage is incomplete.',
                503
            );
        }
        $cache[$cacheKey] = $healthy;
    }

    return $cache[$cacheKey];
}

function workflowEventAcquireSourceIdLock(mysqli $conn): string
{
    static $held = [];
    $connectionId = spl_object_id($conn);
    $lockName = 'workflow_event_source_ids';
    if (($held[$connectionId] ?? false) === true) {
        return $lockName;
    }

    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $acquired = (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $stmt->close();
    if (!$acquired) {
        throw new RuntimeException('Unable to reserve a workflow event identifier.', 503);
    }

    $held[$connectionId] = true;
    register_shutdown_function(static function () use ($conn, $lockName): void {
        try {
            $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt) {
                $stmt->bind_param('s', $lockName);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // Closing the connection also releases the advisory lock.
        }
    });

    return $lockName;
}

function workflowEventNextSourceId(mysqli $conn, string $sourceTable): int
{
    if (!in_array($sourceTable, workflowEventSourceTables(), true)) {
        throw new InvalidArgumentException('Unsupported workflow event source table.');
    }

    workflowEventAcquireSourceIdLock($conn);
    return databaseIdentityNextScopedId(
        $conn,
        'workflow_events',
        'source_event_id',
        'legacy_source_table',
        $sourceTable
    );
}

function workflowEventAssertLegacyWriteTarget(mysqli $conn, string $table): void
{
    if (!workflowEventBaseTableExists($conn, $table)) {
        throw new RuntimeException(
            'Legacy workflow event write target is unavailable: ' . $table,
            503
        );
    }
}

function workflowEventCanonicalInsert(mysqli $conn, array $event): int
{
    $sourceTable = (string) ($event['legacy_source_table'] ?? '');
    if (!in_array($sourceTable, workflowEventSourceTables(), true)) {
        throw new InvalidArgumentException('Unsupported workflow event source table.');
    }

    $eventKey = trim((string) ($event['event_key'] ?? ''));
    if ($eventKey !== '') {
        $stmt = $conn->prepare(
            'SELECT source_event_id
             FROM workflow_events
             WHERE BINARY event_scope = BINARY ? AND BINARY event_key = BINARY ?
             LIMIT 1'
        );
        $scope = (string) $event['event_scope'];
        $stmt->bind_param('ss', $scope, $eventKey);
        $stmt->execute();
        $existingId = (int) ($stmt->get_result()->fetch_assoc()['source_event_id'] ?? 0);
        $stmt->close();
        if ($existingId > 0) {
            return $existingId;
        }
    }

    $sourceEventId = workflowEventNextSourceId($conn, $sourceTable);
    $stmt = $conn->prepare(
        'INSERT INTO workflow_events
            (source_app, event_scope, request_type, entity_type, entity_id,
             related_entity_type, related_entity_id,
             secondary_entity_type, secondary_entity_id,
             tertiary_entity_type, tertiary_entity_id,
             batch_id, event_type, event_key, actor_user_id, actor_email,
             details_json, legacy_source_table, legacy_source_id, source_event_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0),
                 ?, NULLIF(?, 0), ?, NULLIF(?, 0),
                 NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );

    $sourceApp = (string) $event['source_app'];
    $scope = (string) $event['event_scope'];
    $requestTypeValue = trim((string) ($event['request_type'] ?? ''));
    $requestType = $requestTypeValue !== '' ? $requestTypeValue : null;
    $entityType = (string) $event['entity_type'];
    $entityId = (int) $event['entity_id'];
    $relatedTypeValue = trim((string) ($event['related_entity_type'] ?? ''));
    $relatedType = $relatedTypeValue !== '' ? $relatedTypeValue : null;
    $relatedId = (int) ($event['related_entity_id'] ?? 0);
    $secondaryTypeValue = trim((string) ($event['secondary_entity_type'] ?? ''));
    $secondaryType = $secondaryTypeValue !== '' ? $secondaryTypeValue : null;
    $secondaryId = (int) ($event['secondary_entity_id'] ?? 0);
    $tertiaryTypeValue = trim((string) ($event['tertiary_entity_type'] ?? ''));
    $tertiaryType = $tertiaryTypeValue !== '' ? $tertiaryTypeValue : null;
    $tertiaryId = (int) ($event['tertiary_entity_id'] ?? 0);
    $batchId = (int) ($event['batch_id'] ?? 0);
    $eventType = (string) $event['event_type'];
    $eventKeyValue = $eventKey !== '' ? $eventKey : null;
    $actorId = max(0, (int) ($event['actor_user_id'] ?? 0));
    $actorEmail = trim((string) ($event['actor_email'] ?? 'system')) ?: 'system';
    $detailsJson = $event['details_json'] ?? null;
    $createdAtValue = trim((string) ($event['created_at'] ?? ''));
    $createdAt = $createdAtValue !== '' ? $createdAtValue : null;

    $stmt->bind_param(
        'ssssisisisiississsiis',
        $sourceApp,
        $scope,
        $requestType,
        $entityType,
        $entityId,
        $relatedType,
        $relatedId,
        $secondaryType,
        $secondaryId,
        $tertiaryType,
        $tertiaryId,
        $batchId,
        $eventType,
        $eventKeyValue,
        $actorId,
        $actorEmail,
        $detailsJson,
        $sourceTable,
        $sourceEventId,
        $sourceEventId,
        $createdAt
    );
    $stmt->execute();
    $stmt->close();

    if ($eventKey !== '') {
        $lookup = $conn->prepare(
            'SELECT source_event_id
             FROM workflow_events
             WHERE BINARY event_scope = BINARY ? AND BINARY event_key = BINARY ?
             LIMIT 1'
        );
        $lookup->bind_param('ss', $scope, $eventKey);
        $lookup->execute();
        $resolvedId = (int) ($lookup->get_result()->fetch_assoc()['source_event_id'] ?? 0);
        $lookup->close();
        if ($resolvedId > 0) {
            return $resolvedId;
        }
    }

    return $sourceEventId;
}

function workflowEventRecordRequest(
    mysqli $conn,
    int $requestId,
    string $requestType,
    string $eventType,
    int $actorUserId,
    string $actorEmail,
    ?string $detailsJson,
    ?string $createdAt = null
): int {
    // Procurement-request events have completed their runtime cutover and no longer
    // fall back to the procurement_request_events compatibility object.
    if (!workflowEventCanonicalReady($conn)) {
        throw new RuntimeException('Canonical workflow event storage is unavailable.', 503);
    }

    return workflowEventCanonicalInsert($conn, [
        'source_app' => 'procuredesk',
        'event_scope' => 'procurement_request',
        'request_type' => $requestType,
        'entity_type' => 'procurement_request',
        'entity_id' => $requestId,
        'event_type' => $eventType,
        'actor_user_id' => $actorUserId,
        'actor_email' => $actorEmail,
        'details_json' => $detailsJson,
        'legacy_source_table' => WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST,
        'created_at' => $createdAt,
    ]);
}

function workflowEventRecordPayment(
    mysqli $conn,
    string $requestType,
    int $requestId,
    ?int $batchId,
    string $eventType,
    int $actorUserId,
    string $actorEmail,
    ?string $detailsJson,
    ?string $createdAt = null
): int {
    $isAdvance = $requestType === 'local_advance_purchase';
    $sourceTable = $isAdvance
        ? WORKFLOW_EVENT_SOURCE_ADVANCE_PAYMENT
        : WORKFLOW_EVENT_SOURCE_SUPPLIER_PAYMENT;
    $entityType = $isAdvance ? 'advance_payment_request' : 'supplier_fund_request';

    // Supplier/Advance payment events have completed their runtime cutover.
    // The historical payment-event tables were retired, so payment creation
    // must never fall back to a legacy table because of an old/default env mode.
    if (!workflowEventCanonicalReady($conn)) {
        throw new RuntimeException('Canonical workflow event storage is unavailable.', 503);
    }

    return workflowEventCanonicalInsert($conn, [
        'source_app' => 'acctlab',
        'event_scope' => 'payment',
        'request_type' => $requestType,
        'entity_type' => $entityType,
        'entity_id' => $requestId,
        'batch_id' => $batchId,
        'event_type' => $eventType,
        'actor_user_id' => $actorUserId,
        'actor_email' => $actorEmail,
        'details_json' => $detailsJson,
        'legacy_source_table' => $sourceTable,
        'created_at' => $createdAt,
    ]);
}

function workflowEventRecordAdvancePo(
    mysqli $conn,
    int $poId,
    ?int $revisionId,
    ?int $reconciliationId,
    string $eventType,
    string $eventKey,
    int $actorUserId,
    string $actorEmail,
    ?string $detailsJson,
    string $createdAt
): int {
    // Local Advance PO events have completed their runtime cutover and no longer
    // fall back to the procurement_local_advance_po_events compatibility object.
    if (!workflowEventCanonicalReady($conn)) {
        throw new RuntimeException('Canonical workflow event storage is unavailable.', 503);
    }

    return workflowEventCanonicalInsert($conn, [
        'source_app' => 'procuredesk',
        'event_scope' => 'purchase_order',
        'request_type' => 'local_advance_purchase',
        'entity_type' => 'purchase_order',
        'entity_id' => $poId,
        'related_entity_type' => ($revisionId ?? 0) > 0 ? 'purchase_order_revision' : null,
        'related_entity_id' => $revisionId,
        'secondary_entity_type' => ($reconciliationId ?? 0) > 0 ? 'procurement_po_reconciliation' : null,
        'secondary_entity_id' => $reconciliationId,
        'event_type' => $eventType,
        'event_key' => $eventKey,
        'actor_user_id' => $actorUserId,
        'actor_email' => $actorEmail,
        'details_json' => $detailsJson,
        'legacy_source_table' => WORKFLOW_EVENT_SOURCE_ADVANCE_PO,
        'created_at' => $createdAt,
    ]);
}

function workflowEventRecordAdvanceReconciliation(
    mysqli $conn,
    int $accountReconciliationId,
    int $procurementReconciliationId,
    int $poId,
    int $poRevisionId,
    string $eventType,
    string $eventKey,
    int $actorUserId,
    string $actorEmail,
    ?string $detailsJson,
    string $createdAt
): int {
    // Account PO reconciliation events have completed their runtime cutover and
    // no longer fall back to account_advance_po_reconciliation_events.
    if (!workflowEventCanonicalReady($conn)) {
        throw new RuntimeException('Canonical workflow event storage is unavailable.', 503);
    }

    return workflowEventCanonicalInsert($conn, [
        'source_app' => 'acctlab',
        'event_scope' => 'po_reconciliation',
        'request_type' => 'local_advance_purchase',
        'entity_type' => 'account_po_reconciliation',
        'entity_id' => $accountReconciliationId,
        'related_entity_type' => 'procurement_po_reconciliation',
        'related_entity_id' => $procurementReconciliationId,
        'secondary_entity_type' => 'purchase_order',
        'secondary_entity_id' => $poId,
        'tertiary_entity_type' => 'purchase_order_revision',
        'tertiary_entity_id' => $poRevisionId,
        'event_type' => $eventType,
        'event_key' => $eventKey,
        'actor_user_id' => $actorUserId,
        'actor_email' => $actorEmail,
        'details_json' => $detailsJson,
        'legacy_source_table' => WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION,
        'created_at' => $createdAt,
    ]);
}
