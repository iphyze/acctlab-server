<?php

declare(strict_types=1);

/**
 * Allocate public/source identifiers across the ACTIVE + ARCHIVE pair.
 *
 * The unified read facade gives ACTIVE precedence when identities overlap, so
 * new ACTIVE rows must never reuse an identity that already exists only in the
 * archive database.  This helper keeps allocation safe after every cutover.
 */

function archiveIdentitySafeIdentifier(string $identifier): string
{
    if ($identifier === '' || !preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe database identifier.');
    }
    return '`' . $identifier . '`';
}

function archiveIdentityConfiguredDatabaseName(mysqli $conn, string $kind): ?string
{
    if (!in_array($kind, ['active', 'archive'], true)) {
        throw new InvalidArgumentException('Unsupported archive identity database kind.');
    }

    $globalKey = $kind === 'active' ? 'activeDatabaseName' : 'archiveDatabaseName';
    $name = trim((string) ($GLOBALS[$globalKey] ?? ''));
    if ($name === '') {
        $sessionVariable = $kind === 'active' ? '@active_database_name' : '@archive_database_name';
        $result = $conn->query("SELECT {$sessionVariable} AS database_name");
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $name = trim((string) ($row['database_name'] ?? ''));
    }

    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        return null;
    }
    return $name;
}

function archiveIdentityColumnExists(
    mysqli $conn,
    string $database,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function archiveIdentityScopedMax(
    mysqli $conn,
    string $database,
    string $table,
    string $identityColumn,
    string $scopeColumn,
    string $scopeValue
): int {
    if (!archiveIdentityColumnExists($conn, $database, $table, $identityColumn)
        || !archiveIdentityColumnExists($conn, $database, $table, $scopeColumn)) {
        return 0;
    }

    $databaseSql = archiveIdentitySafeIdentifier($database);
    $tableSql = archiveIdentitySafeIdentifier($table);
    $identitySql = archiveIdentitySafeIdentifier($identityColumn);
    $scopeSql = archiveIdentitySafeIdentifier($scopeColumn);

    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX({$identitySql}), 0) AS max_id
         FROM {$databaseSql}.{$tableSql}
         WHERE BINARY {$scopeSql} = BINARY ?"
    );
    $stmt->bind_param('s', $scopeValue);
    $stmt->execute();
    $max = (int) ($stmt->get_result()->fetch_assoc()['max_id'] ?? 0);
    $stmt->close();
    return max(0, $max);
}

function archiveIdentityNextScopedId(
    mysqli $conn,
    string $table,
    string $identityColumn,
    string $scopeColumn,
    string $scopeValue
): int {
    $activeDatabase = archiveIdentityConfiguredDatabaseName($conn, 'active');
    if ($activeDatabase === null) {
        throw new RuntimeException('Active database identity metadata is unavailable.', 500);
    }

    $activeMax = archiveIdentityScopedMax(
        $conn,
        $activeDatabase,
        $table,
        $identityColumn,
        $scopeColumn,
        $scopeValue
    );

    $archiveMax = 0;
    $archiveDatabase = archiveIdentityConfiguredDatabaseName($conn, 'archive');
    if ($archiveDatabase !== null && $archiveDatabase !== $activeDatabase) {
        $archiveMax = archiveIdentityScopedMax(
            $conn,
            $archiveDatabase,
            $table,
            $identityColumn,
            $scopeColumn,
            $scopeValue
        );
    }

    $next = max($activeMax, $archiveMax) + 1;
    if ($next <= 0) {
        throw new RuntimeException('Unable to allocate an archive-safe identifier.', 500);
    }
    return $next;
}
