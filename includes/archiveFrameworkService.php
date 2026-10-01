<?php

declare(strict_types=1);

/**
 * Reusable archive-schema + unified-read facade planner.
 *
 * Safety characteristics:
 * - additive archive schema sync only (missing tables/columns/indexes);
 * - no active/archive row mutations;
 * - no table/column/index drops;
 * - future tables default to active-only reads until explicitly classified;
 * - combined views prefer ACTIVE rows when an identity key is available.
 */

function archiveFrameworkQuoteIdentifier(string $identifier): string
{
    if ($identifier === '' || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
    }

    return '`' . $identifier . '`';
}

function archiveFrameworkQualified(string $database, string $table): string
{
    return archiveFrameworkQuoteIdentifier($database) . '.' . archiveFrameworkQuoteIdentifier($table);
}

function archiveFrameworkBaseTables(mysqli $conn, string $database): array
{
    $sql = 'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $database);
    $stmt->execute();
    $result = $stmt->get_result();
    $tables = [];
    while ($row = $result->fetch_assoc()) {
        $tables[] = (string) $row['TABLE_NAME'];
    }
    $stmt->close();

    return $tables;
}

function archiveFrameworkViews(mysqli $conn, string $database): array
{
    $sql = 'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $database);
    $stmt->execute();
    $result = $stmt->get_result();
    $views = [];
    while ($row = $result->fetch_assoc()) {
        $views[] = (string) $row['TABLE_NAME'];
    }
    $stmt->close();

    return $views;
}

function archiveFrameworkShowCreateTable(mysqli $conn, string $database, string $table): string
{
    $qualified = archiveFrameworkQualified($database, $table);
    $result = $conn->query('SHOW CREATE TABLE ' . $qualified);
    $row = $result->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('Unable to read CREATE TABLE for ' . $database . '.' . $table);
    }

    $values = array_values($row);
    return (string) ($values[1] ?? '');
}

function archiveFrameworkParseCreateComponents(string $createSql): array
{
    $columns = [];
    $indexes = [];
    $lines = preg_split('/\R/', $createSql) ?: [];

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        $line = rtrim($line, ',');
        if ($line === '' || $line === '(' || $line === ')') {
            continue;
        }

        if (preg_match('/^`([^`]+)`\s+(.+)$/s', $line, $matches) === 1) {
            $columns[$matches[1]] = $line;
            continue;
        }

        if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|FULLTEXT KEY|SPATIAL KEY)\b/i', $line) === 1) {
            $normalized = preg_replace('/\s+/', ' ', $line) ?: $line;
            $indexes[$normalized] = $line;
        }
    }

    return [
        'columns' => $columns,
        'indexes' => $indexes,
    ];
}

function archiveFrameworkColumnNames(mysqli $conn, string $database, string $table): array
{
    $sql = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['COLUMN_NAME'];
    }
    $stmt->close();

    return $columns;
}

function archiveFrameworkPrimaryKeyColumns(mysqli $conn, string $database, string $table): array
{
    $sql = 'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = \'PRIMARY\' '
        . 'ORDER BY ORDINAL_POSITION';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['COLUMN_NAME'];
    }
    $stmt->close();

    if ($columns !== []) {
        return $columns;
    }

    // Safe fallback: first unique index whose columns are all non-nullable.
    $sql = 'SELECT s.INDEX_NAME, s.COLUMN_NAME, s.SEQ_IN_INDEX, c.IS_NULLABLE '
        . 'FROM information_schema.STATISTICS s '
        . 'JOIN information_schema.COLUMNS c '
        . '  ON c.TABLE_SCHEMA = s.TABLE_SCHEMA AND c.TABLE_NAME = s.TABLE_NAME AND c.COLUMN_NAME = s.COLUMN_NAME '
        . 'WHERE s.TABLE_SCHEMA = ? AND s.TABLE_NAME = ? AND s.NON_UNIQUE = 0 '
        . 'ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $uniqueIndexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexName = (string) $row['INDEX_NAME'];
        $uniqueIndexes[$indexName] ??= ['columns' => [], 'nullable' => false];
        $uniqueIndexes[$indexName]['columns'][] = (string) $row['COLUMN_NAME'];
        if (strtoupper((string) $row['IS_NULLABLE']) === 'YES') {
            $uniqueIndexes[$indexName]['nullable'] = true;
        }
    }
    $stmt->close();

    foreach ($uniqueIndexes as $index) {
        if (!$index['nullable'] && $index['columns'] !== []) {
            return $index['columns'];
        }
    }

    return [];
}

function archiveFrameworkTableExists(mysqli $conn, string $database, string $table): bool
{
    $sql = 'SELECT 1 FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function archiveFrameworkBuildMissingColumnSql(
    string $archiveDatabase,
    string $table,
    string $columnDefinition,
    ?string $previousColumn
): string {
    $position = $previousColumn === null
        ? ' FIRST'
        : ' AFTER ' . archiveFrameworkQuoteIdentifier($previousColumn);

    return 'ALTER TABLE ' . archiveFrameworkQualified($archiveDatabase, $table)
        . ' ADD COLUMN ' . $columnDefinition . $position . ';';
}

function archiveFrameworkBuildViewSql(
    string $readDatabase,
    string $activeDatabase,
    string $archiveDatabase,
    string $table,
    array $columns,
    string $readMode,
    array $identityColumns
): array {
    $view = archiveFrameworkQualified($readDatabase, $table);
    $active = archiveFrameworkQualified($activeDatabase, $table);
    $archive = archiveFrameworkQualified($archiveDatabase, $table);

    $activeColumns = implode(', ', array_map(
        static fn (string $column): string => 'a.' . archiveFrameworkQuoteIdentifier($column),
        $columns
    ));

    // Synthetic cash carry-forward rows exist only to preserve the ACTIVE
    // operational balance after an archive cutover. They are not genuine
    // user-facing history and must never be exposed through the combined
    // read facade, otherwise archived cash movements are counted twice.
    $activeVisibilityPredicate = '';
    $archiveVisibilityPredicate = '';
    $activeDedupeVisibilityPredicate = '';
    if ($table === 'cash_transactions' && in_array('idempotency_key', $columns, true)) {
        $activeVisibilityPredicate = PHP_EOL
            . "WHERE (a.`idempotency_key` IS NULL OR a.`idempotency_key` NOT LIKE 'archive-cutover:%')";
        $archiveVisibilityPredicate = "(ar.`idempotency_key` IS NULL OR ar.`idempotency_key` NOT LIKE 'archive-cutover:%')";
        $activeDedupeVisibilityPredicate = "(a2.`idempotency_key` IS NULL OR a2.`idempotency_key` NOT LIKE 'archive-cutover:%') AND ";
    }

    if ($readMode !== 'combined') {
        return [
            'sql' => 'CREATE OR REPLACE SQL SECURITY INVOKER VIEW ' . $view . ' AS' . PHP_EOL
                . 'SELECT ' . $activeColumns . PHP_EOL
                . 'FROM ' . $active . ' AS a'
                . $activeVisibilityPredicate . ';',
            'dedupe_mode' => 'active_only',
        ];
    }

    $archiveColumns = implode(', ', array_map(
        static fn (string $column): string => 'ar.' . archiveFrameworkQuoteIdentifier($column),
        $columns
    ));

    if ($identityColumns !== []) {
        $identityPredicate = implode(' AND ', array_map(
            static fn (string $column): string => 'a2.' . archiveFrameworkQuoteIdentifier($column)
                . ' <=> ar.' . archiveFrameworkQuoteIdentifier($column),
            $identityColumns
        ));

        $archiveWhere = 'WHERE ';
        if ($archiveVisibilityPredicate !== '') {
            $archiveWhere .= $archiveVisibilityPredicate . PHP_EOL . '  AND ';
        }

        return [
            'sql' => 'CREATE OR REPLACE SQL SECURITY INVOKER VIEW ' . $view . ' AS' . PHP_EOL
                . 'SELECT ' . $activeColumns . PHP_EOL
                . 'FROM ' . $active . ' AS a'
                . $activeVisibilityPredicate . PHP_EOL
                . 'UNION ALL' . PHP_EOL
                . 'SELECT ' . $archiveColumns . PHP_EOL
                . 'FROM ' . $archive . ' AS ar' . PHP_EOL
                . $archiveWhere . 'NOT EXISTS (' . PHP_EOL
                . '    SELECT 1 FROM ' . $active . ' AS a2' . PHP_EOL
                . '    WHERE ' . $activeDedupeVisibilityPredicate . $identityPredicate . PHP_EOL
                . ');',
            'dedupe_mode' => 'active_precedence_identity',
        ];
    }

    // No stable identity: UNION removes exact duplicate rows while preserving
    // rows that genuinely differ. The table is surfaced in the plan for review.
    $archiveFallbackVisibility = $archiveVisibilityPredicate !== ''
        ? PHP_EOL . 'WHERE ' . $archiveVisibilityPredicate
        : '';

    return [
        'sql' => 'CREATE OR REPLACE SQL SECURITY INVOKER VIEW ' . $view . ' AS' . PHP_EOL
            . 'SELECT ' . $activeColumns . PHP_EOL
            . 'FROM ' . $active . ' AS a'
            . $activeVisibilityPredicate . PHP_EOL
            . 'UNION' . PHP_EOL
            . 'SELECT ' . $archiveColumns . PHP_EOL
            . 'FROM ' . $archive . ' AS ar'
            . $archiveFallbackVisibility . ';',
        'dedupe_mode' => 'exact_row_union_fallback',
    ];
}

