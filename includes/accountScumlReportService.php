<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const ACCOUNT_SCUML_NAVY = '0A1D29';
const ACCOUNT_SCUML_NAVY_SOFT = '102D3A';
const ACCOUNT_SCUML_TEAL = '18A79D';
const ACCOUNT_SCUML_TEAL_DARK = '0B6D68';
const ACCOUNT_SCUML_TEAL_SOFT = 'E8F7F5';
const ACCOUNT_SCUML_TOTAL_SOFT = 'D4F0EA';
const ACCOUNT_SCUML_ROW_ALT = 'F4F9FA';
const ACCOUNT_SCUML_BORDER = 'D8E5E9';
const ACCOUNT_SCUML_TEXT = '20343D';
const ACCOUNT_SCUML_MUTED = '6B7F88';
const ACCOUNT_SCUML_WHITE = 'FFFFFF';

function accountScumlResolvePeriod(array $query): array
{
    if (isset($query['period_from'], $query['period_to']) && $query['period_from'] !== '' && $query['period_to'] !== '') {
        $periodFrom = (string) $query['period_from'];
        $periodTo = (string) $query['period_to'];
    } else {
        $toDate = new DateTime();
        $fromDate = (clone $toDate)->modify('-6 days');
        $periodFrom = $fromDate->format('Y-m-d');
        $periodTo = $toDate->format('Y-m-d');
    }

    $fromDate = new DateTime($periodFrom);
    $toDate = new DateTime($periodTo);
    if ($fromDate > $toDate) {
        throw new Exception("'period_from' cannot be later than 'period_to'", 400);
    }
    if ($fromDate->diff($toDate)->days > 31) {
        throw new Exception('Date range cannot exceed one month (31 days)', 400);
    }

    return [$periodFrom, $periodTo];
}

