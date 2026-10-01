<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementAccountSubmissionScheduleService.php');
$export = (string) file_get_contents($root . '/routes/procurement/reports/accountSubmissionScheduleExport.php');
$previewRoute = (string) file_get_contents($root . '/routes/procurement/reports/accountSubmissionSchedule.php');

$checks = [
    'preview_and_export_share_same_filter_contract' =>
        str_contains($service, 'procurementAccountSubmissionSchedulePrepareFilters')
        && str_contains($export, 'procurementAccountSubmissionSchedulePrepareFilters($_GET, $visibleScopes)'),
    'preview_and_export_share_same_canonical_projection' =>
        str_contains($service, 'procurementAccountSubmissionScheduleBaseSql')
        && str_contains($export, 'procurementAccountSubmissionScheduleBaseSql($filters')
        && str_contains($export, 'procurementAccountSubmissionScheduleBuildWhere($filters)'),
    'only_actual_account_handoffs_are_scheduled' =>
        str_contains($service, 'COALESCE(r.date_received, r.approved_at) IS NOT NULL')
        && str_contains($service, 'DATE(COALESCE(r.date_received, r.approved_at)) AS sent_to_account_date')
        && str_contains($service, 'schedule.sent_to_account_date >= ?')
        && str_contains($service, 'schedule.sent_to_account_date <= ?'),
    'advance_po_identity_remains_scope_isolated' =>
        str_contains($service, 'ap.request_scope = r.request_type')
        && str_contains($service, 'apr.request_scope = r.request_type'),
    'all_four_print_sheets_remain_available' =>
        str_contains($export, "'FX Advance'")
        && str_contains($export, "'FX Final'")
        && str_contains($export, "'Local Advance'")
        && str_contains($export, "'Local Final'"),
    'print_setup_remains_professional_and_signable' =>
        str_contains($export, 'PageSetup::ORIENTATION_LANDSCAPE')
        && str_contains($export, 'PageSetup::PAPERSIZE_A4')
        && str_contains($export, 'setFitToWidth(1)')
        && str_contains($export, 'setRowsToRepeatAtTopByStartAndEnd(1, 6)')
        && str_contains($export, 'Prepared / Submitted by Procurement')
        && str_contains($export, 'Received by Account'),
    'export_row_limit_and_currency_safety_remain' =>
        str_contains($export, 'PROCUREMENT_ACCOUNT_SCHEDULE_MAX_EXPORT_ROWS')
        && !str_contains($export, 'array_sum')
        && !preg_match('/SUM\s*\(/i', $export),
    'supplier_label_survives_async_option_loading' =>
        str_contains($service, "'supplier_label' => \$selectedSupplierLabel")
        && str_contains($export, "meta']['supplier_label"),
    'preview_endpoint_remains_read_only' =>
        !str_contains($previewRoute, 'procurementRequireCsrfToken')
        && !str_contains($previewRoute, 'INSERT INTO')
        && !str_contains($previewRoute, 'UPDATE ')
        && !str_contains($previewRoute, 'DELETE FROM'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
