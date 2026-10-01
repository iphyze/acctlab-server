<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adjustment = (string) file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$advanceList = (string) file_get_contents($root . '/routes/request/advance/getFilteredRequest.php');
$supplierList = (string) file_get_contents($root . '/routes/request/supplier/getFilteredRequest.php');

$checks = [
    'recovery_register_exposes_reserved_offsets' =>
        str_contains($adjustment, 'reserved_offset_amount')
        && str_contains($adjustment, "WHERE status = 'Reserved' GROUP BY adjustment_id"),
    'advance_list_exposes_net_cash_after_offset' =>
        str_contains($advanceList, 'supplier_offset_cash_required')
        && str_contains($advanceList, "\$row['advance_payment']"),
    'final_list_exposes_net_cash_after_offset' =>
        str_contains($supplierList, 'supplier_offset_cash_required')
        && str_contains($supplierList, "\$row['amount']"),
    'reserved_offset_blocks_duplicate_credit_note' =>
        str_contains($adjustment, 'Reserved Supplier Offset')
        && str_contains($adjustment, 'not already reserved for an offset'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 2);
