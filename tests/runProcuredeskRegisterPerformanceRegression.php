<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'tests/procuredeskRegisterHotPathBootstrapPerformanceRegression.php',
    'tests/procuredeskLeanRegisterApiPerformanceRegression.php',
    'tests/procuredeskRegisterQueryIndexPerformanceRegression.php',
    'tests/procuredeskUnifiedReadEnsureSafetyRegression.php',
    'tests/procurementRequestTypeConstantRuntimeRegression.php',
    'tests/procurementFxFinalPurchaseFoundationRegression.php',
    'tests/procurementFxAdvancePurchaseFoundationRegression.php',
    'tests/procurementCompassLocalFinalRoutingRegression.php',
    'tests/procurementCompassLocalAdvanceRoutingRegression.php',
    'tests/procuredeskRegisterPerformanceFinalQaRegression.php',
];

$results = [];
$healthy = true;

foreach ($tests as $relative) {
    $path = $root . '/' . $relative;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $path], $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        $healthy = false;
        $results[$relative] = ['healthy' => false, 'exit_code' => null, 'output' => 'Unable to start test process.'];
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $decoded = json_decode(trim((string) $stdout), true);
    $testHealthy = $exitCode === 0 && is_array($decoded) && ($decoded['healthy'] ?? false) === true;
    $healthy = $healthy && $testHealthy;
    $results[$relative] = [
        'healthy' => $testHealthy,
        'exit_code' => $exitCode,
        'failed' => is_array($decoded) ? ($decoded['failed'] ?? []) : [],
        'stderr' => trim((string) $stderr),
    ];
}

echo json_encode([
    'healthy' => $healthy,
    'suite' => 'ProcureDesk register performance',
    'tests' => $results,
    'failed' => array_keys(array_filter($results, static fn(array $result): bool => !$result['healthy'])),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($healthy ? 0 : 1);
