<?php

declare(strict_types=1);

require_once __DIR__ . '/workflowEventRuntimeSupportService.php';

const WORKFLOW_EVENT_CANONICAL_READ_MODE_ENV = 'WORKFLOW_EVENT_CANONICAL_READ_MODE';

function workflowEventCanonicalLegacyBridgeForwardTriggerNames(): array
{
    return [
        'trg_wf_sup_pay_evt_ai',
        'trg_wf_sup_pay_evt_au',
        'trg_wf_sup_pay_evt_ad',
        'trg_wf_adv_pay_evt_ai',
        'trg_wf_adv_pay_evt_au',
        'trg_wf_adv_pay_evt_ad',
    ];
}

function workflowEventCanonicalLegacyBridgeTriggerNames(): array
{
    return array_merge(
        workflowEventCanonicalLegacyBridgeForwardTriggerNames(),
        [
            'trg_wf_event_ai_legacy',
            'trg_wf_event_au_legacy',
            'trg_wf_event_ad_legacy',
        ]
    );
}

function workflowEventCanonicalLegacyBridgeExistingTriggers(mysqli $conn): array
{
    $names = workflowEventCanonicalLegacyBridgeTriggerNames();
    $quoted = implode(',', array_map(
        static fn(string $name): string => "'" . $conn->real_escape_string($name) . "'",
        $names
    ));
    $result = $conn->query(
        "SELECT TRIGGER_NAME
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN ({$quoted})"
    );

    return array_values(array_map(
        static fn(array $row): string => (string) $row['TRIGGER_NAME'],
        $result->fetch_all(MYSQLI_ASSOC)
    ));
}

function workflowEventCanonicalReadColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function workflowEventCanonicalReadIndexExists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function workflowEventCanonicalReadEnsureCompatibility(mysqli $conn): void
{
    if (!workflowEventCanonicalReady($conn)) {
        throw new RuntimeException('Unified workflow event storage is missing.');
    }

    if (!workflowEventCanonicalReadColumnExists($conn, 'workflow_events', 'source_event_id')) {
        $conn->query(
            'ALTER TABLE workflow_events
             ADD COLUMN source_event_id BIGINT UNSIGNED NULL AFTER legacy_source_id'
        );
    }

    if (!workflowEventCanonicalReadIndexExists(
        $conn,
        'workflow_events',
        'idx_workflow_event_source_event'
    )) {
        $conn->query(
            'ALTER TABLE workflow_events
             ADD KEY idx_workflow_event_source_event (legacy_source_table, source_event_id)'
        );
    }
}

function workflowEventCanonicalReadBackfill(mysqli $conn): void
{
    workflowEventCanonicalReadEnsureCompatibility($conn);

    $conn->query(
        'UPDATE workflow_events
         SET source_event_id = COALESCE(legacy_source_id, id)
         WHERE NOT (source_event_id <=> COALESCE(legacy_source_id, id))'
    );
}

function workflowEventCanonicalReadSourceIdMismatchCounts(mysqli $conn): array
{
    if (!workflowEventCanonicalReadColumnExists($conn, 'workflow_events', 'source_event_id')) {
        return array_fill_keys(workflowEventSourceTables(), -1);
    }

    $counts = [];
    foreach (workflowEventSourceTables() as $table) {
        $escaped = $conn->real_escape_string($table);
        $counts[$table] = workflowEventCount(
            $conn,
            "SELECT COUNT(*) AS total
             FROM workflow_events
             WHERE BINARY legacy_source_table = BINARY '{$escaped}'
               AND NOT (source_event_id <=> COALESCE(legacy_source_id, id))"
        );
    }

    return $counts;
}

function workflowEventCanonicalReadCompatibleTriggerCount(mysqli $conn): int
{
    $names = workflowEventCanonicalLegacyBridgeForwardTriggerNames();
    if ($names === []) {
        return 0;
    }

    $quoted = implode(',', array_map(
        static fn(string $name): string => "'" . $conn->real_escape_string($name) . "'",
        $names
    ));
    $row = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN ({$quoted})
           AND LOWER(ACTION_STATEMENT) LIKE '%source_event_id%'"
    )->fetch_assoc() ?: [];

    return (int) ($row['total'] ?? 0);
}

