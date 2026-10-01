<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/accountSupplierStatementService.php';

$service = file_get_contents($root . '/includes/accountSupplierStatementService.php') ?: '';
$route = file_get_contents($root . '/routes/reports/supplierStatement.php') ?: '';
$index = file_get_contents($root . '/index.php') ?: '';

$requests = [
    [
        'request_type' => 'fx_final_purchase', 'request_id' => 1, 'supplier_id' => 10,
        'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'USD',
        'settlement_currency' => 'USD', 'obligation_amount' => 100.00, 'payment_status' => 'Paid',
        'transaction_at' => '2026-06-10 00:00:00', 'primary_reference' => 'INV-1', 'purchase_number' => 'POREQ-1',
        'po_number' => 'PO-1', 'invoice_number' => 'INV-1', 'description' => 'Opening invoice', 'project_code' => 'PRJ-1',
        'procurement_purchase_id' => 0, 'procurement_source' => 'AcctLab FX', 'request_processing_reference' => '',
    ],
    [
        'request_type' => 'fx_final_purchase', 'request_id' => 2, 'supplier_id' => 10,
        'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'USD',
        'settlement_currency' => 'EUR', 'obligation_amount' => 50.00, 'payment_status' => 'Paid',
        'transaction_at' => '2026-07-05 00:00:00', 'primary_reference' => 'INV-2', 'purchase_number' => 'POREQ-2',
        'po_number' => 'PO-2', 'invoice_number' => 'INV-2', 'description' => 'Period invoice', 'project_code' => 'PRJ-2',
        'procurement_purchase_id' => 0, 'procurement_source' => 'AcctLab FX', 'request_processing_reference' => '',
    ],
    [
        'request_type' => 'fx_advance_purchase', 'request_id' => 3, 'supplier_id' => 10,
        'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'EUR',
        'settlement_currency' => 'EUR', 'obligation_amount' => 80.00, 'payment_status' => 'Pending',
        'transaction_at' => '2026-07-07 00:00:00', 'primary_reference' => 'PO-3', 'purchase_number' => '',
        'po_number' => 'PO-3', 'invoice_number' => '', 'description' => 'EUR advance', 'project_code' => 'PRJ-3',
        'procurement_purchase_id' => 0, 'procurement_source' => 'AcctLab FX', 'request_processing_reference' => '',
    ],
];

$payments = [
    [
        'kind' => 'canonical', 'request_key' => 'fx_final_purchase:1', 'request_type' => 'fx_final_purchase', 'request_id' => 1,
        'currency' => 'USD', 'statement_credit' => 40.00, 'settlement_currency' => 'USD', 'settlement_amount' => 40.00,
        'exchange_rate' => 1.0, 'paid_at' => '2026-06-20 11:00:00', 'canonical_item_id' => 1, 'canonical_batch_id' => 101,
        'legacy_batch_id' => 11, 'batch_reference' => 'FXF-PROC-101', 'processing_method' => 'FX Instruction',
        'processing_reference' => 'PAY-OLD', 'payment_reference' => 'PAY-OLD', 'completion_mode' => 'Immediate',
        'item_status' => 'Paid', 'primary_reference' => 'INV-1', 'purchase_number' => 'POREQ-1', 'po_number' => 'PO-1',
        'invoice_number' => 'INV-1', 'description' => 'Opening invoice', 'project_code' => 'PRJ-1', 'fx_instruction_letter_id' => 1,
        'date_basis' => 'canonical_paid_at',
    ],
    [
        'kind' => 'canonical', 'request_key' => 'fx_final_purchase:1', 'request_type' => 'fx_final_purchase', 'request_id' => 1,
        'currency' => 'USD', 'statement_credit' => 15.00, 'settlement_currency' => 'EUR', 'settlement_amount' => 13.50,
        'exchange_rate' => 0.9, 'paid_at' => '2026-07-15 12:00:00', 'canonical_item_id' => 2, 'canonical_batch_id' => 102,
        'legacy_batch_id' => 12, 'batch_reference' => 'FXF-PROC-102', 'processing_method' => 'FX Instruction',
        'processing_reference' => 'PAY-GROUP', 'payment_reference' => 'PAY-GROUP', 'completion_mode' => 'Immediate',
        'item_status' => 'Paid', 'primary_reference' => 'INV-1', 'purchase_number' => 'POREQ-1', 'po_number' => 'PO-1',
        'invoice_number' => 'INV-1', 'description' => 'Opening invoice', 'project_code' => 'PRJ-1', 'fx_instruction_letter_id' => 2,
        'date_basis' => 'canonical_paid_at',
    ],
    [
        'kind' => 'canonical', 'request_key' => 'fx_final_purchase:2', 'request_type' => 'fx_final_purchase', 'request_id' => 2,
        'currency' => 'USD', 'statement_credit' => 15.00, 'settlement_currency' => 'EUR', 'settlement_amount' => 13.50,
        'exchange_rate' => 0.9, 'paid_at' => '2026-07-15 12:00:00', 'canonical_item_id' => 3, 'canonical_batch_id' => 102,
        'legacy_batch_id' => 12, 'batch_reference' => 'FXF-PROC-102', 'processing_method' => 'FX Instruction',
        'processing_reference' => 'PAY-GROUP', 'payment_reference' => 'PAY-GROUP', 'completion_mode' => 'Immediate',
        'item_status' => 'Paid', 'primary_reference' => 'INV-2', 'purchase_number' => 'POREQ-2', 'po_number' => 'PO-2',
        'invoice_number' => 'INV-2', 'description' => 'Period invoice', 'project_code' => 'PRJ-2', 'fx_instruction_letter_id' => 2,
        'date_basis' => 'canonical_paid_at',
    ],
];

