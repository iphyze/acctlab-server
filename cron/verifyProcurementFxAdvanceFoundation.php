<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/procurementRequestCanonicalRuntimeService.php';

$database = (string) ($conn->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] ?? '');

$columnExists = static function (string $table, string $column) use ($conn, $database): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
};
$indexExists = static function (string $table, string $index) use ($conn, $database): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('sss', $database, $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
};
$constraintExists = static function (string $table, string $constraint) use ($conn, $database): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $stmt->bind_param('sss', $database, $table, $constraint);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
};

$checks = [
    'single_database_procurement_requests_exists' => procurementRequestCanonicalRuntimeObjectType($conn, 'procurement_requests') === 'BASE TABLE',
    'fx_advance_request_currency_column_present' => $columnExists('procurement_requests', 'currency'),
    'shared_po_scope_column_present' => $columnExists('procurement_local_advance_pos', 'request_scope'),
    'shared_po_currency_column_present' => $columnExists('procurement_local_advance_pos', 'currency'),
    'shared_revision_scope_currency_present' => $columnExists('procurement_local_advance_po_revisions', 'request_scope')
        && $columnExists('procurement_local_advance_po_revisions', 'currency'),
    'shared_reconciliation_scope_currency_present' => $columnExists('procurement_local_advance_po_revision_reconciliations', 'request_scope')
        && $columnExists('procurement_local_advance_po_revision_reconciliations', 'currency'),
    'scoped_po_unique_index_present' => $indexExists('procurement_local_advance_pos', 'uq_advance_po_scope_number'),
    'fx_advance_currency_guard_present' => $constraintExists('procurement_requests', 'chk_procurement_fx_advance_currency_required'),
];

try {
    procurementRequestCanonicalFxAdvanceAssertReady($conn);
    $checks['canonical_fx_advance_runtime_ready'] = true;
} catch (Throwable $error) {
    $checks['canonical_fx_advance_runtime_ready'] = false;
}

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode([
    'healthy' => $failed === [],
    'database' => $database,
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