function workflowEventCanonicalReadVerify(mysqli $conn): array
{
    $state = workflowEventSourceObjectState($conn);
    $phase = (string) ($state['phase'] ?? 'mixed_or_incomplete');
    $columnReady = workflowEventCanonicalReadColumnExists(
        $conn,
        'workflow_events',
        'source_event_id'
    );
    $indexReady = workflowEventCanonicalReadIndexExists(
        $conn,
        'workflow_events',
        'idx_workflow_event_source_event'
    );

    if ($phase === 'bridged_legacy_tables') {
        $existingTriggers = workflowEventCanonicalLegacyBridgeExistingTriggers($conn);
        $checks = [
            'canonical_storage_healthy' => false,
            'bridge_healthy' => false,
            'source_event_id_column_ready' => $columnReady,
            'source_event_id_index_ready' => $indexReady,
            'source_event_ids_match' => false,
            'bridge_populates_source_event_id' => false,
        ];

        return [
            'healthy' => false,
            'canonical_read_ready' => false,
            'storage_phase' => $phase,
            'checks' => $checks,
            'source_event_id_mismatches' => [],
            'source_event_id_mismatch_total' => -1,
            'compatible_trigger_count' => count($existingTriggers),
            'expected_trigger_count' => 0,
            'storage_verification' => [
                'healthy' => false,
                'reason' => 'Historical workflow-event bridge support is not part of canonical runtime.',
            ],
            'bridge_verification' => [
                'healthy' => false,
                'required' => true,
                'trigger_count' => count($existingTriggers),
                'expected_trigger_count' => 0,
                'missing_triggers' => [],
            ],
            'source_object_state' => $state,
            'base_table_count' => workflowEventBaseTableCount($conn),
            'table_count_change' => 0,
        ];
    }

    $integrity = workflowEventCanonicalIntegrityVerify($conn);
    $existingTriggers = workflowEventCanonicalLegacyBridgeExistingTriggers($conn);
    $postCleanup = in_array(
        $phase,
        [
            'canonical_with_compatibility_views',
            'canonical_with_partial_compatibility_views',
            'canonical_only',
        ],
        true
    );
    $checks = [
        'canonical_storage_healthy' => ($integrity['healthy'] ?? false) === true,
        'compatibility_views_ready' => $postCleanup,
        'source_event_id_column_ready' => $columnReady,
        'source_event_id_index_ready' => $indexReady,
        'source_event_ids_match' => $columnReady
            && (int) ($integrity['missing_source_event_ids'] ?? -1) === 0,
        'bridge_removed' => $existingTriggers === [],
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'healthy' => $healthy,
        'canonical_read_ready' => $healthy,
        'storage_phase' => $phase,
        'checks' => $checks,
        'source_event_id_mismatches' => [],
        'source_event_id_mismatch_total' => (int) ($integrity['missing_source_event_ids'] ?? -1),
        'compatible_trigger_count' => count($existingTriggers),
        'expected_trigger_count' => 0,
        'storage_verification' => $integrity,
        'bridge_verification' => [
            'healthy' => $existingTriggers === [],
            'required' => false,
            'trigger_count' => count($existingTriggers),
            'expected_trigger_count' => 0,
            'missing_triggers' => [],
        ],
        'source_object_state' => $state,
        'base_table_count' => workflowEventBaseTableCount($conn),
        'table_count_change' => 0,
    ];
}

function workflowEventCanonicalReadPlan(mysqli $conn): array
{
    $verification = workflowEventCanonicalReadVerify($conn);

    return [
        'status' => 'success',
        'mode' => 'plan',
        'verification' => $verification,
        'planned_changes' => [
            'add_source_event_id_column' => !($verification['checks']['source_event_id_column_ready'] ?? false),
            'add_source_event_id_index' => !($verification['checks']['source_event_id_index_ready'] ?? false),
            'backfill_source_event_ids' => true,
            'refresh_bridge_triggers' => false,
        ],
        'destructive_changes' => false,
        'runtime_cutover' => false,
        'table_count_change' => 0,
    ];
}

function workflowEventCanonicalReadApply(mysqli $conn): array
{
    $state = workflowEventSourceObjectState($conn);
    $phase = (string) ($state['phase'] ?? 'mixed_or_incomplete');
    if ($phase === 'bridged_legacy_tables') {
        throw new RuntimeException(
            'Historical workflow-event bridge migration support is required before canonical runtime activation.'
        );
    }
    if (!in_array(
        $phase,
        [
            'canonical_with_compatibility_views',
            'canonical_with_partial_compatibility_views',
            'canonical_only',
        ],
        true
    )) {
        throw new RuntimeException(
            'Workflow event canonical read preparation is blocked by an unsupported mixed storage phase.'
        );
    }
    if (workflowEventCanonicalLegacyBridgeExistingTriggers($conn) !== []) {
        throw new RuntimeException(
            'Historical workflow-event bridge triggers must be retired before canonical runtime preparation.'
        );
    }

    workflowEventCanonicalReadEnsureCompatibility($conn);
    workflowEventCanonicalReadBackfill($conn);

    $verification = workflowEventCanonicalReadVerify($conn);
    return [
        'status' => $verification['healthy'] ? 'success' : 'verification_failed',
        'mode' => 'apply',
        'verification' => $verification,
        'destructive_changes' => false,
        'runtime_cutover' => false,
        'table_count_change' => 0,
    ];
}

function workflowEventCanonicalReadMode(): string
{
    $configured = function_exists('envValue')
        ? envValue(WORKFLOW_EVENT_CANONICAL_READ_MODE_ENV, 'legacy')
        : ($_ENV[WORKFLOW_EVENT_CANONICAL_READ_MODE_ENV]
            ?? getenv(WORKFLOW_EVENT_CANONICAL_READ_MODE_ENV)
            ?: 'legacy');

    $mode = strtolower(trim((string) $configured));
    return in_array($mode, ['legacy', 'auto', 'canonical'], true) ? $mode : 'legacy';
}

