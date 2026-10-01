<?php

declare(strict_types=1);

/**
 * Read-only ProcureDesk register performance verifier.
 *
 * Measures representative canonical reads for Local/FX Final/Advance and
 * reports slow-query warnings without modifying data or enforcing a hardware-
 * specific latency threshold as a deployment failure.
 */

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/procurementRequestCanonicalRuntimeService.php';

$warningThresholdMs = max(1.0, (float) (getenv('PROCUREDESK_REGISTER_WARN_MS') ?: 500));
$iterations = max(1, min(5, (int) (getenv('PROCUREDESK_REGISTER_VERIFY_ITERATIONS') ?: 2)));
$currentYear = (int) date('Y');

$profiles = [
    'local_final' => [
        'request_type' => PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE,
        'source' => PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_SOURCE,
        'date_column' => 'purchase_date',
        'expected_indexes' => ['idx_proc_req_register_created', 'idx_proc_req_register_purchase_date'],
    ],
    'local_advance' => [
        'request_type' => PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_TYPE,
        'source' => PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_SOURCE,
        'date_column' => 'transaction_date',
        'expected_indexes' => ['idx_proc_req_register_created', 'idx_proc_req_register_transaction_date', 'idx_proc_req_advance_allocation'],
    ],
    'fx_final' => [
        'request_type' => PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE,
        'source' => PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE,
        'date_column' => 'purchase_date',
        'expected_indexes' => ['idx_proc_req_register_created', 'idx_proc_req_register_purchase_date'],
    ],
    'fx_advance' => [
        'request_type' => PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE,
        'source' => PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE,
        'date_column' => 'transaction_date',
        'expected_indexes' => ['idx_proc_req_register_created', 'idx_proc_req_register_transaction_date', 'idx_proc_req_advance_allocation'],
    ],
];

function procuredeskPerfMs(callable $callback): array
{
    $start = hrtime(true);
    $value = $callback();
    $elapsed = (hrtime(true) - $start) / 1_000_000;
    return ['value' => $value, 'ms' => round($elapsed, 3)];
}

function procuredeskPerfAverage(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    return round(array_sum($values) / count($values), 3);
}

function procuredeskPerfIndexNames(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'procurement_requests'"
    );
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = (string) ($row['INDEX_NAME'] ?? '');
    }
    return array_values(array_filter($indexes));
}

