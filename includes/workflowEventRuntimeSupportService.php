<?php

declare(strict_types=1);

const WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST = 'procurement_request_events';
const WORKFLOW_EVENT_SOURCE_SUPPLIER_PAYMENT = 'account_supplier_payment_events';
const WORKFLOW_EVENT_SOURCE_ADVANCE_PAYMENT = 'account_advance_payment_events';
const WORKFLOW_EVENT_SOURCE_ADVANCE_PO = 'procurement_local_advance_po_events';
const WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION = 'account_advance_po_reconciliation_events';

function workflowEventSourceTables(): array
{
    return [
        WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST,
        WORKFLOW_EVENT_SOURCE_SUPPLIER_PAYMENT,
        WORKFLOW_EVENT_SOURCE_ADVANCE_PAYMENT,
        WORKFLOW_EVENT_SOURCE_ADVANCE_PO,
        WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION,
    ];
}

function workflowEventCanonicalTable(): string
{
    return 'workflow_events';
}

function workflowEventObjectType(mysqli $conn, string $object): ?string
{
    $stmt = $conn->prepare(
        'SELECT TABLE_TYPE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $object);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();

    return $type !== null ? strtoupper((string) $type) : null;
}

function workflowEventBaseTableExists(mysqli $conn, string $table): bool
{
    return workflowEventObjectType($conn, $table) === 'BASE TABLE';
}

function workflowEventViewExists(mysqli $conn, string $view): bool
{
    return workflowEventObjectType($conn, $view) === 'VIEW';
}

function workflowEventSourceObjectState(mysqli $conn): array
{
    $types = [];
    $baseTables = [];
    $views = [];
    $missing = [];

    foreach (workflowEventSourceTables() as $table) {
        $type = workflowEventObjectType($conn, $table);
        $types[$table] = $type;
        if ($type === 'BASE TABLE') {
            $baseTables[] = $table;
        } elseif ($type === 'VIEW') {
            $views[] = $table;
        } else {
            $missing[] = $table;
        }
    }

    if (count($baseTables) === count(workflowEventSourceTables())) {
        $phase = 'bridged_legacy_tables';
    } elseif (count($baseTables) === 0) {
        if (count($views) === count(workflowEventSourceTables())) {
            $phase = 'canonical_with_compatibility_views';
        } elseif (count($views) > 0) {
            $phase = 'canonical_with_partial_compatibility_views';
        } else {
            $phase = 'canonical_only';
        }
    } else {
        $phase = 'mixed_or_incomplete';
    }

    return [
        'phase' => $phase,
        'types' => $types,
        'base_tables' => $baseTables,
        'views' => $views,
        'missing' => $missing,
        'base_table_count' => count($baseTables),
        'view_count' => count($views),
        'missing_count' => count($missing),
    ];
}

function workflowEventLegacyBaseTablesReady(mysqli $conn): bool
{
    return workflowEventSourceObjectState($conn)['phase'] === 'bridged_legacy_tables';
}

function workflowEventCompatibilityViewsReady(mysqli $conn): bool
{
    return in_array(
        workflowEventSourceObjectState($conn)['phase'],
        [
            'canonical_with_compatibility_views',
            'canonical_with_partial_compatibility_views',
            'canonical_only',
        ],
        true
    );
}

function workflowEventBaseTableCount(mysqli $conn): int
{
    $row = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_TYPE = 'BASE TABLE'"
    )->fetch_assoc() ?: [];

    return (int) ($row['total'] ?? 0);
}

function workflowEventCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc() ?: [];
    return (int) ($row['total'] ?? 0);
}

function workflowEventCanonicalReady(mysqli $conn): bool
{
    return workflowEventBaseTableExists($conn, workflowEventCanonicalTable());
}

