<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$route = $root . '/routes/request/supplier/financialAdjustments.php';

$checks = [
    'main API router registers supplier financial adjustments' => str_contains(
        $index,
        "'/request/supplier/financialAdjustments' => 'routes/request/supplier/financialAdjustments.php'"
    ),
    'supplier financial adjustments route file exists' => is_file($route),
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, "Supplier financial adjustments route regression failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Supplier financial adjustments route regression passed.\n";
