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

const ACCOUNT_SUPPLIER_WHT_XLSX_INK = '172A36';
const ACCOUNT_SUPPLIER_WHT_XLSX_NAVY = '102A3D';
const ACCOUNT_SUPPLIER_WHT_XLSX_NAVY_SOFT = '17384D';
const ACCOUNT_SUPPLIER_WHT_XLSX_TEAL = '20B7AE';
const ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_DARK = '0B7F79';
const ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_SOFT = 'E8F8F6';
const ACCOUNT_SUPPLIER_WHT_XLSX_BLUE = '4A86C5';
const ACCOUNT_SUPPLIER_WHT_XLSX_BLUE_SOFT = 'EAF3FA';
const ACCOUNT_SUPPLIER_WHT_XLSX_AMBER = 'D89A2B';
const ACCOUNT_SUPPLIER_WHT_XLSX_AMBER_SOFT = 'FFF4DD';
const ACCOUNT_SUPPLIER_WHT_XLSX_GREEN = '23865F';
const ACCOUNT_SUPPLIER_WHT_XLSX_GREEN_SOFT = 'E8F5EF';
const ACCOUNT_SUPPLIER_WHT_XLSX_MUTED = '6E8391';
const ACCOUNT_SUPPLIER_WHT_XLSX_BORDER = 'D7E4EA';
const ACCOUNT_SUPPLIER_WHT_XLSX_ROW_ALT = 'F6FAFC';
const ACCOUNT_SUPPLIER_WHT_XLSX_WHITE = 'FFFFFF';

function accountSupplierWhtExcelColumn(int $index): string
{
    return Coordinate::stringFromColumnIndex($index);
}

function accountSupplierWhtExcelText(Worksheet $sheet, string $cell, mixed $value): void
{
    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
}

function accountSupplierWhtExcelDateLabel(mixed $value): string
{
    $date = trim((string) ($value ?? ''));
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time === false ? $date : date('d M Y', $time);
}

function accountSupplierWhtExcelPeriodLabel(array $filters): string
{
    $from = accountSupplierWhtExcelDateLabel($filters['from_date'] ?? null);
    $to = accountSupplierWhtExcelDateLabel($filters['to_date'] ?? null);
    if ($from !== '' && $to !== '') {
        return $from . ' — ' . $to;
    }
    if ($from !== '') {
        return 'From ' . $from;
    }
    if ($to !== '') {
        return 'Up to ' . $to;
    }
    return 'All available periods';
}

function accountSupplierWhtExcelSupplierLabel(array $report): string
{
    $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
    if (empty($filters['supplier_id']) && empty($filters['supplier'])) {
        return 'All suppliers';
    }

    $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
    $first = $rows[0] ?? [];
    $name = trim((string) ($first['supplier'] ?? $filters['supplier'] ?? ''));
    return $name !== '' ? $name : 'Selected supplier';
}

function accountSupplierWhtExcelStatusLabel(array $filters): string
{
    $status = trim((string) ($filters['payment_status'] ?? 'Paid')) ?: 'Paid';
    return strcasecmp($status, 'all') === 0 ? 'All statuses' : $status;
}

function accountSupplierWhtExcelCleanFilePart(mixed $value, string $fallback): string
{
    $clean = preg_replace('/[\\\\\/:*?"<>|]+/', '-', trim((string) $value)) ?? '';
    $clean = preg_replace('/\s+/', '-', $clean) ?? '';
    $clean = preg_replace('/-+/', '-', $clean) ?? '';
    $clean = trim($clean, '-');
    return $clean !== '' ? $clean : $fallback;
}

function accountSupplierWhtExcelMoneyFormat(): string
{
    return '"₦" #,##0.00;[Red]-"₦" #,##0.00;–';
}

function accountSupplierWhtExcelConfigureSheet(Worksheet $sheet): void
{
    $sheet->setShowGridlines(false);
    $sheet->getSheetView()->setZoomScale(90);
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.3)->setRight(0.3);
    $sheet->getHeaderFooter()->setOddFooter('&LAcctLab · Supplier WHT Report&CPage &P of &N&RGenerated ' . date('d M Y H:i'));
}

