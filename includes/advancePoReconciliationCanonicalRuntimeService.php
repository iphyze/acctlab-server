<?php

declare(strict_types=1);

require_once __DIR__ . '/advancePoReconciliationCanonicalStorageService.php';

const ADVANCE_PO_RECONCILIATION_RUNTIME_INSERT_TRIGGER =
    'trg_adv_po_recon_canonical_ai';
const ADVANCE_PO_RECONCILIATION_RUNTIME_UPDATE_TRIGGER =
    'trg_adv_po_recon_canonical_au';
const ADVANCE_PO_RECONCILIATION_ACCOUNT_ID_LOCK =
    'advance_po_reconciliation_account_id';
const ADVANCE_PO_RECONCILIATION_CLEANUP_TEMP_TABLE =
    'account_advance_po_reconciliations__legacy_cleanup';

function advancePoReconciliationRuntimeBridgeTriggerNames(): array
{
    return [
        ADVANCE_PO_RECONCILIATION_RUNTIME_INSERT_TRIGGER,
        ADVANCE_PO_RECONCILIATION_RUNTIME_UPDATE_TRIGGER,
    ];
}

function advancePoReconciliationRuntimeStoragePhase(mysqli $conn): string
{
    $accountType = advancePoReconciliationObjectType(
        $conn,
        ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE
    );
    $temporaryType = advancePoReconciliationObjectType(
        $conn,
        ADVANCE_PO_RECONCILIATION_CLEANUP_TEMP_TABLE
    );

    if ($accountType === 'BASE TABLE' && $temporaryType === null) {
        return 'canonical_with_temporary_mirror';
    }
    if ($accountType === 'VIEW' && $temporaryType === 'BASE TABLE') {
        return 'canonical_with_compatibility_view_staged';
    }
    if ($accountType === 'VIEW' && $temporaryType === null) {
        return 'canonical_with_compatibility_view';
    }

    return 'unexpected';
}

function advancePoReconciliationRuntimeCompatibilityViewColumns(): array
{
    return advancePoReconciliationRuntimeMirrorColumns();
}

function advancePoReconciliationRuntimeCompatibilityViewSql(): string
{
    $columns = advancePoReconciliationRuntimeCompatibilityViewColumns();
    $expressions = advancePoReconciliationRuntimeCanonicalProjectionExpressions(
        'canonical',
        'revision'
    );
    $projection = [];
    foreach ($columns as $index => $column) {
        $projection[] = $expressions[$index] . " AS `{$column}`";
    }

    return "CREATE VIEW `" . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE . "` AS\n"
        . "SELECT " . implode(",\n       ", $projection) . "\n"
        . "FROM `" . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . "` canonical\n"
        . "INNER JOIN `procurement_local_advance_po_revisions` revision\n"
        . "  ON revision.id = canonical.revision_id\n"
        . "WHERE canonical.account_reconciliation_id IS NOT NULL";
}

function advancePoReconciliationRuntimeCompatibilityViewShapeMatches(mysqli $conn): bool
{
    return advancePoReconciliationColumns(
        $conn,
        ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE
    ) === advancePoReconciliationRuntimeCompatibilityViewColumns();
}

function advancePoReconciliationRuntimeMirrorColumns(): array
{
    return [
        'id',
        'procurement_reconciliation_id',
        'po_id',
        'po_number',
        'po_revision_id',
        'po_revision_number',
        'previous_po_revision_id',
        'reconciliation_direction',
        'reconciliation_status',
        'previous_committed_amount',
        'revised_committed_amount',
        'total_paid_at_revision',
        'total_processing_at_revision',
        'total_pending_at_revision',
        'pending_reallocated_amount',
        'supplementary_amount',
        'supplementary_amount_paid',
        'recovery_amount',
        'supplementary_purchase_id',
        'supplementary_advance_payment_request_id',
        'resolution_type',
        'resolution_reference',
        'resolution_notes',
        'allocation_snapshot_json',
        'synced_by',
        'synced_at',
        'resolved_by',
        'resolved_at',
        'updated_by',
        'updated_at',
    ];
}

