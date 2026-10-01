<?php

declare(strict_types=1);

if (!defined('ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE')) {
    define(
        'ADVANCE_PO_RECONCILIATION_CANONICAL_TABLE',
        'procurement_local_advance_po_revision_reconciliations'
    );
}
if (!defined('ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE')) {
    define('ADVANCE_PO_RECONCILIATION_ACCOUNT_TABLE', 'account_advance_po_reconciliations');
}
if (!defined('ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE')) {
    define(
        'ADVANCE_PO_RECONCILIATION_ALLOCATION_TABLE',
        'account_advance_po_reconciliation_allocations'
    );
}
if (!defined('ADVANCE_PO_RECONCILIATION_EXPECTED_FINAL_BASE_TABLE_COUNT')) {
    define('ADVANCE_PO_RECONCILIATION_EXPECTED_FINAL_BASE_TABLE_COUNT', 65);
}

function advancePoReconciliationObjectType(mysqli $conn, string $object): ?string
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
    return $type === null ? null : strtoupper((string) $type);
}

function advancePoReconciliationBaseTableCount(mysqli $conn): int
{
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_TYPE = 'BASE TABLE'"
    );
    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function advancePoReconciliationScalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('PO reconciliation query failed: ' . $conn->error);
    }
    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function advancePoReconciliationColumns(mysqli $conn, string $object): array
{
    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->bind_param('s', $object);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['COLUMN_NAME'];
    }
    $stmt->close();
    return $columns;
}
