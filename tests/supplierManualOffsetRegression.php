<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adjustment = (string) file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$route = (string) file_get_contents($root . '/routes/request/supplier/financialAdjustments.php');
$supplierList = (string) file_get_contents($root . '/routes/request/supplier/getFilteredRequest.php');
$advanceList = (string) file_get_contents($root . '/routes/request/advance/getFilteredRequest.php');
$supplierPayment = (string) file_get_contents($root . '/includes/accountSupplierPaymentService.php');
$advancePayment = (string) file_get_contents($root . '/includes/accountAdvancePaymentService.php');
$migration = (string) file_get_contents($root . '/database/new/20260917_supplier_manual_offset_reservations.sql');
$poReconciliationRoute = (string) file_get_contents($root . '/routes/request/advance/poReconciliations.php');

$checks = [
    'manual_offset_storage_supports_no_credit_note' =>
        str_contains($migration, 'MODIFY credit_note_id BIGINT UNSIGNED NULL')
        && str_contains($migration, 'offset_basis')
        && str_contains($migration, 'agreement_reference'),
    'manual_offset_backend_exists' =>
        str_contains($adjustment, 'procurementSupplierReserveManualOffsets')
        && str_contains($adjustment, 'Supplier Agreed Offset')
        && str_contains($adjustment, 'procurementSupplierOffsetOptionsForRequest'),
    'same_supplier_and_currency_are_enforced' =>
        str_contains($adjustment, 'Only recoveries for the same supplier and currency can be offset.'),
    'supplier_master_id_and_ledger_aliases_match' =>
        str_contains($adjustment, 'procurementSupplierAdjustmentResolveSupplierIdentity')
        && str_contains($adjustment, 'supplier_number = ?')
        && str_contains($adjustment, 'a.supplier_ledger = ?'),
    'credit_notes_are_used_before_direct_offset' =>
        str_contains($adjustment, 'Consume formal credit notes first')
        && str_contains($adjustment, "offset_basis")
        && str_contains($adjustment, "credit_note_id IS NULL"),
    'manual_offset_can_be_saved_or_cleared' =>
        str_contains($route, "save_supplier_offset")
        && str_contains($route, "clear_supplier_offset"),
    'pending_local_final_rows_expose_offset' =>
        str_contains($supplierList, 'supplier_offset_available')
        && str_contains($supplierList, 'local_final_purchase'),
    'pending_local_advance_rows_expose_offset' =>
        str_contains($advanceList, 'supplier_offset_available')
        && str_contains($advanceList, 'local_advance_purchase'),
    'existing_payment_engine_uses_reserved_manual_offset' =>
        str_contains($supplierPayment, 'procurementSupplierReserveCreditForPayment')
        && str_contains($advancePayment, 'procurementSupplierReserveCreditForPayment')
        && str_contains($adjustment, 'procurementSupplierCreditReservationTotals'),
    'release_handles_direct_offset_without_credit_note' =>
        str_contains($adjustment, "isset(\$row['credit_note_id'])")
        && str_contains($adjustment, "status = 'Released'"),
    'po_reconciliation_read_does_not_run_schema_repairs' =>
        !str_contains($poReconciliationRoute, 'accountAdvanceEnsurePaymentStorage')
        && !str_contains($poReconciliationRoute, 'accountAdvancePaymentService.php'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 2);