function advancePoReconciliationRuntimeCanonicalProjectionExpressions(
    string $canonicalAlias = 'canonical',
    string $revisionAlias = 'revision'
): array {
    return [
        "{$canonicalAlias}.account_reconciliation_id",
        "{$canonicalAlias}.id",
        "{$canonicalAlias}.po_id",
        "{$revisionAlias}.po_number",
        "{$canonicalAlias}.revision_id",
        "{$revisionAlias}.revision_number",
        "{$canonicalAlias}.previous_revision_id",
        "{$canonicalAlias}.reconciliation_direction",
        "{$canonicalAlias}.reconciliation_status",
        "{$canonicalAlias}.previous_committed_amount",
        "{$canonicalAlias}.revised_committed_amount",
        "{$canonicalAlias}.total_paid_at_revision",
        "{$canonicalAlias}.total_processing_at_revision",
        "{$canonicalAlias}.total_pending_at_revision",
        "{$canonicalAlias}.pending_reallocated_amount",
        "{$canonicalAlias}.supplementary_amount",
        "{$canonicalAlias}.supplementary_amount_paid",
        "{$canonicalAlias}.recovery_amount",
        "{$canonicalAlias}.supplementary_purchase_id",
        "{$canonicalAlias}.supplementary_advance_payment_request_id",
        "{$canonicalAlias}.resolution_type",
        "{$canonicalAlias}.recovery_reference",
        "{$canonicalAlias}.resolution_notes",
        "{$canonicalAlias}.allocation_snapshot_json",
        "COALESCE({$canonicalAlias}.account_synced_by, {$canonicalAlias}.updated_by, {$canonicalAlias}.created_by)",
        "COALESCE({$canonicalAlias}.account_synced_at, {$canonicalAlias}.updated_at, {$canonicalAlias}.created_at)",
        "{$canonicalAlias}.resolved_by",
        "{$canonicalAlias}.resolved_at",
        "{$canonicalAlias}.updated_by",
        "{$canonicalAlias}.updated_at",
    ];
}

function advancePoReconciliationRuntimeMirrorInsertSql(
    string $canonicalAlias = 'canonical',
    string $revisionAlias = 'revision',
    string $where = ''
): string {
    $columns = implode(', ', array_map(
        static fn(string $column): string => "`{$column}`",
        advancePoReconciliationRuntimeMirrorColumns()
    ));
    $expressions = implode(",\n                   ",
        advancePoReconciliationRuntimeCanonicalProjectionExpressions(
            $canonicalAlias,
            $revisionAlias
        )
    );
    $updates = implode(",\n                ", array_map(
        static fn(string $column): string => "`{$column}` = VALUES(`{$column}`)",
        array_values(array_filter(
            advancePoReconciliationRuntimeMirrorColumns(),
            static fn(string $column): bool => $column !== 'id'
        ))
    ));
    $whereSql = trim($where) !== '' ? "\n            WHERE {$where}" : '';

    return "INSERT INTO `" . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE . "`\n"
        . "            ({$columns})\n"
        . "            SELECT {$expressions}\n"
        . "            FROM `" . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . "` {$canonicalAlias}\n"
        . "            INNER JOIN `procurement_local_advance_po_revisions` {$revisionAlias}\n"
        . "              ON {$revisionAlias}.id = {$canonicalAlias}.revision_id"
        . $whereSql . "\n"
        . "            ON DUPLICATE KEY UPDATE\n                {$updates}";
}

