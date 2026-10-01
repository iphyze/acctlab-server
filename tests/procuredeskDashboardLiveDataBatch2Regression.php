<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = (string) file_get_contents($root . '/routes/procurement/dashboard/overview.php');

$checks = [
    'purchase_mix_is_live_and_permission_scoped' => str_contains($route, 'GROUP BY r.request_type')
        && str_contains($route, "'request_mix' => procurementDashboardRequestMixDefaults(\$visibleTypes)")
        && str_contains($route, "array_map(static function (string \$requestType) use (\$mixByType)")
        && str_contains($route, '$visibleTypes'),
    'purchase_mix_keeps_local_and_fx_amounts_unaggregated' => str_contains($route, 'created_this_month')
        && str_contains($route, 'pending_approval')
        && str_contains($route, 'with_accounts')
        && !str_contains($route, 'SUM(r.purchase_value)')
        && !str_contains($route, 'SUM(r.expected_payment)'),
    'attention_queue_detects_stale_pending_requests' => str_contains($route, 'pending_over_48h')
        && str_contains($route, "\$now->modify('-48 hours')")
        && str_contains($route, "r.approval_status = 'Unapproved'"),
    'attention_queue_detects_stale_account_handoffs' => str_contains($route, 'accounts_over_7d')
        && str_contains($route, "\$now->modify('-7 days')")
        && str_contains($route, "r.handoff_status = 'In Account'"),
    'attention_queue_detects_failed_and_returned_work' => str_contains($route, 'failed_payments')
        && str_contains($route, "r.payment_status = 'Failed'")
        && str_contains($route, 'returned_open')
        && str_contains($route, "r.handoff_status = 'Retrieved'"),
    'officer_attention_and_mix_reuse_own_request_scope' => str_contains($route, "=== 'officer'")
        && str_contains($route, 'r.created_by = ?')
        && substr_count($route, '{$scopeSql}') >= 4,
    'batch2_is_read_only_and_requires_no_schema_change' => !str_contains($route, 'CREATE TABLE')
        && !str_contains($route, 'ALTER TABLE')
        && !str_contains($route, 'DROP TABLE')
        && !str_contains($route, 'INSERT INTO')
        && !str_contains($route, 'UPDATE procurement_requests')
        && !str_contains($route, 'DELETE FROM'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
