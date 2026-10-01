<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const ACCOUNT_SUPPLIER_XLSX_INK = '122433';
const ACCOUNT_SUPPLIER_XLSX_NAVY = '102A3D';
const ACCOUNT_SUPPLIER_XLSX_NAVY_SOFT = '17384D';
const ACCOUNT_SUPPLIER_XLSX_TEAL = '20B7AE';
const ACCOUNT_SUPPLIER_XLSX_TEAL_DARK = '0B7F79';
const ACCOUNT_SUPPLIER_XLSX_TEAL_SOFT = 'E8F8F6';
const ACCOUNT_SUPPLIER_XLSX_BLUE = '4A86C5';
const ACCOUNT_SUPPLIER_XLSX_BLUE_SOFT = 'EAF3FA';
const ACCOUNT_SUPPLIER_XLSX_AMBER = 'D89A2B';
const ACCOUNT_SUPPLIER_XLSX_AMBER_SOFT = 'FFF4DD';
const ACCOUNT_SUPPLIER_XLSX_VIOLET = '796CC7';
const ACCOUNT_SUPPLIER_XLSX_VIOLET_SOFT = 'F0EDFB';
const ACCOUNT_SUPPLIER_XLSX_GREEN = '23865F';
const ACCOUNT_SUPPLIER_XLSX_GREEN_SOFT = 'E8F5EF';
const ACCOUNT_SUPPLIER_XLSX_RED = 'C94F5A';
const ACCOUNT_SUPPLIER_XLSX_RED_SOFT = 'FCECEF';
const ACCOUNT_SUPPLIER_XLSX_MUTED = '6E8391';
const ACCOUNT_SUPPLIER_XLSX_BORDER = 'D7E4EA';
const ACCOUNT_SUPPLIER_XLSX_ROW_ALT = 'F6FAFC';
const ACCOUNT_SUPPLIER_XLSX_WHITE = 'FFFFFF';

function accountSupplierStatementExcelColumn(int $index): string
{
    return Coordinate::stringFromColumnIndex($index);
}

function accountSupplierStatementExcelDateLabel(?string $date): string
{
    $value = trim((string) $date);
    if ($value === '') {
        return '—';
    }
    $time = strtotime($value);
    return $time === false ? $value : date('d M Y', $time);
}

function accountSupplierStatementExcelCleanFilePart(mixed $value, string $fallback): string
{
    $clean = preg_replace('/[\\\\\/:*?"<>|]+/', '-', trim((string) $value)) ?? '';
    $clean = preg_replace('/\s+/', '-', $clean) ?? '';
    $clean = preg_replace('/-+/', '-', $clean) ?? '';
    $clean = trim($clean, '-');
    return $clean !== '' ? $clean : $fallback;
}

function accountSupplierStatementExcelCleanSheetName(string $name): string
{
    $clean = preg_replace('/[\\\\\/?*\[\]:]+/', ' ', $name) ?? 'Statement';
    $clean = trim($clean);
    return mb_substr($clean !== '' ? $clean : 'Statement', 0, 31);
}

function accountSupplierStatementExcelNormalizeOrigin(mixed $origin): string
{
    $value = rtrim(trim((string) $origin), '/');
    if ($value === '' || !preg_match('#^https?://[^\s]+$#i', $value)) {
        return '';
    }
    return $value;
}

function accountSupplierStatementExcelAbsoluteRoute(mixed $route, string $origin): string
{
    $path = trim((string) $route);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if ($origin === '') {
        return '';
    }
    return $origin . '/' . ltrim($path, '/');
}

function accountSupplierStatementExcelCurrencyFormat(string $currency): string
{
    $code = preg_replace('/[^A-Z]/', '', strtoupper($currency)) ?: 'CCY';
    return '"' . $code . '" #,##0.00;[Red]-"' . $code . '" #,##0.00;–';
}

function accountSupplierStatementExcelSetText(Worksheet $sheet, string $cell, mixed $value): void
{
    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
}

