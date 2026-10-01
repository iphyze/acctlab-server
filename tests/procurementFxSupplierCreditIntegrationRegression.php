<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$adjustments = $read('includes/procurementSupplierFinancialAdjustmentService.php');
$fxFinal = $read('includes/procurementFxFinalPurchaseService.php');
$fxAdvance = $read('includes/procurementFxAdvancePurchaseService.php');
$payment = $read('includes/fxFundRequestPaymentService.php');
$processing = $read('includes/accountPaymentProcessingService.php');
$returnPending = $read('includes/fxFundRequestReturnToPendingService.php');
$single = $read('routes/request/fx/processForPayment.php');
$grouped = $read('routes/request/fx/processGroupedForPayment.php');
$list = $read('routes/request/fx/getFilteredRequest.php');
$singleGet = $read('routes/request/fx/getSingle.php');

$checks = [
    'fx_sources_use_shared_adjustment_ledger' => str_contains($adjustments, "'fx_final_purchase'")
        && str_contains($adjustments, "'fx_advance_purchase'"),
    'fx_advance_recovery_can_resolve_reconciliation' => str_contains($adjustments, "['local_advance_purchase', 'fx_advance_purchase']")
        && str_contains($adjustments, 'request_scope = ? AND currency = ?'),
    'fx_final_revision_creates_recoverable_and_payable_adjustments' => str_contains($fxFinal, "'source_type' => 'fx_final_purchase'")
        && str_contains($fxFinal, "'adjustment_direction' => 'Recoverable'")
        && str_contains($fxFinal, "'adjustment_direction' => 'Payable'"),
    'fx_advance_supplier_change_and_decrease_create_adjustments' => str_contains($fxAdvance, 'function procurementFxAdvanceCreateSupplierChangeAdjustments')
        && str_contains($fxAdvance, 'function procurementFxAdvanceCreateValueDecreaseAdjustments')
        && str_contains($fxAdvance, "'source_type' => 'fx_advance_purchase'"),
    'credit_reservation_is_same_supplier_same_currency' => str_contains($adjustments, 'a.supplier_id = ? AND a.currency = ?')
        && str_contains($payment, 'procurementSupplierReserveCreditForPayment'),
    'single_fx_payment_reserves_and_applies_credit' => str_contains($single, 'fxFundRequestReserveSupplierCredit')
        && str_contains($single, 'procurementSupplierAttachCreditReservationsToBatch')
        && str_contains($single, 'procurementSupplierFinalizeCreditReservations'),
    'grouped_fx_payment_reserves_each_request_credit' => str_contains($grouped, '$creditPlans[$requestId] = fxFundRequestReserveSupplierCredit')
        && str_contains($grouped, 'fxFundRequestResolveGroupedConversions($requests, $processing, $creditPlans)'),
    'fx_conversion_uses_net_cash_requirement' => str_contains($payment, "'supplier_credit_applied'")
        && str_contains($payment, "'cash_request_amount'")
        && str_contains($payment, '$paymentAmount / $cashRequestAmount'),
    'fx_paid_confirmation_finalizes_reserved_credit' => str_contains($processing, 'procurementSupplierFinalizeCreditReservations')
        && str_contains($processing, 'accountPaymentProcessingCanonicalTypeFromFx($fxType)'),
    'fx_processing_cancellation_releases_reserved_credit' => str_contains($returnPending, 'procurementSupplierReleaseCreditReservations')
        && str_contains($returnPending, 'FX payment processing returned to Pending.'),
    'fx_requests_expose_available_credit' => str_contains($list, "'available_supplier_credit'")
        && str_contains($singleGet, "'available_supplier_credit'")
        && str_contains($list, 'procurementSupplierAvailableCreditForSupplier'),
    'credit_only_fx_settlement_does_not_require_zero_value_instruction' => str_contains($single, '$creditOnly')
        && str_contains($single, 'fx_instruction_letter_id = NULLIF(?, 0)')
        && str_contains($processing, "'Supplier Credit Offset'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
