<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$localAdvanceRoute = $read('routes/procurement/payments/local/advancePurchases.php');
$localFinalRoute = $read('routes/procurement/payments/local/finalPurchases.php');
$localAdvanceService = $read('includes/procurementLocalAdvancePurchaseService.php');
$canonicalRead = $read('includes/procurementRequestCanonicalReadService.php');

$checks = [
    'local_advance_register_isolates_normal_advance_join_by_account_type' =>
        str_contains($localAdvanceRoute, "AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')"),
    'local_advance_register_reads_compass_from_compass_table' =>
        str_contains($localAdvanceRoute, 'LEFT JOIN compass_fund_request_table cfr')
        && str_contains($localAdvanceRoute, "r.account_request_type = 'compass_fund_request'"),
    'local_advance_register_prefers_canonical_expected_payment' =>
        str_contains(
            $localAdvanceRoute,
            'COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount) AS account_advance_payment'
        ),
    'local_advance_register_combines_payment_state_only_after_type_isolation' =>
        str_contains($localAdvanceRoute, 'COALESCE(apr.payment_status, cfr.payment_status) AS account_payment_status')
        && str_contains($localAdvanceRoute, 'COALESCE(apr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0.00) AS amount_paid'),
    'local_final_register_isolates_supplier_join_by_account_type' =>
        str_contains($localFinalRoute, "AND (p.account_request_type IS NULL OR p.account_request_type = 'supplier_fund_request')"),
    'local_final_register_reads_compass_from_compass_table' =>
        str_contains($localFinalRoute, 'LEFT JOIN compass_fund_request_table cfr')
        && str_contains($localFinalRoute, "p.account_request_type = 'compass_fund_request'"),
    'canonical_local_reads_are_destination_aware' =>
        substr_count($canonicalRead, "account_request_type = 'compass_fund_request'") >= 3
        && substr_count($canonicalRead, "account_request_type = 'supplier_fund_request'") >= 2
        && str_contains($canonicalRead, "account_request_type = 'advance_payment_request'"),
    'canonical_local_advance_prefers_procuredesk_expected_payment' =>
        str_contains(
            $canonicalRead,
            'COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount) AS account_advance_payment'
        ),
    'local_advance_service_keeps_account_joins_type_isolated' =>
        str_contains($localAdvanceService, "AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')")
        && str_contains($localAdvanceService, "r.account_request_type = 'compass_fund_request'"),
    'local_advance_service_uses_canonical_amount_for_display_and_commitment' =>
        str_contains(
            $localAdvanceService,
            'COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount) AS account_advance_payment'
        )
        && str_contains(
            $localAdvanceService,
            'THEN COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount)'
        ),
    'fx_routes_are_not_modified_by_this_fix' =>
        !str_contains($localAdvanceRoute, 'fx_fund_request_table')
        && !str_contains($localFinalRoute, 'fx_fund_request_table'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