function accountSupplierStatementExcelWriteRow(
    Worksheet $sheet,
    int $row,
    array $values,
    array $numericColumns = []
): void {
    foreach (array_values($values) as $index => $value) {
        $column = $index + 1;
        $cell = accountSupplierStatementExcelColumn($column) . $row;
        if (in_array($column, $numericColumns, true)) {
            $sheet->setCellValue($cell, (float) ($value ?? 0));
        } else {
            accountSupplierStatementExcelSetText($sheet, $cell, $value);
        }
    }
}

function accountSupplierStatementExcelConfigureSheet(
    Worksheet $sheet,
    bool $landscape = true,
    int $zoom = 90
): void {
    $sheet->setShowGridlines(false);
    $sheet->getSheetView()->setZoomScale($zoom);
    $sheet->getPageSetup()
        ->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.32)->setRight(0.32);
    $sheet->getHeaderFooter()->setOddFooter('&LAcctLab · Supplier Sub-Ledger&CPage &P of &N&RGenerated ' . date('d M Y H:i'));
}

function accountSupplierStatementExcelBrandHeader(
    Worksheet $sheet,
    string $title,
    string $supplierName,
    string $ledger,
    string $periodLabel,
    string $currency,
    int $lastColumn
): void {
    $endColumn = accountSupplierStatementExcelColumn($lastColumn);
    foreach ([1, 2, 3, 4] as $row) {
        $sheet->mergeCells("A{$row}:{$endColumn}{$row}");
    }

    accountSupplierStatementExcelSetText($sheet, 'A1', 'ACCTLAB  •  SUPPLIER SUB-LEDGER');
    accountSupplierStatementExcelSetText($sheet, 'A2', $title);
    accountSupplierStatementExcelSetText(
        $sheet,
        'A3',
        'Supplier: ' . ($supplierName !== '' ? $supplierName : '—') . '   •   Ledger: ' . ($ledger !== '' ? $ledger : '—')
    );
    accountSupplierStatementExcelSetText(
        $sheet,
        'A4',
        'Period: ' . $periodLabel . '   •   Currency: ' . ($currency !== '' ? $currency : '—')
    );

    $sheet->getStyle("A1:{$endColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_TEAL_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A2:{$endColumn}2")->applyFromArray([
        'font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A3:{$endColumn}4")->applyFromArray([
        'font' => ['size' => 10.5, 'color' => ['rgb' => 'D9E7EF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->getRowDimension(2)->setRowHeight(38);
    $sheet->getRowDimension(3)->setRowHeight(25);
    $sheet->getRowDimension(4)->setRowHeight(25);
}

function accountSupplierStatementExcelMetricCard(
    Worksheet $sheet,
    string $range,
    string $label,
    float $value,
    string $fill,
    string $accent,
    string $currency
): void {
    [$start, $end] = Coordinate::rangeBoundaries($range);
    $startColumn = accountSupplierStatementExcelColumn((int) $start[0]);
    $endColumn = accountSupplierStatementExcelColumn((int) $end[0]);
    $startRow = (int) $start[1];
    $endRow = (int) $end[1];

    $sheet->mergeCells("{$startColumn}{$startRow}:{$endColumn}{$startRow}");
    $sheet->mergeCells("{$startColumn}" . ($startRow + 1) . ":{$endColumn}{$endRow}");
    accountSupplierStatementExcelSetText($sheet, "{$startColumn}{$startRow}", strtoupper($label));
    $sheet->setCellValue("{$startColumn}" . ($startRow + 1), $value);

    $sheet->getStyle($range)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BORDER]],
            'left' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $accent]],
        ],
    ]);
    $sheet->getStyle("{$startColumn}{$startRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 9.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_MUTED]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $valueCell = "{$startColumn}" . ($startRow + 1);
    $sheet->getStyle($valueCell)->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_INK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT],
        'numberFormat' => ['formatCode' => accountSupplierStatementExcelCurrencyFormat($currency)],
    ]);
}