function procuredeskPerfExplainKey(
    mysqli $conn,
    string $requestType,
    string $source,
    ?string $dateColumn = null,
    ?int $year = null
): ?string {
    $sql = "EXPLAIN SELECT legacy_source_id
            FROM procurement_requests
            WHERE request_type = ?
              AND legacy_source_table = ?
              AND deleted_at IS NULL";
    $types = 'ss';
    $params = [$requestType, $source];
    if ($dateColumn !== null && $year !== null) {
        $sql .= " AND {$dateColumn} >= ? AND {$dateColumn} < ?";
        $types .= 'ss';
        $params[] = sprintf('%04d-01-01', $year);
        $params[] = sprintf('%04d-01-01', $year + 1);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 20';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $key = $row['key'] ?? null;
    return is_string($key) && $key !== '' ? $key : null;
}

function procuredeskPerfMeasureProfile(
    mysqli $conn,
    array $profile,
    int $year,
    int $iterations
): array {
    $requestType = (string) $profile['request_type'];
    $source = (string) $profile['source'];
    $dateColumn = (string) $profile['date_column'];
    $yearStart = sprintf('%04d-01-01', $year);
    $yearEnd = sprintf('%04d-01-01', $year + 1);

    $allCountTimes = [];
    $allListTimes = [];
    $yearCountTimes = [];
    $yearListTimes = [];
    $rowCount = 0;
    $yearRowCount = 0;

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $count = procuredeskPerfMs(static function () use ($conn, $requestType, $source): int {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM procurement_requests
                 WHERE request_type = ? AND legacy_source_table = ? AND deleted_at IS NULL'
            );
            $stmt->bind_param('ss', $requestType, $source);
            $stmt->execute();
            $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            return $total;
        });
        $rowCount = (int) $count['value'];
        $allCountTimes[] = (float) $count['ms'];

        $list = procuredeskPerfMs(static function () use ($conn, $requestType, $source): int {
            $stmt = $conn->prepare(
                'SELECT legacy_source_id
                 FROM procurement_requests
                 WHERE request_type = ? AND legacy_source_table = ? AND deleted_at IS NULL
                 ORDER BY created_at DESC, id DESC
                 LIMIT 20'
            );
            $stmt->bind_param('ss', $requestType, $source);
            $stmt->execute();
            $rows = $stmt->get_result()->num_rows;
            $stmt->close();
            return $rows;
        });
        $allListTimes[] = (float) $list['ms'];

        $yearCount = procuredeskPerfMs(static function () use (
            $conn,
            $requestType,
            $source,
            $dateColumn,
            $yearStart,
            $yearEnd
        ): int {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM procurement_requests
                 WHERE request_type = ? AND legacy_source_table = ? AND deleted_at IS NULL
                   AND {$dateColumn} >= ? AND {$dateColumn} < ?"
            );
            $stmt->bind_param('ssss', $requestType, $source, $yearStart, $yearEnd);
            $stmt->execute();
            $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            return $total;
        });
        $yearRowCount = (int) $yearCount['value'];
        $yearCountTimes[] = (float) $yearCount['ms'];

        $yearList = procuredeskPerfMs(static function () use (
            $conn,
            $requestType,
            $source,
            $dateColumn,
            $yearStart,
            $yearEnd
        ): int {
            $stmt = $conn->prepare(
                "SELECT legacy_source_id
                 FROM procurement_requests
                 WHERE request_type = ? AND legacy_source_table = ? AND deleted_at IS NULL
                   AND {$dateColumn} >= ? AND {$dateColumn} < ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT 20"
            );
            $stmt->bind_param('ssss', $requestType, $source, $yearStart, $yearEnd);
            $stmt->execute();
            $rows = $stmt->get_result()->num_rows;
            $stmt->close();
            return $rows;
        });
        $yearListTimes[] = (float) $yearList['ms'];
    }

    return [
        'rows' => $rowCount,
        'current_year' => $year,
        'current_year_rows' => $yearRowCount,
        'average_ms' => [
            'all_count' => procuredeskPerfAverage($allCountTimes),
            'all_page_20' => procuredeskPerfAverage($allListTimes),
            'year_count' => procuredeskPerfAverage($yearCountTimes),
            'year_page_20' => procuredeskPerfAverage($yearListTimes),
        ],
        'explain_key' => [
            'all' => procuredeskPerfExplainKey($conn, $requestType, $source),
            'year' => procuredeskPerfExplainKey($conn, $requestType, $source, $dateColumn, $year),
        ],
    ];
}

try {
    $existingIndexes = procuredeskPerfIndexNames($conn);
    $requiredIndexes = [
        'idx_proc_req_register_created',
        'idx_proc_req_register_purchase_date',
        'idx_proc_req_register_transaction_date',
        'idx_proc_req_advance_allocation',
    ];
    $missingIndexes = array_values(array_diff($requiredIndexes, $existingIndexes));

    $metrics = [];
    $warnings = [];
    foreach ($profiles as $name => $profile) {
        $metrics[$name] = procuredeskPerfMeasureProfile($conn, $profile, $currentYear, $iterations);
        foreach ($metrics[$name]['average_ms'] as $metricName => $milliseconds) {
            if ((float) $milliseconds > $warningThresholdMs) {
                $warnings[] = sprintf(
                    '%s.%s averaged %.3fms (warning threshold %.3fms).',
                    $name,
                    $metricName,
                    $milliseconds,
                    $warningThresholdMs
                );
            }
        }
    }

    $checks = [
        'procurement_requests_exists' => (bool) ($conn->query("SHOW TABLES LIKE 'procurement_requests'")->num_rows),
        'performance_indexes_present' => $missingIndexes === [],
        'four_register_profiles_measured' => count($metrics) === 4,
        'all_years_and_current_year_queries_measured' => array_reduce(
            $metrics,
            static fn(bool $ok, array $metric): bool => $ok
                && isset($metric['average_ms']['all_page_20'])
                && isset($metric['average_ms']['year_page_20']),
            true
        ),
    ];
    $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

    echo json_encode([
        'healthy' => $failed === [],
        'database' => $GLOBALS['activeDatabaseName'] ?? null,
        'read_only' => true,
        'warning_threshold_ms' => $warningThresholdMs,
        'iterations' => $iterations,
        'checks' => $checks,
        'missing_indexes' => $missingIndexes,
        'metrics' => $metrics,
        'warnings' => $warnings,
        'failed' => $failed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'healthy' => false,
        'read_only' => true,
        'message' => $error->getMessage(),
        'failed' => ['verification_runtime'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
