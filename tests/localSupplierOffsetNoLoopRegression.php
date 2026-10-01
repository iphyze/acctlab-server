<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adjustment = file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$supplier = file_get_contents($root . '/includes/accountSupplierPaymentService.php');
$advance = file_get_contents($root . '/includes/accountAdvancePaymentService.php');

$checks = [
    'manual_offset_replaces_prior_reservation_in_transactional_route' => str_contains($adjustment, 'Supplier offset selection updated before payment processing.')
        && str_contains(file_get_contents($root . '/routes/request/supplier/financialAdjustments.php'), 'begin_transaction()')
        && str_contains(file_get_contents($root . '/routes/request/supplier/financialAdjustments.php'), 'rollback()'),
    'manual_offset_reuses_existing_reservation_during_processing' => str_contains($adjustment, '$existingReservedCents > 0 || $existingAppliedCents > 0'),
    'finalization_only_consumes_reserved_once' => str_contains($adjustment, "status = 'Reserved'")
        && str_contains($adjustment, "SET status = 'Applied'"),
    'release_only_restores_reserved_once' => str_contains($adjustment, "SET status = 'Released'")
        && str_contains($adjustment, "WHERE id = ? AND status = 'Reserved'"),
    'final_payment_uses_cash_required_not_gross' => str_contains($supplier, 'accountSupplierCreditPlanCashCents')
        && str_contains($supplier, "['cash_required']"),
    'advance_payment_uses_cash_required_not_gross' => str_contains($advance, 'accountAdvanceCreditPlanCashCents')
        && str_contains($advance, "['cash_required']"),
    'no_recursive_manual_offset_call' => substr_count($adjustment, 'procurementSupplierReserveManualOffsets(') === 1,
    'no_recursive_credit_reservation_call' => substr_count($adjustment, 'procurementSupplierReserveCreditForPayment(') === 1,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