function workflowEventCanonicalReadsEnabled(mysqli $conn): bool
{
    static $cache = [];

    $mode = workflowEventCanonicalReadMode();
    if ($mode === 'legacy') {
        return false;
    }

    $cacheKey = spl_object_id($conn) . ':' . $mode;
    if (!array_key_exists($cacheKey, $cache)) {
        $verification = workflowEventCanonicalReadVerify($conn);
        $healthy = ($verification['healthy'] ?? false) === true;

        if ($mode === 'canonical' && !$healthy) {
            throw new RuntimeException(
                'Canonical workflow event reads were forced, but unified event storage is incomplete.',
                503
            );
        }

        $cache[$cacheKey] = $healthy;
    }

    return $cache[$cacheKey];
}

function workflowEventCanonicalProjection(string $sourceTable): string
{
    return match ($sourceTable) {
        WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST =>
            "(SELECT source_event_id AS id,
                     entity_id AS request_id,
                     request_type,
                     NULL AS legacy_source_table,
                     NULL AS legacy_source_id,
                     event_type,
                     actor_user_id,
                     actor_email,
                     details_json,
                     created_at
                FROM workflow_events
               WHERE legacy_source_table = 'procurement_request_events'
                 AND event_scope = 'procurement_request'
                 AND entity_type = 'procurement_request')",
        WORKFLOW_EVENT_SOURCE_SUPPLIER_PAYMENT =>
            "(SELECT source_event_id AS id,
                     entity_id AS supplier_fund_request_id,
                     batch_id,
                     event_type,
                     actor_user_id,
                     actor_email,
                     details_json,
                     created_at
                FROM workflow_events
               WHERE legacy_source_table = 'account_supplier_payment_events'
                 AND event_scope = 'payment'
                 AND request_type = 'local_final_purchase')",
        WORKFLOW_EVENT_SOURCE_ADVANCE_PAYMENT =>
            "(SELECT source_event_id AS id,
                     entity_id AS advance_payment_request_id,
                     batch_id,
                     event_type,
                     actor_user_id,
                     actor_email,
                     details_json,
                     created_at
                FROM workflow_events
               WHERE legacy_source_table = 'account_advance_payment_events'
                 AND event_scope = 'payment'
                 AND request_type = 'local_advance_purchase')",
        WORKFLOW_EVENT_SOURCE_ADVANCE_PO =>
            "(SELECT source_event_id AS id,
                     entity_id AS po_id,
                     CASE WHEN related_entity_type = 'purchase_order_revision'
                          THEN related_entity_id ELSE NULL END AS revision_id,
                     CASE WHEN secondary_entity_type = 'procurement_po_reconciliation'
                          THEN secondary_entity_id ELSE NULL END AS reconciliation_id,
                     event_type,
                     event_key,
                     actor_user_id,
                     actor_email,
                     details_json,
                     created_at
                FROM workflow_events
               WHERE legacy_source_table = 'procurement_local_advance_po_events'
                 AND event_scope = 'purchase_order')",
        WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION =>
            "(SELECT source_event_id AS id,
                     entity_id AS account_reconciliation_id,
                     CASE WHEN related_entity_type = 'procurement_po_reconciliation'
                          THEN related_entity_id ELSE NULL END AS procurement_reconciliation_id,
                     CASE WHEN secondary_entity_type = 'purchase_order'
                          THEN secondary_entity_id ELSE NULL END AS po_id,
                     CASE WHEN tertiary_entity_type = 'purchase_order_revision'
                          THEN tertiary_entity_id ELSE NULL END AS po_revision_id,
                     event_type,
                     event_key,
                     actor_user_id,
                     actor_email,
                     details_json,
                     created_at
                FROM workflow_events
               WHERE legacy_source_table = 'account_advance_po_reconciliation_events'
                 AND event_scope = 'po_reconciliation')",
        default => throw new InvalidArgumentException('Unsupported workflow event source table.'),
    };
}

function workflowEventReadSource(mysqli $conn, string $sourceTable): string
{
    if (!in_array($sourceTable, workflowEventSourceTables(), true)) {
        throw new InvalidArgumentException('Unsupported workflow event read source.');
    }

    // Procurement request history is canonical-only at runtime.
    if ($sourceTable === WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST) {
        return workflowEventCanonicalProjection($sourceTable);
    }

    // After the historical event tables/views have been retired, the legacy
    // source object no longer exists. In that canonical-only phase, a legacy
    // read-mode setting must not send runtime queries to a retired table name.
    // Resolve directly to workflow_events while still preserving the legacy
    // compatibility behaviour whenever an actual source table/view exists.
    if (workflowEventObjectType($conn, $sourceTable) === null) {
        return workflowEventCanonicalProjection($sourceTable);
    }

    return workflowEventCanonicalReadsEnabled($conn)
        ? workflowEventCanonicalProjection($sourceTable)
        : $sourceTable;
}