function archiveFrameworkPlan(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $readDatabase,
    array $policy
): array {
    $activeTables = archiveFrameworkBaseTables($conn, $activeDatabase);
    $archiveTables = archiveFrameworkBaseTables($conn, $archiveDatabase);
    $archiveSet = array_fill_keys($archiveTables, true);
    $configured = (array) ($policy['tables'] ?? []);
    $defaultPolicy = (array) ($policy['default'] ?? []);

    $sql = [];
    $sql[] = '-- Archive framework additive schema sync + unified read facade.';
    $sql[] = '-- Generated at ' . date(DATE_ATOM) . '.';
    $sql[] = '-- No ACTIVE or ARCHIVE rows are modified by this migration.';
    $sql[] = 'CREATE DATABASE IF NOT EXISTS ' . archiveFrameworkQuoteIdentifier($readDatabase)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';

    $missingArchiveTables = [];
    $missingArchiveColumns = [];
    $missingArchiveIndexes = [];
    $blockingColumnDrift = [];
    $unclassifiedTables = [];
    $combinedTables = [];
    $activeOnlyTables = [];
    $fallbackIdentityTables = [];
    $viewDefinitions = [];

    foreach ($activeTables as $table) {
        $tablePolicy = array_merge($defaultPolicy, (array) ($configured[$table] ?? []));
        if (!array_key_exists($table, $configured)) {
            $unclassifiedTables[] = $table;
        }
        $readMode = (string) ($tablePolicy['read_mode'] ?? 'active_only');
        if (!in_array($readMode, ['active_only', 'combined'], true)) {
            $readMode = 'active_only';
        }

        $activeCreate = archiveFrameworkShowCreateTable($conn, $activeDatabase, $table);
        $activeComponents = archiveFrameworkParseCreateComponents($activeCreate);
        $columns = array_keys($activeComponents['columns']);

        if (!isset($archiveSet[$table])) {
            $missingArchiveTables[] = $table;
            $sql[] = 'CREATE TABLE IF NOT EXISTS ' . archiveFrameworkQualified($archiveDatabase, $table)
                . ' LIKE ' . archiveFrameworkQualified($activeDatabase, $table) . ';';
        } else {
            $archiveCreate = archiveFrameworkShowCreateTable($conn, $archiveDatabase, $table);
            $archiveComponents = archiveFrameworkParseCreateComponents($archiveCreate);
            $previous = null;
            foreach ($activeComponents['columns'] as $column => $definition) {
                if (!isset($archiveComponents['columns'][$column])) {
                    $missingArchiveColumns[$table][] = $column;
                    $sql[] = archiveFrameworkBuildMissingColumnSql(
                        $archiveDatabase,
                        $table,
                        $definition,
                        $previous
                    );
                } else {
                    $normalize = static fn (string $value): string => strtolower(trim(preg_replace('/\s+/', ' ', $value) ?: $value));
                    if ($normalize($definition) !== $normalize($archiveComponents['columns'][$column])) {
                        $blockingColumnDrift[$table][$column] = [
                            'active' => $definition,
                            'archive' => $archiveComponents['columns'][$column],
                        ];
                    }
                }
                $previous = $column;
            }

            $archiveIndexNormalized = array_fill_keys(array_keys($archiveComponents['indexes']), true);
            foreach ($activeComponents['indexes'] as $normalized => $definition) {
                if (!isset($archiveIndexNormalized[$normalized])) {
                    $missingArchiveIndexes[$table][] = $definition;
                    $sql[] = 'ALTER TABLE ' . archiveFrameworkQualified($archiveDatabase, $table)
                        . ' ADD ' . $definition . ';';
                }
            }
        }

        $identity = archiveFrameworkPrimaryKeyColumns($conn, $activeDatabase, $table);
        $view = archiveFrameworkBuildViewSql(
            $readDatabase,
            $activeDatabase,
            $archiveDatabase,
            $table,
            $columns,
            $readMode,
            $identity
        );
        $sql[] = $view['sql'];
        $viewDefinitions[$table] = [
            'read_mode' => $readMode,
            'identity_columns' => $identity,
            'dedupe_mode' => $view['dedupe_mode'],
            'archive_strategy' => (string) ($tablePolicy['archive_strategy'] ?? 'manual_review'),
        ];

        if ($readMode === 'combined') {
            $combinedTables[] = $table;
            if ($identity === []) {
                $fallbackIdentityTables[] = $table;
            }
        } else {
            $activeOnlyTables[] = $table;
        }
    }

    $readViews = archiveFrameworkViews($conn, $readDatabase);
    $staleReadViews = array_values(array_diff($readViews, $activeTables));
    $archiveOnlyTables = array_values(array_diff($archiveTables, $activeTables));

    $healthy = $blockingColumnDrift === [];

    return [
        'healthy' => $healthy,
        'policy_version' => $policy['version'] ?? null,
        'databases' => [
            'active' => $activeDatabase,
            'archive' => $archiveDatabase,
            'read' => $readDatabase,
        ],
        'counts' => [
            'active_tables' => count($activeTables),
            'archive_tables' => count($archiveTables),
            'read_views_before_refresh' => count($readViews),
            'configured_tables' => count($configured),
            'combined_tables' => count($combinedTables),
            'active_only_tables' => count($activeOnlyTables),
        ],
        'schema_sync' => [
            'missing_archive_tables' => $missingArchiveTables,
            'missing_archive_columns' => $missingArchiveColumns,
            'missing_archive_indexes' => $missingArchiveIndexes,
            'blocking_column_drift' => $blockingColumnDrift,
            'archive_only_tables_for_review' => $archiveOnlyTables,
        ],
        'read_facade' => [
            'combined_tables' => $combinedTables,
            'active_only_tables' => $activeOnlyTables,
            'identity_fallback_tables' => $fallbackIdentityTables,
            'stale_views_for_review' => $staleReadViews,
            'definitions' => $viewDefinitions,
        ],
        'future_schema_safety' => [
            'unclassified_active_tables' => $unclassifiedTables,
            'unclassified_default' => $defaultPolicy,
            'rule' => 'New active tables are never auto-archived. They default to active-only reads until explicitly classified, while schema refresh can create their empty archive counterpart and read view.',
        ],
        'sql' => implode(PHP_EOL . PHP_EOL, $sql) . PHP_EOL,
        'database_rows_mutated' => false,
        'destructive_changes' => false,
    ];
}

