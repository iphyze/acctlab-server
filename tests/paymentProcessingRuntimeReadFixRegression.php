<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/accountPaymentProcessingService.php') ?: '';
$route = file_get_contents($root . '/routes/request/payment-processing/items.php') ?: '';

$checks = [];
$checks['unified_items_route_still_uses_canonical_service'] = str_contains($route, 'accountPaymentProcessingListItems');
$checks['local_advance_projection_is_present'] = str_contains($service, 'FROM advance_payment_request apr')
    && str_contains($service, "'local_advance_purchase'")
    && str_contains($service, 'apr.advance_payment');
$checks['fx_projection_remains_present'] = str_contains($service, 'FROM fx_fund_request_table fx')
    && str_contains($service, "'fx_final_purchase'")
    && str_contains($service, "'fx_advance_purchase'");
$checks['union_text_projection_is_collation_safe'] = substr_count($service, 'COLLATE utf8mb4_unicode_ci') >= 16
    && str_contains($service, 'CONVERT(sfr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci')
    && str_contains($service, 'CONVERT(apr.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci')
    && str_contains($service, 'CONVERT(fx.suppliers_name USING utf8mb4) COLLATE utf8mb4_unicode_ci');
$checks['payment_status_union_is_collation_safe'] = str_contains($service, 'CONVERT(sfr.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci')
    && str_contains($service, 'CONVERT(apr.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci')
    && str_contains($service, 'CONVERT(fx.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci');
$checks['currency_union_is_collation_safe'] = str_contains($service, "CONVERT('NGN' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency")
    && str_contains($service, "COALESCE(NULLIF(fx.payment_currency, ''), fx.currency, 'FX')");
$checks['request_type_filter_remains_parameterized'] = str_contains($service, "i.request_type = ?")
    && str_contains($service, 'accountPaymentProcessingAssertType($requestType)');
$checks['no_schema_change_required'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $service . $route);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
