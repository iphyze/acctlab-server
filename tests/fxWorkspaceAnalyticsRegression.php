<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$route = file_get_contents($root . '/routes/fx/analytics/overview.php');

$checks = [
    'analytics_route_registered' => str_contains($index, "'/fx/analytics/overview' => 'routes/fx/analytics/overview.php'"),
    'analytics_requires_admin' => str_contains($route, 'requireAdmin();'),
    'analytics_is_year_scoped' => str_contains($route, 'YEAR(created_at) = ?'),
    'request_analytics_use_fx_fund_requests' => str_contains($route, 'FROM fx_fund_request_table'),
    'payment_analytics_use_fx_instructions' => str_contains($route, 'FROM fx_instruction_letter_table'),
    'final_advance_mix_is_reported' => str_contains($route, "request_type = 'Final'") && str_contains($route, "request_type = 'Advance'"),
    'request_and_payment_lifecycles_are_separate' => str_contains($route, "'requests' => [") && str_contains($route, "'payments' => ["),
    'currency_amounts_are_grouped_not_cross_summed' => str_contains($route, 'GROUP BY COALESCE(NULLIF(UPPER(TRIM(currency))') && str_contains($route, "'currency_policy' => 'Amounts are grouped by currency and are never cross-summed.'"),
    'payment_currency_prefers_currency_table' => str_contains($route, 'UPPER(TRIM(currency_table))'),
    'monthly_activity_is_reported' => str_contains($route, "'monthly_activity' => array_values(\$monthlyMap)"),
    'top_suppliers_are_currency_separated' => str_contains($route, "GROUP BY suppliers_name, COALESCE(NULLIF(UPPER(TRIM(currency))"),
    'top_projects_are_currency_separated' => str_contains($route, "GROUP BY project_code, COALESCE(NULLIF(UPPER(TRIM(currency))"),
    'no_schema_mutation_in_analytics_route' => !preg_match('/\b(?:ALTER|CREATE|DROP|TRUNCATE)\s+TABLE\b/i', $route),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