function archiveFrameworkVerify(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $readDatabase,
    array $policy
): array {
    $activeTables = archiveFrameworkBaseTables($conn, $activeDatabase);
    $archiveTables = archiveFrameworkBaseTables($conn, $archiveDatabase);
    $readViews = archiveFrameworkViews($conn, $readDatabase);
    $configured = (array) ($policy['tables'] ?? []);
    $defaultPolicy = (array) ($policy['default'] ?? []);

    $missingArchive = array_values(array_diff($activeTables, $archiveTables));
    $missingViews = array_values(array_diff($activeTables, $readViews));
    $columnDrift = [];
    $viewModes = [];

    foreach ($activeTables as $table) {
        if (in_array($table, $missingArchive, true)) {
            continue;
        }
        $active = archiveFrameworkParseCreateComponents(archiveFrameworkShowCreateTable($conn, $activeDatabase, $table));
        $archive = archiveFrameworkParseCreateComponents(archiveFrameworkShowCreateTable($conn, $archiveDatabase, $table));
        foreach ($active['columns'] as $column => $definition) {
            if (!isset($archive['columns'][$column])) {
                $columnDrift[$table][$column] = 'missing';
            }
        }
        $tablePolicy = array_merge($defaultPolicy, (array) ($configured[$table] ?? []));
        $viewModes[$table] = (string) ($tablePolicy['read_mode'] ?? 'active_only');
    }

    return [
        'healthy' => $missingArchive === [] && $missingViews === [] && $columnDrift === [],
        'counts' => [
            'active_tables' => count($activeTables),
            'archive_tables' => count($archiveTables),
            'read_views' => count($readViews),
        ],
        'missing_archive_tables' => $missingArchive,
        'missing_read_views' => $missingViews,
        'missing_archive_columns' => $columnDrift,
        'view_modes' => $viewModes,
        'database_rows_mutated' => false,
    ];
}

function archiveFrameworkCutoffPlan(
    mysqli $conn,
    string $activeDatabase,
    array $policy,
    string $cutoff
): array {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff);
    if (!$date || $date->format('Y-m-d') !== $cutoff) {
        throw new InvalidArgumentException('Cutoff must use YYYY-MM-DD format.');
    }

    $activeTables = array_fill_keys(archiveFrameworkBaseTables($conn, $activeDatabase), true);
    $configured = (array) ($policy['tables'] ?? []);
    $tablePlans = [];
    $blocked = [];
    $totalEligible = 0;

    foreach ($configured as $table => $tablePolicy) {
        if (!isset($activeTables[$table])) {
            continue;
        }

        $strategy = (string) ($tablePolicy['archive_strategy'] ?? 'manual_review');
        $entry = [
            'strategy' => $strategy,
            'eligible_rows' => 0,
            'selection' => null,
            'status' => 'skipped',
        ];

        if (in_array($strategy, ['never', 'transient', 'business_review', 'manual_review', 'parent', 'dependency_aware'], true)) {
            $entry['status'] = in_array($strategy, ['parent', 'dependency_aware'], true)
                ? 'dependency_planned_separately'
                : 'not_directly_archived';
            $tablePlans[$table] = $entry;
            continue;
        }

        $cutoffColumn = (string) ($tablePolicy['cutoff_column'] ?? '');
        if ($cutoffColumn === '') {
            $blocked[$table] = 'Missing cutoff_column in archive policy.';
            $entry['status'] = 'blocked';
            $tablePlans[$table] = $entry;
            continue;
        }

        $columns = archiveFrameworkColumnNames($conn, $activeDatabase, $table);
        if (!in_array($cutoffColumn, $columns, true)) {
            $blocked[$table] = 'Configured cutoff column does not exist: ' . $cutoffColumn;
            $entry['status'] = 'blocked';
            $tablePlans[$table] = $entry;
            continue;
        }

        $where = archiveFrameworkQuoteIdentifier($cutoffColumn) . ' < ?';
        $params = [$cutoff . ' 00:00:00'];
        $types = 's';

        if ($strategy === 'carry_forward') {
            $statusColumn = (string) ($tablePolicy['status_column'] ?? '');
            $keepStatuses = array_values(array_filter(array_map('strval', (array) ($tablePolicy['keep_statuses'] ?? []))));
            if ($statusColumn === '' || !in_array($statusColumn, $columns, true) || $keepStatuses === []) {
                $blocked[$table] = 'Carry-forward policy requires a valid status_column and keep_statuses.';
                $entry['status'] = 'blocked';
                $tablePlans[$table] = $entry;
                continue;
            }
            $placeholders = implode(', ', array_fill(0, count($keepStatuses), '?'));
            $where .= ' AND (' . archiveFrameworkQuoteIdentifier($statusColumn)
                . ' IS NULL OR ' . archiveFrameworkQuoteIdentifier($statusColumn)
                . ' NOT IN (' . $placeholders . '))';
            $params = array_merge($params, $keepStatuses);
            $types .= str_repeat('s', count($keepStatuses));
        }

        $sql = 'SELECT COUNT(*) AS total FROM ' . archiveFrameworkQualified($activeDatabase, $table)
            . ' WHERE ' . $where;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $count = (int) ($row['total'] ?? 0);
        $totalEligible += $count;

        $entry['eligible_rows'] = $count;
        $entry['selection'] = $where;
        $entry['status'] = 'planned';
        $tablePlans[$table] = $entry;
    }

    return [
        'healthy' => $blocked === [],
        'cutoff' => $cutoff,
        'total_directly_eligible_rows' => $totalEligible,
        'tables' => $tablePlans,
        'blocked' => $blocked,
        'note' => 'This is a read-only eligibility plan. Parent/dependency-aware groups are intentionally not deleted by this foundation planner.',
        'database_rows_mutated' => false,
    ];
}


/**
 * Build a concrete SQL predicate for a cutoff/carry-forward table.
 */
function archiveFrameworkConcreteSelectionWhere(
    mysqli $conn,
    string $database,
    string $table,
    array $tablePolicy,
    string $cutoff
): ?string {
    $strategy = (string) ($tablePolicy['archive_strategy'] ?? 'manual_review');
    if (!in_array($strategy, ['cutoff', 'carry_forward'], true)) {
        return null;
    }

    $cutoffColumn = (string) ($tablePolicy['cutoff_column'] ?? '');
    if ($cutoffColumn === '') {
        throw new RuntimeException('Missing cutoff_column for ' . $table);
    }

    $columns = archiveFrameworkColumnNames($conn, $database, $table);
    if (!in_array($cutoffColumn, $columns, true)) {
        throw new RuntimeException('Configured cutoff column does not exist for ' . $table . ': ' . $cutoffColumn);
    }

    $literal = "'" . $conn->real_escape_string($cutoff . ' 00:00:00') . "'";
    $where = archiveFrameworkQuoteIdentifier($cutoffColumn) . ' < ' . $literal;

    if ($strategy === 'carry_forward') {
        $statusColumn = (string) ($tablePolicy['status_column'] ?? '');
        $keepStatuses = array_values(array_filter(array_map('strval', (array) ($tablePolicy['keep_statuses'] ?? []))));
        if ($statusColumn === '' || !in_array($statusColumn, $columns, true) || $keepStatuses === []) {
            throw new RuntimeException('Carry-forward policy is incomplete for ' . $table);
        }
        $statusLiterals = implode(', ', array_map(
            static fn (string $status): string => "'" . $conn->real_escape_string($status) . "'",
            $keepStatuses
        ));
        $where .= ' AND (' . archiveFrameworkQuoteIdentifier($statusColumn)
            . ' IS NULL OR ' . archiveFrameworkQuoteIdentifier($statusColumn)
            . ' NOT IN (' . $statusLiterals . '))';
    }

    return $where;
}