function accountSupplierStatementExcelCountChip(
    Worksheet $sheet,
    string $range,
    string $label,
    int $count,
    string $fill,
    string $fontColor
): void {
    $sheet->mergeCells($range);
    [$start] = Coordinate::rangeBoundaries($range);
    $cell = accountSupplierStatementExcelColumn((int) $start[0]) . (int) $start[1];
    accountSupplierStatementExcelSetText($sheet, $cell, number_format($count) . '  ' . $label);
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'size' => 9.5, 'color' => ['rgb' => $fontColor]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BORDER]]],
    ]);
}

function accountSupplierStatementExcelSectionTitle(Worksheet $sheet, int $row, string $title, int $lastColumn): void
{
    $endColumn = accountSupplierStatementExcelColumn($lastColumn);
    $sheet->mergeCells("A{$row}:{$endColumn}{$row}");
    accountSupplierStatementExcelSetText($sheet, "A{$row}", $title);
    $sheet->getStyle("A{$row}:{$endColumn}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_TEAL_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_TEAL]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(28);
}

function accountSupplierStatementExcelTableHeader(Worksheet $sheet, int $row, int $lastColumn): void
{
    $endColumn = accountSupplierStatementExcelColumn($lastColumn);
    $sheet->getStyle("A{$row}:{$endColumn}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_TEAL]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(34);
}

function accountSupplierStatementExcelStyleBodyRows(
    Worksheet $sheet,
    int $firstRow,
    int $lastRow,
    int $lastColumn
): void {
    if ($lastRow < $firstRow) {
        return;
    }
    $endColumn = accountSupplierStatementExcelColumn($lastColumn);
    $sheet->getStyle("A{$firstRow}:{$endColumn}{$lastRow}")->applyFromArray([
        'font' => ['size' => 10.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_INK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BORDER]]],
    ]);
    for ($row = $firstRow; $row <= $lastRow; $row++) {
        $sheet->getRowDimension($row)->setRowHeight(34);
        if (($row - $firstRow) % 2 === 1) {
            $sheet->getStyle("A{$row}:{$endColumn}{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_ROW_ALT);
        }
    }
}

function accountSupplierStatementExcelStyleStatusCell(Worksheet $sheet, string $cell, string $status): void
{
    $normalized = strtolower(trim($status));
    $fill = ACCOUNT_SUPPLIER_XLSX_BLUE_SOFT;
    $font = ACCOUNT_SUPPLIER_XLSX_BLUE;

    if ($normalized === 'paid') {
        $fill = ACCOUNT_SUPPLIER_XLSX_GREEN_SOFT;
        $font = ACCOUNT_SUPPLIER_XLSX_GREEN;
    } elseif (str_contains($normalized, 'partial') || str_contains($normalized, 'pending')) {
        $fill = ACCOUNT_SUPPLIER_XLSX_AMBER_SOFT;
        $font = '946200';
    } elseif (str_contains($normalized, 'failed') || str_contains($normalized, 'cancel')) {
        $fill = ACCOUNT_SUPPLIER_XLSX_RED_SOFT;
        $font = ACCOUNT_SUPPLIER_XLSX_RED;
    } elseif (str_contains($normalized, 'unconfirmed')) {
        $fill = 'EFF3F5';
        $font = ACCOUNT_SUPPLIER_XLSX_MUTED;
    }

    $sheet->getStyle($cell)->applyFromArray([
        'font' => ['bold' => true, 'size' => 9.5, 'color' => ['rgb' => $font]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
}

function accountSupplierStatementExcelAllocationSummary(array $allocations): string
{
    $lines = [];
    foreach ($allocations as $allocation) {
        $reference = trim((string) ($allocation['po_number'] ?? ''))
            ?: trim((string) ($allocation['invoice_number'] ?? ''))
            ?: trim((string) ($allocation['reference'] ?? ''))
            ?: 'Linked transaction';
        $project = trim((string) ($allocation['project_code'] ?? ''));
        $currency = trim((string) ($allocation['statement_currency'] ?? ''));
        $amount = number_format((float) ($allocation['statement_credit_amount'] ?? 0), 2, '.', ',');
        $parts = [$reference];
        if ($project !== '') {
            $parts[] = $project;
        }
        $parts[] = trim($currency . ' ' . $amount);
        $supplierCredit = (float) ($allocation['supplier_credit_applied'] ?? 0);
        if ($supplierCredit > 0) {
            $parts[] = 'Supplier credit ' . trim($currency . ' ' . number_format($supplierCredit, 2, '.', ','));
        }
        $lines[] = implode(' · ', $parts);
    }
    return implode("\n", $lines);
}

function accountSupplierStatementExcelTransactionDescription(array $transaction, string $currency): string
{
    $parts = [trim((string) ($transaction['description'] ?? ''))];
    $supplierCredit = (float) ($transaction['supplier_credit_applied'] ?? 0);
    if ($supplierCredit > 0) {
        $parts[] = 'Supplier credit applied: ' . $currency . ' ' . number_format($supplierCredit, 2, '.', ',');
    }
    $creditNote = (float) ($transaction['credit_note_amount'] ?? 0);
    if ($creditNote > 0) {
        $available = (float) ($transaction['credit_available_amount'] ?? 0);
        $parts[] = 'Credit note: ' . $currency . ' ' . number_format($creditNote, 2, '.', ',')
            . ' · Available: ' . $currency . ' ' . number_format($available, 2, '.', ',');
    }
    $audit = is_array($transaction['audit'] ?? null) ? $transaction['audit'] : [];
    if ($audit !== []) {
        $parts[] = 'Recovery outstanding: ' . $currency . ' ' . number_format((float) ($audit['outstanding_amount'] ?? 0), 2, '.', ',');
    }
    return implode("\n", array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
}

function accountSupplierStatementExcelDocumentSummary(array $documents): string
{
    $lines = [];
    foreach ($documents as $document) {
        $reference = trim((string) ($document['reference'] ?? ''));
        $type = trim((string) ($document['type'] ?? ''));
        $label = $reference !== '' ? $reference : $type;
        if ($label !== '') {
            $lines[] = $label;
        }
    }
    return implode("\n", $lines);
}

function accountSupplierStatementExcelAddHyperlink(Worksheet $sheet, string $cell, string $route, string $origin): void
{
    $target = accountSupplierStatementExcelAbsoluteRoute($route, $origin);
    if ($target === '') {
        accountSupplierStatementExcelSetText($sheet, $cell, '—');
        return;
    }

    accountSupplierStatementExcelSetText($sheet, $cell, 'Open in AcctLab');
    $sheet->getCell($cell)->getHyperlink()->setUrl($target)->setTooltip('Open source record in AcctLab');
    $sheet->getStyle($cell)->getFont()->setUnderline(true)->getColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_TEAL_DARK);
}

function accountSupplierStatementExcelBuildCurrencySheet(
    Spreadsheet $workbook,
    array $statement,
    array $section,
    string $origin,
    bool $useActiveSheet
): Worksheet {
    $currency = strtoupper(trim((string) ($section['currency'] ?? 'CCY'))) ?: 'CCY';
    $sheet = $useActiveSheet ? $workbook->getActiveSheet() : $workbook->createSheet();
    $sheet->setTitle(accountSupplierStatementExcelCleanSheetName($currency . ' Statement'));
    $sheet->getTabColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_TEAL);
    accountSupplierStatementExcelConfigureSheet($sheet, true, 90);

    $supplier = is_array($statement['supplier'] ?? null) ? $statement['supplier'] : [];
    $period = is_array($statement['period'] ?? null) ? $statement['period'] : [];
    $summary = is_array($section['summary'] ?? null) ? $section['summary'] : [];
    $transactions = is_array($section['transactions'] ?? null) ? $section['transactions'] : [];
    $periodLabel = accountSupplierStatementExcelDateLabel((string) ($period['from_date'] ?? ''))
        . ' — ' . accountSupplierStatementExcelDateLabel((string) ($period['to_date'] ?? ''));

    accountSupplierStatementExcelBrandHeader(
        $sheet,
        'SUPPLIER STATEMENT',
        trim((string) ($supplier['name'] ?? '')),
        trim((string) ($supplier['ledger'] ?? '')),
        $periodLabel,
        $currency,
        13
    );

    accountSupplierStatementExcelMetricCard($sheet, 'A6:C8', 'Opening payable', (float) ($summary['opening_payable_balance'] ?? 0), ACCOUNT_SUPPLIER_XLSX_BLUE_SOFT, ACCOUNT_SUPPLIER_XLSX_BLUE, $currency);
    accountSupplierStatementExcelMetricCard($sheet, 'D6:F8', 'New obligations', (float) ($summary['new_obligations'] ?? 0), ACCOUNT_SUPPLIER_XLSX_AMBER_SOFT, ACCOUNT_SUPPLIER_XLSX_AMBER, $currency);
    accountSupplierStatementExcelMetricCard($sheet, 'G6:I8', 'Payments / settlements', (float) ($summary['payments_settlements'] ?? 0), ACCOUNT_SUPPLIER_XLSX_TEAL_SOFT, ACCOUNT_SUPPLIER_XLSX_TEAL_DARK, $currency);
    accountSupplierStatementExcelMetricCard($sheet, 'J6:M8', 'Closing payable', (float) ($summary['closing_payable_balance'] ?? 0), ACCOUNT_SUPPLIER_XLSX_VIOLET_SOFT, ACCOUNT_SUPPLIER_XLSX_VIOLET, $currency);
    foreach ([6, 7, 8] as $row) {
        $sheet->getRowDimension($row)->setRowHeight($row === 6 ? 23 : 26);
    }

    accountSupplierStatementExcelCountChip($sheet, 'A9:C9', 'UNPAID', (int) ($summary['unpaid_transaction_count'] ?? 0), ACCOUNT_SUPPLIER_XLSX_AMBER_SOFT, '8A6100');
    accountSupplierStatementExcelCountChip($sheet, 'D9:F9', 'PAID', (int) ($summary['paid_transaction_count'] ?? 0), ACCOUNT_SUPPLIER_XLSX_GREEN_SOFT, ACCOUNT_SUPPLIER_XLSX_GREEN);
    accountSupplierStatementExcelCountChip($sheet, 'G9:I9', 'PARTIALLY SETTLED', (int) ($summary['partially_settled_transaction_count'] ?? 0), ACCOUNT_SUPPLIER_XLSX_VIOLET_SOFT, ACCOUNT_SUPPLIER_XLSX_VIOLET);
    $sheet->mergeCells('J9:M9');
    accountSupplierStatementExcelSetText($sheet, 'J9', 'OUTSTANDING  ' . $currency . ' ' . number_format((float) ($summary['outstanding_pending_payable'] ?? 0), 2, '.', ','));
    $sheet->getStyle('J9:M9')->applyFromArray([
        'font' => ['bold' => true, 'size' => 9.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ECF3F7']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BORDER]]],
    ]);
    $sheet->getRowDimension(9)->setRowHeight(26);

    accountSupplierStatementExcelSectionTitle($sheet, 11, 'TRANSACTION LEDGER', 13);
    $headers = [
        'Date', 'Transaction Type', 'Reference', 'PO / Invoice', 'Description', 'Project / Site',
        'Payable / Debit', 'Payment / Credit', 'Running Balance', 'Status', 'Payment / Batch Ref',
        'Grouped Payment Allocations', 'Linked Documents',
    ];
    accountSupplierStatementExcelWriteRow($sheet, 12, $headers);
    accountSupplierStatementExcelTableHeader($sheet, 12, 13);

    $firstDataRow = 13;
    accountSupplierStatementExcelWriteRow($sheet, $firstDataRow, [
        $period['from_date'] ?? '', 'Opening Balance B/F', '', '',
        'Outstanding supplier position before the selected From Date', '', 0, 0,
        (float) ($summary['opening_payable_balance'] ?? 0), 'Brought Forward', '', '', '',
    ], [7, 8, 9]);

    $row = $firstDataRow + 1;
    $transactionPresentation = [];
    foreach ($transactions as $transaction) {
        $allocations = is_array($transaction['allocations'] ?? null) ? $transaction['allocations'] : [];
        $documents = is_array($transaction['linked_documents'] ?? null) ? $transaction['linked_documents'] : [];
        accountSupplierStatementExcelWriteRow($sheet, $row, [
            $transaction['date'] ?? '',
            $transaction['transaction_type'] ?? '',
            $transaction['reference'] ?? '',
            $transaction['po_invoice'] ?? '',
            accountSupplierStatementExcelTransactionDescription($transaction, $currency),
            $transaction['project'] ?? '',
            (float) ($transaction['debit'] ?? 0),
            (float) ($transaction['credit'] ?? 0),
            (float) ($transaction['running_balance'] ?? 0),
            $transaction['status'] ?? '',
            $transaction['payment_batch_reference'] ?? ($transaction['payment_reference'] ?? ''),
            accountSupplierStatementExcelAllocationSummary($allocations),
            accountSupplierStatementExcelDocumentSummary($documents),
        ], [7, 8, 9]);
        $transactionPresentation[] = [
            'row' => $row,
            'status' => (string) ($transaction['status'] ?? ''),
        ];
        $row++;
    }

    $closingRow = $row;
    accountSupplierStatementExcelWriteRow($sheet, $closingRow, [
        $period['to_date'] ?? '', 'Closing Balance C/F', '', '',
        'Supplier payable position at the selected To Date', '', 0, 0,
        (float) ($summary['closing_payable_balance'] ?? 0), 'Carried Forward', '', '', '',
    ], [7, 8, 9]);

    accountSupplierStatementExcelStyleBodyRows($sheet, $firstDataRow, $closingRow, 13);
    foreach ($transactionPresentation as $presentation) {
        accountSupplierStatementExcelStyleStatusCell($sheet, 'J' . $presentation['row'], $presentation['status']);
    }
    $moneyFormat = accountSupplierStatementExcelCurrencyFormat($currency);
    $sheet->getStyle("G{$firstDataRow}:I{$closingRow}")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle("G{$firstDataRow}:I{$closingRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("G{$firstDataRow}:G{$closingRow}")->getFont()->getColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_AMBER);
    $sheet->getStyle("H{$firstDataRow}:H{$closingRow}")->getFont()->getColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_TEAL_DARK);
    $sheet->getStyle("I{$firstDataRow}:I{$closingRow}")->getFont()->setBold(true)->getColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_NAVY);

    $sheet->getStyle("A{$firstDataRow}:M{$firstDataRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BLUE_SOFT]],
        'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BLUE]]],
    ]);
    accountSupplierStatementExcelStyleStatusCell($sheet, 'J' . $firstDataRow, 'Brought Forward');

    $sheet->getStyle("A{$closingRow}:M{$closingRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_NAVY]],
        'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_TEAL]]],
    ]);
    $sheet->getStyle("G{$closingRow}:I{$closingRow}")->getFont()->getColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_WHITE);
    $sheet->getRowDimension($closingRow)->setRowHeight(34);

    $noteRow = $closingRow + 2;
    $sheet->mergeCells("A{$noteRow}:M{$noteRow}");
    accountSupplierStatementExcelSetText(
        $sheet,
        "A{$noteRow}",
        'Currency-safe statement: balances and running totals on this sheet are calculated only in ' . $currency
        . '. Recovery credits: ' . $currency . ' ' . number_format((float) ($summary['supplier_recovery_adjustments'] ?? 0), 2, '.', ',')
        . ' · Refunds/recoveries received: ' . $currency . ' ' . number_format((float) ($summary['supplier_recoveries_received'] ?? 0), 2, '.', ',')
        . ' · Supplier credit offsets used: ' . $currency . ' ' . number_format((float) ($summary['supplier_credit_offsets_applied'] ?? 0), 2, '.', ',')
        . '. Settlement currencies are shown separately in the allocation detail sheet.'
    );
    $sheet->getStyle("A{$noteRow}:M{$noteRow}")->applyFromArray([
        'font' => ['italic' => true, 'size' => 9.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_MUTED]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F7F9']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BORDER]]],
    ]);
    $sheet->getRowDimension($noteRow)->setRowHeight(34);

    $widths = [
        'A' => 14, 'B' => 24, 'C' => 23, 'D' => 26, 'E' => 42, 'F' => 27,
        'G' => 19, 'H' => 19, 'I' => 19, 'J' => 19, 'K' => 25, 'L' => 44, 'M' => 32,
    ];
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->freezePane('A13');
    $sheet->setAutoFilter("A12:M{$closingRow}");
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 12);
    $sheet->getPageSetup()->setPrintArea("A1:M{$noteRow}");
    $sheet->setSelectedCell('A1');

    return $sheet;
}