function accountSupplierWhtExcelBrandHeader(
    Worksheet $sheet,
    int $lastColumn,
    string $supplierLabel,
    string $statusLabel,
    string $periodLabel
): void {
    $endColumn = accountSupplierWhtExcelColumn($lastColumn);
    foreach ([1, 2, 3, 4] as $row) {
        $sheet->mergeCells("A{$row}:{$endColumn}{$row}");
    }

    accountSupplierWhtExcelText($sheet, 'A1', 'ACCTLAB  •  SUPPLIER TAX REPORTING');
    accountSupplierWhtExcelText($sheet, 'A2', "SUPPLIER'S WITHHOLDING TAX REPORT");
    accountSupplierWhtExcelText($sheet, 'A3', 'Supplier scope: ' . $supplierLabel . '   •   Payment status: ' . $statusLabel);
    accountSupplierWhtExcelText($sheet, 'A4', 'Reporting period: ' . $periodLabel . '   •   Generated: ' . date('d M Y H:i'));

    $sheet->getStyle("A1:{$endColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A2:{$endColumn}2")->applyFromArray([
        'font' => ['bold' => true, 'size' => 22, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A3:{$endColumn}4")->applyFromArray([
        'font' => ['size' => 10, 'color' => ['rgb' => 'D9E7EF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->getRowDimension(2)->setRowHeight(38);
    $sheet->getRowDimension(3)->setRowHeight(25);
    $sheet->getRowDimension(4)->setRowHeight(25);
}

function accountSupplierWhtExcelMetricRanges(int $columnCount): array
{
    $ranges = [];
    $base = intdiv($columnCount, 4);
    $remainder = $columnCount % 4;
    $start = 1;
    for ($index = 0; $index < 4; $index++) {
        $width = $base + ($index < $remainder ? 1 : 0);
        $width = max(1, $width);
        $end = min($columnCount, $start + $width - 1);
        $ranges[] = [
            accountSupplierWhtExcelColumn($start) . '6',
            accountSupplierWhtExcelColumn($end) . '7',
        ];
        $start = $end + 1;
    }
    return $ranges;
}

function accountSupplierWhtExcelMetricCard(
    Worksheet $sheet,
    array $range,
    string $label,
    mixed $value,
    string $fill,
    string $accent,
    bool $money = false
): void {
    [$topLeft, $bottomRight] = $range;
    $bounds = Coordinate::rangeBoundaries($topLeft . ':' . $bottomRight);
    $startColumn = accountSupplierWhtExcelColumn((int) $bounds[0][0]);
    $endColumn = accountSupplierWhtExcelColumn((int) $bounds[1][0]);
    $topRow = (int) $bounds[0][1];
    $bottomRow = (int) $bounds[1][1];

    $sheet->mergeCells("{$startColumn}{$topRow}:{$endColumn}{$topRow}");
    $sheet->mergeCells("{$startColumn}" . ($topRow + 1) . ":{$endColumn}{$bottomRow}");
    accountSupplierWhtExcelText($sheet, "{$startColumn}{$topRow}", strtoupper($label));
    if ($money || is_numeric($value)) {
        $sheet->setCellValue("{$startColumn}" . ($topRow + 1), $money ? (float) $value : (int) $value);
    } else {
        accountSupplierWhtExcelText($sheet, "{$startColumn}" . ($topRow + 1), $value);
    }

    $sheet->getStyle("{$startColumn}{$topRow}:{$endColumn}{$bottomRow}")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_BORDER]],
            'left' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => $accent]],
        ],
    ]);
    $sheet->getStyle("{$startColumn}{$topRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_MUTED]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $valueCell = "{$startColumn}" . ($topRow + 1);
    $sheet->getStyle($valueCell)->applyFromArray([
        'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_INK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    if ($money) {
        $sheet->getStyle($valueCell)->getNumberFormat()->setFormatCode(accountSupplierWhtExcelMoneyFormat());
    }
}

function accountSupplierWhtExcelTableHeader(Worksheet $sheet, int $row, int $lastColumn): void
{
    $endColumn = accountSupplierWhtExcelColumn($lastColumn);
    $sheet->getStyle("A{$row}:{$endColumn}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(30);
}

function accountSupplierWhtExcelStyleRows(Worksheet $sheet, int $firstRow, int $lastRow, int $lastColumn): void
{
    if ($lastRow < $firstRow) {
        return;
    }
    $endColumn = accountSupplierWhtExcelColumn($lastColumn);
    $sheet->getStyle("A{$firstRow}:{$endColumn}{$lastRow}")->applyFromArray([
        'font' => ['size' => 10, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_INK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_BORDER]]],
    ]);

    for ($row = $firstRow; $row <= $lastRow; $row++) {
        $sheet->getRowDimension($row)->setRowHeight(24);
        if (($row - $firstRow) % 2 === 1) {
            $sheet->getStyle("A{$row}:{$endColumn}{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(ACCOUNT_SUPPLIER_WHT_XLSX_ROW_ALT);
        }
    }
}

function accountSupplierWhtExcelDateTimeLabel(mixed $value): string
{
    $date = trim((string) ($value ?? ''));
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time === false ? $date : date('d M Y H:i', $time);
}

function accountSupplierWhtExcelDetailColumns(): array
{
    return [
        ['key' => 'request_id', 'label' => 'Request ID', 'type' => 'integer', 'width' => 12],
        ['key' => 'supplier', 'label' => 'Supplier Name', 'type' => 'text', 'width' => 34, 'wrap' => true],
        ['key' => 'supplier_id', 'label' => 'Supplier ID', 'type' => 'integer', 'width' => 12],
        ['key' => 'invoice_number', 'label' => 'Invoice Number', 'type' => 'text', 'width' => 18],
        ['key' => 'invoice_date', 'label' => 'Invoice Date', 'type' => 'date', 'width' => 15],
        ['key' => 'purchase_number', 'label' => 'Purchase Number', 'type' => 'text', 'width' => 18],
        ['key' => 'purchase_date', 'label' => 'Purchase Date', 'type' => 'date', 'width' => 15],
        ['key' => 'po_number', 'label' => 'PO Number', 'type' => 'text', 'width' => 17],
        ['key' => 'date_received', 'label' => 'Date Received', 'type' => 'date', 'width' => 15],
        ['key' => 'report_date', 'label' => 'Report Date', 'type' => 'date', 'width' => 15],
        ['key' => 'invoice_month', 'label' => 'Invoice Month', 'type' => 'text', 'width' => 14],
        ['key' => 'purchase_month', 'label' => 'Purchase Month', 'type' => 'text', 'width' => 15],
        ['key' => 'project_code', 'label' => 'Project Code', 'type' => 'text', 'width' => 14],
        ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 38, 'wrap' => true],
        ['key' => 'vat_policy', 'label' => 'VAT Status / Rate', 'type' => 'text', 'width' => 16],
        ['key' => 'net_value', 'label' => 'Net Value', 'type' => 'money', 'width' => 17],
        ['key' => 'discount', 'label' => 'Discount', 'type' => 'money', 'width' => 15],
        ['key' => 'vat_amount', 'label' => 'VAT', 'type' => 'money', 'width' => 15],
        ['key' => 'wht_rate', 'label' => 'WHT Rate', 'type' => 'rate', 'width' => 12],
        ['key' => 'wht_amount', 'label' => 'WHT Amount', 'type' => 'money', 'width' => 17],
        ['key' => 'other_charges', 'label' => 'Other Charges', 'type' => 'money', 'width' => 16],
        ['key' => 'gross_amount', 'label' => 'Gross Amount', 'type' => 'money', 'width' => 18],
        ['key' => 'payable_amount', 'label' => 'Payable Total', 'type' => 'money', 'width' => 18],
        ['key' => 'payment_percentage', 'label' => 'Payment %', 'type' => 'text', 'width' => 13],
        ['key' => 'payment_status', 'label' => 'Payment Status', 'type' => 'text', 'width' => 15],
        ['key' => 'payment_confirmation_status', 'label' => 'Confirmation Status', 'type' => 'text', 'width' => 19],
        ['key' => 'processing_method', 'label' => 'Processing Method', 'type' => 'text', 'width' => 18],
        ['key' => 'processing_reference', 'label' => 'Processing Reference', 'type' => 'text', 'width' => 22],
        ['key' => 'processing_started_at', 'label' => 'Processing Started', 'type' => 'datetime', 'width' => 19],
        ['key' => 'expected_completion_at', 'label' => 'Expected Completion', 'type' => 'datetime', 'width' => 19],
        ['key' => 'completion_mode', 'label' => 'Completion Mode', 'type' => 'text', 'width' => 17],
        ['key' => 'amount_paid', 'label' => 'Amount Eventually Paid', 'type' => 'money', 'width' => 21],
        ['key' => 'supplier_credit_applied', 'label' => 'Supplier Credit Applied', 'type' => 'money', 'width' => 21],
        ['key' => 'cash_amount_paid', 'label' => 'Cash Amount Paid', 'type' => 'money', 'width' => 18],
        ['key' => 'payment_reference', 'label' => 'Payment Reference', 'type' => 'text', 'width' => 21],
        ['key' => 'paid_at', 'label' => 'Paid At', 'type' => 'datetime', 'width' => 19],
        ['key' => 'account_remarks', 'label' => 'Account Remarks', 'type' => 'text', 'width' => 34, 'wrap' => true],
        ['key' => 'note', 'label' => 'Note', 'type' => 'text', 'width' => 28, 'wrap' => true],
        ['key' => 'created_at', 'label' => 'Created At', 'type' => 'datetime', 'width' => 19],
        ['key' => 'updated_at', 'label' => 'Updated At', 'type' => 'text', 'width' => 19],
        ['key' => 'calculation_method', 'label' => 'WHT Calculation Basis', 'type' => 'text', 'width' => 30, 'wrap' => true],
    ];
}

