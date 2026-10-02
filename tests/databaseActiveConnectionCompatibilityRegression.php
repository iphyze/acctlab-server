<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scanRoots = ['routes', 'includes', 'cron'];
$unguarded = [];
$scanned = 0;

foreach ($scanRoots as $relativeRoot) {
    $directory = $root . '/' . $relativeRoot;
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $source = file_get_contents($path) ?: '';
        $scanned++;

        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            if (!str_contains($line, 'databaseActiveConnection(')) {
                continue;
            }
            if (str_contains($line, 'function databaseActiveConnection(')) {
                continue;
            }
            if (str_contains($line, "function_exists('databaseActiveConnection')")) {
                continue;
            }
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $unguarded[] = $relative . ':' . ($index + 1);
        }
    }
}

$supplierRoute = file_get_contents($root . '/routes/request/supplier/financialAdjustments.php') ?: '';
$checks = [
    'runtime_php_files_scanned' => $scanned > 0,
    'no_unguarded_active_connection_calls' => $unguarded === [],
    'supplier_offset_route_has_production_fallback' => str_contains(
        $supplierRoute,
        '$writeConn = function_exists(\'databaseActiveConnection\') ? databaseActiveConnection($conn) : $conn;'
    ),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'unguarded' => $unguarded,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