$credits = [
    'fx_final_purchase:1' => 55.00,
    'fx_final_purchase:2' => 15.00,
    'fx_advance_purchase:3' => 0.00,
];
$artifacts = [
    102 => [[
        'id' => 1, 'type' => 'fx_instruction', 'artifact_id' => 2, 'reference' => 'PAY-GROUP',
        'route' => '/payments/fx-payments/print/2', 'request_ids' => [1, 2], 'created_at' => '2026-07-15 12:00:00',
    ]],
];

$sections = accountSupplierStatementBuildCurrencySections(
    $requests,
    $payments,
    $credits,
    $artifacts,
    '2026-07-01',
    '2026-07-31',
    'all'
);
$sectionByCurrency = [];
foreach ($sections as $section) {
    $sectionByCurrency[$section['currency']] = $section;
}
$usd = $sectionByCurrency['USD'] ?? [];
$eur = $sectionByCurrency['EUR'] ?? [];
$usdPayments = array_values(array_filter($usd['transactions'] ?? [], static fn(array $row): bool => ($row['kind'] ?? '') === 'payment'));
$grouped = $usdPayments[0] ?? [];

$cancelledRequests = [[
    'request_type' => 'local_final_purchase', 'request_id' => 99, 'supplier_id' => 10,
    'supplier_name' => 'Supplier A', 'supplier_ledger' => '40100001', 'currency' => 'NGN',
    'settlement_currency' => 'NGN', 'obligation_amount' => 1000.00, 'payment_status' => 'Cancelled',
    'transaction_at' => '2026-07-03 00:00:00', 'primary_reference' => 'CAN-99', 'purchase_number' => 'CAN-99',
    'po_number' => '', 'invoice_number' => '', 'description' => 'Cancelled request', 'project_code' => 'PRJ-C',
    'procurement_purchase_id' => 0, 'procurement_source' => 'AcctLab Local', 'request_processing_reference' => '',
]];
$cancelledPayments = [[
    'kind' => 'canonical', 'request_key' => 'local_final_purchase:99', 'request_type' => 'local_final_purchase', 'request_id' => 99,
    'currency' => 'NGN', 'statement_credit' => 1000.00, 'settlement_currency' => 'NGN', 'settlement_amount' => 1000.00,
    'exchange_rate' => 1.0, 'paid_at' => '2026-07-04 00:00:00', 'canonical_item_id' => 99, 'canonical_batch_id' => 199,
    'legacy_batch_id' => 99, 'batch_reference' => 'CANCELLED-199', 'processing_method' => 'Manual',
    'processing_reference' => 'CANCELLED', 'payment_reference' => 'CANCELLED', 'completion_mode' => 'Notify',
    'item_status' => 'Paid', 'primary_reference' => 'CAN-99', 'purchase_number' => 'CAN-99', 'po_number' => '',
    'invoice_number' => '', 'description' => 'Cancelled request', 'project_code' => 'PRJ-C', 'fx_instruction_letter_id' => 0,
    'date_basis' => 'canonical_paid_at',
]];
$cancelledSections = accountSupplierStatementBuildCurrencySections(
    $cancelledRequests,
    $cancelledPayments,
    ['local_final_purchase:99' => 1000.00],
    [],
    '2026-07-01',
    '2026-07-31',
    'all'
);
$cancelledSummary = $cancelledSections[0]['summary'] ?? [];
$cancelledTransactions = $cancelledSections[0]['transactions'] ?? [];

