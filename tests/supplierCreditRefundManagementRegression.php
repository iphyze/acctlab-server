<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementSupplierFinancialAdjustmentService.php');
$route = (string) file_get_contents($root . '/routes/request/supplier/financialAdjustments.php');
$advanceRoute = (string) file_get_contents($root . '/routes/procurement/payments/local/advancePurchaseAmendments.php');
$migration = (string) file_get_contents($root . '/database/new/20260917_supplier_credit_refund_management.sql');

$checks = [
    'credit_notes_have_dedicated_storage' =>
        str_contains($migration, 'procurement_supplier_financial_adjustment_credit_notes')
        && str_contains($service, 'credit_available_amount'),
    'credit_note_does_not_reduce_outstanding' =>
        str_contains($service, 'procurementSupplierAdjustmentRegisterCreditNote')
        && !str_contains(substr($service, strpos($service, 'function procurementSupplierAdjustmentRegisterCreditNote'), 3500), 'outstanding_amount = ?'),
    'refund_and_recovery_reduce_outstanding' =>
        str_contains($service, 'procurementSupplierAdjustmentRecordRecovery')
        && str_contains($service, 'Recovered amount exceeds the balance not reserved by supplier credit notes.')
        && str_contains($service, 'outstanding_amount = ?'),
    'double_recovery_is_blocked' =>
        str_contains($service, '$unreservedCents = max(0, $outstandingCents - $availableCreditCents);'),
    'unused_credit_notes_can_be_cancelled' =>
        str_contains($service, 'procurementSupplierAdjustmentCancelCreditNote')
        && str_contains($service, 'Only an unused credit note can be cancelled.'),
    'acctlab_is_single_recovery_authority' =>
        str_contains($route, "requireAdmin()")
        && str_contains($advanceRoute, 'Supplier recoveries are managed in AcctLab under Supplier Recoveries.'),
    'local_advance_reconciliation_closes_only_after_actual_settlement' =>
        str_contains($service, 'procurementSupplierAdjustmentResolveLocalAdvanceSource')
        && str_contains($service, "reconciliation_status = 'Resolved'"),
    'credit_available_remains_outstanding_for_future_offset' =>
        str_contains($service, "status IN ('Open', 'Credit Available', 'Partially Settled')"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 2);
