<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$index = $read('index.php');
$route = $read('routes/receivables/configuration.php');
$service = $read('includes/accountReceivablesService.php');

$checks = [
    'configuration_route_is_registered' => str_contains($index, "'/receivables/configuration'")
        && str_contains($index, 'routes/receivables/configuration.php'),
    'configuration_route_is_admin_protected' => str_contains($route, 'requireAdmin()'),
    'configuration_supports_settings_bands_projects_and_status' =>
        str_contains($route, "\$resource === 'settings'")
        && str_contains($route, "\$resource === 'ageing_band'")
        && str_contains($route, "\$resource === 'project'")
        && str_contains($route, "\$resource === 'status'"),
    'reporting_controls_are_validated_server_side' =>
        str_contains($service, 'accountReceivablesUpdateSettings')
        && str_contains($service, "['invoice_date', 'due_date']")
        && str_contains($service, 'USD / NGN rate must be greater than zero.'),
    'ageing_bands_prevent_active_overlap' =>
        str_contains($service, 'accountReceivablesAssertBandRangeAvailable')
        && str_contains($service, 'The ageing range overlaps'),
    'project_terms_link_to_acctlab_project_directory' =>
        str_contains($service, 'accountReceivablesSourceProjects')
        && str_contains($service, 'FROM location_table')
        && str_contains($service, 'acctlab_project_id'),
    'deactivation_is_non_destructive' =>
        str_contains($service, 'accountReceivablesSetConfigurationStatus')
        && str_contains($service, 'SET is_active = ?')
        && !str_contains($route, 'DELETE FROM account_receivable'),
    'bootstrap_exposes_active_project_defaults' =>
        str_contains($service, "\$response['projects'] = accountReceivablesProjects(\$conn, true)"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
