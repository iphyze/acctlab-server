<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$advanceGaps = $read('routes/gaps/advance/suppliersAdvanceGaps.php');
$supplierGaps = $read('routes/gaps/supplier/suppliersGaps.php');
$instruction = $read('routes/letter/supplier/createRequest.php');
$union = $read('routes/union-bank-schedule/schedule.php');
$advanceService = $read('includes/accountAdvancePaymentService.php');
$supplierService = $read('includes/accountSupplierPaymentService.php');
$adjustments = $read('includes/procurementSupplierFinancialAdjustmentService.php');

$checks = [
    'advance_gaps_normalizes_to_net_cash' => str_contains($advanceGaps, 'accountAdvancePrepareCreditOffsets')
        && str_contains($advanceGaps, 'accountAdvanceNormalizeAllocationAmounts'),
    'supplier_gaps_normalizes_to_net_cash' => str_contains($supplierGaps, 'accountSupplierPrepareCreditOffsets')
        && str_contains($supplierGaps, 'accountSupplierNormalizeAllocationAmounts'),
    'instruction_normalizes_both_local_request_types' => str_contains($instruction, 'accountAdvanceNormalizeAllocationAmounts')
        && str_contains($instruction, 'accountSupplierNormalizeAllocationAmounts'),
    'union_normalizes_both_local_request_types' => str_contains($union, 'accountAdvanceNormalizeAllocationAmounts')
        && str_contains($union, 'accountSupplierNormalizeAllocationAmounts'),
    'advance_payment_batch_uses_cash_required' => str_contains($advanceService, 'accountAdvanceCreditPlanCashCents')
        && str_contains($advanceService, "['cash_required']"),
    'supplier_payment_batch_uses_cash_required' => str_contains($supplierService, 'accountSupplierCreditPlanCashCents')
        && str_contains($supplierService, "['cash_required']"),
    'existing_manual_reservation_is_reused' => str_contains($adjustments, '$existingReservedCents > 0 || $existingAppliedCents > 0')
        && str_contains($adjustments, '\'cash_required\' => procurementSupplierAdjustmentCents(max(0, $grossCents - $creditCents))'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
