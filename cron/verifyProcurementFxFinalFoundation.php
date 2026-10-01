<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/connection.php';

function procurementFxFinalVerifyObjectType(mysqli $conn, string $database, string $name): ?string
{
    $stmt = $conn->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $database, $name);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return is_string($type) ? strtoupper($type) : null;
}

function procurementFxFinalVerifyColumnExists(mysqli $conn, string $database, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function procurementFxFinalVerifyIndexExists(mysqli $conn, string $database, string $table, string $index): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $stmt->bind_param('sss', $database, $table, $index);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function procurementFxFinalVerifyConstraintExists(mysqli $conn, string $database, string $table, string $constraint): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK' LIMIT 1");
    $stmt->bind_param('sss', $database, $table, $constraint);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

$database = trim((string) ($GLOBALS['activeDatabaseName'] ?? envValue('DB_NAME', 'lambert2_acctlab_db')));
$table = 'procurement_requests';

try {
    $checks = [
        'single_database_procurement_requests_exists' => procurementFxFinalVerifyObjectType($conn, $database, $table) === 'BASE TABLE',
        'currency_column_present' => procurementFxFinalVerifyColumnExists($conn, $database, $table, 'currency'),
        'currency_index_present' => procurementFxFinalVerifyIndexExists($conn, $database, $table, 'idx_procurement_request_currency'),
        'currency_domain_guard_present' => procurementFxFinalVerifyConstraintExists($conn, $database, $table, 'chk_procurement_request_currency_domain'),
        'fx_final_currency_required_guard_present' => procurementFxFinalVerifyConstraintExists($conn, $database, $table, 'chk_procurement_fx_final_currency_required'),
    ];
    $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
    echo json_encode([
        'healthy' => $failed === [],
        'database' => $database,
        'checks' => $checks,
        'failed' => $failed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['healthy' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
