<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$route = (string) file_get_contents($root . '/routes/procurement/reports/accountSubmissionSchedule.php');
$service = (string) file_get_contents($root . '/includes/procurementAccountSubmissionScheduleService.php');

$checks = [
    'account_submission_schedule_route_registered' => str_contains(
        $index,
        "'/procurement/reports/account-submission-schedule' => 'routes/procurement/reports/accountSubmissionSchedule.php'"
    ),
    'foundation_is_get_only_and_permission_aware' =>
        str_contains($route, 'REQUEST_METHOD')
        && str_contains($route, 'procurementRequireAnyPermission')
        && str_contains($route, 'procurementSupplierActivityReportPermissionCodes()')
        && !str_contains($route, 'procurementRequireCsrfToken'),
    'all_four_procurement_scopes_are_supported' =>
        str_contains($service, "'local_final' => ['request_type' => 'local_final_purchase'")
        && str_contains($service, "'local_advance' => ['request_type' => 'local_advance_purchase'")
        && str_contains($service, "'fx_final' => ['request_type' => 'fx_final_purchase'")
        && str_contains($service, "'fx_advance' => ['request_type' => 'fx_advance_purchase'"),
    'period_is_based_on_actual_account_handoff_date' =>
        str_contains($service, 'COALESCE(r.date_received, r.approved_at) IS NOT NULL')
        && str_contains($service, 'DATE(COALESCE(r.date_received, r.approved_at)) AS sent_to_account_date')
        && str_contains($service, 'schedule.sent_to_account_date >= ?')
        && str_contains($service, 'schedule.sent_to_account_date <= ?')
        && str_contains($service, 'Date From and Date To are required'),
    'advance_po_joins_are_request_scope_isolated' =>
        str_contains($service, 'ap.request_scope = r.request_type')
        && str_contains($service, 'apr.request_scope = r.request_type'),
    'sample_schedule_fields_are_present' =>
        str_contains($service, "'po_number'")
        && str_contains($service, "'purchase_number'")
        && str_contains($service, "'grn_number'")
        && str_contains($service, "'site'")
        && str_contains($service, "'supplier_name'")
        && str_contains($service, "'invoice_number'")
        && str_contains($service, "'po_value'")
        && str_contains($service, "'purchase_value'")
        && str_contains($service, "'percentage'")
        && str_contains($service, "'po_date'")
        && str_contains($service, "'contact_person'")
        && str_contains($service, "'phone_number'")
        && str_contains($service, "'remark'")
        && str_contains($service, "'status'")
        && str_contains($service, "'payment_status'")
        && str_contains($service, "'date_sent_to_account'"),
    'local_currency_is_ngn_and_fx_keeps_native_currency' =>
        str_contains($service, "WHEN r.request_type IN ('local_final_purchase', 'local_advance_purchase') THEN 'NGN'")
        && str_contains($service, "UPPER(COALESCE(NULLIF(TRIM(r.currency)"),
    'supplier_filter_uses_async_full_supplier_master_lookup' =>
        str_contains($service, "'suppliers' => []")
        && !str_contains($service, 'procurementSupplierActivityReportMasterSuppliers($conn)'),
    'schedule_supports_scope_supplier_currency_status_and_payment_filters' =>
        str_contains($service, "query['scope']")
        && str_contains($service, "query['supplier_id']")
        && str_contains($service, "query['currency']")
        && str_contains($service, "query['status']")
        && str_contains($service, "query['payment_status']"),
    'pagination_and_direct_purchase_routes_are_ready_for_batch2' =>
        str_contains($service, 'LIMIT ? OFFSET ?')
        && str_contains($service, 'procurementSupplierActivityReportRoute')
        && str_contains($service, "'total_pages' => (int) ceil"),
    'foundation_creates_no_new_storage' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE')
        && !str_contains($service, 'DROP TABLE')
        && !str_contains($route, 'CREATE TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
