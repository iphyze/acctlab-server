<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/accountSupplierStatementService.php';

$requests = [
    [
        'request_type' => 'local_final_purchase', 'request_id' => 1, 'supplier_id' => 10,
        'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'NGN',
        'settlement_currency' => 'NGN', 'obligation_amount' => 10000000.00, 'payment_status' => 'Paid',
        'transaction_at' => '2026-09-01 09:00:00', 'primary_reference' => 'PO-A', 'purchase_number' => 'PUR-A',
        'po_number' => 'PO-A', 'invoice_number' => '', 'description' => 'Original paid PO', 'project_code' => 'PRJ-1',
        'procurement_purchase_id' => 101, 'procurement_source' => 'ProcureDesk', 'request_processing_reference' => '',
    ],
    [
        'request_type' => 'local_final_purchase', 'request_id' => 2, 'supplier_id' => 10,
        'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'NGN',
        'settlement_currency' => 'NGN', 'obligation_amount' => 15000000.00, 'payment_status' => 'Paid',
        'transaction_at' => '2026-09-10 09:00:00', 'primary_reference' => 'PO-B', 'purchase_number' => 'PUR-B',
        'po_number' => 'PO-B', 'invoice_number' => '', 'description' => 'Later supplier PO', 'project_code' => 'PRJ-2',
        'procurement_purchase_id' => 102, 'procurement_source' => 'ProcureDesk', 'request_processing_reference' => '',
    ],
];

$payments = [
    [
        'kind' => 'canonical', 'request_key' => 'local_final_purchase:1', 'request_type' => 'local_final_purchase', 'request_id' => 1,
        'currency' => 'NGN', 'statement_credit' => 10000000.00, 'settlement_coverage' => 10000000.00,
        'gross_amount' => 10000000.00, 'supplier_credit_applied' => 0.00,
        'settlement_currency' => 'NGN', 'settlement_amount' => 10000000.00, 'exchange_rate' => 1.0,
        'paid_at' => '2026-09-02 10:00:00', 'canonical_item_id' => 1, 'canonical_batch_id' => 201,
        'legacy_batch_id' => 1, 'batch_reference' => 'PAY-A', 'processing_method' => 'Bank',
        'processing_reference' => 'PAY-A', 'payment_reference' => 'PAY-A', 'completion_mode' => 'Immediate',
        'item_status' => 'Paid', 'primary_reference' => 'PO-A', 'purchase_number' => 'PUR-A', 'po_number' => 'PO-A',
        'invoice_number' => '', 'description' => 'Original paid PO', 'project_code' => 'PRJ-1', 'fx_instruction_letter_id' => 0,
        'date_basis' => 'canonical_paid_at',
    ],
    [
        'kind' => 'canonical', 'request_key' => 'local_final_purchase:2', 'request_type' => 'local_final_purchase', 'request_id' => 2,
        'currency' => 'NGN', 'statement_credit' => 5000000.00, 'settlement_coverage' => 15000000.00,
        'gross_amount' => 15000000.00, 'supplier_credit_applied' => 10000000.00,
        'settlement_currency' => 'NGN', 'settlement_amount' => 5000000.00, 'exchange_rate' => 1.0,
        'paid_at' => '2026-09-10 12:00:00', 'canonical_item_id' => 2, 'canonical_batch_id' => 202,
        'legacy_batch_id' => 2, 'batch_reference' => 'PAY-B', 'processing_method' => 'Bank',
        'processing_reference' => 'PAY-B', 'payment_reference' => 'PAY-B', 'completion_mode' => 'Immediate',
        'item_status' => 'Paid', 'primary_reference' => 'PO-B', 'purchase_number' => 'PUR-B', 'po_number' => 'PO-B',
        'invoice_number' => '', 'description' => 'Later supplier PO', 'project_code' => 'PRJ-2', 'fx_instruction_letter_id' => 0,
        'date_basis' => 'canonical_paid_at',
    ],
];

