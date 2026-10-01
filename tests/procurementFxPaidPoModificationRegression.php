<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$advance = $read('includes/procurementFxAdvancePurchaseService.php');
$final = $read('includes/procurementFxFinalPurchaseService.php');
$route = $read('routes/procurement/payments/foreign/finalPurchases.php');
$auth = $read('includes/procurementAuthService.php');
$migration = $read('database/new/20260917_fx_final_paid_purchase_revision.sql');

$checks = [
    'fx_advance_supplier_change_detected' => str_contains($advance, 'procurementLocalAdvanceRevisionSupplierChanged($previousRevision, $revision)')
        && str_contains($advance, "'Supplier Replaced'")
        && str_contains($advance, 'exact_revision_supplier_id'),
    'fx_advance_processing_blocks_supplier_switch' => str_contains($advance, 'Complete or cancel the current Account processing payment before approving a supplier change.'),
    'fx_advance_old_supplier_payment_not_counted_for_new_supplier' => str_contains($advance, '$belongsToCurrentSupplier')
        && str_contains($advance, '$allocatedAfterCents += $previousCents;'),
    'fx_final_paid_revision_storage_exists' => str_contains($final, 'procurement_fx_final_paid_revisions')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS procurement_fx_final_paid_revisions'),
    'fx_final_paid_revision_permission_exists' => str_contains($auth, 'payments.fx_final.amend_paid_purchase')
        && str_contains($route, "'payments.fx_final.amend_paid_purchase'"),
    'fx_final_currency_is_immutable_after_payment' => str_contains($final, 'Currency cannot be changed after FX payment.'),
    'fx_final_supplier_change_separates_recovery_and_new_payable' => str_contains($final, '$recoverableCents = $supplierChanged ? $paidCents')
        && str_contains($final, '$additionalPayableCents = $supplierChanged ? $revisedPayableCents'),
    'fx_final_historical_paid_amount_uses_handoffs' => str_contains($final, 'function procurementFxFinalPaidAmountForSupplier')
        && str_contains($final, 'procurement_request_handoffs')
        && str_contains($final, "ffr.payment_status = 'Paid'"),
    'fx_final_new_payable_creates_new_fx_request' => str_contains($final, 'function procurementFxFinalCreateRevisionFundRequest')
        && str_contains($final, 'procurementFxFinalCreateHandoff($conn, $id, $nextHandoffRevision, $newFundRequestId, $actorId)'),
    'fx_final_original_payment_not_rewritten' => str_contains($final, "'paid_purchase_revised'")
        && str_contains($final, 'previous_fx_fund_request_id')
        && !str_contains($final, 'UPDATE fx_fund_request_table SET payment_status = \'Cancelled\''),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
