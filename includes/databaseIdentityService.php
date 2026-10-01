<?php

declare(strict_types=1);

/**
 * Allocate scoped public/source identifiers inside the single canonical DB.
 * Advisory locks remain in the calling services, so MAX()+1 allocation cannot
 * race another request using the same allocator.
 */

function databaseIdentitySafeIdentifier(string $identifier): string
{
    if ($identifier === '' || !preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return '`' . $identifier . '`';
}

function databaseIdentityColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function databaseIdentityNextScopedId(
    mysqli $conn,
    string $table,
    string $identityColumn,
    string $scopeColumn,
    string $scopeValue
): int {
    if (!databaseIdentityColumnExists($conn, $table, $identityColumn)
        || !databaseIdentityColumnExists($conn, $table, $scopeColumn)) {
        throw new RuntimeException('Canonical identity metadata is unavailable.', 500);
    }

    $tableSql = databaseIdentitySafeIdentifier($table);
    $identitySql = databaseIdentitySafeIdentifier($identityColumn);
    $scopeSql = databaseIdentitySafeIdentifier($scopeColumn);

    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX({$identitySql}), 0) AS max_id
         FROM {$tableSql}
         WHERE BINARY {$scopeSql} = BINARY ?"
    );
    $stmt->bind_param('s', $scopeValue);
    $stmt->execute();
    $max = (int) ($stmt->get_result()->fetch_assoc()['max_id'] ?? 0);
    $stmt->close();

    $next = $max + 1;
    if ($next <= 0) {
        throw new RuntimeException('Unable to allocate a canonical identifier.', 500);
    }
    return $next;
}
