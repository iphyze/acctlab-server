<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = file_get_contents($root . '/routes/request/supplier/getFilteredRequest.php') ?: '';

$checks = [];
$checks['read_route_avoids_supplier_storage_repair'] = !str_contains($route, 'accountSupplierEnsurePaymentStorage')
    && !str_contains($route, "includes/accountSupplierPaymentService.php")
    && str_contains($route, "includes/accountPaymentReminderService.php");
$checks['authentication_precedes_data_work'] = strpos($route, 'authenticateUser()') !== false
    && strpos($route, 'authenticateUser()') < strpos($route, 'SELECT COUNT(*) AS total');
$checks['count_query_stays_on_supplier_request_table'] = str_contains($route, '$baseQuery = "FROM supplier_fund_request_table WHERE 1=1"')
    && str_contains($route, 'SELECT COUNT(*) AS total $baseQuery');
$checks['page_is_selected_before_reminder_enrichment'] = strpos($route, 'LIMIT ? OFFSET ?') !== false
    && strpos($route, 'accountPaymentReminderAttachSummaries') !== false
    && strpos($route, 'LIMIT ? OFFSET ?') < strpos($route, 'accountPaymentReminderAttachSummaries');
$checks['reminder_enrichment_is_current_page_only'] = str_contains($route, '$data = $result->fetch_all(MYSQLI_ASSOC);')
    && str_contains($route, "accountPaymentReminderAttachSummaries(\$conn, 'Supplier', \$data)");
$checks['existing_workspace_filters_are_preserved'] = str_contains($route, "payment_status")
    && str_contains($route, "YEAR(created_at)")
    && str_contains($route, "suppliers_name LIKE ?")
    && str_contains($route, "purchase_number LIKE ?")
    && str_contains($route, "invoice_number LIKE ?")
    && str_contains($route, "po_number LIKE ?");
$checks['no_schema_or_repair_sql_in_read_route'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|UPDATE\s+supplier_fund_request_table/i', $route);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