$adjustments = [
    [
        'id' => 301, 'source_type' => 'local_final_purchase', 'source_purchase_id' => 101,
        'source_po_id' => 0, 'source_revision_id' => 0, 'source_revision_number' => 1,
        'adjustment_kind' => 'Cancellation', 'adjustment_direction' => 'Recoverable',
        'supplier_id' => 10, 'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'NGN',
        'amount' => 10000000.00, 'outstanding_amount' => 0.00, 'status' => 'Settled',
        'reason' => 'Supplier could not supply.',
        'origin_snapshot_json' => json_encode(['po_number' => 'PO-A', 'project_code' => 'PRJ-1']),
        'revised_snapshot_json' => json_encode(['po_number' => 'PO-A', 'project_code' => 'PRJ-1', 'po_status' => 'Cancelled']),
        'created_at' => '2026-09-05 09:00:00',
        'credit_notes' => [[
            'id' => 401, 'adjustment_id' => 301, 'reference' => 'CN-001', 'issued_date' => '2026-09-06',
            'amount' => 10000000.00, 'available_amount' => 0.00, 'status' => 'Applied', 'notes' => 'Credit note issued.',
            'created_at' => '2026-09-06 09:00:00', 'cancelled_at' => null,
        ]],
        'allocations' => [[
            'id' => 501, 'adjustment_id' => 301, 'allocation_type' => 'Credit Offset',
            'target_source_type' => 'local_final_purchase', 'target_purchase_id' => 102,
            'target_account_request_type' => 'local_final_purchase', 'target_account_request_id' => 2,
            'amount' => 10000000.00, 'reference' => 'PAY-B', 'notes' => 'Applied to later supplier payment.',
            'created_at' => '2026-09-10 12:00:00',
        ]],
    ],
    [
        'id' => 302, 'source_type' => 'local_final_purchase', 'source_purchase_id' => 101,
        'source_po_id' => 0, 'source_revision_id' => 0, 'source_revision_number' => 2,
        'adjustment_kind' => 'Value Decrease', 'adjustment_direction' => 'Recoverable',
        'supplier_id' => 10, 'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'NGN',
        'amount' => 4000000.00, 'outstanding_amount' => 0.00, 'status' => 'Settled',
        'reason' => 'Partial refund test.',
        'origin_snapshot_json' => json_encode(['po_number' => 'PO-A', 'project_code' => 'PRJ-1']),
        'revised_snapshot_json' => json_encode(['po_number' => 'PO-A', 'project_code' => 'PRJ-1']),
        'created_at' => '2026-09-12 09:00:00',
        'credit_notes' => [],
        'allocations' => [[
            'id' => 502, 'adjustment_id' => 302, 'allocation_type' => 'Refund',
            'target_source_type' => null, 'target_purchase_id' => 0,
            'target_account_request_type' => null, 'target_account_request_id' => 0,
            'amount' => 4000000.00, 'reference' => 'REFUND-001', 'notes' => 'Cash refund received.',
            'created_at' => '2026-09-13 10:00:00',
        ]],
    ],
];

$sections = accountSupplierStatementBuildCurrencySections(
    $requests,
    $payments,
    ['local_final_purchase:1' => 10000000.00, 'local_final_purchase:2' => 15000000.00],
    [],
    '2026-09-01',
    '2026-09-30',
    'all',
    $adjustments
);

[$coverageByRequest, $offsetPaymentRecords] = accountSupplierStatementApplyCanonicalCredits(
    ['local_final_purchase:2' => $requests[1]],
    [[
        'request_type' => 'local_final_purchase', 'request_id' => 2,
        'settlement_amount' => 5000000.00, 'settlement_amount_paid' => 5000000.00,
        'gross_amount' => 15000000.00, 'supplier_credit_applied' => 10000000.00,
        'currency' => 'NGN', 'settlement_currency' => 'NGN', 'paid_at' => '2026-09-10 12:00:00',
        'canonical_item_id' => 2, 'canonical_batch_id' => 202, 'legacy_batch_id' => 2,
        'batch_reference' => 'PAY-B', 'processing_method' => 'Bank', 'processing_reference' => 'PAY-B',
        'payment_reference' => 'PAY-B', 'completion_mode' => 'Immediate', 'item_status' => 'Paid',
        'primary_reference' => 'PO-B', 'purchase_number' => 'PUR-B', 'po_number' => 'PO-B', 'invoice_number' => '',
        'description' => 'Later supplier PO', 'project_code' => 'PRJ-2', 'fx_instruction_letter_id' => 0,
        'exchange_rate_hint' => 1,
    ]]
);