function workflowEventCanonicalIntegrityVerify(mysqli $conn): array
{
    $ready = workflowEventCanonicalReady($conn);
    $sourceCounts = [];
    foreach (workflowEventSourceTables() as $table) {
        $escaped = $conn->real_escape_string($table);
        $sourceCounts[$table] = $ready
            ? workflowEventCount(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM workflow_events
                 WHERE BINARY legacy_source_table = BINARY '{$escaped}'"
            )
            : 0;
    }
    $sourceCounts['total'] = array_sum($sourceCounts);

    $missingSourceIds = $ready
        ? workflowEventCount(
            $conn,
            'SELECT COUNT(*) AS total
             FROM workflow_events
             WHERE source_event_id IS NULL OR source_event_id = 0'
        )
        : -1;

    $supportedSources = implode(',', array_map(
        static fn(string $table): string => "BINARY '" . $conn->real_escape_string($table) . "'",
        workflowEventSourceTables()
    ));
    $unsupportedSources = $ready
        ? workflowEventCount(
            $conn,
            "SELECT COUNT(*) AS total
             FROM workflow_events
             WHERE BINARY legacy_source_table NOT IN ({$supportedSources})"
        )
        : -1;

    $duplicateSourceIds = $ready
        ? workflowEventCount(
            $conn,
            'SELECT COUNT(*) AS total
             FROM (
                 SELECT legacy_source_table, source_event_id
                 FROM workflow_events
                 WHERE source_event_id IS NOT NULL
                 GROUP BY legacy_source_table, source_event_id
                 HAVING COUNT(*) > 1
             ) duplicates'
        )
        : -1;
    $duplicateLegacyMappings = $ready
        ? workflowEventCount(
            $conn,
            'SELECT COUNT(*) AS total
             FROM (
                 SELECT legacy_source_table, legacy_source_id
                 FROM workflow_events
                 GROUP BY legacy_source_table, legacy_source_id
                 HAVING COUNT(*) > 1
             ) duplicates'
        )
        : -1;
    $duplicateEventKeys = $ready
        ? workflowEventCount(
            $conn,
            'SELECT COUNT(*) AS total
             FROM (
                 SELECT event_scope, event_key
                 FROM workflow_events
                 WHERE event_key IS NOT NULL
                 GROUP BY event_scope, event_key
                 HAVING COUNT(*) > 1
             ) duplicates'
        )
        : -1;

    $shapeRules = [
        WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST => [
            'source_app' => 'procuredesk',
            'event_scope' => 'procurement_request',
            'request_type' => null,
            'entity_type' => 'procurement_request',
        ],
        WORKFLOW_EVENT_SOURCE_SUPPLIER_PAYMENT => [
            'source_app' => 'acctlab',
            'event_scope' => 'payment',
            'request_type' => 'local_final_purchase',
            'entity_type' => 'supplier_fund_request',
        ],
        WORKFLOW_EVENT_SOURCE_ADVANCE_PAYMENT => [
            'source_app' => 'acctlab',
            'event_scope' => 'payment',
            'request_type' => 'local_advance_purchase',
            'entity_type' => 'advance_payment_request',
        ],
        WORKFLOW_EVENT_SOURCE_ADVANCE_PO => [
            'source_app' => 'procuredesk',
            'event_scope' => 'purchase_order',
            'request_type' => 'local_advance_purchase',
            'entity_type' => 'purchase_order',
        ],
        WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION => [
            'source_app' => 'acctlab',
            'event_scope' => 'po_reconciliation',
            'request_type' => 'local_advance_purchase',
            'entity_type' => 'account_po_reconciliation',
        ],
    ];
    $shapeClauses = [];
    foreach ($shapeRules as $sourceTable => $rule) {
        $source = $conn->real_escape_string($sourceTable);
        $sourceApp = $conn->real_escape_string($rule['source_app']);
        $eventScope = $conn->real_escape_string($rule['event_scope']);
        $entityType = $conn->real_escape_string($rule['entity_type']);
        $parts = [
            "BINARY legacy_source_table = BINARY '{$source}'",
            "BINARY source_app = BINARY '{$sourceApp}'",
            "BINARY event_scope = BINARY '{$eventScope}'",
            "BINARY entity_type = BINARY '{$entityType}'",
        ];
        if ($rule['request_type'] !== null) {
            $requestType = $conn->real_escape_string($rule['request_type']);
            $parts[] = "BINARY request_type = BINARY '{$requestType}'";
        }
        $shapeClauses[] = '(' . implode(' AND ', $parts) . ')';
    }
    $invalidShapes = $ready
        ? workflowEventCount(
            $conn,
            'SELECT COUNT(*) AS total FROM workflow_events WHERE NOT ('
                . implode(' OR ', $shapeClauses)
                . ')'
        )
        : -1;

    $checks = [
        'canonical_table_ready' => $ready,
        'source_event_ids_present' => $missingSourceIds === 0,
        'supported_source_types_only' => $unsupportedSources === 0,
        'no_duplicate_source_event_ids' => $duplicateSourceIds === 0,
        'no_duplicate_legacy_mappings' => $duplicateLegacyMappings === 0,
        'no_duplicate_event_keys' => $duplicateEventKeys === 0,
        'canonical_event_shapes_valid' => $invalidShapes === 0,
    ];

    return [
        'healthy' => !in_array(false, $checks, true),
        'checks' => $checks,
        'source_counts' => $sourceCounts,
        'missing_source_event_ids' => $missingSourceIds,
        'unsupported_source_rows' => $unsupportedSources,
        'duplicate_source_event_ids' => $duplicateSourceIds,
        'duplicate_legacy_mappings' => $duplicateLegacyMappings,
        'duplicate_event_keys' => $duplicateEventKeys,
        'invalid_shape_rows' => $invalidShapes,
    ];
}