function advancePoReconciliationRuntimeTriggerSql(string $triggerName, string $event): string
{
    $event = strtoupper($event);
    if (!in_array($event, ['INSERT', 'UPDATE'], true)) {
        throw new InvalidArgumentException('Unsupported PO reconciliation bridge event.');
    }

    $columns = implode(', ', array_map(
        static fn(string $column): string => "`{$column}`",
        advancePoReconciliationRuntimeMirrorColumns()
    ));
    $expressions = [
        'NEW.account_reconciliation_id',
        'NEW.id',
        'NEW.po_id',
        'revision.po_number',
        'NEW.revision_id',
        'revision.revision_number',
        'NEW.previous_revision_id',
        'NEW.reconciliation_direction',
        'NEW.reconciliation_status',
        'NEW.previous_committed_amount',
        'NEW.revised_committed_amount',
        'NEW.total_paid_at_revision',
        'NEW.total_processing_at_revision',
        'NEW.total_pending_at_revision',
        'NEW.pending_reallocated_amount',
        'NEW.supplementary_amount',
        'NEW.supplementary_amount_paid',
        'NEW.recovery_amount',
        'NEW.supplementary_purchase_id',
        'NEW.supplementary_advance_payment_request_id',
        'NEW.resolution_type',
        'NEW.recovery_reference',
        'NEW.resolution_notes',
        'NEW.allocation_snapshot_json',
        'COALESCE(NEW.account_synced_by, NEW.updated_by, NEW.created_by)',
        'COALESCE(NEW.account_synced_at, NEW.updated_at, NEW.created_at)',
        'NEW.resolved_by',
        'NEW.resolved_at',
        'NEW.updated_by',
        'NEW.updated_at',
    ];
    $updates = implode(",\n                    ", array_map(
        static fn(string $column): string => "`{$column}` = VALUES(`{$column}`)",
        array_values(array_filter(
            advancePoReconciliationRuntimeMirrorColumns(),
            static fn(string $column): bool => $column !== 'id'
        ))
    ));

    return "CREATE TRIGGER `{$triggerName}` AFTER {$event}\n"
        . "ON `" . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . "` FOR EACH ROW\n"
        . "BEGIN\n"
        . "    IF NEW.account_reconciliation_id IS NOT NULL THEN\n"
        . "        INSERT INTO `" . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE . "`\n"
        . "            ({$columns})\n"
        . "        SELECT " . implode(",\n               ", $expressions) . "\n"
        . "        FROM `procurement_local_advance_po_revisions` revision\n"
        . "        WHERE revision.id = NEW.revision_id\n"
        . "        ON DUPLICATE KEY UPDATE\n                    {$updates};\n"
        . "    END IF;\n"
        . "END";
}

function advancePoReconciliationRuntimeTriggerCount(mysqli $conn): int
{
    $names = advancePoReconciliationRuntimeBridgeTriggerNames();
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.TRIGGERS\n"
        . "WHERE TRIGGER_SCHEMA = @active_database_name AND TRIGGER_NAME IN ({$placeholders})"
    );
    $types = str_repeat('s', count($names));
    $stmt->bind_param($types, ...$names);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function advancePoReconciliationRuntimeDropBridge(mysqli $conn): void
{
    foreach (advancePoReconciliationRuntimeBridgeTriggerNames() as $triggerName) {
        if (!$conn->query("DROP TRIGGER IF EXISTS `{$triggerName}`")) {
            throw new RuntimeException(
                'Unable to remove temporary PO reconciliation bridge trigger: ' . $conn->error
            );
        }
    }
}

function advancePoReconciliationRuntimeCreateBridge(mysqli $conn): void
{
    advancePoReconciliationRuntimeDropBridge($conn);
    $definitions = [
        ADVANCE_PO_RECONCILIATION_RUNTIME_INSERT_TRIGGER => 'INSERT',
        ADVANCE_PO_RECONCILIATION_RUNTIME_UPDATE_TRIGGER => 'UPDATE',
    ];
    foreach ($definitions as $triggerName => $event) {
        if (!$conn->query(
            advancePoReconciliationRuntimeTriggerSql($triggerName, $event)
        )) {
            throw new RuntimeException(
                'Unable to create temporary PO reconciliation bridge trigger: ' . $conn->error
            );
        }
    }
}

function advancePoReconciliationRuntimeBackfillLegacyMirror(mysqli $conn): int
{
    $sql = advancePoReconciliationRuntimeMirrorInsertSql(
        'canonical',
        'revision',
        'canonical.account_reconciliation_id IS NOT NULL'
    );
    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'Unable to refresh the temporary AcctLab PO reconciliation mirror: ' . $conn->error
        );
    }
    return (int) $conn->affected_rows;
}

