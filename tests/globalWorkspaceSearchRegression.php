<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/routes/search/global.php');
$index = (string) file_get_contents($root . '/index.php');

$checks = [
    'canonical_global_search_route_remains_registered' => str_contains($index, "'/search/global' => 'routes/search/global.php'"),
    'global_search_supports_operational_scopes' => str_contains($source, "'fund_requests'")
        && str_contains($source, "'payments'")
        && str_contains($source, "'schedules'")
        && str_contains($source, "'cash'")
        && str_contains($source, "'master_data'")
        && str_contains($source, "'reports'")
        && str_contains($source, "'administration'"),
    'fund_request_search_covers_local_and_fx_records' => str_contains($source, 'supplier_fund_request_table')
        && str_contains($source, 'advance_payment_request')
        && str_contains($source, 'expense_fund_request_table')
        && str_contains($source, 'compass_fund_request_table')
        && str_contains($source, 'fx_fund_request_table'),
    'fx_search_includes_new_contact_fields' => str_contains($source, 'contact_person LIKE ?')
        && str_contains($source, 'phone_number LIKE ?'),
    'schedule_search_covers_gaps_and_union' => str_contains($source, 'payment_schedule_tab')
        && str_contains($source, 'advance_payment_schedule_tab')
        && str_contains($source, 'other_payment_schedule')
        && str_contains($source, 'union_payment_schedule'),
    'cash_search_covers_transactions_and_ious' => str_contains($source, 'cash_transactions')
        && str_contains($source, 'cash_ious')
        && str_contains($source, "'filterable' => true"),
    'cash_search_restricts_non_admin_assignments' => str_contains($source, 'cash_account_users cau')
        && str_contains($source, 'cau.user_id = ?')
        && str_contains($source, 'cau.is_active = 1'),
    'master_data_search_is_app_wide' => str_contains($source, 'location_table')
        && str_contains($source, 'suppliers_table')
        && str_contains($source, 'suppliers_account_details')
        && str_contains($source, 'bank_sortcode_tab')
        && str_contains($source, 'bank_beneficiary_details_table'),
    'payments_and_reconciliation_are_searchable' => str_contains($source, 'fx_instruction_letter_table')
        && str_contains($source, 'local_transfer')
        && str_contains($source, 'instruction_letter')
        && str_contains($source, 'bank_recons'),
    'super_admin_search_includes_users_and_logs' => str_contains($source, 'if ($isSuperAdmin)')
        && str_contains($source, 'user_table')
        && str_contains($source, 'FROM logs'),
    'year_based_records_follow_active_accounting_year' => str_contains($source, '$accountingYear')
        && str_contains($source, 'YEAR(created_at) = ?')
        && str_contains($source, 'accounting_year = ?'),
    'record_results_include_direct_workspace_context' => str_contains($source, "'module' =>")
        && str_contains($source, "'category' =>")
        && str_contains($source, "'status' =>")
        && str_contains($source, "'amount' =>")
        && str_contains($source, "'currency' =>")
        && str_contains($source, 'workspaceSearchResultPath'),
    'global_results_are_relevance_ranked' => str_contains($source, 'workspaceSearchScore')
        && str_contains($source, 'usort($results'),
    'search_is_schema_safe_without_new_tables' => str_contains($source, 'workspaceSearchTableExists')
        && !str_contains($source, 'CREATE TABLE')
        && !str_contains($source, 'ALTER TABLE')
        && !str_contains($source, 'DROP TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
