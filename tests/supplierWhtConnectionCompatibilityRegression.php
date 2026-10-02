<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'report' => $root . '/routes/reports/supplierWht.php',
    'export' => $root . '/routes/reports/exportSupplierWht.php',
];

$failures = [];
foreach ($files as $name => $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        $failures[] = $name . ': unable to read route';
        continue;
    }

    if (!str_contains($source, "function_exists('databaseActiveConnection') ? databaseActiveConnection(\$conn) : \$conn")) {
        $failures[] = $name . ': missing production-compatible active connection fallback';
    }

    if (str_contains($source, '$writeConn = databaseActiveConnection($conn);')) {
        $failures[] = $name . ': still hard-depends on databaseActiveConnection';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Supplier WHT connection compatibility regression failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Supplier WHT connection compatibility regression passed.\n";