function advancePoReconciliationRuntimeMirrorMismatchCount(mysqli $conn): int
{
    return advancePoReconciliationScalar(
        $conn,
        "SELECT COUNT(*) AS total\n"
        . "FROM `" . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . "` canonical\n"
        . "INNER JOIN `procurement_local_advance_po_revisions` revision\n"
        . "  ON revision.id = canonical.revision_id\n"
        . "INNER JOIN `" . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE . "` account_row\n"
        . "  ON account_row.id = canonical.account_reconciliation_id\n"
        . "WHERE NOT (\n"
        . "    account_row.procurement_reconciliation_id <=> canonical.id\n"
        . "    AND account_row.po_id <=> canonical.po_id\n"
        . "    AND BINARY account_row.po_number <=> BINARY revision.po_number\n"
        . "    AND account_row.po_revision_id <=> canonical.revision_id\n"
        . "    AND account_row.po_revision_number <=> revision.revision_number\n"
        . "    AND account_row.previous_po_revision_id <=> canonical.previous_revision_id\n"
        . "    AND BINARY account_row.reconciliation_direction\n"
        . "        <=> BINARY canonical.reconciliation_direction\n"
        . "    AND BINARY account_row.reconciliation_status\n"
        . "        <=> BINARY canonical.reconciliation_status\n"
        . "    AND account_row.previous_committed_amount\n"
        . "        <=> canonical.previous_committed_amount\n"
        . "    AND account_row.revised_committed_amount\n"
        . "        <=> canonical.revised_committed_amount\n"
        . "    AND account_row.total_paid_at_revision\n"
        . "        <=> canonical.total_paid_at_revision\n"
        . "    AND account_row.total_processing_at_revision\n"
        . "        <=> canonical.total_processing_at_revision\n"
        . "    AND account_row.total_pending_at_revision\n"
        . "        <=> canonical.total_pending_at_revision\n"
        . "    AND account_row.pending_reallocated_amount\n"
        . "        <=> canonical.pending_reallocated_amount\n"
        . "    AND account_row.supplementary_amount <=> canonical.supplementary_amount\n"
        . "    AND account_row.supplementary_amount_paid\n"
        . "        <=> canonical.supplementary_amount_paid\n"
        . "    AND account_row.recovery_amount <=> canonical.recovery_amount\n"
        . "    AND account_row.supplementary_purchase_id\n"
        . "        <=> canonical.supplementary_purchase_id\n"
        . "    AND account_row.supplementary_advance_payment_request_id\n"
        . "        <=> canonical.supplementary_advance_payment_request_id\n"
        . "    AND BINARY account_row.resolution_type <=> BINARY canonical.resolution_type\n"
        . "    AND BINARY account_row.resolution_reference\n"
        . "        <=> BINARY canonical.recovery_reference\n"
        . "    AND BINARY account_row.resolution_notes\n"
        . "        <=> BINARY canonical.resolution_notes\n"
        . "    AND BINARY account_row.allocation_snapshot_json\n"
        . "        <=> BINARY canonical.allocation_snapshot_json\n"
        . ")"
    );
}

