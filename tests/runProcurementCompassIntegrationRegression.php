<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$tests = [
    'procurementCompassHandoffFoundationRegression.php',
    'procurementCompassLocalFinalRoutingRegression.php',
    'procurementCompassLocalAdvanceRoutingRegression.php',
    'procurementCompassFundRequestParityRegression.php',
    'procurementCompassCanonicalPaymentProcessingRegression.php',
    'procurementCompassCompletionLifecycleRegression.php',
    'accountPaymentProcessingConsolidationRegression.php',
    'fxFundRequestCompletionLifecycleRegression.php',
    'procurementFxFinalPurchaseHandoffRegression.php',
    'procurementFxAdvancePurchaseHandoffRegression.php',
    'procurementFxAdvancePurchaseAmendmentRegression.php',
    'procurementPoAmendmentCanonicalHistoryRegression.php',
    'procurementCompassIntegrationFinalRegression.php',
];

$results = [];
$healthy = true;

foreach ($tests as $test) {
    $path = $root . DIRECTORY_SEPARATOR . $test;
    if (!is_file($path)) {
        $results[] = ['test' => $test, 'healthy' => false, 'error' => 'Test file is missing.'];
        $healthy = false;
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $root);
    if (!is_resource($process)) {
        $results[] = ['test' => $test, 'healthy' => false, 'error' => 'Unable to start regression test.'];
        $healthy = false;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $payload = json_decode(trim((string) $stdout), true);
    $passed = $exitCode === 0 && is_array($payload) && ($payload['healthy'] ?? false) === true;
    $results[] = [
        'test' => $test,
        'healthy' => $passed,
        'failed' => is_array($payload) ? ($payload['failed'] ?? []) : [],
        'stderr' => trim((string) $stderr),
    ];
    if (!$passed) {
        $healthy = false;
    }
}

$failed = array_values(array_map(
    static fn(array $result): string => (string) $result['test'],
    array_filter($results, static fn(array $result): bool => !$result['healthy'])
));

echo json_encode([
    'healthy' => $healthy,
    'tests' => $results,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($healthy ? 0 : 1);
