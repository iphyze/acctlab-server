<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementLocalAdvancePurchaseService.php');
$modal = (string) file_get_contents($root . '/src/pages/payments/LocalAdvancePoAmendmentModal.jsx');

$checks = [
    'supplier_is_supported_amendment_type' =>
        str_contains($service, "'Scope', 'Supplier'")
        && str_contains($modal, "'Scope', 'Supplier'"),

    'paid_amendment_accepts_revised_supplier' =>
        str_contains($service, "array_key_exists('supplier_id', \$data)")
        && str_contains($service, "procurementLocalAdvanceRevisionSupplierChanged")
        && !str_contains(
            $service,
            'The supplier cannot be changed after payment has started. Cancel or reverse the old PO and create a new PO instead.'
        ),

    'supplier_change_keeps_processing_guard' =>
        str_contains($service, "'Processing Supplier Payment'")
        && str_contains(
            $service,
            'Complete or cancel the current Account processing payment before approving a supplier change.'
        ),

    'allocation_reads_exact_paid_supplier' =>
        str_contains($service, 'exact_revision.supplier_id AS exact_revision_supplier_id')
        && str_contains($service, 'supplier_change_recovery_recorded'),

    'historical_supplier_payment_does_not_satisfy_revised_supplier' =>
        str_contains($service, "\$belongsToCurrentSupplier")
        && str_contains($service, "'Supplier Replaced'")
        && str_contains($service, "'Historical Supplier'"),

    'old_supplier_paid_amount_creates_recoverable' =>
        str_contains($service, 'procurementLocalAdvanceCreateSupplierChangeAdjustments')
        && str_contains($service, "'adjustment_direction' => 'Recoverable'")
        && str_contains($service, "'supplier_id' => \$previousSupplierId"),

    'legacy_paid_rows_still_create_recovery' =>
        str_contains($service, 'Supplier Change Paid Commitment')
        && str_contains($service, "procurementLocalAdvanceAllocationPaymentStatus(\$row) === 'Paid'"),

    'new_supplier_shortfall_creates_payable' =>
        str_contains($service, "'adjustment_direction' => 'Payable'")
        && str_contains($service, "'supplier_id' => \$newSupplierId")
        && str_contains($service, "\$supplementaryPurchaseId"),

    'supplier_change_is_idempotently_recorded_through_shared_ledger' =>
        str_contains($service, 'procurementSupplierAdjustmentCreate($conn'),

    'amendment_ui_can_select_supplier' =>
        str_contains($modal, 'label="Revised supplier"')
        && str_contains($modal, "options('suppliers', supplierSearch, 50)")
        && str_contains($modal, 'supplier_id: Number(form.supplier_id)')
        && str_contains($modal, 'supplier_ledger: form.supplier_ledger'),

    'ui_explains_financial_effect' =>
        str_contains(
            $modal,
            'Supplier changes create recovery against the previous supplier and a separate payable for the revised supplier.'
        ),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