function accountScumlFetchRows(mysqli $conn, string $sql, string $periodFrom, string $periodTo): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SCUML report query.');
    }
    $stmt->bind_param('ss', $periodFrom, $periodTo);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function accountScumlFetchData(mysqli $conn, string $periodFrom, string $periodTo): array
{
    return [
        'instruction_letter' => accountScumlFetchRows($conn, "
            SELECT
                payment_bank_name AS beneficiary_bank_name,
                payment_to AS beneficiary_account_number,
                payment_account_number AS paid_from,
                bank_code,
                payment_date,
                payment_amount
            FROM instruction_letter
            WHERE payment_date BETWEEN ? AND ?
            ORDER BY payment_date DESC
        ", $periodFrom, $periodTo),
        'advance_payment_schedule' => accountScumlFetchRows($conn, "
            SELECT
                account_name AS beneficiary_account_name,
                account_number AS beneficiary_bank_number,
                bank_name AS beneficiary_bank_name,
                payment_date,
                payment_amount,
                batch
            FROM advance_payment_schedule_tab
            WHERE payment_date BETWEEN ? AND ?
            ORDER BY payment_date DESC
        ", $periodFrom, $periodTo),
        'fx_instruction_letter' => accountScumlFetchRows($conn, "
            SELECT
                beneficiary_name AS beneficiary_account_name,
                beneficiary_account_number,
                payment_account_number AS paid_from,
                payment_bank AS bank_code,
                currency,
                payment_date,
                amount_figure AS payment_amount
            FROM fx_instruction_letter_table
            WHERE payment_date BETWEEN ? AND ?
            ORDER BY payment_date DESC
        ", $periodFrom, $periodTo),
        'local_transfer' => accountScumlFetchRows($conn, "
            SELECT
                beneficiary_name AS beneficiary_account_name,
                account_number AS beneficiary_account_number,
                ben_bank_name AS beneficiary_bank_name,
                payment_account_number AS paid_from,
                amount AS payment_amount,
                date AS payment_date,
                batch
            FROM local_transfer
            WHERE date BETWEEN ? AND ?
            ORDER BY date DESC
        ", $periodFrom, $periodTo),
        'other_payment_schedule' => accountScumlFetchRows($conn, "
            SELECT
                bank_name AS beneficiary_bank_name,
                account_number AS beneficiary_account_number,
                account_name AS beneficiary_account_name,
                payment_date,
                payment_amount,
                batch
            FROM other_payment_schedule
            WHERE payment_date BETWEEN ? AND ?
            ORDER BY payment_date DESC
        ", $periodFrom, $periodTo),
        'payment_schedule' => accountScumlFetchRows($conn, "
            SELECT
                bank_name AS beneficiary_bank_name,
                account_number AS beneficiary_account_number,
                account_name AS beneficiary_account_name,
                payment_date,
                payment_amount,
                batch
            FROM payment_schedule_tab
            WHERE payment_date BETWEEN ? AND ?
            ORDER BY payment_date DESC
        ", $periodFrom, $periodTo),
        'union_payment_schedule' => accountScumlFetchRows($conn, "
            SELECT
                bank_name AS beneficiary_bank_name,
                account_number AS beneficiary_account_number,
                account_name AS beneficiary_account_name,
                payment_date,
                payment_amount,
                batch
            FROM union_payment_schedule
            WHERE payment_date BETWEEN ? AND ?
            ORDER BY payment_date DESC
        ", $periodFrom, $periodTo),
    ];
}

function accountScumlReportConfigs(): array
{
    return [
        'instruction_letter' => [
            'sheet' => 'Instruction Letter',
            'headers' => ['Beneficiary Bank Name', 'Beneficiary Account Number', 'Paid From', 'Bank Code', 'Payment Date', 'Payment Amount'],
            'fields' => ['beneficiary_bank_name', 'beneficiary_account_number', 'paid_from', 'bank_code', 'payment_date', 'payment_amount'],
            'widths' => [30, 26, 24, 16, 16, 20],
            'amount_col' => 6,
        ],
        'advance_payment_schedule' => [
            'sheet' => 'Advance Payment Schedule',
            'headers' => ['Beneficiary Account Name', 'Beneficiary Account Number', 'Beneficiary Bank Name', 'Payment Date', 'Payment Amount', 'Batch'],
            'fields' => ['beneficiary_account_name', 'beneficiary_account_number|beneficiary_bank_number', 'beneficiary_bank_name', 'payment_date', 'payment_amount', 'batch'],
            'widths' => [32, 26, 30, 16, 20, 16],
            'amount_col' => 5,
            'batch_col' => 6,
        ],
        'fx_instruction_letter' => [
            'sheet' => 'FX Instruction Letter',
            'headers' => ['Beneficiary Account Name', 'Beneficiary Account Number', 'Paid From', 'Bank Code', 'Currency', 'Payment Date', 'Payment Amount'],
            'fields' => ['beneficiary_account_name', 'beneficiary_account_number', 'paid_from', 'bank_code', 'currency', 'payment_date', 'payment_amount'],
            'widths' => [32, 26, 24, 16, 12, 16, 20],
            'amount_col' => 7,
        ],
        'local_transfer' => [
            'sheet' => 'Local Transfer',
            'headers' => ['Beneficiary Account Name', 'Beneficiary Account Number', 'Beneficiary Bank Name', 'Paid From', 'Payment Date', 'Payment Amount', 'Batch'],
            'fields' => ['beneficiary_account_name', 'beneficiary_account_number', 'beneficiary_bank_name', 'paid_from', 'payment_date', 'payment_amount', 'batch'],
            'widths' => [32, 26, 30, 24, 16, 20, 16],
            'amount_col' => 6,
            'batch_col' => 7,
        ],
        'other_payment_schedule' => [
            'sheet' => 'Other Payment Schedule',
            'headers' => ['Beneficiary Account Name', 'Beneficiary Account Number', 'Beneficiary Bank Name', 'Payment Date', 'Payment Amount', 'Batch'],
            'fields' => ['beneficiary_account_name', 'beneficiary_account_number', 'beneficiary_bank_name', 'payment_date', 'payment_amount', 'batch'],
            'widths' => [32, 26, 30, 16, 20, 16],
            'amount_col' => 5,
            'batch_col' => 6,
        ],
        'payment_schedule' => [
            'sheet' => 'Payment Schedule',
            'headers' => ['Beneficiary Account Name', 'Beneficiary Account Number', 'Beneficiary Bank Name', 'Payment Date', 'Payment Amount', 'Batch'],
            'fields' => ['beneficiary_account_name', 'beneficiary_account_number', 'beneficiary_bank_name', 'payment_date', 'payment_amount', 'batch'],
            'widths' => [32, 26, 30, 16, 20, 16],
            'amount_col' => 5,
            'batch_col' => 6,
        ],
        'union_payment_schedule' => [
            'sheet' => 'Union Bank Schedule',
            'headers' => ['Beneficiary Account Name', 'Beneficiary Account Number', 'Beneficiary Bank Name', 'Payment Date', 'Payment Amount', 'Batch'],
            'fields' => ['beneficiary_account_name', 'beneficiary_account_number', 'beneficiary_bank_name', 'payment_date', 'payment_amount', 'batch'],
            'widths' => [32, 26, 30, 16, 20, 16],
            'amount_col' => 5,
            'batch_col' => 6,
        ],
    ];
}

function accountScumlIsBatchReport(string $key): bool
{
    return in_array($key, [
        'advance_payment_schedule',
        'local_transfer',
        'other_payment_schedule',
        'payment_schedule',
        'union_payment_schedule',
    ], true);
}

function accountScumlBatchLabel($batch): string
{
    $raw = trim((string) ($batch ?? ''));
    if ($raw === '') {
        return 'Unbatched';
    }
    return preg_match('/^batch\b/i', $raw) ? $raw : 'Batch ' . $raw;
}

function accountScumlFieldValue(array $row, string $field)
{
    foreach (explode('|', $field) as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }
    return '';
}

function accountScumlColumn(int $index): string
{
    return Coordinate::stringFromColumnIndex($index);
}

function accountScumlBrandSheet(Worksheet $sheet, string $title, string $period, int $lastColumn): void
{
    $last = accountScumlColumn($lastColumn);
    $sheet->setShowGridlines(false);
    foreach ([1, 2, 3] as $row) {
        $sheet->mergeCells("A{$row}:{$last}{$row}");
    }
    $sheet->setCellValue('A1', 'ACCTLAB  |  SCUML REPORT');
    $sheet->setCellValue('A2', $title);
    $sheet->setCellValue('A3', 'Reporting period: ' . $period . '  •  Generated ' . date('d M Y H:i'));

    $sheet->getStyle("A1:{$last}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => ACCOUNT_SCUML_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_TEAL_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    $sheet->getStyle("A2:{$last}2")->applyFromArray([
        'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => ACCOUNT_SCUML_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_NAVY]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    $sheet->getStyle("A3:{$last}3")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => 'D7E7EC']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_NAVY_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(23);
    $sheet->getRowDimension(2)->setRowHeight(34);
    $sheet->getRowDimension(3)->setRowHeight(22);

    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.35)->setRight(0.35);
    $sheet->getHeaderFooter()->setOddFooter('&LGenerated by AcctLab&CPage &P of &N&R' . date('Y-m-d H:i'));
}

function accountScumlStyleHeader(Worksheet $sheet, int $row, int $lastColumn): void
{
    $last = accountScumlColumn($lastColumn);
    $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => ACCOUNT_SCUML_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_TEAL_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SCUML_TEAL]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(28);
}

function accountScumlStyleDetailRow(Worksheet $sheet, int $row, int $lastColumn, bool $alternate): void
{
    $last = accountScumlColumn($lastColumn);
    $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => ACCOUNT_SCUML_TEXT]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $alternate ? ACCOUNT_SCUML_ROW_ALT : ACCOUNT_SCUML_WHITE]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => ACCOUNT_SCUML_BORDER]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(23);
}

function accountScumlStyleSubtotal(Worksheet $sheet, int $row, int $lastColumn, int $amountColumn, ?int $batchColumn): void
{
    $last = accountScumlColumn($lastColumn);
    $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => ACCOUNT_SCUML_TEAL_DARK]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_TOTAL_SOFT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => [
            'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SCUML_TEAL]],
            'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SCUML_TEAL]],
        ],
    ]);

    $amountCell = accountScumlColumn($amountColumn) . $row;
    $sheet->getStyle($amountCell)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => ACCOUNT_SCUML_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_TEAL_DARK]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_SCUML_TEAL]]],
    ]);

    if ($batchColumn !== null) {
        $batchCell = accountScumlColumn($batchColumn) . $row;
        $sheet->getStyle($batchCell)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => ACCOUNT_SCUML_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_NAVY_SOFT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }
    $sheet->getRowDimension($row)->setRowHeight(27);
}

