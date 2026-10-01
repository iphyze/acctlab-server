<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementAccountSubmissionScheduleService.php');

$checks = [
    'currency_options_are_permission_scoped_not_period_scoped' =>
        str_contains($service, '$visibleTypes = array_map(')
        && str_contains($service, '$optionBase = procurementAccountSubmissionScheduleBaseSql($visibleTypes)')
        && str_contains($service, 'GROUP BY schedule.currency')
        && str_contains($service, "'currencies' => array_merge(['All'], array_values(array_map("),
    'period_summary_still_uses_filtered_currency_counts' =>
        str_contains($service, "'by_currency' => \$currencyCounts")
        && str_contains($service, "schedule.sent_to_account_date >= ?")
        && str_contains($service, "schedule.sent_to_account_date <= ?"),
    'batch2_keeps_storage_read_only' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE')
        && !str_contains($service, 'DROP TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
