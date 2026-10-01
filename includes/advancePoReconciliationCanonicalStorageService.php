<?php

declare(strict_types=1);

require_once __DIR__ . '/advancePoReconciliationCanonicalMetadataService.php';

const ADVANCE_PO_RECONCILIATION_CANONICAL_STORAGE_INDEX =
    'uq_local_advance_account_reconciliation_id';

function advancePoReconciliationCanonicalStorageColumns(): array
{
    return [
        'pending_reallocated_amount' =>
            "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_pending_at_revision`",
        'supplementary_amount' =>
            "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `pending_reallocated_amount`",
        'supplementary_amount_paid' =>
            "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `supplementary_amount`",
        'recovery_amount' =>
            "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `supplementary_amount_paid`",
        'allocation_snapshot_json' =>
            "LONGTEXT NULL AFTER `resolution_notes`",
    ];
}

function advancePoReconciliationCanonicalStorageColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $table = ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) === 1;
    $stmt->close();
    return $exists;
}

function advancePoReconciliationCanonicalStorageIndexExists(mysqli $conn): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(DISTINCT INDEX_NAME) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0'
    );
    $table = ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE;
    $index = ADVANCE_PO_RECONCILIATION_CANONICAL_STORAGE_INDEX;
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) === 1;
    $stmt->close();
    return $exists;
}

function advancePoReconciliationCanonicalStorageColumnsState(mysqli $conn): array
{
    $state = [];
    foreach (array_keys(advancePoReconciliationCanonicalStorageColumns()) as $column) {
        $state[$column] = advancePoReconciliationCanonicalStorageColumnExists($conn, $column);
    }
    return $state;
}

/**
 * Historical field comparison is only meaningful while the duplicated Account
 * header is still a BASE TABLE. Once it is a compatibility VIEW (or retired),
 * the canonical header is the authority and this check is self-contained.
 */
function advancePoReconciliationCanonicalStorageMismatchCount(mysqli $conn): int
{
    $columns = advancePoReconciliationCanonicalStorageColumnsState($conn);
    if (in_array(false, $columns, true)) {
        return -1;
    }

    if (advancePoReconciliationObjectType($conn, ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE) !== 'BASE TABLE') {
        return 0;
    }

    $accountTable = ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE;
    $canonicalTable = ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE;
    return advancePoReconciliationScalar(
        $conn,
        "SELECT COUNT(*) AS total\n"
        . "FROM `{$accountTable}` account_row\n"
        . "INNER JOIN `{$canonicalTable}` canonical\n"
        . "  ON canonical.id = account_row.procurement_reconciliation_id\n"
        . "WHERE NOT (\n"
        . "    canonical.account_reconciliation_id <=> account_row.id\n"
        . "    AND canonical.pending_reallocated_amount <=> account_row.pending_reallocated_amount\n"
        . "    AND canonical.supplementary_amount <=> account_row.supplementary_amount\n"
        . "    AND canonical.supplementary_amount_paid <=> account_row.supplementary_amount_paid\n"
        . "    AND canonical.recovery_amount <=> account_row.recovery_amount\n"
        . "    AND BINARY canonical.allocation_snapshot_json <=> BINARY account_row.allocation_snapshot_json\n"
        . ")"
    );
}

