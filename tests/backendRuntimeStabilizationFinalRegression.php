<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'activeDatabaseSchemaDetectionRegression.php',
    'paymentReminderSchedulerDiagnosticsRegression.php',
    'paymentReminderAdvanceSchemaDetectionRegression.php',
    'notificationSchemaDetectionRegression.php',
    'procuredeskLoginSchemaGuardRegression.php',
    'cashDeskSchemaDetectionRegression.php',
    'accountPaymentProcessingConsolidationRegression.php',
    'paymentProcessingRuntimeReadFixRegression.php',
    'procurementCompassCanonicalPaymentProcessingRegression.php',
    'procurementCompassCompletionLifecycleRegression.php',
    'fxFundRequestCompletionLifecycleRegression.php',
    'fxFundRequestPaymentLifecycleRegression.php',
    'procurementFxFinalPurchaseHandoffRegression.php',
    'receivablesFinalIntegrationRegression.php',
    'backendErrorLogHardeningRegression.php',
];

$results = [];
$failed = [];
foreach ($tests as $test) {
    $path = $root . '/tests/' . $test;
    if (!is_file($path)) {
        $results[$test] = [
            'healthy' => false,
            'exit_code' => null,
            'output' => 'Regression file is missing.',
        ];
        $failed[] = $test;
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    $healthy = $exitCode === 0;
    $results[$test] = [
        'healthy' => $healthy,
        'exit_code' => $exitCode,
        'output' => implode("\n", $output),
    ];
    if (!$healthy) {
        $failed[] = $test;
    }
}

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $phpFiles[] = $file->getRealPath();
    }
}

$syntaxFailures = [];
foreach ($phpFiles as $file) {
    if ($file === false) {
        continue;
    }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        $syntaxFailures[] = [
            'file' => ltrim(str_replace($root, '', $file), DIRECTORY_SEPARATOR),
            'output' => implode("\n", $output),
        ];
    }
}

$healthy = $failed === [] && $syntaxFailures === [];
echo json_encode([
    'healthy' => $healthy,
    'regressions_checked' => count($tests),
    'regressions_failed' => $failed,
    'php_files_linted' => count($phpFiles),
    'syntax_failures' => $syntaxFailures,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($healthy ? 0 : 1);
