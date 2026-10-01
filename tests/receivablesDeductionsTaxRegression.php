<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/accountReceivablesDeductionsService.php');
$summaryRoute = $read('routes/receivables/deductions.php');
$detailRoute = $read('routes/receivables/deductionsDetail.php');
$index = $read('index.php');

$checks = [
    'deductions_routes_are_registered' =>
        str_contains($index, "'/receivables/deductions'")
        && str_contains($index, "'/receivables/deductions/detail'")
        && str_contains($index, 'routes/receivables/deductions.php')
        && str_contains($index, 'routes/receivables/deductionsDetail.php'),
    'deductions_routes_are_admin_protected' =>
        str_contains($summaryRoute, 'requireAdmin()')
        && str_contains($detailRoute, 'requireAdmin()'),
    'report_uses_complete_non_deleted_register_history' =>
        str_contains($service, "['deleted_at IS NULL']")
        && !str_contains($service, "position = 'Open'"),
    'workbook_deduction_categories_are_present' =>
        str_contains($service, "'retention' => 'Retention'")
        && str_contains($service, "'advance_amortisation' => 'Advance Amortisation'")
        && str_contains($service, "'admin_other_charges' => 'Admin & Other Charges'")
        && str_contains($service, "'wht' => 'WHT'")
        && str_contains($service, "'vat_deducted_at_source' => 'VAT Deducted at Source'")
        && str_contains($service, "'ncd_levy' => 'NCD Levy'")
        && str_contains($service, "'stamp_duty' => 'Stamp Duty'")
        && str_contains($service, "'bank_charges' => 'Bank Charges'")
        && str_contains($service, "'other_deductions' => 'Other Deductions'"),
    'summary_includes_tax_and_wht_controls' =>
        str_contains($service, "'vat_charged'")
        && str_contains($service, "'gross_invoiced'")
        && str_contains($service, "'wht_credit_note_outstanding'"),
    'report_separates_ngn_and_usd_and_ngn_equivalent' =>
        str_contains($service, 'ACCOUNT_RECEIVABLES_CURRENCIES')
        && str_contains($service, "'total_deductions_ngn_equivalent'")
        && str_contains($service, '$fxRate'),
    'detail_drills_to_source_register_lines' =>
        str_contains($service, "'selected_amount'")
        && str_contains($service, "'invoice_number'")
        && str_contains($service, "'source_reference'")
        && str_contains($service, "'remarks'"),
    'detail_supports_wht_credit_note_drilldown' =>
        str_contains($service, 'ACCOUNT_RECEIVABLES_WHT_CREDIT_OUTSTANDING')
        && str_contains($service, 'WHT Credit Note Not Yet Collected'),
    'batch_requires_no_new_database_tables' =>
        !str_contains($service, 'CREATE TABLE')
        && !str_contains($service, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
