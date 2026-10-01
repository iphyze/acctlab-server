<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/connection.php';

function singleDbSchemaExists(mysqli $conn, string $schema): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
    $stmt->bind_param('s', $schema);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

try {
    $database = (string) ($GLOBALS['activeDatabaseName'] ?? '');
    $row = $conn->query('SELECT DATABASE() AS db')->fetch_assoc();
    $selected = (string) ($row['db'] ?? '');
    $tableCount = (int) ($conn->query("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetch_assoc()['total'] ?? 0);
    $syntheticCash = (int) ($conn->query("SELECT COUNT(*) AS total FROM cash_transactions WHERE idempotency_key LIKE 'archive-cutover:%'")->fetch_assoc()['total'] ?? 0);

    $checks = [
        'canonical_database_selected' => $database !== '' && $selected === $database,
        'runtime_source_is_active' => ($GLOBALS['databaseReadSource'] ?? '') === 'active',
        'canonical_schema_has_expected_tables' => $tableCount >= 66,
        'archive_database_removed' => !singleDbSchemaExists($conn, 'lambert2_acctlab_archive'),
        'read_database_removed' => !singleDbSchemaExists($conn, 'lambert2_acctlab_read'),
        'synthetic_archive_cash_rows_removed' => $syntheticCash === 0,
    ];
    $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
    echo json_encode([
        'healthy' => $failed === [],
        'database' => $selected,
        'table_count' => $tableCount,
        'checks' => $checks,
        'failed' => $failed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['healthy' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
