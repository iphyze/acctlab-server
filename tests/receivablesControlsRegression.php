<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/accountReceivablesControlsService.php');
$route = $read('routes/receivables/controls.php');
$index = $read('index.php');

$checks = [
    'controls_route_is_registered' =>
        str_contains($index, "'/receivables/controls'")
        && str_contains($index, 'routes/receivables/controls.php'),
    'controls_route_is_admin_protected' => str_contains($route, 'requireAdmin()'),
    'controls_reconcile_register_to_ageing' =>
        str_contains($service, "'_register_to_ageing'")
        && str_contains($service, "'aged_open'"),
    'controls_reconcile_dashboard_to_ageing' =>
        str_contains($service, "'_dashboard_to_ageing'")
        && str_contains($service, "'total_receivable'"),
    'controls_include_open_register_bridge' =>
        str_contains($service, "'_open_register_bridge'")
        && str_contains($service, "'unaged_open'"),
    'controls_reconcile_deductions_and_wht' =>
        str_contains($service, "'_deductions_reconciliation'")
        && str_contains($service, "'_wht_credit_note_reconciliation'"),
    'controls_validate_configuration' =>
        str_contains($service, 'accountReceivablesConfigurationControl')
        && str_contains($service, 'accountReceivablesFxControl'),
    'control_exceptions_cover_source_quality' =>
        str_contains($service, "'unaged_open'")
        && str_contains($service, "'open_settled_threshold'")
        && str_contains($service, "'negative_open_balance'")
        && str_contains($service, "'review_balance'")
        && str_contains($service, "'gross_override_mismatch'")
        && str_contains($service, "'due_date_mismatch'")
        && str_contains($service, "'missing_invoice_number'")
        && str_contains($service, "'missing_usd_fx_rate'"),
    'exceptions_support_filtering_and_pagination' =>
        str_contains($service, "'severity'")
        && str_contains($service, "'type'")
        && str_contains($service, "'currency'")
        && str_contains($service, "'per_page'"),
    'batch_requires_no_database_migration' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