function archiveFrameworkTempName(string $prefix, string $table): string
{
    $base = 'tmp_af_' . $prefix . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $table);
    if (strlen($base) <= 60) {
        return $base;
    }
    return substr($base, 0, 48) . '_' . substr(hash('sha256', $base), 0, 10);
}

function archiveFrameworkPkJoin(string $leftAlias, string $rightAlias, array $identityColumns): string
{
    if ($identityColumns === []) {
        throw new RuntimeException('Archive cutover requires a stable identity key.');
    }
    return implode(' AND ', array_map(
        static fn (string $column): string => $leftAlias . '.' . archiveFrameworkQuoteIdentifier($column)
            . ' <=> ' . $rightAlias . '.' . archiveFrameworkQuoteIdentifier($column),
        $identityColumns
    ));
}

function archiveFrameworkBuildStageSql(
    string $activeDatabase,
    string $table,
    array $identityColumns,
    string $where
): array {
    $stage = archiveFrameworkTempName('stage', $table);
    $pkList = implode(', ', array_map('archiveFrameworkQuoteIdentifier', $identityColumns));
    $qualified = archiveFrameworkQualified($activeDatabase, $table);
    $sql = [
        'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($stage) . ';',
        'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($stage)
            . ' AS SELECT ' . $pkList . ' FROM ' . $qualified . ' WHERE ' . $where . ';',
    ];
    if ($identityColumns !== []) {
        $sql[] = 'ALTER TABLE ' . archiveFrameworkQuoteIdentifier($stage)
            . ' ADD PRIMARY KEY (' . $pkList . ');';
    }
    return ['name' => $stage, 'sql' => $sql];
}

function archiveFrameworkBuildParentStageSql(
    mysqli $conn,
    string $activeDatabase,
    string $table,
    array $tablePolicy,
    array $stages
): array {
    $parentTable = (string) ($tablePolicy['parent_table'] ?? '');
    $parentFk = (string) ($tablePolicy['parent_fk'] ?? '');
    if ($parentTable === '' || $parentFk === '' || !isset($stages[$parentTable])) {
        throw new RuntimeException('Parent archive policy cannot resolve parent stage for ' . $table);
    }

    $identity = archiveFrameworkPrimaryKeyColumns($conn, $activeDatabase, $table);
    $parentIdentity = archiveFrameworkPrimaryKeyColumns($conn, $activeDatabase, $parentTable);
    if (count($parentIdentity) !== 1) {
        throw new RuntimeException('Parent strategy currently requires a single-column parent identity: ' . $parentTable);
    }
    $columns = archiveFrameworkColumnNames($conn, $activeDatabase, $table);
    if (!in_array($parentFk, $columns, true)) {
        throw new RuntimeException('Parent foreign-key column does not exist for ' . $table . ': ' . $parentFk);
    }

    $stage = archiveFrameworkTempName('stage', $table);
    $pkList = implode(', ', array_map(
        static fn (string $column): string => 'c.' . archiveFrameworkQuoteIdentifier($column),
        $identity
    ));
    $pkDefinition = implode(', ', array_map('archiveFrameworkQuoteIdentifier', $identity));
    $parentStage = archiveFrameworkQuoteIdentifier((string) $stages[$parentTable]['name']);
    $parentPk = archiveFrameworkQuoteIdentifier($parentIdentity[0]);

    $sql = [
        'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($stage) . ';',
        'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($stage)
            . ' AS SELECT ' . $pkList
            . ' FROM ' . archiveFrameworkQualified($activeDatabase, $table) . ' AS c'
            . ' INNER JOIN ' . $parentStage . ' AS p'
            . ' ON c.' . archiveFrameworkQuoteIdentifier($parentFk) . ' <=> p.' . $parentPk . ';',
        'ALTER TABLE ' . archiveFrameworkQuoteIdentifier($stage)
            . ' ADD PRIMARY KEY (' . $pkDefinition . ');',
    ];

    return ['name' => $stage, 'sql' => $sql, 'identity' => $identity];
}

function archiveFrameworkBuildUpsertSql(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $table,
    array $identityColumns,
    string $stageName,
    ?string $orderBy = null
): string {
    $columns = archiveFrameworkColumnNames($conn, $activeDatabase, $table);
    $columnList = implode(', ', array_map('archiveFrameworkQuoteIdentifier', $columns));
    $selectList = implode(', ', array_map(
        static fn (string $column): string => 'a.' . archiveFrameworkQuoteIdentifier($column),
        $columns
    ));
    $updates = implode(', ', array_map(
        static fn (string $column): string => archiveFrameworkQuoteIdentifier($column)
            . ' = VALUES(' . archiveFrameworkQuoteIdentifier($column) . ')',
        array_values(array_diff($columns, $identityColumns))
    ));
    if ($updates === '') {
        $first = $identityColumns[0] ?? $columns[0];
        $updates = archiveFrameworkQuoteIdentifier($first) . ' = VALUES(' . archiveFrameworkQuoteIdentifier($first) . ')';
    }

    $sql = 'INSERT INTO ' . archiveFrameworkQualified($archiveDatabase, $table)
        . ' (' . $columnList . ')' . PHP_EOL
        . 'SELECT ' . $selectList . PHP_EOL
        . 'FROM ' . archiveFrameworkQualified($activeDatabase, $table) . ' AS a' . PHP_EOL
        . 'INNER JOIN ' . archiveFrameworkQuoteIdentifier($stageName) . ' AS s ON '
        . archiveFrameworkPkJoin('a', 's', $identityColumns);
    if ($orderBy !== null && $orderBy !== '') {
        $sql .= PHP_EOL . 'ORDER BY ' . $orderBy;
    }
    $sql .= PHP_EOL . 'ON DUPLICATE KEY UPDATE ' . $updates . ';';
    return $sql;
}

function archiveFrameworkBuildArchiveGuardSql(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $table,
    array $identityColumns,
    string $stageName,
    string $guardName
): string {
    $columns = archiveFrameworkColumnNames($conn, $activeDatabase, $table);
    $equal = implode(' AND ', array_map(
        static fn (string $column): string => 'ar.' . archiveFrameworkQuoteIdentifier($column)
            . ' <=> a.' . archiveFrameworkQuoteIdentifier($column),
        $columns
    ));
    return 'INSERT INTO ' . archiveFrameworkQuoteIdentifier($guardName) . ' (`id`)' . PHP_EOL
        . 'SELECT 1 WHERE EXISTS (' . PHP_EOL
        . '  SELECT 1 FROM ' . archiveFrameworkQualified($activeDatabase, $table) . ' AS a' . PHP_EOL
        . '  INNER JOIN ' . archiveFrameworkQuoteIdentifier($stageName) . ' AS s ON '
        . archiveFrameworkPkJoin('a', 's', $identityColumns) . PHP_EOL
        . '  LEFT JOIN ' . archiveFrameworkQualified($archiveDatabase, $table) . ' AS ar ON '
        . archiveFrameworkPkJoin('ar', 'a', $identityColumns) . PHP_EOL
        . '  WHERE ar.' . archiveFrameworkQuoteIdentifier($identityColumns[0]) . ' IS NULL OR NOT (' . $equal . ')' . PHP_EOL
        . ');';
}

