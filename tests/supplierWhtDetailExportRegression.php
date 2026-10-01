<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/accountSupplierWhtReportService.php';

function detailExportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sourceRows = [
    [
        'id' => 101,
        'supplier_id' => 7,
        'source_supplier_id' => 7001,
        'supplier_name' => 'Alpha Engineering Ltd',
        'source_supplier_name' => 'Alpha Engineering Ltd',
        'supplier_wht_status' => '5.00%',
        'invoice_number' => 'INV-001',
        'purchase_number' => 'PUR-001',
        'po_number' => 'PO-001',
        'invoice_date' => '2026-03-01',
        'purchase_date' => '2026-03-02',
        'date_received' => '2026-03-03',
        'effective_date' => '2026-03-03',
        'invoice_month' => 'March',
        'purchase_month' => 'March',
        'project_code' => 'PRJ-01',
        'description' => 'Electrical materials',
        'vat_policy' => '7.50%',
        'vat' => '7500.00',
        'wht' => '5000.00',
        'wht_override_status' => null,
        'wht_override_rate' => null,
        'wht_override_amount' => null,
        'wht_override_reason' => null,
        'wht_override_at' => null,
        'payment_percentage' => '100%',
        'net_value' => '100000.00',
        'discount' => '0.00',
        'other_charges' => '0.00',
        'amount' => '102500.00',
        'note' => 'Paid in full',
        'payment_status' => 'Paid',
        'payment_confirmation_status' => 'Confirmed',
        'processing_method' => 'Bank Transfer',
        'processing_reference' => 'PROC-001',
        'processing_started_at' => '2026-03-04 09:00:00',
        'processing_business_days' => '1',
        'expected_completion_at' => '2026-03-05 09:00:00',
        'completion_mode' => 'Manual',
        'amount_paid' => '102500.00',
        'supplier_credit_applied' => '0.00',
        'cash_amount_paid' => '102500.00',
        'paid_at' => '2026-03-05 11:00:00',
        'payment_reference' => 'PAY-001',
        'account_remarks' => 'Completed',
        'payment_batch_id' => '22',
        'procurement_source' => 'local_final_purchase',
        'procurement_purchase_id' => '55',
        'procurement_revision' => '1',
        'created_at' => '2026-03-03 10:00:00',
        'updated_at' => '2026-03-05 11:00:00',
    ],
    [
        'id' => 102,
        'supplier_id' => 8,
        'source_supplier_id' => 8001,
        'supplier_name' => 'Beta Supplies Ltd',
        'source_supplier_name' => 'Beta Supplies Ltd',
        'invoice_number' => 'INV-002',
        'purchase_number' => 'PUR-002',
        'po_number' => 'PO-002',
        'invoice_date' => '2026-04-01',
        'purchase_date' => '2026-04-02',
        'date_received' => '2026-04-03',
        'effective_date' => '2026-04-03',
        'project_code' => 'PRJ-02',
        'description' => 'No VAT line',
        'vat_policy' => '0.00%',
        'vat' => '0.00',
        'wht' => '0.00',
        'net_value' => '200000.00',
        'discount' => '0.00',
        'other_charges' => '0.00',
        'amount' => '200000.00',
        'payment_status' => 'Paid',
        'created_at' => '2026-04-03 10:00:00',
    ],
];

$details = accountSupplierWhtBuildDetailRows($sourceRows);
detailExportAssert(count($details) === 1, 'Detail export must contain only WHT lines that contribute to the report total.');
$row = $details[0];
detailExportAssert(($row['request_id'] ?? null) === 101, 'The contributing Supplier Fund Request line was not preserved.');
detailExportAssert(($row['supplier'] ?? '') === 'Alpha Engineering Ltd', 'Supplier grouping identity was not preserved.');
detailExportAssert(($row['supplier_id'] ?? null) === 7001, 'The source Supplier Fund Request supplier ID must be visible in detail.');
detailExportAssert(abs((float) ($row['wht_amount'] ?? 0) - 5000.00) < 0.001, 'Detail WHT must match the canonical report calculation.');
detailExportAssert(abs((float) ($row['gross_amount'] ?? 0) - 107500.00) < 0.001, 'Gross amount must reconcile to Supplier Fund Request values.');
detailExportAssert(($row['payment_reference'] ?? '') === 'PAY-001', 'Payment processing detail must be retained.');
detailExportAssert(($row['account_remarks'] ?? '') === 'Completed', 'Account remarks must be retained.');
detailExportAssert(($row['report_date'] ?? '') === '2026-03-03', 'Resolved report date must be retained.');

$service = file_get_contents(dirname(__DIR__) . '/includes/accountSupplierWhtReportService.php') ?: '';
detailExportAssert(str_contains($service, 'bool $includeDetailFields = false'), 'Regular report queries must keep full detail fields opt-in.');
detailExportAssert(str_contains($service, 'accountSupplierWhtExportReport'), 'Excel export must have a dedicated detail-enabled report path.');

echo json_encode([
    'healthy' => true,
    'detail_rows' => count($details),
    'request_id' => $row['request_id'],
    'wht_amount' => $row['wht_amount'],
], JSON_PRETTY_PRINT) . PHP_EOL;
