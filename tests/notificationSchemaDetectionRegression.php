<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'metadata' => $root . '/includes/userNotificationCanonicalMetadataService.php',
    'runtime' => $root . '/includes/userNotificationCanonicalRuntimeService.php',
    'account' => $root . '/includes/accountNotificationService.php',
    'procurement' => $root . '/includes/procurementNotificationService.php',
];

$sources = [];
foreach ($files as $key => $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }
    $sources[$key] = $source;
}

$checks = [
    'notification_metadata_uses_selected_database' =>
        str_contains($sources['metadata'], 'TABLE_SCHEMA = DATABASE()'),
    'notification_runtime_trigger_lookup_uses_selected_database' =>
        str_contains($sources['runtime'], 'TRIGGER_SCHEMA = DATABASE()'),
    'account_notification_schema_checks_use_selected_database' =>
        substr_count($sources['account'], 'TABLE_SCHEMA = DATABASE()') >= 3,
    'procuredesk_notification_schema_checks_use_selected_database' =>
        substr_count($sources['procurement'], 'TABLE_SCHEMA = DATABASE()') >= 3,
    'notification_runtime_no_longer_depends_on_session_schema_variable' =>
        !str_contains(implode("\n", $sources), '@active_database_name'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
