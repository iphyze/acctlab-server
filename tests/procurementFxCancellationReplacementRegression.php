<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$finalService = $read('includes/procurementFxFinalPurchaseService.php');
$advanceService = $read('includes/procurementFxAdvancePurchaseService.php');
$adjustmentService = $read('includes/procurementSupplierFinancialAdjustmentService.php');
$replacementService = $read('includes/procurementPurchaseReplacementService.php');
$finalActions = $read('routes/procurement/payments/foreign/finalPurchaseActions.php');
$advanceActions = $read('routes/procurement/payments/foreign/advancePurchaseActions.php');
$finalRoute = $read('routes/procurement/payments/foreign/finalPurchases.php');
$advanceRoute = $read('routes/procurement/payments/foreign/advancePurchases.php');

$checks = [
    'fx_adjustment_sources_are_supported' =>
        str_contains($adjustmentService, "'fx_final_purchase'")
        && str_contains($adjustmentService, "'fx_advance_purchase'"),

    'fx_replacement_types_are_supported' =>
        str_contains($replacementService, "'fx_final_purchase'")
        && str_contains($replacementService, "'fx_advance_purchase'"),

    'fx_final_paid_cancel_uses_controlled_workflow' =>
        str_contains($finalActions, 'procurementFxFinalCancelOne($conn')
        && str_contains($finalService, 'function procurementFxFinalCancelOne('),

    'fx_final_processing_is_blocked' =>
        str_contains($finalService, 'Complete or cancel the current Account FX processing payment before cancelling this PO.'),

    'fx_final_paid_history_is_preserved_and_recovered' =>
        str_contains($finalService, "payment_status = 'Paid'")
        && str_contains($finalService, "'source_type' => 'fx_final_purchase'")
        && str_contains($finalService, "'adjustment_kind' => 'Cancellation'")
        && str_contains($finalService, "'adjustment_direction' => 'Recoverable'"),

    'fx_final_pending_request_is_withdrawn' =>
        str_contains($finalService, 'DELETE FROM fx_fund_request_table')
        && str_contains($finalService, "payment_status = 'Cancelled'"),

    'fx_advance_paid_po_can_be_cancelled' =>
        str_contains($advanceService, 'function procurementFxAdvanceCancelPoOne(')
        && str_contains($advanceService, 'return procurementFxAdvanceCancelPoOne($conn, $requestId, $actor, $reason);'),

    'fx_advance_processing_guard_exists' =>
        str_contains($advanceService, 'Complete or cancel all Account FX processing payments before cancelling this PO.'),

    'fx_advance_paid_rows_create_recoverables' =>
        str_contains($advanceService, "'source_type' => 'fx_advance_purchase'")
        && str_contains($advanceService, "'source_po_id' => \$poId")
        && str_contains($advanceService, '$recoverableTotalCents += $newRecoveryCents;'),

    'fx_advance_pending_requests_are_withdrawn' =>
        str_contains($advanceService, "request_type = 'Advance' AND payment_status = 'Pending'")
        && str_contains($advanceService, "payment_status = 'Cancelled'"),

    'fx_final_replacement_requires_cancelled_source' =>
        str_contains($finalRoute, 'A replacement can only be linked to a cancelled FX Final Purchase.')
        && str_contains($finalRoute, "'fx_final_purchase'"),

    'fx_advance_replacement_requires_cancelled_source' =>
        str_contains($advanceRoute, 'A replacement can only be linked to a cancelled FX Advance Purchase.')
        && str_contains($advanceRoute, "'fx_advance_purchase'"),

    'fx_routes_expose_replacement_links' =>
        str_contains($finalRoute, "'replacement_links' => procurementPurchaseReplacementLinks(")
        && str_contains($advanceRoute, "'replacement_links' => procurementPurchaseReplacementLinks("),

    'fx_action_routes_no_longer_block_paid_cancellation' =>
        !str_contains($finalActions, 'A processing or paid transaction cannot have its PO cancelled.')
        && !str_contains($advanceActions, 'An FX Advance PO with approved, processing or paid advances cannot be cancelled.'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