$ngn = $sections[0] ?? [];
$summary = $ngn['summary'] ?? [];
$transactions = $ngn['transactions'] ?? [];
$byKind = [];
foreach ($transactions as $transaction) {
    $byKind[$transaction['kind'] ?? 'unknown'][] = $transaction;
}
$paymentsOut = $byKind['payment'] ?? [];
$offsets = $byKind['credit_offset'] ?? [];
$adjustmentRows = $byKind['supplier_adjustment'] ?? [];
$refundRows = $byKind['supplier_recovery'] ?? [];
$creditNotes = $byKind['credit_note'] ?? [];

$checks = [];
$checks['cash_and_supplier_credit_are_not_double_counted'] = abs((float) ($summary['new_obligations'] ?? 0) - 25000000.00) < 0.001
    && abs((float) ($summary['payments_settlements'] ?? 0) - 15000000.00) < 0.001
    && abs((float) ($summary['supplier_recovery_adjustments'] ?? 0) - 14000000.00) < 0.001
    && abs((float) ($summary['supplier_recoveries_received'] ?? 0) - 4000000.00) < 0.001
    && abs((float) ($summary['closing_payable_balance'] ?? 999) - 0.00) < 0.001;
$checks['canonical_offset_uses_full_coverage_but_posts_cash_only'] = abs((float) ($coverageByRequest['local_final_purchase:2'] ?? 0) - 15000000.00) < 0.001
    && abs((float) ($offsetPaymentRecords[0]['statement_credit'] ?? 0) - 5000000.00) < 0.001
    && abs((float) ($offsetPaymentRecords[0]['supplier_credit_applied'] ?? 0) - 10000000.00) < 0.001;
$checks['credit_offset_is_a_non_posting_audit_event'] = count($offsets) === 1
    && abs((float) ($offsets[0]['supplier_credit_applied'] ?? 0) - 10000000.00) < 0.001
    && abs((float) ($offsets[0]['debit'] ?? 1)) < 0.001
    && abs((float) ($offsets[0]['credit'] ?? 1)) < 0.001
    && abs((float) ($summary['supplier_credit_offsets_applied'] ?? 0) - 10000000.00) < 0.001;
$checks['payment_row_exposes_cash_and_credit_composition'] = count($paymentsOut) === 2
    && abs((float) ($paymentsOut[1]['credit'] ?? 0) - 5000000.00) < 0.001
    && abs((float) ($paymentsOut[1]['supplier_credit_applied'] ?? 0) - 10000000.00) < 0.001
    && abs((float) ($paymentsOut[1]['gross_settlement_amount'] ?? 0) - 15000000.00) < 0.001;
$checks['refund_reverses_supplier_recovery_credit'] = count($refundRows) === 1
    && abs((float) ($refundRows[0]['debit'] ?? 0) - 4000000.00) < 0.001;
$checks['adjustment_and_credit_note_audit_are_visible'] = count($adjustmentRows) === 2
    && count($creditNotes) === 1
    && (($adjustmentRows[0]['source']['entity'] ?? '') === 'supplier_adjustment')
    && count($adjustmentRows[0]['audit']['credit_notes'] ?? []) === 1
    && count($adjustmentRows[0]['audit']['allocations'] ?? []) === 1;
$checks['net_supplier_position_is_zero'] = abs((float) ($summary['net_supplier_payable_balance'] ?? 1)) < 0.001
    && abs((float) ($summary['net_supplier_credit_balance'] ?? 1)) < 0.001;
$checks['running_balance_closes_to_summary'] = abs((float) (($transactions[count($transactions) - 1]['running_balance'] ?? 999)) - (float) ($summary['closing_payable_balance'] ?? 0)) < 0.001;

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
