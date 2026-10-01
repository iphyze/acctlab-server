<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$supplierService = $read('includes/accountSupplierPaymentService.php');
$advanceService = $read('includes/accountAdvancePaymentService.php');
$adjustmentService = $read('includes/procurementSupplierFinancialAdjustmentService.php');
$finalPurchase = $read('includes/procurementLocalFinalPurchaseService.php');
$advancePurchase = $read('includes/procurementLocalAdvancePurchaseService.php');

$managedRoutes = implode("\n", [
    $read('routes/gaps/supplier/suppliersGaps.php'),
    $read('routes/gaps/advance/suppliersAdvanceGaps.php'),
    $read('routes/union-bank-schedule/schedule.php'),
    $read('routes/letter/supplier/createRequest.php'),
]);

$checks = [
    'existing_manual_offset_is_used_by_supplier_preview' =>
        str_contains($supplierService, 'procurementSupplierCreditReservationTotals')
        && str_contains($supplierService, "'local_final_purchase'"),
    'existing_manual_offset_is_used_by_advance_preview' =>
        str_contains($advanceService, 'procurementSupplierCreditReservationTotals')
        && str_contains($advanceService, "'local_advance_purchase'"),
    'supplier_direct_processing_and_paid_use_net_offset_plan' =>
        str_contains($supplierService, 'procurementSupplierReserveCreditForPayment')
        && str_contains($supplierService, 'cash_amount_paid = ?')
        && str_contains($supplierService, 'procurementSupplierFinalizeCreditReservations')
        && str_contains($supplierService, 'procurementSupplierReleaseCreditReservations'),
    'advance_direct_processing_and_paid_use_net_offset_plan' =>
        str_contains($advanceService, 'procurementSupplierReserveCreditForPayment')
        && str_contains($advanceService, 'cash_amount_paid = ?')
        && str_contains($advanceService, 'procurementSupplierFinalizeCreditReservations')
        && str_contains($advanceService, 'procurementSupplierReleaseCreditReservations'),
    'single_and_bulk_payment_batches_store_net_cash' =>
        str_contains($supplierService, "'total_amount' => \$totalCash")
        && str_contains($advanceService, "'total_amount' => \$totalCash")
        && str_contains($supplierService, "'amount' => \$cashAmount")
        && str_contains($advanceService, "'amount' => \$cashAmount"),
    'managed_bank_and_instruction_routes_normalize_to_net_cash' =>
        str_contains($managedRoutes, 'accountSupplierNormalizeAllocationAmounts')
        && str_contains($managedRoutes, 'accountAdvanceNormalizeAllocationAmounts'),
    'offset_summary_exposes_reserved_applied_reason_and_reference' =>
        str_contains($adjustmentService, 'function procurementSupplierPaymentOffsetSummaryForRequest')
        && str_contains($adjustmentService, "'effective_offset_amount'")
        && str_contains($adjustmentService, "'reason'")
        && str_contains($adjustmentService, "'references'"),
    'procurement_final_reads_account_offset_and_net_cash' =>
        str_contains($finalPurchase, 'account_supplier_offset_amount')
        && str_contains($finalPurchase, 'account_net_cash_payable')
        && str_contains($finalPurchase, 'account_offset_reason'),
    'procurement_advance_reads_account_offset_and_net_cash' =>
        str_contains($advancePurchase, 'account_supplier_offset_amount')
        && str_contains($advancePurchase, 'account_net_cash_payable')
        && str_contains($advancePurchase, 'account_offset_reason'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === [] ? 0 : 1);