function accountSupplierStatementExcelBuildAllocationSheet(
    Spreadsheet $workbook,
    array $statement,
    array $section,
    string $origin
): ?Worksheet {
    $transactions = is_array($section['transactions'] ?? null) ? $section['transactions'] : [];
    $allocationRows = [];
    foreach ($transactions as $transaction) {
        if (($transaction['kind'] ?? '') !== 'payment') {
            continue;
        }
        $allocations = is_array($transaction['allocations'] ?? null) ? $transaction['allocations'] : [];
        foreach ($allocations as $allocation) {
            $allocationRows[] = [$transaction, $allocation];
        }
    }
    if ($allocationRows === []) {
        return null;
    }

    $currency = strtoupper(trim((string) ($section['currency'] ?? 'CCY'))) ?: 'CCY';
    $supplier = is_array($statement['supplier'] ?? null) ? $statement['supplier'] : [];
    $period = is_array($statement['period'] ?? null) ? $statement['period'] : [];
    $periodLabel = accountSupplierStatementExcelDateLabel((string) ($period['from_date'] ?? ''))
        . ' — ' . accountSupplierStatementExcelDateLabel((string) ($period['to_date'] ?? ''));

    $sheet = $workbook->createSheet();
    $sheet->setTitle(accountSupplierStatementExcelCleanSheetName($currency . ' Allocations'));
    $sheet->getTabColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_VIOLET);
    accountSupplierStatementExcelConfigureSheet($sheet, true, 90);
    accountSupplierStatementExcelBrandHeader(
        $sheet,
        'PAYMENT ALLOCATION DETAIL',
        trim((string) ($supplier['name'] ?? '')),
        trim((string) ($supplier['ledger'] ?? '')),
        $periodLabel,
        $currency,
        12
    );

    $sheet->mergeCells('A6:L6');
    accountSupplierStatementExcelSetText(
        $sheet,
        'A6',
        'Grouped settlement allocations are shown at source-request level. Statement credits remain in ' . $currency . '; settlement currency and amount are displayed separately.'
    );
    $sheet->getStyle('A6:L6')->applyFromArray([
        'font' => ['size' => 10, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_MUTED]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_VIOLET_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_XLSX_BORDER]]],
    ]);
    $sheet->getRowDimension(6)->setRowHeight(36);

    accountSupplierStatementExcelSectionTitle($sheet, 8, 'PAYMENT TO OBLIGATION ALLOCATIONS', 12);
    $headers = [
        'Payment Date', 'Payment Reference', 'Payment / Batch Ref', 'PO', 'Invoice', 'Request Reference',
        'Project / Site', 'Statement Currency', 'Statement Credit', 'Settlement Currency', 'Settlement Amount',
        'Description',
    ];
    accountSupplierStatementExcelWriteRow($sheet, 9, $headers);
    accountSupplierStatementExcelTableHeader($sheet, 9, 12);

    $row = 10;
    foreach ($allocationRows as [$transaction, $allocation]) {
        accountSupplierStatementExcelWriteRow($sheet, $row, [
            $transaction['date'] ?? '',
            $transaction['reference'] ?? '',
            $transaction['payment_batch_reference'] ?? ($transaction['payment_reference'] ?? ''),
            $allocation['po_number'] ?? '',
            $allocation['invoice_number'] ?? '',
            $allocation['reference'] ?? '',
            $allocation['project_code'] ?? '',
            $allocation['statement_currency'] ?? $currency,
            (float) ($allocation['statement_credit_amount'] ?? 0),
            $allocation['settlement_currency'] ?? '',
            (float) ($allocation['settlement_amount'] ?? 0),
            $allocation['description'] ?? '',
        ], [9, 11]);
        $row++;
    }
    $lastRow = $row - 1;

    accountSupplierStatementExcelStyleBodyRows($sheet, 10, $lastRow, 12);
    $sheet->getStyle("I10:I{$lastRow}")->getNumberFormat()->setFormatCode(accountSupplierStatementExcelCurrencyFormat($currency));
    $sheet->getStyle("I10:I{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("I10:I{$lastRow}")->getFont()->setBold(true)->getColor()->setRGB(ACCOUNT_SUPPLIER_XLSX_TEAL_DARK);
    $sheet->getStyle("K10:K{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
    $sheet->getStyle("K10:K{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $widths = [
        'A' => 14, 'B' => 23, 'C' => 25, 'D' => 23, 'E' => 23, 'F' => 25, 'G' => 24,
        'H' => 18, 'I' => 19, 'J' => 19, 'K' => 19, 'L' => 42,
    ];
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    $sheet->freezePane('A10');
    $sheet->setAutoFilter("A9:L{$lastRow}");
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 9);
    $sheet->getPageSetup()->setPrintArea("A1:L{$lastRow}");
    $sheet->setSelectedCell('A1');

    return $sheet;
}

