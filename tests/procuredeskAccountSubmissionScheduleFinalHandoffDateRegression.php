<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementAccountSubmissionScheduleService.php');

$checks = [
    'schedule_uses_received_date_with_approved_at_fallback' =>
        str_contains($service, 'DATE(COALESCE(r.date_received, r.approved_at)) AS sent_to_account_date')
        && str_contains($service, 'COALESCE(r.date_received, r.approved_at) IS NOT NULL'),
    'period_filter_uses_resolved_handoff_date' =>
        str_contains($service, 'schedule.sent_to_account_date >= ?')
        && str_contains($service, 'schedule.sent_to_account_date <= ?'),
    'all_four_scopes_still_share_one_schedule_projection' =>
        str_contains($service, "WHEN 'local_final_purchase' THEN 'Local Final'")
        && str_contains($service, "WHEN 'local_advance_purchase' THEN 'Local Advance'")
        && str_contains($service, "WHEN 'fx_final_purchase' THEN 'FX Final'")
        && str_contains($service, "WHEN 'fx_advance_purchase' THEN 'FX Advance'"),
    'schedule_does_not_require_date_received_only' =>
        !str_contains($service, 'AND r.date_received IS NOT NULL'),
    'export_inherits_fix_through_shared_projection' =>
        str_contains($service, 'function procurementAccountSubmissionScheduleBaseSql'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
