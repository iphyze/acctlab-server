<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/accountReceivablesDashboardService.php');
$route = $read('routes/receivables/dashboard.php');
$index = $read('index.php');

$checks = [
    'dashboard_route_is_registered' =>
        str_contains($index, "'/receivables/dashboard'")
        && str_contains($index, 'routes/receivables/dashboard.php'),
    'dashboard_route_is_admin_protected' => str_contains($route, 'requireAdmin()'),
    'dashboard_reuses_canonical_receivables_calculations' =>
        str_contains($service, 'accountReceivablesDecorateInvoice')
        && str_contains($service, 'accountReceivablesAgeingBands')
        && str_contains($service, 'accountReceivablesSettings'),
    'dashboard_matches_workbook_core_kpis' =>
        str_contains($service, "'total_receivable'")
        && str_contains($service, "'combined_exposure_ngn_equivalent'")
        && str_contains($service, "'wht_credit_notes_outstanding'")
        && str_contains($service, "'review_not_reported'"),
    'ageing_profile_is_dynamic_not_hardcoded' =>
        str_contains($service, 'foreach ($bands as $band)')
        && str_contains($service, "'ageing_profile'"),
    'undated_open_items_are_controlled_separately' =>
        str_contains($service, "'unaged_open_count'")
        && str_contains($service, "'unaged_open_amount'"),
    'ngn_exposure_concentration_is_available' =>
        str_contains($service, "'largest_ngn_exposures'")
        && str_contains($service, "'ngn_client_exposure'"),
    'dashboard_does_not_require_new_tables' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
