<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementSupplierActivityReportService.php');
$export = (string) file_get_contents($root . '/routes/procurement/reports/supplierActivityExport.php');

$checks = [
    'screen_summary_and_export_share_one_canonical_financial_projection' =>
        str_contains($service, 'one canonical projection')
        && str_contains($service, 'AS paid_amount')
        && str_contains($service, 'AS outstanding_amount')
        && str_contains($service, 'AS wht_amount')
        && str_contains($service, "'paid_amount' => \$paid")
        && str_contains($service, "'outstanding_amount' => \$outstanding")
        && str_contains($export, 'procurementSupplierActivityReportSerializeRow'),
    'cancelled_paid_and_outstanding_safety_is_centralized' =>
        str_contains($service, "WHEN core.payment_status = 'Cancelled' THEN 0.00")
        && str_contains($service, 'LEAST(GREATEST(core.raw_paid_amount, 0.00), GREATEST(core.payable_amount, 0.00))'),
    'outstanding_aging_is_currency_separated' =>
        str_contains($service, 'aging_0_30')
        && str_contains($service, 'aging_31_60')
        && str_contains($service, 'aging_61_90')
        && str_contains($service, 'aging_90_plus')
        && str_contains($service, 'GROUP BY report.currency')
        && str_contains($service, "'aging' => ["),
    'aging_uses_account_received_date_then_request_date' =>
        str_contains($service, 'AS aging_date')
        && str_contains($service, 'GREATEST(DATEDIFF(CURDATE(), core.aging_date), 0) AS age_days')
        && str_contains($service, 'r.date_received')
        && str_contains($service, 'DATE(r.approved_at)'),
    'repeat_report_reads_can_skip_expensive_option_queries' =>
        str_contains($service, "query['include_options']")
        && str_contains($service, 'if ($includeOptions)')
        && str_contains($service, "'options' => \$optionsPayload")
        && str_contains($service, "'options_included' => \$includeOptions"),
    'backend_reports_query_timing_for_real_environment_qa' =>
        str_contains($service, '$startedAt = hrtime(true)')
        && str_contains($service, "'query_ms' => round((hrtime(true) - \$startedAt) / 1_000_000, 2)"),
    'excel_contains_same_currency_aging_analysis' =>
        str_contains($export, 'OUTSTANDING AGING BY CURRENCY')
        && str_contains($export, "'0–30 Days'")
        && str_contains($export, "'90+ Days'")
        && str_contains($export, "\$currency['aging']")
        && str_contains($export, "\$currency['outstanding']"),
    'excel_transaction_details_expose_aging_basis' =>
        str_contains($export, "'Aging Date', 'Age (Days)'")
        && str_contains($export, "\$row['aging_date']")
        && str_contains($export, "\$row['age_days']"),
    'batch4_remains_read_only_and_schema_neutral' =>
        !str_contains($service, 'INSERT INTO')
        && !str_contains($service, 'UPDATE ')
        && !str_contains($service, 'DELETE FROM')
        && !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE')
        && !str_contains($export, 'INSERT INTO')
        && !str_contains($export, 'UPDATE ')
        && !str_contains($export, 'DELETE FROM'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