function accountSupplierWhtExcelDetailColumnIndex(array $columns, string $key): int
{
    foreach ($columns as $index => $column) {
        if (($column['key'] ?? '') === $key) {
            return $index + 2; // S/N occupies column A.
        }
    }
    return 0;
}

function accountSupplierWhtExcelWriteDetailValue(Worksheet $sheet, string $cell, array $column, mixed $value): void
{
    $type = (string) ($column['type'] ?? 'text');
    if ($type === 'money' || $type === 'rate') {
        $sheet->setCellValue($cell, (float) ($value ?? 0));
        return;
    }
    if ($type === 'integer') {
        $sheet->setCellValue($cell, (int) ($value ?? 0));
        return;
    }
    if ($type === 'date') {
        accountSupplierWhtExcelText($sheet, $cell, accountSupplierWhtExcelDateLabel($value));
        return;
    }
    if ($type === 'datetime') {
        accountSupplierWhtExcelText($sheet, $cell, accountSupplierWhtExcelDateTimeLabel($value));
        return;
    }
    accountSupplierWhtExcelText($sheet, $cell, $value);
}

function accountSupplierWhtExcelBuildDetailSheet(Spreadsheet $workbook, array $report): void
{
    $detailRows = is_array($report['detail_rows'] ?? null) ? $report['detail_rows'] : [];
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
    $years = is_array($report['years'] ?? null) ? array_values($report['years']) : [];
    $columns = accountSupplierWhtExcelDetailColumns();
    $columnCount = count($columns) + 1;
    $lastColumn = accountSupplierWhtExcelColumn($columnCount);
    $whtColumnIndex = accountSupplierWhtExcelDetailColumnIndex($columns, 'wht_amount');
    $whtColumn = accountSupplierWhtExcelColumn($whtColumnIndex);

    $sheet = new Worksheet($workbook, 'WHT Detail');
    $workbook->addSheet($sheet);
    $sheet->getTabColor()->setRGB(ACCOUNT_SUPPLIER_WHT_XLSX_BLUE);
    accountSupplierWhtExcelConfigureSheet($sheet);
    $sheet->getSheetView()->setZoomScale(72);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0);
    accountSupplierWhtExcelBrandHeader(
        $sheet,
        $columnCount,
        accountSupplierWhtExcelSupplierLabel($report),
        accountSupplierWhtExcelStatusLabel($filters),
        accountSupplierWhtExcelPeriodLabel($filters)
    );

    $yearCoverage = $years === []
        ? '—'
        : (count($years) === 1 ? (string) $years[0] : ((string) $years[0] . ' – ' . (string) $years[count($years) - 1]));
    $cards = accountSupplierWhtExcelMetricRanges($columnCount);
    accountSupplierWhtExcelMetricCard($sheet, $cards[0], 'Suppliers', (int) ($summary['supplier_count'] ?? 0), ACCOUNT_SUPPLIER_WHT_XLSX_BLUE_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_BLUE);
    accountSupplierWhtExcelMetricCard($sheet, $cards[1], 'Report years', $yearCoverage, ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_TEAL);
    accountSupplierWhtExcelMetricCard($sheet, $cards[2], 'WHT source lines', count($detailRows), ACCOUNT_SUPPLIER_WHT_XLSX_AMBER_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_AMBER);
    accountSupplierWhtExcelMetricCard($sheet, $cards[3], 'Total WHT', (float) ($summary['total_wht'] ?? 0), ACCOUNT_SUPPLIER_WHT_XLSX_GREEN_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_GREEN, true);
    $sheet->getRowDimension(6)->setRowHeight(21);
    $sheet->getRowDimension(7)->setRowHeight(30);

    $sheet->mergeCells("A9:{$lastColumn}9");
    accountSupplierWhtExcelText($sheet, 'A9', 'WHT SOURCE LINES  •  GROUPED BY SUPPLIER');
    $sheet->getStyle("A9:{$lastColumn}9")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL]]],
    ]);
    $sheet->getRowDimension(9)->setRowHeight(28);

    $sheet->mergeCells("A10:{$lastColumn}10");
    accountSupplierWhtExcelText($sheet, 'A10', 'Only eligible WHT lines contributing to the summary schedule are listed below.');
    $sheet->getStyle("A10:{$lastColumn}10")->applyFromArray([
        'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_MUTED]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(10)->setRowHeight(22);

    $groups = [];
    foreach ($detailRows as $detailRow) {
        $key = (string) ($detailRow['supplier_group_key'] ?? accountSupplierWhtSupplierGroupKey(
            (string) ($detailRow['supplier'] ?? ''),
            (int) ($detailRow['canonical_supplier_id'] ?? 0)
        ));
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'supplier' => (string) ($detailRow['supplier'] ?? ''),
                'supplier_id' => (int) ($detailRow['canonical_supplier_id'] ?? $detailRow['supplier_id'] ?? 0),
                'rows' => [],
                'wht_total' => 0.0,
            ];
        }
        $groups[$key]['rows'][] = $detailRow;
        $groups[$key]['wht_total'] += (float) ($detailRow['wht_amount'] ?? 0);
    }

    $rowNumber = 12;
    $globalSn = 1;
    $groupNumber = 1;
    foreach ($groups as $group) {
        $supplierName = trim((string) ($group['supplier'] ?? '')) ?: 'Unnamed supplier';
        $supplierId = (int) ($group['supplier_id'] ?? 0);
        $lineCount = count($group['rows']);
        $groupTotal = (float) ($group['wht_total'] ?? 0);

        $sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
        $supplierLabel = sprintf(
            'SUPPLIER %02d  •  %s%s  •  %d WHT LINE%s  •  SUBTOTAL ₦%s',
            $groupNumber,
            $supplierName,
            $supplierId > 0 ? '  [ID ' . $supplierId . ']' : '',
            $lineCount,
            $lineCount === 1 ? '' : 'S',
            number_format($groupTotal, 2)
        );
        accountSupplierWhtExcelText($sheet, 'A' . $rowNumber, $supplierLabel);
        $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY_SOFT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['left' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL]]],
        ]);
        $sheet->getRowDimension($rowNumber)->setRowHeight(27);
        $rowNumber++;

        accountSupplierWhtExcelText($sheet, 'A' . $rowNumber, 'S/N');
        foreach ($columns as $index => $column) {
            accountSupplierWhtExcelText($sheet, accountSupplierWhtExcelColumn($index + 2) . $rowNumber, $column['label']);
        }
        accountSupplierWhtExcelTableHeader($sheet, $rowNumber, $columnCount);
        $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerRow = $rowNumber;
        $rowNumber++;

        $firstDataRow = $rowNumber;
        foreach ($group['rows'] as $detailRow) {
            $sheet->setCellValue('A' . $rowNumber, $globalSn++);
            foreach ($columns as $index => $column) {
                $cell = accountSupplierWhtExcelColumn($index + 2) . $rowNumber;
                accountSupplierWhtExcelWriteDetailValue($sheet, $cell, $column, $detailRow[$column['key']] ?? null);
            }
            $rowNumber++;
        }
        $lastDataRow = $rowNumber - 1;
        accountSupplierWhtExcelStyleRows($sheet, $firstDataRow, $lastDataRow, $columnCount);
        $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ($columns as $index => $column) {
            $columnLetter = accountSupplierWhtExcelColumn($index + 2);
            $range = "{$columnLetter}{$firstDataRow}:{$columnLetter}{$lastDataRow}";
            $type = (string) ($column['type'] ?? 'text');
            if ($type === 'money') {
                $sheet->getStyle($range)->applyFromArray([
                    'numberFormat' => ['formatCode' => accountSupplierWhtExcelMoneyFormat()],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                ]);
            } elseif ($type === 'rate') {
                $sheet->getStyle($range)->applyFromArray([
                    'numberFormat' => ['formatCode' => '0.00"%"'],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                ]);
            } elseif ($type === 'integer') {
                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            if (!empty($column['wrap'])) {
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
            }
        }

        $subtotalRow = $rowNumber;
        $subtotalEndColumnIndex = max(1, $whtColumnIndex - 1);
        $subtotalEndColumn = accountSupplierWhtExcelColumn($subtotalEndColumnIndex);
        $sheet->mergeCells("A{$subtotalRow}:{$subtotalEndColumn}{$subtotalRow}");
        accountSupplierWhtExcelText($sheet, 'A' . $subtotalRow, $supplierName . ' — WHT SUBTOTAL');
        $sheet->setCellValue($whtColumn . $subtotalRow, $groupTotal);
        $sheet->getStyle("A{$subtotalRow}:{$lastColumn}{$subtotalRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_BLUE_SOFT]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_BLUE]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($whtColumn . $subtotalRow)->applyFromArray([
            'numberFormat' => ['formatCode' => accountSupplierWhtExcelMoneyFormat()],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
        $sheet->getRowDimension($subtotalRow)->setRowHeight(24);
        $rowNumber += 2;
        $groupNumber++;
    }

    if ($groups === []) {
        $sheet->mergeCells("A12:{$lastColumn}14");
        accountSupplierWhtExcelText($sheet, 'A12', 'No WHT source lines match the selected filters.');
        $sheet->getStyle("A12:{$lastColumn}14")->applyFromArray([
            'font' => ['italic' => true, 'size' => 11, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_MUTED]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_ROW_ALT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_BORDER]]],
        ]);
        $rowNumber = 16;
    }

    $grandTotalRow = $rowNumber;
    $grandLabelEndIndex = max(1, $whtColumnIndex - 1);
    $grandLabelEnd = accountSupplierWhtExcelColumn($grandLabelEndIndex);
    $sheet->mergeCells("A{$grandTotalRow}:{$grandLabelEnd}{$grandTotalRow}");
    accountSupplierWhtExcelText($sheet, 'A' . $grandTotalRow, 'GRAND TOTAL WHT');
    $sheet->setCellValue($whtColumn . $grandTotalRow, (float) ($summary['total_wht'] ?? 0));
    $sheet->getStyle("A{$grandTotalRow}:{$lastColumn}{$grandTotalRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY]],
        'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL]]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle($whtColumn . $grandTotalRow)->applyFromArray([
        'numberFormat' => ['formatCode' => accountSupplierWhtExcelMoneyFormat()],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    ]);
    $sheet->getRowDimension($grandTotalRow)->setRowHeight(29);

    $sheet->getColumnDimension('A')->setWidth(8);
    foreach ($columns as $index => $column) {
        $sheet->getColumnDimension(accountSupplierWhtExcelColumn($index + 2))->setWidth((float) ($column['width'] ?? 16));
    }

    $sheet->freezePane('E12');
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 10);
    $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$grandTotalRow}");
    $sheet->getStyle("A1:{$lastColumn}{$grandTotalRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}

function accountSupplierWhtExcelBuildWorkbook(array $report): Spreadsheet
{
    $years = is_array($report['years'] ?? null) ? array_values($report['years']) : [];
    $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
    $columnCount = max(4, count($years) + 3);
    $lastColumn = accountSupplierWhtExcelColumn($columnCount);

    $workbook = new Spreadsheet();
    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('Supplier WHT');
    $sheet->getTabColor()->setRGB(ACCOUNT_SUPPLIER_WHT_XLSX_TEAL);
    accountSupplierWhtExcelConfigureSheet($sheet);
    accountSupplierWhtExcelBrandHeader(
        $sheet,
        $columnCount,
        accountSupplierWhtExcelSupplierLabel($report),
        accountSupplierWhtExcelStatusLabel($filters),
        accountSupplierWhtExcelPeriodLabel($filters)
    );

    $yearCoverage = $years === []
        ? '—'
        : (count($years) === 1 ? (string) $years[0] : ((string) $years[0] . ' – ' . (string) $years[count($years) - 1]));
    $cards = accountSupplierWhtExcelMetricRanges($columnCount);
    accountSupplierWhtExcelMetricCard($sheet, $cards[0], 'Suppliers', (int) ($summary['supplier_count'] ?? count($rows)), ACCOUNT_SUPPLIER_WHT_XLSX_BLUE_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_BLUE);
    accountSupplierWhtExcelMetricCard($sheet, $cards[1], 'Report years', $yearCoverage, ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_TEAL);
    accountSupplierWhtExcelMetricCard($sheet, $cards[2], 'WHT lines', (int) ($summary['wht_line_count'] ?? 0), ACCOUNT_SUPPLIER_WHT_XLSX_AMBER_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_AMBER);
    accountSupplierWhtExcelMetricCard($sheet, $cards[3], 'Total WHT', (float) ($summary['total_wht'] ?? 0), ACCOUNT_SUPPLIER_WHT_XLSX_GREEN_SOFT, ACCOUNT_SUPPLIER_WHT_XLSX_GREEN, true);
    $sheet->getRowDimension(6)->setRowHeight(21);
    $sheet->getRowDimension(7)->setRowHeight(30);

    $sheet->mergeCells("A9:{$lastColumn}9");
    accountSupplierWhtExcelText($sheet, 'A9', 'SUPPLIER WHT SCHEDULE');
    $sheet->getStyle("A9:{$lastColumn}9")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL]]],
    ]);
    $sheet->getRowDimension(9)->setRowHeight(28);

    $headers = ['S/N', 'Supplier', ...array_map(static fn($year): string => (string) $year, $years), 'Total WHT'];
    foreach ($headers as $index => $label) {
        accountSupplierWhtExcelText($sheet, accountSupplierWhtExcelColumn($index + 1) . '10', $label);
    }
    accountSupplierWhtExcelTableHeader($sheet, 10, count($headers));
    $sheet->getStyle('A10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    if (count($headers) > 2) {
        $sheet->getStyle('C10:' . accountSupplierWhtExcelColumn(count($headers)) . '10')
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    $firstDataRow = 11;
    $rowNumber = $firstDataRow;
    foreach ($rows as $index => $reportRow) {
        $sheet->setCellValue('A' . $rowNumber, $index + 1);
        accountSupplierWhtExcelText($sheet, 'B' . $rowNumber, $reportRow['supplier'] ?? '');
        $column = 3;
        foreach ($years as $year) {
            $sheet->setCellValue(accountSupplierWhtExcelColumn($column) . $rowNumber, (float) ($reportRow['years'][(string) $year] ?? 0));
            $column++;
        }
        $sheet->setCellValue(accountSupplierWhtExcelColumn($column) . $rowNumber, (float) ($reportRow['total_wht'] ?? 0));
        $rowNumber++;
    }
    $lastDataRow = $rowNumber - 1;
    accountSupplierWhtExcelStyleRows($sheet, $firstDataRow, $lastDataRow, count($headers));

    if ($lastDataRow >= $firstDataRow) {
        $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $moneyStart = 'C' . $firstDataRow;
        $moneyEnd = accountSupplierWhtExcelColumn(count($headers)) . $lastDataRow;
        $sheet->getStyle("{$moneyStart}:{$moneyEnd}")->applyFromArray([
            'numberFormat' => ['formatCode' => accountSupplierWhtExcelMoneyFormat()],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
    }

    $totalRow = max($firstDataRow, $rowNumber);
    $sheet->mergeCells("A{$totalRow}:B{$totalRow}");
    accountSupplierWhtExcelText($sheet, 'A' . $totalRow, 'TOTAL');
    $column = 3;
    foreach ($years as $year) {
        $sheet->setCellValue(accountSupplierWhtExcelColumn($column) . $totalRow, (float) ($summary['by_year'][(string) $year] ?? 0));
        $column++;
    }
    $sheet->setCellValue(accountSupplierWhtExcelColumn($column) . $totalRow, (float) ($summary['total_wht'] ?? 0));
    $sheet->getStyle("A{$totalRow}:" . accountSupplierWhtExcelColumn(count($headers)) . $totalRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 10.5, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_NAVY]],
        'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SUPPLIER_WHT_XLSX_TEAL]]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("C{$totalRow}:" . accountSupplierWhtExcelColumn(count($headers)) . $totalRow)->applyFromArray([
        'numberFormat' => ['formatCode' => accountSupplierWhtExcelMoneyFormat()],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    ]);
    $sheet->getRowDimension($totalRow)->setRowHeight(28);

    $sheet->getColumnDimension('A')->setWidth(9);
    $sheet->getColumnDimension('B')->setWidth(42);
    for ($column = 3; $column <= count($headers); $column++) {
        $sheet->getColumnDimension(accountSupplierWhtExcelColumn($column))->setWidth($column === count($headers) ? 21 : 18);
    }

    $sheet->freezePane('C11');
    if ($lastDataRow >= $firstDataRow) {
        $sheet->setAutoFilter("A10:" . accountSupplierWhtExcelColumn(count($headers)) . "{$lastDataRow}");
    }
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 10);
    $sheet->getPageSetup()->setPrintArea("A1:" . accountSupplierWhtExcelColumn(count($headers)) . $totalRow);
    $sheet->getStyle("A1:" . accountSupplierWhtExcelColumn(count($headers)) . $totalRow)
        ->getAlignment()->setWrapText(false);
    if ($lastDataRow >= $firstDataRow) {
        $sheet->getStyle("B{$firstDataRow}:B{$lastDataRow}")->getAlignment()->setWrapText(true);
    }

    accountSupplierWhtExcelBuildDetailSheet($workbook, $report);

    $workbook->getProperties()
        ->setCreator('AcctLab')
        ->setTitle("Supplier's WHT Report")
        ->setSubject('Supplier withholding tax schedule by supplier and year')
        ->setDescription('Professional Supplier WHT summary and grouped source-line schedule generated by AcctLab.');

    return $workbook;
}

function accountSupplierWhtExcelFileName(array $report): string
{
    $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
    $supplier = accountSupplierWhtExcelCleanFilePart(accountSupplierWhtExcelSupplierLabel($report), 'All-Suppliers');
    $status = accountSupplierWhtExcelCleanFilePart(accountSupplierWhtExcelStatusLabel($filters), 'Paid');
    $from = accountSupplierWhtExcelCleanFilePart($filters['from_date'] ?? null, 'all');
    $to = accountSupplierWhtExcelCleanFilePart($filters['to_date'] ?? null, 'periods');
    return "Supplier-WHT-{$supplier}-{$status}-{$from}-to-{$to}.xlsx";
}
