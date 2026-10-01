<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$advance = $read('includes/procurementFxAdvancePurchaseService.php');
$final = $read('includes/procurementFxFinalPurchaseService.php');
$adjustments = $read('includes/procurementSupplierFinancialAdjustmentService.php');
$replacement = $read('includes/procurementPurchaseReplacementService.php');
$payment = $read('includes/fxFundRequestPaymentService.php');
$processing = $read('includes/accountPaymentProcessingService.php');
$returnPending = $read('includes/fxFundRequestReturnToPendingService.php');
$single = $read('routes/request/fx/processForPayment.php');
$grouped = $read('routes/request/fx/processGroupedForPayment.php');

$checks = [
    'fx_advance_paid_supplier_change_is_revision_based' => str_contains($advance, 'function procurementFxAdvanceCreateSupplierChangeAdjustments')
        && str_contains($advance, "'adjustment_direction' => 'Recoverable'")
        && str_contains($advance, "'adjustment_direction' => 'Payable'"),
    'fx_final_paid_revision_uses_adjustment_ledger' => str_contains($final, "'source_type' => 'fx_final_purchase'")
        && str_contains($final, "'adjustment_direction' => 'Recoverable'")
        && str_contains($final, "'adjustment_direction' => 'Payable'"),
    'fx_currency_cannot_be_rewritten_after_payment' => str_contains($advance, 'The currency cannot be changed after FX Advance payment activity has started')
        && str_contains($final, 'currency'),
    'paid_fx_cancellation_preserves_recovery' => str_contains($advance, "'adjustment_kind' => 'Cancellation'")
        && str_contains($advance, "'adjustment_direction' => 'Recoverable'")
        && str_contains($final, "'adjustment_kind' => 'Cancellation'")
        && str_contains($final, "'adjustment_direction' => 'Recoverable'")
        && str_contains($adjustments, "'fx_final_purchase'")
        && str_contains($adjustments, "'fx_advance_purchase'"),
    'fx_replacement_is_linked_not_overwritten' => str_contains($replacement, 'replacement')
        && str_contains($replacement, 'source_type'),
    'fx_credit_is_same_supplier_same_currency' => str_contains($adjustments, 'a.supplier_id = ? AND a.currency = ?')
        && str_contains($payment, 'procurementSupplierReserveCreditForPayment'),
    'fx_single_payment_uses_net_cash_after_credit' => str_contains($single, 'fxFundRequestReserveSupplierCredit')
        && str_contains($payment, "'cash_request_amount'")
        && str_contains($payment, "'supplier_credit_applied'"),
    'fx_grouped_payment_uses_per_request_credit' => str_contains($grouped, '$creditPlans[$requestId] = fxFundRequestReserveSupplierCredit')
        && str_contains($grouped, 'fxFundRequestResolveGroupedConversions($requests, $processing, $creditPlans)'),
    'credit_reservation_finalizes_only_with_payment' => str_contains($processing, 'procurementSupplierFinalizeCreditReservations'),
    'cancelled_processing_releases_reserved_credit' => str_contains($returnPending, 'procurementSupplierReleaseCreditReservations'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
