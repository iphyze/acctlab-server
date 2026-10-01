<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scanRoots = [
    $root . '/includes',
    $root . '/cron',
];
$allowed = [
    realpath($root . '/includes/connection.php'),
    realpath($root . '/includes/archiveIdentityService.php'),
];

$failures = [];
$checked = 0;
foreach ($scanRoots as $scanRoot) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getRealPath();
        if ($path === false || in_array($path, $allowed, true)) {
            continue;
        }
        $source = file_get_contents($path);
        if ($source === false) {
            $failures[] = "Unable to read {$file->getPathname()}";
            continue;
        }
        $checked++;
        if (str_contains($source, '@active_database_name')) {
            $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            $failures[] = "{$relative} still depends on @active_database_name.";
        }
    }
}

$connection = file_get_contents($root . '/includes/connection.php') ?: '';
$archiveIdentity = file_get_contents($root . '/includes/archiveIdentityService.php') ?: '';
if (!str_contains($connection, 'SET @active_database_name = ?')) {
    $failures[] = 'connection.php no longer preserves the legacy active-schema compatibility variable.';
}
if (!str_contains($archiveIdentity, "'@active_database_name' : '@archive_database_name'")) {
    $failures[] = 'archiveIdentityService.php archive/active identity compatibility handling changed unexpectedly.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Active database schema detection regression passed ({$checked} runtime PHP files scanned).\n");
