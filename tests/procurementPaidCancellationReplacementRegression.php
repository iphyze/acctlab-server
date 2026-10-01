<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$finalService = (string) file_get_contents($root . '/includes/procurementLocalFinalPurchaseService.php');
$advanceService = (string) file_get_contents($root . '/includes/procurementLocalAdvancePurchaseService.php');
$adjustmentService = (string) file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$replacementService = (string) file_get_contents($root . '/includes/procurementPurchaseReplacementService.php');
$finalActions = (string) file_get_contents($root . '/routes/procurement/payments/local/finalPurchaseActions.php');
$finalRoute = (string) file_get_contents($root . '/routes/procurement/payments/local/finalPurchases.php');
$advanceRoute = (string) file_get_contents($root . '/routes/procurement/payments/local/advancePurchases.php');
$migration = (string) file_get_contents($root . '/database/new/20260917_procurement_purchase_replacement_links.sql');

$checks = [
    'local_final_paid_cancel_uses_controlled_workflow' =>
        str_contains($finalActions, 'procurementLocalFinalCancelOne($conn')
        && str_contains($finalService, 'function procurementLocalFinalCancelOne('),

    'local_final_processing_is_blocked' =>
        str_contains($finalService, 'Complete or cancel the current Account processing payment before cancelling this PO.'),

    'local_final_paid_history_is_preserved_and_recovered' =>
        str_contains($finalService, "payment_status = 'Paid'")
        && str_contains($finalService, "'adjustment_kind' => 'Cancellation'")
        && str_contains($finalService, "'adjustment_direction' => 'Recoverable'"),

    'local_final_pending_handoff_is_withdrawn' =>
        str_contains($finalService, 'procurementLocalFinalDeletePendingAccountRequest')
        && str_contains($finalService, "payment_status = 'Cancelled'"),

    'cancellation_does_not_duplicate_existing_recovery' =>
        str_contains($adjustmentService, 'procurementSupplierAdjustmentRecoverableRecordedForSourceSupplier')
        && str_contains($finalService, '$newRecoveryCents = max(0, $paidCents - $recordedRecoveryCents);')
        && str_contains($advanceService, '$newRecoveryCents = max(0, $paidCents - $alreadyRecordedCents);'),

    'open_payables_are_cancelled_when_obligation_is_cancelled' =>
        str_contains($adjustmentService, 'procurementSupplierAdjustmentCancelOpenPayablesForSource')
        && str_contains($adjustmentService, 'procurementSupplierAdjustmentCancelOpenPayablesForPo'),

    'local_advance_paid_po_can_be_cancelled' =>
        str_contains($advanceService, 'function procurementLocalAdvanceCancelPoOne(')
        && str_contains($advanceService, 'return procurementLocalAdvanceCancelPoOne($conn, $purchaseId, $actor, $reason);'),

    'local_advance_processing_guard_and_pending_withdrawal_exist' =>
        str_contains($advanceService, 'Complete or cancel all Account processing payments before cancelling this PO.')
        && str_contains($advanceService, 'procurementLocalAdvanceDeletePendingAccountRequest('),

    'local_advance_paid_rows_create_recoverables' =>
        str_contains($advanceService, "'adjustment_kind' => 'Cancellation'")
        && str_contains($advanceService, '\'source_po_id\' => $poId')
        && str_contains($advanceService, '$recoverableTotalCents += $newRecoveryCents;'),

    'replacement_links_have_persistent_storage' =>
        str_contains($replacementService, 'procurement_purchase_replacement_links')
        && str_contains($migration, 'uq_procurement_purchase_replacement'),

    'replacement_must_point_to_cancelled_source' =>
        str_contains($finalRoute, 'A replacement can only be linked to a cancelled Local Final Purchase.')
        && str_contains($advanceRoute, 'A replacement can only be linked to a cancelled Local Advance Purchase.'),

    'replacement_link_is_created_on_new_purchase' =>
        str_contains($finalRoute, 'procurementPurchaseReplacementCreate(')
        && str_contains($advanceRoute, 'procurementPurchaseReplacementCreate(')
        && str_contains($finalRoute, 'replacement_of_id')
        && str_contains($advanceRoute, 'replacement_of_id'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