function archiveFrameworkBuildDeleteSql(
    string $activeDatabase,
    string $table,
    array $identityColumns,
    string $stageName
): string {
    return 'DELETE a FROM ' . archiveFrameworkQualified($activeDatabase, $table) . ' AS a'
        . ' INNER JOIN ' . archiveFrameworkQuoteIdentifier($stageName) . ' AS s ON '
        . archiveFrameworkPkJoin('a', 's', $identityColumns) . ';';
}

function archiveFrameworkBuildMasterReferenceSyncSql(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $table
): string {
    $identity = archiveFrameworkPrimaryKeyColumns($conn, $activeDatabase, $table);
    $columns = archiveFrameworkColumnNames($conn, $activeDatabase, $table);
    $columnList = implode(', ', array_map('archiveFrameworkQuoteIdentifier', $columns));
    $selectList = implode(', ', array_map(
        static fn (string $column): string => 'a.' . archiveFrameworkQuoteIdentifier($column),
        $columns
    ));
    $updates = implode(', ', array_map(
        static fn (string $column): string => archiveFrameworkQuoteIdentifier($column)
            . ' = VALUES(' . archiveFrameworkQuoteIdentifier($column) . ')',
        array_values(array_diff($columns, $identity))
    ));
    return 'INSERT INTO ' . archiveFrameworkQualified($archiveDatabase, $table)
        . ' (' . $columnList . ') SELECT ' . $selectList
        . ' FROM ' . archiveFrameworkQualified($activeDatabase, $table) . ' AS a'
        . ' ON DUPLICATE KEY UPDATE ' . $updates . ';';
}

/**
 * Generate a phpMyAdmin-ready, transaction-wrapped cutover SQL script.
 *
 * The script first upserts every selected row into ARCHIVE, verifies byte-for-byte
 * column equality using NULL-safe comparisons, then deletes only those verified
 * rows from ACTIVE. Parent/dependency groups are staged from the same cutoff set.
 */