function advancePoReconciliationRuntimeVerify(mysqli $conn): array
{
    $phase = advancePoReconciliationRuntimeStoragePhase($conn);
    $canonicalType = advancePoReconciliationObjectType(
        $conn,
        ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
    );
    $accountType = advancePoReconciliationObjectType(
        $conn,
        ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE
    );
    $allocationType = advancePoReconciliationObjectType(
        $conn,
        ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE
    );
    $columnState = advancePoReconciliationCanonicalStorageColumnsState($conn);
    $columnsReady = !in_array(false, $columnState, true);
    $indexReady = advancePoReconciliationCanonicalStorageIndexExists($conn);

    $canonicalCount = advancePoReconciliationScalar(
        $conn,
        'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . '`'
    );
    $canonicalLinkedCount = advancePoReconciliationScalar(
        $conn,
        'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
        . '` WHERE account_reconciliation_id IS NOT NULL'
    );
    $accountCount = in_array($accountType, ['BASE TABLE', 'VIEW'], true)
        ? advancePoReconciliationScalar(
            $conn,
            'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE . '`'
        )
        : 0;
    $missingMirrorRows = in_array($accountType, ['BASE TABLE', 'VIEW'], true)
        ? advancePoReconciliationScalar(
            $conn,
            'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
            . '` canonical LEFT JOIN `' . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE
            . '` account_row ON account_row.id = canonical.account_reconciliation_id '
            . 'WHERE canonical.account_reconciliation_id IS NOT NULL AND account_row.id IS NULL'
        )
        : $canonicalLinkedCount;
    $accountOrphans = in_array($accountType, ['BASE TABLE', 'VIEW'], true)
        ? advancePoReconciliationScalar(
            $conn,
            'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE
            . '` account_row LEFT JOIN `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
            . '` canonical ON canonical.id = account_row.procurement_reconciliation_id '
            . 'WHERE canonical.id IS NULL'
        )
        : $accountCount;
    $syncedWithoutPublicId = advancePoReconciliationScalar(
        $conn,
        'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
        . "` WHERE account_sync_status = 'Synced' AND account_reconciliation_id IS NULL"
    );
    $mismatches = in_array($accountType, ['BASE TABLE', 'VIEW'], true)
        ? advancePoReconciliationRuntimeMirrorMismatchCount($conn)
        : $canonicalLinkedCount;
    $triggerCount = advancePoReconciliationRuntimeTriggerCount($conn);
    $baseTableCount = advancePoReconciliationBaseTableCount($conn);
    $compatibilityViewPhase = in_array(
        $phase,
        ['canonical_with_compatibility_view_staged', 'canonical_with_compatibility_view'],
        true
    );
    $expectedTriggerCount = $compatibilityViewPhase ? 0 : 2;
    $expectedBaseTableCount = $phase === 'canonical_with_compatibility_view'
        ? ADVANCE_PO_RECONCILIATION_EXPECTED_FINAL_BASE_TABLE_COUNT
        : ADVANCE_PO_RECONCILIATION_EXPECTED_BASE_TABLE_COUNT;

    $checks = [
        'supported_storage_phase' => $phase !== 'unexpected',
        'canonical_header_is_base_table' => $canonicalType === 'BASE TABLE',
        'allocation_detail_is_base_table' => $allocationType === 'BASE TABLE',
        'canonical_summary_columns_ready' => $columnsReady,
        'canonical_public_account_id_index_ready' => $indexReady,
        'account_compatibility_object_ready' =>
            $compatibilityViewPhase
                ? $accountType === 'VIEW'
                : $accountType === 'BASE TABLE',
        'compatibility_view_shape_matches' =>
            !$compatibilityViewPhase
                || advancePoReconciliationRuntimeCompatibilityViewShapeMatches($conn),
        'expected_temporary_bridge_state' => $triggerCount === $expectedTriggerCount,
        'all_public_account_ids_have_compatibility_rows' => $missingMirrorRows === 0,
        'compatibility_object_has_no_orphans' => $accountOrphans === 0,
        'compatibility_counts_match_linked_canonical_rows' =>
            $accountCount === $canonicalLinkedCount,
        'compatibility_fields_match_canonical' => $mismatches === 0,
        'synced_headers_have_public_account_ids' => $syncedWithoutPublicId === 0,
        'expected_base_table_count' => $baseTableCount === $expectedBaseTableCount,
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'healthy' => $healthy,
        'ready_for_runtime_testing' => $healthy,
        'storage_phase' => $phase,
        'checks' => $checks,
        'runtime_sources' => [
            'reads' => ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE,
            'writes' => ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE,
            'compatibility_object' => ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE,
            'compatibility_object_type' => $accountType,
            'temporary_mirror' => ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE,
            'allocations' => ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE,
        ],
        'trigger_count' => $triggerCount,
        'expected_trigger_count' => $expectedTriggerCount,
        'counts' => [
            'canonical_headers' => $canonicalCount,
            'canonical_headers_with_account_id' => $canonicalLinkedCount,
            'compatibility_headers' => $accountCount,
            'temporary_account_headers' => $accountCount,
        ],
        'integrity' => [
            'missing_compatibility_rows' => $missingMirrorRows,
            'compatibility_orphans' => $accountOrphans,
            'compatibility_field_mismatches' => $mismatches,
            'missing_mirror_rows' => $missingMirrorRows,
            'account_orphans' => $accountOrphans,
            'mirror_field_mismatches' => $mismatches,
            'synced_without_public_account_id' => $syncedWithoutPublicId,
        ],
        'base_table_count' => $baseTableCount,
        'expected_base_table_count' => $expectedBaseTableCount,
        'table_count_change' =>
            $baseTableCount - ADVANCE_PO_RECONCILIATION_EXPECTED_BASE_TABLE_COUNT,
    ];
}

