<?php
$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$supplierRoute = file_get_contents($root . '/routes/request/supplier/getFilteredRequest.php');
$advanceRoute = file_get_contents($root . '/routes/request/advance/getFilteredRequest.php');

$checks = [
    'bulk_offset_summary_function_exists' => str_contains($service, 'function procurementSupplierPaymentOffsetSummariesForRequests'),
    'supplier_route_uses_bulk_summary' => str_contains($supplierRoute, 'procurementSupplierPaymentOffsetSummariesForRequests'),
    'advance_route_uses_bulk_summary' => str_contains($advanceRoute, 'procurementSupplierPaymentOffsetSummariesForRequests'),
    'supplier_route_no_longer_queries_availability_per_row' => !str_contains($supplierRoute, 'procurementSupplierAvailableOffsetForSupplier'),
    'advance_route_no_longer_queries_availability_per_row' => !str_contains($advanceRoute, 'procurementSupplierAvailableOffsetForSupplier'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}

echo json_encode(['healthy' => !$failed, 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed ? 1 : 0);