function accountSupplierStatementExcelBuildWorkbook(array $statement, string $origin = ''): Spreadsheet
{
    $sections = is_array($statement['currencies'] ?? null) ? $statement['currencies'] : [];
    if ($sections === []) {
        throw new RuntimeException('No supplier statement data is available to export.', 400);
    }

    $origin = accountSupplierStatementExcelNormalizeOrigin($origin);
    $workbook = new Spreadsheet();
    $workbook->getProperties()
        ->setCreator('AcctLab')
        ->setLastModifiedBy('AcctLab')
        ->setTitle('Supplier Statement - ' . (string) ($statement['supplier']['name'] ?? 'Supplier'))
        ->setSubject('Supplier sub-ledger statement')
        ->setDescription('Currency-separated supplier obligations and settlements generated by AcctLab.')
        ->setCompany('Lambert Electromec');

    foreach ($sections as $index => $section) {
        accountSupplierStatementExcelBuildCurrencySheet($workbook, $statement, $section, $origin, $index === 0);
        accountSupplierStatementExcelBuildAllocationSheet($workbook, $statement, $section, $origin);
    }

    $workbook->setActiveSheetIndex(0);
    return $workbook;
}

function accountSupplierStatementExcelFileName(array $statement): string
{
    $supplier = accountSupplierStatementExcelCleanFilePart($statement['supplier']['name'] ?? '', 'supplier');
    $fromDate = accountSupplierStatementExcelCleanFilePart($statement['period']['from_date'] ?? '', 'from');
    $toDate = accountSupplierStatementExcelCleanFilePart($statement['period']['to_date'] ?? '', 'to');
    return "Supplier-Statement-{$supplier}-{$fromDate}-to-{$toDate}.xlsx";
}
