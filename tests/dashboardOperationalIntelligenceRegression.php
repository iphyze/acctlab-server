<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/routes/reports/dashboardOverview.php');

$checks = [
    'dashboard_fx_exposure_is_grouped_by_original_currency' => str_contains($source, "FROM fx_fund_request_table")
        && str_contains($source, 'GROUP BY UPPER(COALESCE(NULLIF(TRIM(currency)')
        && str_contains($source, "'currencies' => \$fxExposure"),
    'fx_values_are_not_combined_into_cross_currency_total' => str_contains($source, 'Values from different currencies are never converted or aggregated together.')
        && str_contains($source, "'currency_count' => 0")
        && str_contains($source, "'request_count' => 0")
        && !str_contains($source, "'total_amount' => \$fxSummary"),
    'fx_currency_rows_include_status_and_settlement_context' => str_contains($source, "'requested_amount' => \$requestedAmount")
        && str_contains($source, "'paid_amount' => \$paidAmount")
        && str_contains($source, "'open_amount' => (float)")
        && str_contains($source, "'settlement_rate' => \$requestedAmount > 0"),
    'dashboard_exceptions_use_canonical_payment_reminders' => str_contains($source, 'FROM account_payment_reminders')
        && str_contains($source, "lifecycle_status NOT IN ('Completed', 'Cancelled')")
        && str_contains($source, 'next_reminder_at < NOW()'),
    'exception_summary_exposes_overdue_escalated_and_failure_counts' => str_contains($source, "'overdue_payment_reminders'")
        && str_contains($source, "'escalated_payment_reminders'")
        && str_contains($source, "'failed_delivery_reminders'"),
    'exception_items_preserve_request_routing_context' => str_contains($source, 'reminder_reference')
        && str_contains($source, 'request_type')
        && str_contains($source, 'request_id')
        && str_contains($source, 'overdue_minutes'),
    'dashboard_optional_tables_are_guarded' => str_contains($source, "dashboardTableExists(\$conn, 'fx_fund_request_table')")
        && str_contains($source, "dashboardTableExists(\$conn, 'account_payment_reminders')"),
    'dashboard_enhancement_requires_no_schema_change' => !str_contains($source, 'CREATE TABLE')
        && !str_contains($source, 'ALTER TABLE')
        && !str_contains($source, 'DROP TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