function advancePoReconciliationCanonicalStorageVerify(mysqli $conn): array
{
    $columnState = advancePoReconciliationCanonicalStorageColumnsState($conn);
    $columnsReady = !in_array(false, $columnState, true);
    $indexReady = advancePoReconciliationCanonicalStorageIndexExists($conn);
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
    $mismatchCount = $columnsReady
        ? advancePoReconciliationCanonicalStorageMismatchCount($conn)
        : null;
    $baseTableCount = advancePoReconciliationBaseTableCount($conn);
    $expectedBaseTableCount = $accountType === 'BASE TABLE'
        ? ADVANCE_PO_RECONCILIATION_EXPECTED_FINAL_BASE_TABLE_COUNT + 1
        : ADVANCE_PO_RECONCILIATION_EXPECTED_FINAL_BASE_TABLE_COUNT;

    $canonicalCount = $canonicalType === 'BASE TABLE'
        ? advancePoReconciliationScalar(
            $conn,
            'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE . '`'
        )
        : 0;
    $allocationCount = $allocationType === 'BASE TABLE'
        ? advancePoReconciliationScalar(
            $conn,
            'SELECT COUNT(*) AS total FROM `' . ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE . '`'
        )
        : 0;

    $checks = [
        'canonical_header_remains_base_table' => $canonicalType === 'BASE TABLE',
        'allocation_detail_remains_base_table' => $allocationType === 'BASE TABLE',
        'account_compatibility_object_is_phase_safe' => in_array(
            $accountType,
            ['BASE TABLE', 'VIEW', null],
            true
        ),
        'five_account_summary_columns_present' => $columnsReady,
        'account_public_id_unique_index_present' => $indexReady,
        'legacy_header_fields_match_when_legacy_base_table_exists' => $mismatchCount === 0,
        'base_table_count_matches_current_phase' => $baseTableCount === $expectedBaseTableCount,
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'healthy' => $healthy,
        'ready_for_runtime_cutover' => $healthy,
        'checks' => $checks,
        'column_state' => $columnState,
        'unique_index' => [
            'name' => ADVANCE_PO_RECONCILIATION_CANONICAL_STORAGE_INDEX,
            'present' => $indexReady,
        ],
        'account_summary_field_mismatches' => $mismatchCount,
        'counts' => [
            'canonical_reconciliation_headers' => $canonicalCount,
            'allocation_details' => $allocationCount,
        ],
        'object_types' => [
            ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE => $canonicalType,
            ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE => $accountType,
            ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE => $allocationType,
        ],
        'base_table_count' => $baseTableCount,
        'expected_base_table_count' => $expectedBaseTableCount,
        'table_count_change' => 0,
    ];
}

function advancePoReconciliationCanonicalStoragePlan(mysqli $conn): array
{
    $verification = advancePoReconciliationCanonicalStorageVerify($conn);
    $accountType = $verification['object_types'][ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE] ?? null;

    return [
        'status' => ($verification['healthy'] ?? false) === true ? 'success' : 'blocked',
        'mode' => 'plan',
        'verification' => $verification,
        'planned_columns' => advancePoReconciliationCanonicalStorageColumns(),
        'planned_unique_index' => [
            'name' => ADVANCE_PO_RECONCILIATION_CANONICAL_STORAGE_INDEX,
            'column' => 'account_reconciliation_id',
        ],
        'planned_backfill' => [
            'required' => $accountType === 'BASE TABLE',
            'source_phase' => $accountType,
            'target' => ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE,
            'preserves_account_reconciliation_id' => true,
            'fields' => array_keys(advancePoReconciliationCanonicalStorageColumns()),
        ],
        'runtime_cutover' => false,
        'destructive_changes' => false,
        'database_changes_applied' => false,
        'table_count_change' => 0,
    ];
}

function advancePoReconciliationCanonicalStorageEnsureColumn(
    mysqli $conn,
    string $column,
    string $definition
): void {
    if (advancePoReconciliationCanonicalStorageColumnExists($conn, $column)) {
        return;
    }
    $table = ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE;
    if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
        throw new RuntimeException(
            'Unable to add canonical PO reconciliation column ' . $column . ': ' . $conn->error
        );
    }
}

function advancePoReconciliationCanonicalStorageEnsureIndex(mysqli $conn): void
{
    if (advancePoReconciliationCanonicalStorageIndexExists($conn)) {
        return;
    }
    $table = ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE;
    $index = ADVANCE_PO_RECONCILIATION_CANONICAL_STORAGE_INDEX;
    if (!$conn->query(
        "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`account_reconciliation_id`)"
    )) {
        throw new RuntimeException(
            'Unable to add canonical PO reconciliation account-ID index: ' . $conn->error
        );
    }
}

function advancePoReconciliationCanonicalStorageBackfill(mysqli $conn): int
{
    if (advancePoReconciliationObjectType($conn, ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE) !== 'BASE TABLE') {
        return 0;
    }

    $canonicalTable = ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE;
    $accountTable = ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE;
    $sql = "UPDATE `{$canonicalTable}` canonical\n"
        . "INNER JOIN `{$accountTable}` account_row\n"
        . "  ON canonical.id = account_row.procurement_reconciliation_id\n"
        . "SET canonical.account_reconciliation_id = account_row.id,\n"
        . "    canonical.pending_reallocated_amount = account_row.pending_reallocated_amount,\n"
        . "    canonical.supplementary_amount = account_row.supplementary_amount,\n"
        . "    canonical.supplementary_amount_paid = account_row.supplementary_amount_paid,\n"
        . "    canonical.recovery_amount = account_row.recovery_amount,\n"
        . "    canonical.allocation_snapshot_json = account_row.allocation_snapshot_json";
    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'Unable to backfill canonical PO reconciliation summaries: ' . $conn->error
        );
    }
    return (int) $conn->affected_rows;
}

function advancePoReconciliationCanonicalStorageApply(mysqli $conn): array
{
    $accountType = advancePoReconciliationObjectType(
        $conn,
        ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE
    );
    $columnState = advancePoReconciliationCanonicalStorageColumnsState($conn);
    $indexReady = advancePoReconciliationCanonicalStorageIndexExists($conn);
    if ($accountType !== 'BASE TABLE'
        && (in_array(false, $columnState, true) || !$indexReady)) {
        throw new RuntimeException(
            'Canonical PO reconciliation storage is incomplete after legacy header retirement; restore the pre-cutover backup before attempting historical preparation.'
        );
    }

    foreach (advancePoReconciliationCanonicalStorageColumns() as $column => $definition) {
        advancePoReconciliationCanonicalStorageEnsureColumn($conn, $column, $definition);
    }
    advancePoReconciliationCanonicalStorageEnsureIndex($conn);
    $backfilledRows = advancePoReconciliationCanonicalStorageBackfill($conn);
    $verification = advancePoReconciliationCanonicalStorageVerify($conn);
    if (($verification['healthy'] ?? false) !== true) {
        throw new RuntimeException(
            'Canonical PO reconciliation storage verification failed after preparation.'
        );
    }

    return [
        'status' => 'success',
        'mode' => 'apply',
        'verification' => $verification,
        'backfilled_rows' => $backfilledRows,
        'runtime_cutover' => false,
        'destructive_changes' => false,
        'database_changes_applied' => $backfilledRows > 0,
        'table_count_change' => 0,
    ];
}
