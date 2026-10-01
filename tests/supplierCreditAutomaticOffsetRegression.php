<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adjustment = (string) file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$supplier = (string) file_get_contents($root . '/includes/accountSupplierPaymentService.php');
$advance = (string) file_get_contents($root . '/includes/accountAdvancePaymentService.php');
$union = (string) file_get_contents($root . '/routes/union-bank-schedule/schedule.php');
$letter = (string) file_get_contents($root . '/routes/letter/supplier/createRequest.php');
$gapsSupplier = (string) file_get_contents($root . '/routes/gaps/supplier/suppliersGaps.php');
$gapsAdvance = (string) file_get_contents($root . '/routes/gaps/advance/suppliersAdvanceGaps.php');
$supplierRoute = (string) file_get_contents($root . '/routes/request/supplier/paymentBatches.php');
$advanceRoute = (string) file_get_contents($root . '/routes/request/advance/paymentBatches.php');
$migration = (string) file_get_contents($root . '/database/new/20260917_supplier_credit_payment_offset.sql');

$checks = [
    'reservation_storage_exists' =>
        str_contains($migration, 'procurement_supplier_credit_reservations')
        && str_contains($adjustment, 'procurementSupplierReserveCreditForPayment'),
    'credit_is_reserved_then_finalized_or_released' =>
        str_contains($adjustment, 'procurementSupplierFinalizeCreditReservations')
        && str_contains($adjustment, 'procurementSupplierReleaseCreditReservations')
        && str_contains($adjustment, "status = 'Applied'")
        && str_contains($adjustment, "status = 'Released'"),
    'supplier_payment_uses_net_cash' =>
        str_contains($supplier, 'accountSupplierPrepareCreditOffsets')
        && str_contains($supplier, "'cash_amount' => \$cashAmount")
        && str_contains($supplier, 'cash_amount_paid = ?'),
    'advance_payment_uses_net_cash' =>
        str_contains($advance, 'accountAdvancePrepareCreditOffsets')
        && str_contains($advance, "'cash_amount' => \$cashAmount")
        && str_contains($advance, 'cash_amount_paid = ?'),
    'confirmed_payment_applies_credit' =>
        str_contains($supplier, "procurementSupplierFinalizeCreditReservations(\n        \$conn,\n        'local_final_purchase'")
        && str_contains($advance, "procurementSupplierFinalizeCreditReservations(\n        \$conn,\n        'local_advance_purchase'"),
    'failed_or_cancelled_payment_releases_credit' =>
        str_contains($supplier, "procurementSupplierReleaseCreditReservations(\n                    \$conn,\n                    'local_final_purchase'")
        && str_contains($advance, "procurementSupplierReleaseCreditReservations(\n                    \$conn,\n                    'local_advance_purchase'"),
    'bank_instruction_routes_are_net_of_credit' =>
        str_contains($union, 'accountSupplierNormalizeAllocationAmounts')
        && str_contains($union, 'accountAdvanceNormalizeAllocationAmounts')
        && str_contains($letter, 'accountSupplierNormalizeAllocationAmounts')
        && str_contains($letter, 'accountAdvanceNormalizeAllocationAmounts')
        && str_contains($gapsSupplier, 'accountSupplierNormalizeAllocationAmounts')
        && str_contains($gapsAdvance, 'accountAdvanceNormalizeAllocationAmounts'),
    'credit_preview_is_available_before_processing' =>
        str_contains($supplierRoute, 'credit_preview')
        && str_contains($supplierRoute, 'accountSupplierPreviewCreditOffsets')
        && str_contains($advanceRoute, 'accountAdvancePreviewCreditOffsets'),
    'gross_credit_cash_are_stored_separately' =>
        str_contains($migration, 'gross_amount DECIMAL(18,2)')
        && str_contains($migration, 'supplier_credit_applied DECIMAL(18,2)')
        && str_contains($migration, 'cash_amount_paid DECIMAL(18,2)'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 2);
