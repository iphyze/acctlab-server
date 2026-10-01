<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/routes/reports/dashboardOverview.php');

$checks = [
    'recent_activity_is_collected_per_category' => str_contains($source, '$recentActivityByCategory = []')
        && str_contains($source, '$recentActivityByCategory[$categoryKey] = $categoryRecent'),
    'each_category_gets_its_own_recent_limit' => str_contains($source, 'ORDER BY created_at DESC, id DESC')
        && str_contains($source, 'LIMIT 8')
        && str_contains($source, "bind_param('ssi', \$categoryKey, \$categoryLabel, \$year)"),
    'all_categories_still_get_latest_eight_overall' => str_contains($source, 'usort($recentActivityPool')
        && str_contains($source, '$recentActivity = array_slice($recentActivityPool, 0, 8)'),
    'category_recent_activity_is_exposed_to_frontend' => str_contains($source, "'recent_activity_by_category' => \$recentActivityByCategory"),
    'funding_totals_remain_full_year_analytics' => str_contains($source, 'COUNT(*) AS request_count')
        && str_contains($source, 'WHERE YEAR(created_at) = ?'),
    'no_schema_change_is_required' => !str_contains($source, 'CREATE TABLE')
        && !str_contains($source, 'ALTER TABLE')
        && !str_contains($source, 'DROP TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