function accountScumlSetWidths(Worksheet $sheet, array $widths): void
{
    foreach ($widths as $index => $width) {
        $sheet->getColumnDimension(accountScumlColumn($index + 1))->setWidth($width);
    }
}

function accountScumlWriteRow(Worksheet $sheet, int $rowNumber, array $source, array $config): void
{
    foreach ($config['fields'] as $index => $field) {
        $column = $index + 1;
        $value = accountScumlFieldValue($source, $field);
        $cellAddress = accountScumlColumn($column) . $rowNumber;
        if ($column === $config['amount_col']) {
            $sheet->setCellValue($cellAddress, (float) ($value ?: 0));
            continue;
        }
        if ($field === 'payment_date' && $value !== '') {
            try {
                $sheet->setCellValue($cellAddress, ExcelDate::PHPToExcel(new DateTime((string) $value)));
                continue;
            } catch (Throwable $e) {
                // Keep the source text if a legacy record contains a non-standard date.
            }
        }
        if (preg_match('/account_number|paid_from|bank_code/', $field)) {
            $sheet->setCellValueExplicit($cellAddress, (string) $value, DataType::TYPE_STRING);
            continue;
        }
        $sheet->setCellValue($cellAddress, $value);
    }

    $dateColumn = array_search('payment_date', $config['fields'], true);
    if ($dateColumn !== false) {
        $sheet->getStyle(accountScumlColumn($dateColumn + 1) . $rowNumber)->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
    }
    $sheet->getStyle(accountScumlColumn($config['amount_col']) . $rowNumber)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
    $sheet->getStyle(accountScumlColumn($config['amount_col']) . $rowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

function accountScumlGroupRows(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $paymentDate = trim((string) ($row['payment_date'] ?? '')) ?: 'No date';
        $rawBatch = trim((string) ($row['batch'] ?? ''));
        $key = $paymentDate . '::' . ($rawBatch !== '' ? $rawBatch : '__unbatched__');
        if (!isset($groups[$key])) {
            $groups[$key] = ['payment_date' => $paymentDate, 'batch' => $rawBatch, 'rows' => [], 'total' => 0.0];
        }
        $groups[$key]['rows'][] = $row;
        $groups[$key]['total'] += (float) ($row['payment_amount'] ?? 0);
    }
    return array_values($groups);
}

function accountScumlWriteReportSheet(Worksheet $sheet, string $reportKey, array $rows, string $periodFrom, string $periodTo): void
{
    $config = accountScumlReportConfigs()[$reportKey];
    $lastColumn = count($config['headers']);
    $batchColumn = isset($config['batch_col']) ? (int) $config['batch_col'] : null;
    $period = date('d M Y', strtotime($periodFrom)) . ' to ' . date('d M Y', strtotime($periodTo));

    accountScumlBrandSheet($sheet, strtoupper($config['sheet']), $period, $lastColumn);
    foreach ($config['headers'] as $index => $header) {
        $sheet->setCellValue(accountScumlColumn($index + 1) . '5', $header);
    }
    accountScumlStyleHeader($sheet, 5, $lastColumn);
    accountScumlSetWidths($sheet, $config['widths']);
    $sheet->freezePane('A6');
    $sheet->setAutoFilter('A5:' . accountScumlColumn($lastColumn) . '5');
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);

    if ($rows === []) {
        $sheet->mergeCells('A6:' . accountScumlColumn($lastColumn) . '6');
        $sheet->setCellValue('A6', 'No transactions for this period.');
        $sheet->getStyle('A6:' . accountScumlColumn($lastColumn) . '6')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => ACCOUNT_SCUML_MUTED]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_ROW_ALT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_SCUML_BORDER]]],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(26);
        return;
    }

    $rowNumber = 6;
    $detailIndex = 0;
    if (accountScumlIsBatchReport($reportKey)) {
        foreach (accountScumlGroupRows($rows) as $group) {
            foreach ($group['rows'] as $sourceRow) {
                accountScumlWriteRow($sheet, $rowNumber, $sourceRow, $config);
                accountScumlStyleDetailRow($sheet, $rowNumber, $lastColumn, $detailIndex % 2 === 1);
                $detailIndex++;
                $rowNumber++;
            }

            $sheet->setCellValue('A' . $rowNumber, strtoupper(accountScumlBatchLabel($group['batch'])) . ' TOTAL');
            if ($lastColumn >= 2) {
                $sheet->setCellValue('B' . $rowNumber, count($group['rows']) . ' record' . (count($group['rows']) === 1 ? '' : 's'));
            }
            $dateIndex = array_search('payment_date', $config['fields'], true);
            if ($dateIndex !== false) {
                $sheet->setCellValue(accountScumlColumn($dateIndex + 1) . $rowNumber, $group['payment_date']);
            }
            $sheet->setCellValue(accountScumlColumn($config['amount_col']) . $rowNumber, $group['total']);
            $sheet->getStyle(accountScumlColumn($config['amount_col']) . $rowNumber)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
            if ($batchColumn !== null) {
                $sheet->setCellValue(accountScumlColumn($batchColumn) . $rowNumber, 'BATCH TOTAL');
            }
            accountScumlStyleSubtotal($sheet, $rowNumber, $lastColumn, (int) $config['amount_col'], $batchColumn);
            $rowNumber++;
        }
    } else {
        foreach ($rows as $sourceRow) {
            accountScumlWriteRow($sheet, $rowNumber, $sourceRow, $config);
            accountScumlStyleDetailRow($sheet, $rowNumber, $lastColumn, $detailIndex % 2 === 1);
            $detailIndex++;
            $rowNumber++;
        }
    }
}

