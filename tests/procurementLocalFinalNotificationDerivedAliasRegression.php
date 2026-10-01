<?php

declare(strict_types=1);

$servicePath = dirname(__DIR__) . '/includes/procurementNotificationService.php';
$source = file_get_contents($servicePath);
if ($source === false) {
    fwrite(STDERR, "Unable to read procurementNotificationService.php\n");
    exit(1);
}

$checks = [
    'procuredesk_notification_local_final_projection_is_aliased' =>
        preg_match('/FROM \\{\\$localFinalRelation\\} purchase\\s+WHERE purchase\\.id = \\? LIMIT 1/', $source) === 1,
    'account_notification_local_final_projection_is_aliased' =>
        substr_count($source, 'FROM {$localFinalRelation} purchase') >= 2,
    'no_unaliased_local_final_projection_before_where' =>
        preg_match('/FROM \\{\\$localFinalRelation\\}(?!\\s+[A-Za-z_][A-Za-z0-9_]*)\\s+WHERE/i', $source) !== 1,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
