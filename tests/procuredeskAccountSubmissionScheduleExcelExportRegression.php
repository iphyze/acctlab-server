<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = file_get_contents($root . '/routes/procurement/reports/accountSubmissionScheduleExport.php');
$index = file_get_contents($root . '/index.php');
$service = file_get_contents($root . '/includes/procurementAccountSubmissionScheduleService.php');

$checks = [
    'export_route_registered' => str_contains($index, "'/procurement/reports/account-submission-schedule/export'"),
    'uses_existing_schedule_filters' => str_contains($route, 'procurementAccountSubmissionSchedulePrepareFilters($_GET, $visibleScopes)'),
    'uses_existing_schedule_projection' => str_contains($route, 'procurementAccountSubmissionScheduleBaseSql')
        && str_contains($route, 'procurementAccountSubmissionScheduleBuildWhere'),
    'full_filtered_result_not_current_page' => str_contains($route, 'PROCUREMENT_ACCOUNT_SCHEDULE_MAX_EXPORT_ROWS')
        && str_contains($route, 'LIMIT ?'),
    'sample_schedule_sheets_present' => str_contains($route, "'FX Advance'")
        && str_contains($route, "'FX Final'")
        && str_contains($route, "'Local Advance'")
        && str_contains($route, "'Local Final'"),
    'sample_final_fields_preserved' => str_contains($route, "'Purchase Number'")
        && str_contains($route, "'GRN Number'")
        && str_contains($route, "'Invoice No.'")
        && str_contains($route, "'Purchase Value'"),
    'sample_advance_fields_preserved' => str_contains($route, "'Advance %'")
        && str_contains($route, "'PO Date'")
        && str_contains($route, "'Contact Person'")
        && str_contains($route, "'Phone Number'"),
    'handoff_date_added' => str_contains($route, "'Sent to Account'"),
    'fx_currency_is_explicit' => substr_count($route, "'Currency'") >= 2,
    'professional_charcoal_blue_branding' => str_contains($route, "PROCUREMENT_ACCOUNT_SCHEDULE_NAVY = '0A1D29'")
        && str_contains($route, "PROCUREMENT_ACCOUNT_SCHEDULE_TEAL = '18A79D'"),
    'print_ready_landscape_a4' => str_contains($route, 'PageSetup::ORIENTATION_LANDSCAPE')
        && str_contains($route, 'PageSetup::PAPERSIZE_A4')
        && str_contains($route, 'setFitToWidth(1)')
        && str_contains($route, 'setRowsToRepeatAtTopByStartAndEnd(1, 6)')
        && str_contains($route, 'setPrintArea'),
    'printed_handover_signature_area' => str_contains($route, 'Prepared / Submitted by Procurement')
        && str_contains($route, 'Received by Account'),
    'percentage_not_forced_to_six_decimals' => str_contains($route, "0.##\"%\""),
    'no_cross_currency_totaling' => !str_contains($route, 'SUM(')
        && !str_contains($route, 'array_sum'),
    'schedule_service_still_requires_account_handoff' => str_contains($service, 'r.date_received IS NOT NULL'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
