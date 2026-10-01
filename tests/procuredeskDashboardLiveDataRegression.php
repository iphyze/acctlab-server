<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = (string) file_get_contents($root . '/routes/procurement/dashboard/overview.php');
$index = (string) file_get_contents($root . '/index.php');

$checks = [
    'dashboard_route_is_registered' => str_contains($index, "'/procurement/dashboard' => 'routes/procurement/dashboard/overview.php'"),
    'dashboard_requires_dashboard_permission' => str_contains($route, "procurementRequirePermission(\$conn, 'dashboard.view')"),
    'dashboard_reads_canonical_procurement_requests' => str_contains($route, 'FROM procurement_requests r')
        && str_contains($route, "'local_final_purchase'")
        && str_contains($route, "'local_advance_purchase'")
        && str_contains($route, "'fx_final_purchase'")
        && str_contains($route, "'fx_advance_purchase'"),
    'officer_dashboard_is_scoped_to_own_requests' => str_contains($route, "=== 'officer'")
        && str_contains($route, 'r.created_by = ?'),
    'request_types_are_permission_scoped' => str_contains($route, "'payments.local_final.view'")
        && str_contains($route, "'payments.local_advance.view'")
        && str_contains($route, "'payments.fx_final.view'")
        && str_contains($route, "'payments.fx_advance.view'"),
    'live_metrics_replace_static_dashboard_counts' => str_contains($route, 'pending_approval')
        && str_contains($route, 'approved_this_week')
        && str_contains($route, 'awaiting_accounts')
        && str_contains($route, 'paid_this_week'),
    'approval_pulse_is_derived_from_real_approval_timestamps' => str_contains($route, 'TIMESTAMPDIFF(SECOND, r.created_at, r.approved_at)')
        && str_contains($route, 'within_24h_count')
        && str_contains($route, 'GROUP BY DATE(r.approved_at)'),
    'recent_activity_uses_real_request_rows' => str_contains($route, 'ORDER BY r.created_at DESC, r.id DESC')
        && str_contains($route, 'LIMIT 8')
        && str_contains($route, 'supplier_name')
        && str_contains($route, 'officer_name'),
    'currency_values_are_kept_per_request_not_aggregated' => str_contains($route, "'currency' => \$isLocal ? 'NGN'")
        && str_contains($route, "'amount' => (float) (\$isAdvance")
        && !str_contains($route, 'SUM(r.purchase_value + r.expected_payment)')
        && !str_contains($route, 'total_amount'),
    'dashboard_uses_hot_path_schema_probe_not_migrations' => str_contains($route, 'procurementRequestCanonicalRuntimeColumnsReady')
        && !str_contains($route, 'CREATE TABLE')
        && !str_contains($route, 'ALTER TABLE')
        && !str_contains($route, 'DROP TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
