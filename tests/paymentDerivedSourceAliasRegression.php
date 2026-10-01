<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$supplier = file_get_contents($root . '/includes/accountSupplierPaymentService.php');
$advance = file_get_contents($root . '/includes/accountAdvancePaymentService.php');

$checks = [
    'supplier_artifact_projection_has_alias' => str_contains($supplier, 'FROM {$artifactSource} artifact'),
    'supplier_artifact_filter_is_qualified' => str_contains($supplier, "WHERE artifact.batch_id = ? AND artifact.artifact_status = 'Active'"),
    'supplier_item_projection_has_alias' => str_contains($supplier, 'FROM {$itemSource} item'),
    'advance_artifact_projection_has_alias' => str_contains($advance, 'FROM {$artifactSource} artifact'),
    'advance_artifact_filter_is_qualified' => str_contains($advance, "WHERE artifact.batch_id = ? AND artifact.artifact_status = 'Active'"),
    'advance_item_projection_has_alias' => str_contains($advance, 'FROM {$itemSource} item'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
