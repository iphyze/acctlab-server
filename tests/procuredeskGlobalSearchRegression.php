<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$route = file_get_contents($root . '/routes/procurement/search/global.php');

$checks = [
    'route_registered' => str_contains($index, "'/procurement/search/global' => 'routes/procurement/search/global.php'"),
    'procurement_auth_used' => str_contains($route, 'procurementAuthenticateUser($conn)'),
    'canonical_requests_are_searched' => str_contains($route, 'FROM procurement_requests r'),
    'all_four_purchase_permissions_are_scoped' =>
        str_contains($route, "'local_final_purchase' => 'payments.local_final.view'")
        && str_contains($route, "'local_advance_purchase' => 'payments.local_advance.view'")
        && str_contains($route, "'fx_final_purchase' => 'payments.fx_final.view'")
        && str_contains($route, "'fx_advance_purchase' => 'payments.fx_advance.view'"),
    'advance_po_supplier_resolution_present' =>
        str_contains($route, 'procurement_local_advance_pos ap')
        && str_contains($route, 'procurement_local_advance_po_revisions apr')
        && str_contains($route, 'resolved_supplier_name'),
    'business_fields_are_searchable' =>
        str_contains($route, "'r.purchase_number'")
        && str_contains($route, "'r.po_number'")
        && str_contains($route, "'r.invoice_number'")
        && str_contains($route, "'r.project_code'")
        && str_contains($route, "'r.supplier_name'"),
    'direct_record_routes_present' =>
        str_contains($route, '"/payments/local/final/{$publicId}"')
        && str_contains($route, '"/payments/local/advance/{$publicId}"')
        && str_contains($route, '"/payments/foreign/final/{$publicId}"')
        && str_contains($route, '"/payments/foreign/advance/{$publicId}"'),
    'user_search_is_permission_gated' =>
        str_contains($route, "['users.view', 'access.users.view']")
        && str_contains($route, '$canSearchUsers'),
    'search_does_not_sum_currency_values' =>
        !preg_match('/SUM\s*\(.*(?:purchase_value|expected_payment|amount)/is', $route),
    'search_has_scope_and_limit_guards' =>
        str_contains($route, "['all', 'local', 'fx', 'users']")
        && str_contains($route, 'min(32, max(1'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