function accountBuildScumlWorkbook(array $data, string $periodFrom, string $periodTo): Spreadsheet
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);
    $configs = accountScumlReportConfigs();
    $period = date('d M Y', strtotime($periodFrom)) . ' to ' . date('d M Y', strtotime($periodTo));

    $summary = new Worksheet($spreadsheet, 'Summary');
    $spreadsheet->addSheet($summary);
    accountScumlBrandSheet($summary, 'SCUML REPORT SUMMARY', $period, 3);
    $summary->fromArray(['Report', 'Transactions', 'Batch Totals'], null, 'A5');
    accountScumlStyleHeader($summary, 5, 3);
    $summary->getColumnDimension('A')->setWidth(32);
    $summary->getColumnDimension('B')->setWidth(16);
    $summary->getColumnDimension('C')->setWidth(18);
    $summary->freezePane('A6');

    $summaryRow = 6;
    foreach ($configs as $key => $config) {
        $rows = is_array($data[$key] ?? null) ? $data[$key] : [];
        $batchCount = accountScumlIsBatchReport($key) ? count(accountScumlGroupRows($rows)) : '';
        $summary->fromArray([$config['sheet'], count($rows), $batchCount], null, 'A' . $summaryRow);
        accountScumlStyleDetailRow($summary, $summaryRow, 3, ($summaryRow - 6) % 2 === 1);
        if ($batchCount !== '') {
            $summary->getStyle('C' . $summaryRow)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_SCUML_TEAL_DARK]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_SCUML_TEAL_SOFT]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $summaryRow++;
    }

    foreach ($configs as $key => $config) {
        $sheet = new Worksheet($spreadsheet, $config['sheet']);
        $spreadsheet->addSheet($sheet);
        accountScumlWriteReportSheet($sheet, $key, is_array($data[$key] ?? null) ? $data[$key] : [], $periodFrom, $periodTo);
    }

    $spreadsheet->setActiveSheetIndex(0);
    $spreadsheet->getProperties()
        ->setCreator('AcctLab')
        ->setCompany('Lambert Electromec')
        ->setTitle('SCUML Report')
        ->setSubject('SCUML transaction report by source and batch');

    return $spreadsheet;
}