$checks = [];
$checks['endpoint_is_registered'] = str_contains($index, "'/reports/supplierStatement'")
    && str_contains($route, 'accountSupplierStatementReport');
$checks['all_supplier_obligation_sources_are_projected'] = str_contains($service, 'supplier_fund_request_table')
    && str_contains($service, 'advance_payment_request')
    && str_contains($service, 'fx_fund_request_table')
    && str_contains($service, "'local_final_purchase'")
    && str_contains($service, "'local_advance_purchase'")
    && str_contains($service, "'fx_final_purchase'")
    && str_contains($service, "'fx_advance_purchase'");
$checks['canonical_payment_tables_are_reused'] = str_contains($service, 'account_payment_batches')
    && str_contains($service, 'account_payment_batch_items')
    && str_contains($service, 'account_payment_artifacts');
$checks['no_statement_ledger_table_is_created'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $service . $route)
    && !str_contains($service, 'supplier_statement_transactions');
$checks['cancelled_paid_history_is_preserved_without_changing_net_balance'] = abs((float) ($cancelledSummary['new_obligations'] ?? 0) - 1000.00) < 0.001
    && abs((float) ($cancelledSummary['payments_settlements'] ?? 0) - 1000.00) < 0.001
    && abs((float) ($cancelledSummary['closing_payable_balance'] ?? -1)) < 0.001
    && count(array_filter($cancelledTransactions, static fn(array $row): bool => ($row['kind'] ?? '') === 'payment')) === 1;
$checks['currency_balances_are_separate'] = isset($sectionByCurrency['USD'], $sectionByCurrency['EUR'])
    && count($sections) === 2
    && abs((float) ($eur['summary']['closing_payable_balance'] ?? 0) - 80.00) < 0.001;
$checks['opening_balance_uses_pre_period_activity'] = abs((float) ($usd['summary']['opening_payable_balance'] ?? 0) - 60.00) < 0.001;
$checks['period_obligations_and_payments_reconcile'] = abs((float) ($usd['summary']['new_obligations'] ?? 0) - 50.00) < 0.001
    && abs((float) ($usd['summary']['payments_settlements'] ?? 0) - 30.00) < 0.001
    && abs((float) ($usd['summary']['closing_payable_balance'] ?? 0) - 80.00) < 0.001;
$checks['grouped_payment_is_one_transaction_with_allocations'] = count($usdPayments) === 1
    && count($grouped['allocations'] ?? []) === 2
    && abs((float) ($grouped['credit'] ?? 0) - 30.00) < 0.001
    && (($grouped['source']['canonical_batch_id'] ?? 0) === 102);
$checks['fx_actual_settlement_currency_is_preserved'] = (($grouped['settlement_summary_by_currency'][0]['currency'] ?? '') === 'EUR')
    && abs((float) ($grouped['settlement_summary_by_currency'][0]['amount'] ?? 0) - 27.00) < 0.001
    && (($grouped['currency'] ?? '') === 'USD');
$checks['running_balance_closes_to_summary'] = abs((float) (($usd['transactions'][count($usd['transactions']) - 1]['running_balance'] ?? 0)) - (float) ($usd['summary']['closing_payable_balance'] ?? 0)) < 0.001;
$checks['partial_and_unpaid_counts_are_accounting_aware'] = (($usd['summary']['partially_settled_transaction_count'] ?? -1) === 2)
    && (($usd['summary']['unpaid_transaction_count'] ?? -1) === 2)
    && (($usd['summary']['paid_transaction_count'] ?? -1) === 0);
$checks['legacy_paid_fallback_is_explicit'] = str_contains($service, 'legacy_request_payment_metadata')
    && str_contains($service, 'legacy_created_at_fallback');
$checks['manual_fx_without_supplier_identity_is_not_name_matched'] = str_contains($service, 'Manual FX instructions')
    && str_contains($service, 'do not carry a reliable supplier_id')
    && str_contains($service, 'not name-matched into a supplier sub-ledger');
$checks['legacy_schedules_are_not_guessed_into_allocations'] = str_contains($service, 'Unlinked historical schedules')
    && str_contains($service, 'are not guessed/matched into the ledger')
    && str_contains($service, 'payment_schedule_tab')
    && str_contains($service, 'union_payment_schedule');
$checks['source_drilldown_ids_are_exposed'] = str_contains($service, "'request_id' =>")
    && str_contains($service, "'canonical_batch_id' =>")
    && str_contains($service, "'/payments/processing?batch_id='");

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
