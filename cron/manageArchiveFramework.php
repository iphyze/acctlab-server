<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/archiveFrameworkService.php';

function archiveFrameworkCliOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $pair = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '1';
    }
    return $options;
}

function archiveFrameworkWriteFile(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create output directory: ' . $directory);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to write output file: ' . $path);
    }
}

$options = archiveFrameworkCliOptions($argv);
$action = strtolower(trim((string) ($options['action'] ?? 'foundation-plan')));
$activeDatabase = trim((string) ($GLOBALS['activeDatabaseName'] ?? envValue('DB_NAME', 'lambert2_acctlab_db')));
$archiveDatabase = trim((string) envValue(
    'DB_ARCHIVE_NAME',
    preg_replace('/_db$/', '_archive', $activeDatabase) ?: ($activeDatabase . '_archive')
));
$readDatabase = trim((string) envValue(
    'DB_READ_NAME',
    preg_replace('/_db$/', '_read', $activeDatabase) ?: ($activeDatabase . '_read')
));
$policy = require __DIR__ . '/../config/archiveFrameworkPolicy.php';

try {
    if ($archiveDatabase === '' || $readDatabase === '') {
        throw new RuntimeException('DB_ARCHIVE_NAME and DB_READ_NAME must be configured.');
    }

    if ($action === 'foundation-plan' || $action === 'plan') {
        $plan = archiveFrameworkPlan($conn, $activeDatabase, $archiveDatabase, $readDatabase, $policy);
        $plan['action'] = 'foundation-plan';
        $plan['executed_at'] = date(DATE_ATOM);

        $sqlOutput = trim((string) ($options['sql-output'] ?? ''));
        if ($sqlOutput !== '') {
            archiveFrameworkWriteFile($sqlOutput, (string) $plan['sql']);
            $plan['sql_output'] = $sqlOutput;
        }

        $jsonOutput = trim((string) ($options['output'] ?? ''));
        if ($jsonOutput !== '') {
            $copy = $plan;
            unset($copy['sql']);
            archiveFrameworkWriteFile(
                $jsonOutput,
                json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );
            $plan['output'] = $jsonOutput;
        }

        // Keep console output concise; SQL belongs in --sql-output.
        $console = $plan;
        unset($console['sql']);
        echo json_encode($console, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($plan['healthy'] ? 0 : 2);
    }

    if ($action === 'verify') {
        $verification = archiveFrameworkVerify($conn, $activeDatabase, $archiveDatabase, $readDatabase, $policy);
        $verification['action'] = 'verify';
        $verification['executed_at'] = date(DATE_ATOM);
        echo json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($verification['healthy'] ? 0 : 2);
    }

    if ($action === 'cutoff-plan') {
        $cutoff = trim((string) ($options['cutoff'] ?? ''));
        if ($cutoff === '') {
            throw new InvalidArgumentException('--cutoff=YYYY-MM-DD is required for cutoff-plan.');
        }
        $cutoffPlan = archiveFrameworkCutoffPlan($conn, $activeDatabase, $policy, $cutoff);
        $cutoffPlan['action'] = 'cutoff-plan';
        $cutoffPlan['executed_at'] = date(DATE_ATOM);
        echo json_encode($cutoffPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($cutoffPlan['healthy'] ? 0 : 2);
    }

    if ($action === 'cutover-execution-plan') {
        $cutoff = trim((string) ($options['cutoff'] ?? ''));
        $backupReference = trim((string) ($options['backup-reference'] ?? ''));
        if ($cutoff === '') {
            throw new InvalidArgumentException('--cutoff=YYYY-MM-DD is required for cutover-execution-plan.');
        }
        $plan = archiveFrameworkCutoverExecutionPlan(
            $conn,
            $activeDatabase,
            $archiveDatabase,
            $readDatabase,
            $policy,
            $cutoff,
            $backupReference
        );
        $plan['action'] = 'cutover-execution-plan';
        $plan['executed_at'] = date(DATE_ATOM);

        $sqlOutput = trim((string) ($options['sql-output'] ?? ''));
        if ($sqlOutput !== '') {
            archiveFrameworkWriteFile($sqlOutput, (string) ($plan['sql'] ?? ''));
            $plan['sql_output'] = $sqlOutput;
        }
        $jsonOutput = trim((string) ($options['output'] ?? ''));
        if ($jsonOutput !== '') {
            $copy = $plan;
            unset($copy['sql']);
            archiveFrameworkWriteFile(
                $jsonOutput,
                json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );
            $plan['output'] = $jsonOutput;
        }
        $console = $plan;
        unset($console['sql']);
        echo json_encode($console, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($plan['healthy'] ? 0 : 2);
    }

    if ($action === 'cutover-verify') {
        $cutoff = trim((string) ($options['cutoff'] ?? ''));
        if ($cutoff === '') {
            throw new InvalidArgumentException('--cutoff=YYYY-MM-DD is required for cutover-verify.');
        }
        $verification = archiveFrameworkCutoverVerify(
            $conn,
            $activeDatabase,
            $archiveDatabase,
            $readDatabase,
            $policy,
            $cutoff
        );
        $verification['action'] = 'cutover-verify';
        $verification['executed_at'] = date(DATE_ATOM);
        echo json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($verification['healthy'] ? 0 : 2);
    }

    throw new InvalidArgumentException(
        'Unknown --action. Supported: foundation-plan, verify, cutoff-plan, cutover-execution-plan, cutover-verify.'
    );
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'healthy' => false,
        'action' => $action,
        'error' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
