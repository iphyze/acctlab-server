<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'includes/procurementLocalAdvancePurchaseService.php',
    'includes/accountAdvancePaymentService.php',
];

$failures = [];
foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    $source = file_get_contents($path);
    if ($source === false) {
        $failures[] = "$relative could not be read.";
        continue;
    }

    if (str_contains($source, 'TABLE_SCHEMA = @active_database_name')) {
        $failures[] = "$relative still depends on @active_database_name for table/column metadata checks.";
    }
    if (!str_contains($source, 'TABLE_SCHEMA = DATABASE()')) {
        $failures[] = "$relative does not use DATABASE() for current-schema metadata checks.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Payment reminder advance schema detection regression passed.\n");
