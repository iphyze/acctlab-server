<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$route = (string) file_get_contents($root . '/routes/procurement/reports/supplierOptions.php');
$supplierService = (string) file_get_contents($root . '/includes/procurementSupplierActivityReportService.php');
$scheduleService = (string) file_get_contents($root . '/includes/procurementAccountSubmissionScheduleService.php');
$supplierExport = (string) file_get_contents($root . '/routes/procurement/reports/supplierActivityExport.php');
$scheduleExport = (string) file_get_contents($root . '/routes/procurement/reports/accountSubmissionScheduleExport.php');

$checks = [
    'shared_report_supplier_options_route_registered' =>
        str_contains($index, "'/procurement/reports/supplier-options' => 'routes/procurement/reports/supplierOptions.php'"),
    'supplier_lookup_is_permission_aware_and_read_only' =>
        str_contains($route, 'procurementRequireAnyPermission')
        && str_contains($route, 'procurementSupplierActivityReportPermissionCodes()')
        && str_contains($route, "REQUEST_METHOD")
        && !str_contains($route, 'procurementRequireCsrfToken'),
    'supplier_lookup_matches_create_flow_limit_and_search' =>
        str_contains($route, 'min(100, max(1')
        && str_contains($route, "\$_GET['limit'] ?? 50")
        && str_contains($route, "\$_GET['search']")
        && str_contains($route, 'procurementSupplierActivityReportMasterSuppliers($conn, $search, $limit)'),
    'supplier_master_searches_name_and_ledger_without_number_range' =>
        str_contains($supplierService, 'supplier_name LIKE ?')
        && str_contains($supplierService, 'CAST(supplier_number AS CHAR) LIKE ?')
        && !str_contains($supplierService, 'supplier_number BETWEEN 40000000 AND 70000000'),
    'report_payloads_no_longer_embed_entire_supplier_master' =>
        str_contains($supplierService, "'suppliers' => []")
        && str_contains($scheduleService, "'suppliers' => []")
        && !str_contains($scheduleService, 'procurementSupplierActivityReportMasterSuppliers($conn)'),
    'selected_supplier_label_is_resolved_independently_for_exports' =>
        str_contains($supplierService, 'procurementSupplierActivityReportMasterSupplierLabel')
        && str_contains($supplierService, "'supplier_label' => \$selectedSupplierLabel")
        && str_contains($scheduleService, "'supplier_label' => \$selectedSupplierLabel")
        && str_contains($supplierExport, "meta']['supplier_label")
        && str_contains($scheduleExport, "meta']['supplier_label"),
    'no_new_storage_or_write_path' =>
        !str_contains($route, 'INSERT INTO')
        && !str_contains($route, 'UPDATE ')
        && !str_contains($route, 'DELETE FROM')
        && !str_contains($route, 'CREATE TABLE')
        && !str_contains($route, 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