function archiveFrameworkCutoverExecutionPlan(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $readDatabase,
    array $policy,
    string $cutoff,
    string $backupReference
): array {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff);
    if (!$date || $date->format('Y-m-d') !== $cutoff) {
        throw new InvalidArgumentException('Cutoff must use YYYY-MM-DD format.');
    }
    if (trim($backupReference) === '') {
        throw new InvalidArgumentException('--backup-reference is required before generating destructive cutover SQL.');
    }

    $foundation = archiveFrameworkVerify($conn, $activeDatabase, $archiveDatabase, $readDatabase, $policy);
    if (!$foundation['healthy']) {
        throw new RuntimeException('Archive framework verification must be healthy before cutover planning.');
    }

    $configured = (array) ($policy['tables'] ?? []);
    $stages = [];
    $stageSql = [];
    $stageMeta = [];
    $blocked = [];

    // 1) Direct cutoff/carry-forward stages, except cash special tables handled below.
    $cashSpecial = ['cash_transactions', 'cash_receipts', 'cash_transaction_edits'];
    foreach ($configured as $table => $tablePolicy) {
        $strategy = (string) ($tablePolicy['archive_strategy'] ?? 'manual_review');
        if (!in_array($strategy, ['cutoff', 'carry_forward'], true) || in_array($table, $cashSpecial, true)) {
            continue;
        }
        try {
            $where = archiveFrameworkConcreteSelectionWhere($conn, $activeDatabase, $table, $tablePolicy, $cutoff);
            if ($where === null) {
                continue;
            }

            $retainArtifacts = array_values((array) ($tablePolicy['retain_artifacts'] ?? []));
            if ($retainArtifacts !== []) {
                $artifactClauses = [];
                foreach ($retainArtifacts as $artifactRule) {
                    if (!is_array($artifactRule)) {
                        continue;
                    }
                    $artifactType = trim((string) ($artifactRule['artifact_type'] ?? ''));
                    $requestTypes = array_values(array_filter(array_map('strval', (array) ($artifactRule['request_types'] ?? []))));
                    if ($artifactType === '') {
                        continue;
                    }
                    $clause = "af.`artifact_type` = '" . $conn->real_escape_string($artifactType) . "'";
                    if ($requestTypes !== []) {
                        $requestTypeLiterals = implode(', ', array_map(
                            static fn (string $requestType): string => "'" . $conn->real_escape_string($requestType) . "'",
                            $requestTypes
                        ));
                        $clause .= ' AND af.`request_type` IN (' . $requestTypeLiterals . ')';
                    }
                    $artifactClauses[] = '(' . $clause . ')';
                }
                if ($artifactClauses !== []) {
                    $batchPolicy = (array) ($configured['account_payment_batches'] ?? []);
                    $batchStatusColumn = (string) ($batchPolicy['status_column'] ?? 'status');
                    $batchKeepStatuses = array_values(array_filter(array_map('strval', (array) ($batchPolicy['keep_statuses'] ?? []))));
                    $batchKeepLiterals = implode(', ', array_map(
                        static fn (string $status): string => "'" . $conn->real_escape_string($status) . "'",
                        $batchKeepStatuses
                    ));
                    $outerId = archiveFrameworkQuoteIdentifier($table) . '.`id`';
                    $where .= ' AND NOT EXISTS (SELECT 1 FROM '
                        . archiveFrameworkQualified($activeDatabase, 'account_payment_artifacts') . ' AS af'
                        . ' INNER JOIN ' . archiveFrameworkQualified($activeDatabase, 'account_payment_batches') . ' AS b'
                        . ' ON b.`id` = af.`batch_id`'
                        . ' WHERE af.`artifact_id` = ' . $outerId
                        . ' AND (' . implode(' OR ', $artifactClauses) . ')'
                        . " AND (b.`created_at` >= '" . $conn->real_escape_string($cutoff . ' 00:00:00') . "'"
                        . ($batchKeepLiterals !== '' ? ' OR b.' . archiveFrameworkQuoteIdentifier($batchStatusColumn) . ' IN (' . $batchKeepLiterals . ')' : '')
                        . '))';
                }
            }

            $identity = archiveFrameworkPrimaryKeyColumns($conn, $activeDatabase, $table);
            if ($identity === []) {
                throw new RuntimeException('No stable identity key.');
            }
            $stage = archiveFrameworkBuildStageSql($activeDatabase, $table, $identity, $where);
            $stages[$table] = ['name' => $stage['name'], 'identity' => $identity];
            $stageSql = array_merge($stageSql, $stage['sql']);
            $stageMeta[$table] = ['strategy' => $strategy, 'selection' => $where];
        } catch (Throwable $error) {
            $blocked[$table] = $error->getMessage();
        }
    }

    // 2) Parent stages. Iterate because parent stages can themselves depend on a staged parent.
    $remainingParents = [];
    foreach ($configured as $table => $tablePolicy) {
        if (($tablePolicy['archive_strategy'] ?? '') === 'parent') {
            $remainingParents[$table] = $tablePolicy;
        }
    }
    for ($pass = 0; $pass < 8 && $remainingParents !== []; $pass++) {
        $progress = false;
        foreach ($remainingParents as $table => $tablePolicy) {
            $parent = (string) ($tablePolicy['parent_table'] ?? '');
            if (!isset($stages[$parent])) {
                continue;
            }
            try {
                $stage = archiveFrameworkBuildParentStageSql($conn, $activeDatabase, $table, $tablePolicy, $stages);
                $stages[$table] = ['name' => $stage['name'], 'identity' => $stage['identity']];
                $stageSql = array_merge($stageSql, $stage['sql']);
                $stageMeta[$table] = ['strategy' => 'parent', 'parent_table' => $parent];
                unset($remainingParents[$table]);
                $progress = true;
            } catch (Throwable $error) {
                $blocked[$table] = $error->getMessage();
                unset($remainingParents[$table]);
            }
        }
        if (!$progress) {
            break;
        }
    }
    foreach ($remainingParents as $table => $tablePolicy) {
        $blocked[$table] = 'Parent stage could not be resolved: ' . (string) ($tablePolicy['parent_table'] ?? '');
    }

    // 3) Cash dependency closure. Closed IOUs and resolved mutilated rows may be archived,
    // but transactions needed by retained/open operational rows must remain active.
    $cashTxnIdentity = archiveFrameworkPrimaryKeyColumns($conn, $activeDatabase, 'cash_transactions');
    if ($cashTxnIdentity !== ['id']) {
        $blocked['cash_transactions'] = 'Cash dependency planner expects cash_transactions primary key id.';
    } elseif (!isset($stages['cash_ious'], $stages['cash_mutilated_cash'])) {
        $blocked['cash_transactions'] = 'Cash dependency planner requires cash_ious and cash_mutilated_cash stages.';
    } else {
        $keepCash = archiveFrameworkTempName('keep', 'cash_transactions');
        $stageCash = archiveFrameworkTempName('stage', 'cash_transactions');
        $cutoffLiteral = "'" . $conn->real_escape_string($cutoff . ' 00:00:00') . "'";
        $stageIou = archiveFrameworkQuoteIdentifier($stages['cash_ious']['name']);
        $stageMutilated = archiveFrameworkQuoteIdentifier($stages['cash_mutilated_cash']['name']);
        $stageSql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($keepCash) . ';';
        $stageSql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' (`id` bigint(20) UNSIGNED NOT NULL PRIMARY KEY);';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' SELECT `id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions')
            . ' WHERE `created_at` >= ' . $cutoffLiteral . ';';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' SELECT i.`source_transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_ious') . ' AS i'
            . ' LEFT JOIN ' . $stageIou . ' AS s ON s.`id` = i.`id`'
            . ' WHERE s.`id` IS NULL AND i.`source_transaction_id` IS NOT NULL;';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' SELECT a.`linked_transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_iou_actions') . ' AS a'
            . ' INNER JOIN ' . archiveFrameworkQualified($activeDatabase, 'cash_ious') . ' AS i ON i.`id` = a.`iou_id`'
            . ' LEFT JOIN ' . $stageIou . ' AS s ON s.`id` = i.`id`'
            . ' WHERE s.`id` IS NULL AND a.`linked_transaction_id` IS NOT NULL;';
        foreach (['source_transaction_id','source_receipt_transaction_id','linked_disbursement_transaction_id','set_aside_transaction_id','replacement_transaction_id','resolution_transaction_id'] as $column) {
            $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
                . ' SELECT m.' . archiveFrameworkQuoteIdentifier($column)
                . ' FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_mutilated_cash') . ' AS m'
                . ' LEFT JOIN ' . $stageMutilated . ' AS s ON s.`id` = m.`id`'
                . ' WHERE s.`id` IS NULL AND m.' . archiveFrameworkQuoteIdentifier($column) . ' IS NOT NULL;';
        }
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' SELECT r.`transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_receipts') . ' AS r'
            . ' WHERE r.`created_at` >= ' . $cutoffLiteral . ' AND r.`transaction_id` IS NOT NULL;';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' SELECT r.`transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_receipts') . ' AS r'
            . ' INNER JOIN ' . archiveFrameworkQualified($activeDatabase, 'cash_ious') . ' AS i ON i.`id` = r.`iou_id`'
            . ' LEFT JOIN ' . $stageIou . ' AS s ON s.`id` = i.`id`'
            . ' WHERE s.`id` IS NULL AND r.`transaction_id` IS NOT NULL;';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
            . ' SELECT e.`transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transaction_edits') . ' AS e'
            . ' WHERE e.`edited_at` >= ' . $cutoffLiteral . ';';
        // Preserve the full reversal chain for every retained transaction.
        for ($i = 0; $i < 16; $i++) {
            $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($keepCash)
                . ' SELECT t.`reversal_of_transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS t'
                . ' INNER JOIN ' . archiveFrameworkQuoteIdentifier($keepCash) . ' AS k ON k.`id` = t.`id`'
                . ' WHERE t.`reversal_of_transaction_id` IS NOT NULL;';
        }
        $stageSql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($stageCash) . ';';
        $stageSql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($stageCash)
            . ' AS SELECT t.`id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS t'
            . ' LEFT JOIN ' . archiveFrameworkQuoteIdentifier($keepCash) . ' AS k ON k.`id` = t.`id`'
            . ' WHERE t.`created_at` < ' . $cutoffLiteral . ' AND k.`id` IS NULL;';
        $stageSql[] = 'ALTER TABLE ' . archiveFrameworkQuoteIdentifier($stageCash) . ' ADD PRIMARY KEY (`id`);';
        $stages['cash_transactions'] = ['name' => $stageCash, 'identity' => ['id']];
        $stageMeta['cash_transactions'] = ['strategy' => 'dependency_aware'];

        // Old receipts/edits remain ACTIVE whenever their operational parent transaction/IOU remains active.
        $receiptStage = archiveFrameworkTempName('stage', 'cash_receipts');
        $stageSql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($receiptStage) . ';';
        $stageSql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($receiptStage)
            . ' AS SELECT r.`id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_receipts') . ' AS r'
            . ' LEFT JOIN ' . $stageIou . ' AS si ON si.`id` = r.`iou_id`'
            . ' LEFT JOIN ' . archiveFrameworkQuoteIdentifier($stageCash) . ' AS st ON st.`id` = r.`transaction_id`'
            . ' WHERE r.`created_at` < ' . $cutoffLiteral
            . ' AND ((r.`iou_id` IS NOT NULL AND si.`id` IS NOT NULL)'
            . ' OR (r.`iou_id` IS NULL AND (r.`transaction_id` IS NULL OR st.`id` IS NOT NULL)));';
        $stageSql[] = 'ALTER TABLE ' . archiveFrameworkQuoteIdentifier($receiptStage) . ' ADD PRIMARY KEY (`id`);';
        $stages['cash_receipts'] = ['name' => $receiptStage, 'identity' => ['id']];
        $stageMeta['cash_receipts'] = ['strategy' => 'dependency_aware'];

        $editStage = archiveFrameworkTempName('stage', 'cash_transaction_edits');
        $stageSql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($editStage) . ';';
        $stageSql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($editStage)
            . ' AS SELECT e.`id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transaction_edits') . ' AS e'
            . ' INNER JOIN ' . archiveFrameworkQuoteIdentifier($stageCash) . ' AS st ON st.`id` = e.`transaction_id`'
            . ' WHERE e.`edited_at` < ' . $cutoffLiteral . ';';
        $stageSql[] = 'ALTER TABLE ' . archiveFrameworkQuoteIdentifier($editStage) . ' ADD PRIMARY KEY (`id`);';
        $stages['cash_transaction_edits'] = ['name' => $editStage, 'identity' => ['id']];
        $stageMeta['cash_transaction_edits'] = ['strategy' => 'dependency_aware'];

        // Archive-side FK safety: sync every cash transaction referenced by a row
        // being archived, even when that transaction itself must remain ACTIVE.
        $syncCash = archiveFrameworkTempName('sync', 'cash_transactions');
        $stageSql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($syncCash) . ';';
        $stageSql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($syncCash)
            . ' (`id` bigint(20) UNSIGNED NOT NULL PRIMARY KEY);';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($syncCash)
            . ' SELECT `id` FROM ' . archiveFrameworkQuoteIdentifier($stageCash) . ';';
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($syncCash)
            . ' SELECT i.`source_transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_ious') . ' AS i'
            . ' INNER JOIN ' . $stageIou . ' AS s ON s.`id` = i.`id`'
            . ' WHERE i.`source_transaction_id` IS NOT NULL;';
        if (isset($stages['cash_iou_actions'])) {
            $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($syncCash)
                . ' SELECT a.`linked_transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_iou_actions') . ' AS a'
                . ' INNER JOIN ' . archiveFrameworkQuoteIdentifier($stages['cash_iou_actions']['name']) . ' AS s ON s.`id` = a.`id`'
                . ' WHERE a.`linked_transaction_id` IS NOT NULL;';
        }
        $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($syncCash)
            . ' SELECT r.`transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_receipts') . ' AS r'
            . ' INNER JOIN ' . archiveFrameworkQuoteIdentifier($receiptStage) . ' AS s ON s.`id` = r.`id`'
            . ' WHERE r.`transaction_id` IS NOT NULL;';
        foreach (['source_transaction_id','source_receipt_transaction_id','linked_disbursement_transaction_id','set_aside_transaction_id','replacement_transaction_id','resolution_transaction_id'] as $column) {
            $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($syncCash)
                . ' SELECT m.' . archiveFrameworkQuoteIdentifier($column)
                . ' FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_mutilated_cash') . ' AS m'
                . ' INNER JOIN ' . $stageMutilated . ' AS s ON s.`id` = m.`id`'
                . ' WHERE m.' . archiveFrameworkQuoteIdentifier($column) . ' IS NOT NULL;';
        }
        // Sync reversal parents as well; IDs are older than their reversing rows.
        for ($i = 0; $i < 16; $i++) {
            $stageSql[] = 'INSERT IGNORE INTO ' . archiveFrameworkQuoteIdentifier($syncCash)
                . ' SELECT t.`reversal_of_transaction_id` FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS t'
                . ' INNER JOIN ' . archiveFrameworkQuoteIdentifier($syncCash) . ' AS s ON s.`id` = t.`id`'
                . ' WHERE t.`reversal_of_transaction_id` IS NOT NULL;';
        }
        $stageMeta['cash_transaction_archive_sync'] = ['strategy' => 'archive_fk_dependency_sync', 'stage' => $syncCash];

        // Preserve total cash balance after historical transactions are removed.
        $opening = archiveFrameworkTempName('opening', 'cash_transactions');
        $stageSql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($opening) . ';';
        $stageSql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($opening) . ' AS'
            . ' SELECT a.`id` AS `account_id`,'
            . ' COALESCE(SUM(CASE WHEN t.`status` IN (\'POSTED\',\'REVERSED\') THEN'
            . ' CASE WHEN t.`transaction_type` IN (\'MUTILATED_CASH_SET_ASIDE\',\'MUTILATED_CASH_REPLACEMENT\') THEN 0'
            . ' WHEN t.`direction` = \'IN\' THEN t.`amount` ELSE -t.`amount` END ELSE 0 END),0) AS `original_balance`,'
            . ' COALESCE(SUM(CASE WHEN st.`id` IS NULL AND t.`status` IN (\'POSTED\',\'REVERSED\') THEN'
            . ' CASE WHEN t.`transaction_type` IN (\'MUTILATED_CASH_SET_ASIDE\',\'MUTILATED_CASH_REPLACEMENT\') THEN 0'
            . ' WHEN t.`direction` = \'IN\' THEN t.`amount` ELSE -t.`amount` END ELSE 0 END),0) AS `retained_balance`'
            . ' FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_accounts') . ' AS a'
            . ' LEFT JOIN ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS t ON t.`account_id` = a.`id`'
            . ' LEFT JOIN ' . archiveFrameworkQuoteIdentifier($stageCash) . ' AS st ON st.`id` = t.`id`'
            . ' GROUP BY a.`id`;';
        $stageMeta['cash_opening_balance'] = ['strategy' => 'balance_carry_forward', 'stage' => $opening];
    }

    if ($blocked !== []) {
        return [
            'healthy' => false,
            'cutoff' => $cutoff,
            'backup_reference' => $backupReference,
            'blocked' => $blocked,
            'database_rows_mutated' => false,
            'destructive_changes' => false,
        ];
    }

    // Count staged rows now (read-only) using equivalent concrete SQL for direct/parent tables where possible.
    // Temporary stages are created by the generated migration, so dependency counts are reported as runtime-staged.
    $directPlan = archiveFrameworkCutoffPlan($conn, $activeDatabase, $policy, $cutoff);

    $guard = 'tmp_af_archive_guard';
    $sql = [];
    $sql[] = '-- AcctLab archive clean-slate cutover.';
    $sql[] = '-- Cutoff: ' . $cutoff;
    $sql[] = '-- Backup reference: ' . str_replace(["\r", "\n"], ' ', $backupReference);
    $sql[] = '-- Safety: archive/upsert -> equality guard -> dependency-safe delete -> cash balance carry-forward.';
    $sql[] = 'SET @archive_cutoff = ' . "'" . $conn->real_escape_string($cutoff . ' 00:00:00') . "';";
    $sql[] = 'SET @archive_cutover_lock = GET_LOCK(\'acctlab_archive_cutover\', 30);';
    $sql[] = 'START TRANSACTION;';
    $sql[] = 'DROP TEMPORARY TABLE IF EXISTS ' . archiveFrameworkQuoteIdentifier($guard) . ';';
    $sql[] = 'CREATE TEMPORARY TABLE ' . archiveFrameworkQuoteIdentifier($guard) . ' (`id` tinyint NOT NULL PRIMARY KEY);';
    $sql[] = 'INSERT INTO ' . archiveFrameworkQuoteIdentifier($guard) . ' (`id`) VALUES (1);';
    $sql[] = 'INSERT INTO ' . archiveFrameworkQuoteIdentifier($guard) . ' (`id`) SELECT 1 WHERE COALESCE(@archive_cutover_lock, 0) <> 1;';
    $sql = array_merge($sql, $stageSql);

    // Keep archive-side FK parents current for future cash archive rows.
    foreach (['cash_accounts', 'cash_categories'] as $referenceTable) {
        $sql[] = archiveFrameworkBuildMasterReferenceSyncSql($conn, $activeDatabase, $archiveDatabase, $referenceTable);
    }

    // Archive parents before children. Cash transactions precede IOUs because IOUs FK to transactions.
    $preferredUpsertOrder = [
        'account_payment_batches',
        'bank_recons',
        'cash_transactions',
        'cash_ious',
        'cash_mutilated_cash',
        'procurement_local_advance_pos',
        'procurement_local_advance_po_revision_reconciliations',
        'procurement_requests',
        'advance_payment_request',
        'supplier_fund_request_table',
        'expense_fund_request_table',
        'compass_fund_request_table',
    ];
    $allStageTables = array_keys($stages);
    $upsertOrder = array_values(array_unique(array_merge(
        array_values(array_intersect($preferredUpsertOrder, $allStageTables)),
        $allStageTables
    )));
    foreach ($upsertOrder as $table) {
        $orderBy = $table === 'cash_transactions' ? 'a.`id` ASC' : null;
        $upsertStage = $stages[$table]['name'];
        if ($table === 'cash_transactions' && isset($stageMeta['cash_transaction_archive_sync']['stage'])) {
            $upsertStage = (string) $stageMeta['cash_transaction_archive_sync']['stage'];
        }
        $sql[] = archiveFrameworkBuildUpsertSql(
            $conn,
            $activeDatabase,
            $archiveDatabase,
            $table,
            $stages[$table]['identity'],
            $upsertStage,
            $orderBy
        );
    }

    // Hard guard: every staged row must be present in ARCHIVE with exactly matching column values.
    foreach ($upsertOrder as $table) {
        $sql[] = archiveFrameworkBuildArchiveGuardSql(
            $conn,
            $activeDatabase,
            $archiveDatabase,
            $table,
            $stages[$table]['identity'],
            $stages[$table]['name'],
            $guard
        );
    }

    // Delete child/dependent rows before parents. Semantic children are included even without DB FKs.
    $preferredDeleteOrder = [
        'account_payment_artifacts',
        'account_payment_batch_items',
        'account_advance_po_reconciliation_allocations',
        'procurement_request_handoffs',
        'procurement_local_advance_po_revisions',
        'bank_recon_matches',
        'bank_recon_difference_explanations',
        'bank_recon_bank_lines',
        'bank_recon_ledger_lines',
        'cash_receipts',
        'cash_iou_actions',
        'cash_iou_retirements',
        'cash_transaction_edits',
        'cash_ious',
        'cash_mutilated_cash',
    ];
    $deleteOrder = array_values(array_unique(array_merge(
        array_values(array_intersect($preferredDeleteOrder, $allStageTables)),
        array_values(array_diff($allStageTables, ['cash_transactions']))
    )));
    foreach ($deleteOrder as $table) {
        $sql[] = archiveFrameworkBuildDeleteSql(
            $activeDatabase,
            $table,
            $stages[$table]['identity'],
            $stages[$table]['name']
        );
    }

    // Cash transactions have a self-referencing reversal FK; remove staged leaves first.
    if (isset($stages['cash_transactions'])) {
        $stageCash = archiveFrameworkQuoteIdentifier($stages['cash_transactions']['name']);
        for ($i = 0; $i < 32; $i++) {
            $sql[] = 'DELETE t FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS t'
                . ' INNER JOIN ' . $stageCash . ' AS s ON s.`id` = t.`id`'
                . ' LEFT JOIN ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS child'
                . ' ON child.`reversal_of_transaction_id` = t.`id`'
                . ' WHERE child.`id` IS NULL;';
        }
        // If a staged transaction remains, fail the transaction before COMMIT.
        $sql[] = 'INSERT INTO ' . archiveFrameworkQuoteIdentifier($guard) . ' (`id`)'
            . ' SELECT 1 WHERE EXISTS (SELECT 1 FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions')
            . ' AS t INNER JOIN ' . $stageCash . ' AS s ON s.`id` = t.`id`);';

        $opening = archiveFrameworkQuoteIdentifier((string) $stageMeta['cash_opening_balance']['stage']);
        $cutoffDateLiteral = "'" . $conn->real_escape_string($cutoff) . "'";
        $sql[] = 'INSERT INTO ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' ('
            . '`account_id`,`transaction_reference`,`transaction_date`,`transaction_type`,`direction`,`person_name`,`amount`,'
            . '`reason`,`description`,`category_id`,`external_reference`,`disbursement_type`,`receipt_status`,`status`,'
            . '`reversal_of_transaction_id`,`idempotency_key`,`accounting_year`,`created_by_user_id`,`created_by_email`,`metadata`,`created_at`,`updated_at`)'
            . ' SELECT o.`account_id`,'
            . ' CONCAT(\'ARCV-OPEN-\', DATE_FORMAT(' . $cutoffDateLiteral . ', \'%Y%m%d\'), \'-\', o.`account_id`),'
            . ' DATE_SUB(' . $cutoffDateLiteral . ', INTERVAL 1 DAY), \'OPENING_BALANCE\','
            . ' CASE WHEN (o.`original_balance` - o.`retained_balance`) >= 0 THEN \'IN\' ELSE \'OUT\' END,'
            . ' \'Archive Carry Forward\', ABS(o.`original_balance` - o.`retained_balance`),'
            . ' \'Balance carried forward after archive cutover\', \'Historical activity moved to archive database\','
            . ' NULL, NULL, NULL, \'NOT_REQUIRED\', \'POSTED\', NULL,'
            . ' CONCAT(\'archive-cutover:\', ' . $cutoffDateLiteral . ', \':account:\', o.`account_id`),'
            . ' YEAR(DATE_SUB(' . $cutoffDateLiteral . ', INTERVAL 1 DAY)), 0, \'archive-system\','
            . ' CONCAT(\'{"archive_cutoff":"\', ' . $cutoffDateLiteral . ', \'","source":"archive_framework"}\'),'
            . ' CONCAT(' . $cutoffDateLiteral . ', \' 00:00:00\'), NULL'
            . ' FROM ' . $opening . ' AS o'
            . ' WHERE ABS(o.`original_balance` - o.`retained_balance`) > 0.004'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . archiveFrameworkQualified($activeDatabase, 'cash_transactions') . ' AS x'
            . ' WHERE x.`idempotency_key` = CONCAT(\'archive-cutover:\', ' . $cutoffDateLiteral . ', \':account:\', o.`account_id`));';
    }

    $sql[] = 'COMMIT;';
    $sql[] = 'SELECT RELEASE_LOCK(\'acctlab_archive_cutover\') AS archive_cutover_unlock;';

    return [
        'healthy' => true,
        'cutoff' => $cutoff,
        'backup_reference' => $backupReference,
        'foundation' => $foundation['counts'],
        'direct_eligibility' => [
            'total_rows' => (int) ($directPlan['total_directly_eligible_rows'] ?? 0),
            'tables' => $directPlan['tables'] ?? [],
        ],
        'dependency_stages' => array_map(
            static fn (array $meta): array => $meta,
            $stageMeta
        ),
        'blocked' => [],
        'sql' => implode(PHP_EOL . PHP_EOL, $sql) . PHP_EOL,
        'database_rows_mutated' => false,
        'destructive_changes' => true,
        'execution_policy' => [
            'archive_upsert_before_delete' => true,
            'full_row_equality_guard_before_delete' => true,
            'dependency_safe_delete_order' => true,
            'cash_balance_carry_forward' => true,
            'transaction_wrapped' => true,
        ],
    ];
}

function archiveFrameworkCutoverVerify(
    mysqli $conn,
    string $activeDatabase,
    string $archiveDatabase,
    string $readDatabase,
    array $policy,
    string $cutoff
): array {
    $foundation = archiveFrameworkVerify($conn, $activeDatabase, $archiveDatabase, $readDatabase, $policy);
    $eligibility = archiveFrameworkCutoffPlan($conn, $activeDatabase, $policy, $cutoff);
    $remaining = [];
    foreach ((array) ($eligibility['tables'] ?? []) as $table => $entry) {
        if (($entry['status'] ?? '') === 'planned' && (int) ($entry['eligible_rows'] ?? 0) > 0) {
            $remaining[$table] = (int) $entry['eligible_rows'];
        }
    }
    return [
        'healthy' => $foundation['healthy'] && $remaining === [],
        'cutoff' => $cutoff,
        'foundation' => $foundation['counts'],
        'remaining_directly_eligible_rows' => array_sum($remaining),
        'remaining_directly_eligible_by_table' => $remaining,
        'note' => 'Parent/dependency groups are protected by the execution migration and application regressions; direct eligibility must be zero after cutover.',
        'database_rows_mutated' => false,
    ];
}