function advancePoReconciliationRuntimePlan(mysqli $conn): array
{
    $storage = advancePoReconciliationCanonicalStorageVerify($conn);
    $ready = (bool) ($storage['healthy'] ?? false);

    return [
        'status' => $ready ? 'success' : 'blocked',
        'mode' => 'plan',
        'verification' => $storage,
        'runtime_cutover' => [
            'read_source' => ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE,
            'write_source' => ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE,
            'allocation_table_retained' => ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE,
            'temporary_mirror_table' => ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE,
            'temporary_bridge_triggers' => advancePoReconciliationRuntimeBridgeTriggerNames(),
        ],
        'required_retests' => [
            'increase amendment and supplementary reconciliation creation',
            'decrease amendment and recovery reconciliation creation',
            'pending WHT adjustment refresh',
            'supplementary payment resolution refresh',
            'manual recovery resolution',
            'supersession by a later amendment',
            'ProcureDesk amendment history and reconciliation events',
            'AcctLab reconciliation list, request filters, allocations and events',
        ],
        'destructive_changes' => false,
        'database_changes_applied' => false,
        'table_count_change' => 0,
    ];
}

function advancePoReconciliationRuntimeApply(mysqli $conn): array
{
    $plan = advancePoReconciliationRuntimePlan($conn);
    if (($plan['status'] ?? 'blocked') !== 'success') {
        throw new RuntimeException(
            'Canonical PO reconciliation runtime cutover is blocked by storage verification.'
        );
    }

    advancePoReconciliationRuntimeCreateBridge($conn);
    $backfilledRows = advancePoReconciliationRuntimeBackfillLegacyMirror($conn);
    $verification = advancePoReconciliationRuntimeVerify($conn);
    if (($verification['healthy'] ?? false) !== true) {
        advancePoReconciliationRuntimeDropBridge($conn);
        throw new RuntimeException(
            'Canonical PO reconciliation runtime verification failed after bridge activation.'
        );
    }

    return [
        'status' => 'success',
        'mode' => 'apply',
        'verification' => $verification,
        'backfilled_or_refreshed_rows' => $backfilledRows,
        'runtime_cutover' => true,
        'destructive_changes' => false,
        'database_changes_applied' => true,
        'table_count_change' => 0,
    ];
}

function advancePoReconciliationAllocateAccountId(
    mysqli $conn,
    int $procurementReconciliationId
): int {
    if ($procurementReconciliationId <= 0) {
        throw new InvalidArgumentException('A valid procurement reconciliation is required.');
    }

    $lockName = ADVANCE_PO_RECONCILIATION_ACCOUNT_ID_LOCK;
    $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $acquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $lockStmt->close();
    if (!$acquired) {
        throw new RuntimeException('Unable to reserve an AcctLab reconciliation identifier.', 409);
    }

    try {
        $lookup = $conn->prepare(
            'SELECT account_reconciliation_id FROM `'
            . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
            . '` WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $lookup->bind_param('i', $procurementReconciliationId);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$row) {
            throw new RuntimeException('The canonical PO reconciliation could not be found.', 404);
        }
        $currentId = (int) ($row['account_reconciliation_id'] ?? 0);
        if ($currentId > 0) {
            return $currentId;
        }

        $nextId = advancePoReconciliationScalar(
            $conn,
            'SELECT COALESCE(MAX(account_reconciliation_id), 0) + 1 AS total '
            . 'FROM `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . '`'
        );
        $assign = $conn->prepare(
            'UPDATE `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE
            . '` SET account_reconciliation_id = ? '
            . 'WHERE id = ? AND account_reconciliation_id IS NULL'
        );
        $assign->bind_param('ii', $nextId, $procurementReconciliationId);
        $assign->execute();
        $affected = $assign->affected_rows;
        $assign->close();
        if ($affected !== 1) {
            throw new RuntimeException(
                'The AcctLab reconciliation identifier changed before assignment completed.',
                409
            );
        }
        return $nextId;
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s', $lockName);
        $release->execute();
        $release->close();
    }
}
