<?php

declare(strict_types=1);

require_once __DIR__ . '/accountReceivablesService.php';
require_once __DIR__ . '/accountReceivablesDashboardService.php';
require_once __DIR__ . '/accountReceivablesAgeingService.php';
require_once __DIR__ . '/accountReceivablesDeductionsService.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const ACCOUNT_RECEIVABLES_REPORT_NAVY = '102A43';
const ACCOUNT_RECEIVABLES_REPORT_NAVY_SOFT = '173B5A';
const ACCOUNT_RECEIVABLES_REPORT_TEAL = '0B8F87';
const ACCOUNT_RECEIVABLES_REPORT_TEAL_DARK = '086B66';
const ACCOUNT_RECEIVABLES_REPORT_TEAL_SOFT = 'E8F7F5';
const ACCOUNT_RECEIVABLES_REPORT_BLUE_SOFT = 'ECF3FA';
const ACCOUNT_RECEIVABLES_REPORT_GREEN = '2E8B68';
const ACCOUNT_RECEIVABLES_REPORT_GREEN_SOFT = 'EAF6F0';
const ACCOUNT_RECEIVABLES_REPORT_AMBER = 'B7791F';
const ACCOUNT_RECEIVABLES_REPORT_AMBER_SOFT = 'FFF6E5';
const ACCOUNT_RECEIVABLES_REPORT_RED = 'C94A4A';
const ACCOUNT_RECEIVABLES_REPORT_RED_SOFT = 'FCECEC';
const ACCOUNT_RECEIVABLES_REPORT_BORDER = 'D9E4EA';
const ACCOUNT_RECEIVABLES_REPORT_TEXT = '243742';
const ACCOUNT_RECEIVABLES_REPORT_MUTED = '6B7D86';
const ACCOUNT_RECEIVABLES_REPORT_WHITE = 'FFFFFF';
const ACCOUNT_RECEIVABLES_REPORT_FORMULA_FILL = 'F4F9FC';


function accountReceivablesReportFilterOptions(mysqli $conn): array
{
    $projects = [];
    $clients = [];
    $result = $conn->query(
        "SELECT DISTINCT project_name, client_name
         FROM account_receivable_invoices
         WHERE deleted_at IS NULL
         ORDER BY project_name ASC, client_name ASC"
    );

    while ($row = $result->fetch_assoc()) {
        $project = trim((string) ($row['project_name'] ?? ''));
        $client = trim((string) ($row['client_name'] ?? ''));
        if ($project !== '') {
            $projects[$project] = true;
        }
        if ($client !== '') {
            $clients[$client] = true;
        }
    }

    return [
        'projects' => array_keys($projects),
        'clients' => array_keys($clients),
    ];
}

function accountReceivablesReportCentre(mysqli $conn, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $dashboard = accountReceivablesDashboard($conn, $actor);
    $settings = accountReceivablesSettings($conn);

    return [
        'as_at' => (string) $settings['reporting_date'],
        'ageing_basis' => (string) $settings['ageing_basis'],
        'usd_ngn_rate' => (float) $settings['usd_ngn_rate'],
        'fx_conversion_basis' => 'HISTORICAL_POSTING_AND_ALLOCATION_RATES',
        'management_pack' => [
            'title' => 'Receivables Management Pack',
            'format' => 'Excel (.xlsx)',
            'formula_driven' => true,
            'sections' => [
                'Management Dashboard',
                'Ageing Report',
                'Invoice Register',
                'Band Details',
                'Deductions & Tax',
            ],
        ],
        'summary' => [
            'invoice_rows' => (int) ($dashboard['summary']['total_rows'] ?? 0),
            'open_rows' => (int) ($dashboard['summary']['open_rows'] ?? 0),
            'combined_exposure_ngn_equivalent' => (float) ($dashboard['summary']['combined_exposure_ngn_equivalent'] ?? 0),
        ],
        'filter_options' => accountReceivablesReportFilterOptions($conn),
        'notes' => [
            'Financial totals, outstanding balances and ageing are formula-driven inside the workbook.',
            'Ageing uses the standard Receivables bands and the current date automatically; no separate setup sheet is required.',
            'NGN-equivalent management exposure uses each USD posting rate and any recorded Flexible allocation rate overrides; the latest FX rate is reference-only.',
            'The workbook is configured for management printing with freeze panes, print areas and consistent AcctLab styling.',
        ],
        'permissions' => [
            'can_export' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
        ],
    ];
}

function accountReceivablesReportPeriod(array $query): array
{
    $dateFrom = accountReceivablesNullableDate($query['date_from'] ?? null, 'Report period from');
    $dateTo = accountReceivablesNullableDate($query['date_to'] ?? null, 'Report period to');
    $projectName = accountReceivablesNullableText($query['project_name'] ?? null, 255);
    $clientName = accountReceivablesNullableText($query['client_name'] ?? null, 255);

    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        throw new RuntimeException('Report period From date cannot be later than the To date.', 422);
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'project_name' => $projectName,
        'client_name' => $clientName,
        'is_filtered' => $dateFrom !== null || $dateTo !== null || $projectName !== null || $clientName !== null,
    ];
}

function accountReceivablesReportPeriodLabel(array $period): string
{
    $from = $period['date_from'] ?? null;
    $to = $period['date_to'] ?? null;
    $projectName = trim((string) ($period['project_name'] ?? ''));
    $clientName = trim((string) ($period['client_name'] ?? ''));
    $parts = [];

    if ($projectName !== '') {
        $parts[] = "Project: {$projectName}";
    }
    if ($clientName !== '') {
        $parts[] = "Client: {$clientName}";
    }
    if ($from && $to) {
        $parts[] = "Invoice Date {$from} to {$to}";
    } elseif ($from) {
        $parts[] = "Invoice Date from {$from}";
    } elseif ($to) {
        $parts[] = "Invoice Date up to {$to}";
    }

    return $parts === [] ? 'Entire Invoice Register' : implode(' · ', $parts);
}

function accountReceivablesReportRows(mysqli $conn, array $query = []): array
{
    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);
    $period = accountReceivablesReportPeriod($query);

    $conditions = ['deleted_at IS NULL'];
    $types = '';
    $params = [];
    foreach (['project_name', 'client_name'] as $key) {
        if ($period[$key] !== null) {
            $conditions[] = "{$key} = ?";
            $types .= 's';
            $params[] = $period[$key];
        }
    }
    if ($period['date_from'] !== null) {
        $conditions[] = 'invoice_date >= ?';
        $types .= 's';
        $params[] = $period['date_from'];
    }
    if ($period['date_to'] !== null) {
        $conditions[] = 'invoice_date <= ?';
        $types .= 's';
        $params[] = $period['date_to'];
    }

    $sql = 'SELECT * FROM account_receivable_invoices WHERE ' . implode(' AND ', $conditions)
        . ' ORDER BY CASE WHEN invoice_date IS NULL THEN 1 ELSE 0 END ASC, invoice_date DESC, id DESC';
    $stmt = $conn->prepare($sql);
    accountReceivablesBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $historicalRateAdjustments = accountReceivablesHistoricalRateAdjustmentMap($conn);
    $rows = [];
    while ($raw = $result->fetch_assoc()) {
        $row = accountReceivablesDecorateInvoice($raw, $settings, $bands);
        $rows[] = accountReceivablesApplyHistoricalRateMetrics(
            $row,
            $historicalRateAdjustments[(int) ($row['id'] ?? 0)] ?? []
        );
    }
    $stmt->close();

    return $rows;
}

function accountReceivablesReportBundle(mysqli $conn, array $actor, array $query = []): array
{
    $period = accountReceivablesReportPeriod($query);
    $periodQuery = array_filter([
        'date_from' => $period['date_from'],
        'date_to' => $period['date_to'],
        'project_name' => $period['project_name'],
        'client_name' => $period['client_name'],
    ], static fn(mixed $value): bool => $value !== null && $value !== '');

    return [
        'settings' => accountReceivablesSettings($conn),
        'bands' => accountReceivablesAgeingBands($conn),
        'period' => $period,
        'period_label' => accountReceivablesReportPeriodLabel($period),
        'rows' => accountReceivablesReportRows($conn, $periodQuery),
        'dashboard' => accountReceivablesDashboard($conn, $actor),
        'ageing' => accountReceivablesAgeingReport($conn, $periodQuery, $actor),
        'deductions' => accountReceivablesDeductionsReport($conn, $periodQuery, $actor),
    ];
}

function accountReceivablesExcelNamedSheet(Spreadsheet $book, string $name): Worksheet
{
    $sheet = $book->getSheetByName($name);
    if ($sheet instanceof Worksheet) {
        return $sheet;
    }

    $sheet = $book->createSheet();
    $sheet->setTitle($name);
    return $sheet;
}

function accountReceivablesExcelColumn(int $index): string
{
    return Coordinate::stringFromColumnIndex($index);
}

function accountReceivablesExcelDateValue(?string $date): ?float
{
    if (!$date) {
        return null;
    }
    return Date::PHPToExcel(new DateTimeImmutable($date));
}

function accountReceivablesExcelBrandSheet(
    Worksheet $sheet,
    string $title,
    string $subtitle,
    int $lastColumn,
    bool $landscape = true
): void {
    $end = accountReceivablesExcelColumn($lastColumn);
    $sheet->setShowGridlines(false);
    $sheet->mergeCells("A1:{$end}1");
    $sheet->mergeCells("A2:{$end}2");
    $sheet->mergeCells("A3:{$end}3");
    $sheet->setCellValue('A1', 'LAMBERT ELECTROMEC LIMITED  |  ACCTLAB RECEIVABLES');
    $sheet->setCellValue('A2', $title);
    $sheet->setCellValue('A3', $subtitle);
    $sheet->getStyle("A1:{$end}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL_DARK]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A2:{$end}2")->applyFromArray([
        'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A3:{$end}3")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => 'DCE8EF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY_SOFT]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(22);
    $sheet->getRowDimension(2)->setRowHeight(34);
    $sheet->getRowDimension(3)->setRowHeight(23);
    $sheet->getPageSetup()
        ->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.45)->setLeft(0.3)->setRight(0.3);
    $sheet->getHeaderFooter()->setOddFooter('&LAcctLab Receivables&CPage &P of &N&RGenerated ' . date('Y-m-d H:i'));
}

function accountReceivablesExcelHeader(Worksheet $sheet, int $row, int $lastColumn): void
{
    $end = accountReceivablesExcelColumn($lastColumn);
    $sheet->getStyle("A{$row}:{$end}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL_DARK]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(30);
}

function accountReceivablesExcelRows(Worksheet $sheet, int $firstRow, int $lastRow, int $lastColumn): void
{
    if ($lastRow < $firstRow) {
        return;
    }
    $end = accountReceivablesExcelColumn($lastColumn);
    $sheet->getStyle("A{$firstRow}:{$end}{$lastRow}")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEXT]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_BORDER]]],
    ]);
    for ($row = $firstRow; $row <= $lastRow; $row++) {
        if (($row - $firstRow) % 2 === 1) {
            $sheet->getStyle("A{$row}:{$end}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7FAFC');
        }
    }
}

function accountReceivablesExcelMoney(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

function accountReceivablesExcelPercent(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode('0.0%');
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}

function accountReceivablesExcelWidths(Worksheet $sheet, array $widths): void
{
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
}

function accountReceivablesExcelFormulaStyle(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_FORMULA_FILL);
}

function accountReceivablesExcelSetText(Worksheet $sheet, string $cell, mixed $value): void
{
    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
}


function accountReceivablesExcelDrillKey(string $currency, string $project, string $client): string
{
    return strtoupper(trim($currency)) . "\x1F" . trim($project) . "\x1F" . trim($client);
}

function accountReceivablesExcelInternalLink(Worksheet $sheet, string $cell, string $targetSheet, int $targetRow): void
{
    $escapedSheet = str_replace("'", "''", $targetSheet);
    $sheet->getCell($cell)->getHyperlink()->setUrl("sheet://'{$escapedSheet}'!A{$targetRow}");
    $sheet->getStyle($cell)->getFont()
        ->setUnderline(true)
        ->getColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_TEAL_DARK);
}

function accountReceivablesExcelSectionTitle(Worksheet $sheet, int $row, string $label, int $lastColumn): void
{
    $end = accountReceivablesExcelColumn($lastColumn);
    $sheet->mergeCells("A{$row}:{$end}{$row}");
    $sheet->setCellValue("A{$row}", $label);
    $sheet->getStyle("A{$row}:{$end}{$row}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL_SOFT]],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_BORDER]]],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(24);
}

function accountReceivablesExcelKpi(Worksheet $sheet, string $range, string $label, string $formula, string $numberFormat = '#,##0.00'): void
{
    [$start, $end] = Coordinate::rangeBoundaries($range);
    $startCol = accountReceivablesExcelColumn((int) $start[0]);
    $endCol = accountReceivablesExcelColumn((int) $end[0]);
    $startRow = (int) $start[1];
    $endRow = (int) $end[1];
    $sheet->mergeCells("{$startCol}{$startRow}:{$endCol}{$startRow}");
    $sheet->mergeCells("{$startCol}" . ($startRow + 1) . ":{$endCol}{$endRow}");
    $sheet->setCellValue("{$startCol}{$startRow}", $label);
    $sheet->setCellValue("{$startCol}" . ($startRow + 1), $formula);
    $sheet->getStyle($range)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_BORDER]]],
    ]);
    $sheet->getStyle("{$startCol}{$startRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_MUTED]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $valueCell = "{$startCol}" . ($startRow + 1);
    $sheet->getStyle($valueCell)->applyFromArray([
        'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'numberFormat' => ['formatCode' => $numberFormat],
    ]);
    accountReceivablesExcelFormulaStyle($sheet, $valueCell);
}

function accountReceivablesExcelBuildInvoiceRegister(Spreadsheet $book, array $bundle): array
{
    $sheet = accountReceivablesExcelNamedSheet($book, 'Invoice Register');
    $sheet->getTabColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_NAVY);
    $headers = [
        'ID', 'Project', 'Client', 'Line Type', 'Invoice No.', 'Invoice Date', 'Credit Days', 'Due Date',
        'Currency', 'FX Rate Used', 'Invoice Value Net', 'VAT Charged', 'Gross Override', 'Gross Invoice',
        'Retention', 'Advance Amortisation', 'Admin & Other Charges', 'WHT', 'VAT Deducted at Source', 'NCD Levy',
        'Stamp Duty', 'Bank Charges', 'Other Deductions', 'Total Deductions', 'Net Receivable', 'Amount Received',
        'Outstanding', 'WHT Credit Note Outstanding', 'Position', 'Days Outstanding', 'Ageing Band', 'Status',
        'In Ageing', 'Source Reference', 'Remarks', 'Created At', 'Updated At',
        'Rate Treatment', 'Outstanding FX Rate Adjustment (NGN)', 'Advance FX Rate Adjustment (NGN)',
        'Outstanding (NGN Eq.)', 'Total Deductions (NGN Eq.)', 'WHT Credit Note Outstanding (NGN Eq.)',
    ];
    accountReceivablesExcelBrandSheet($sheet, 'INVOICE REGISTER', 'Source register with formula-driven receivable calculations · ' . (string) ($bundle['period_label'] ?? 'Entire Invoice Register'), count($headers));
    $sheet->fromArray($headers, null, 'A6');
    accountReceivablesExcelHeader($sheet, 6, count($headers));

    $rowMap = [];
    $dataRow = 7;
    foreach ($bundle['rows'] as $source) {
        $rowMap[(int) $source['id']] = $dataRow;
        $sheet->fromArray([
            (int) $source['id'],
            (string) ($source['project_name'] ?? ''),
            (string) ($source['client_name'] ?? ''),
            (string) ($source['line_type'] ?? ''),
            (string) ($source['invoice_number'] ?? ''),
            accountReceivablesExcelDateValue($source['invoice_date'] ?? null),
            (int) ($source['credit_days'] ?? 0),
            null,
            (string) ($source['currency'] ?? ''),
            $source['fx_rate_used'] === null ? null : (float) $source['fx_rate_used'],
            (float) ($source['invoice_value_net'] ?? 0),
            (float) ($source['vat_charged'] ?? 0),
            $source['invoice_value_gross_override'] === null ? null : (float) $source['invoice_value_gross_override'],
            null,
            (float) ($source['retention'] ?? 0),
            (float) ($source['advance_amortisation'] ?? 0),
            (float) ($source['admin_other_charges'] ?? 0),
            (float) ($source['wht'] ?? 0),
            (float) ($source['vat_deducted_at_source'] ?? 0),
            (float) ($source['ncd_levy'] ?? 0),
            (float) ($source['stamp_duty'] ?? 0),
            (float) ($source['bank_charges'] ?? 0),
            (float) ($source['other_deductions'] ?? 0),
            null,
            null,
            (float) ($source['amount_received'] ?? 0),
            null,
            (float) ($source['wht_credit_note_outstanding'] ?? 0),
            (string) ($source['position'] ?? ''),
            null,
            null,
            null,
            null,
            (string) ($source['source_reference'] ?? ''),
            (string) ($source['remarks'] ?? ''),
            (string) ($source['created_at'] ?? ''),
            (string) ($source['updated_at'] ?? ''),
            (string) ($source['rate_mode'] ?? 'LOCKED'),
            (float) ($source['outstanding_fx_adjustment_ngn'] ?? 0),
            (float) ($source['advance_amortisation_fx_adjustment_ngn'] ?? 0),
            null,
            null,
            null,
        ], null, "A{$dataRow}");

        foreach ([
            'B' => $source['project_name'] ?? '',
            'C' => $source['client_name'] ?? '',
            'D' => $source['line_type'] ?? '',
            'E' => $source['invoice_number'] ?? '',
            'I' => $source['currency'] ?? '',
            'AC' => $source['position'] ?? '',
            'AH' => $source['source_reference'] ?? '',
            'AI' => $source['remarks'] ?? '',
            'AJ' => $source['created_at'] ?? '',
            'AK' => $source['updated_at'] ?? '',
            'AL' => $source['rate_mode'] ?? 'LOCKED',
        ] as $column => $value) {
            accountReceivablesExcelSetText($sheet, "{$column}{$dataRow}", $value);
        }

        $sheet->setCellValue("H{$dataRow}", "=IF(F{$dataRow}=\"\",\"\",F{$dataRow}+G{$dataRow})");
        $sheet->setCellValue("N{$dataRow}", "=IF(M{$dataRow}<>\"\",M{$dataRow},IF(OR(K{$dataRow}<>0,L{$dataRow}<>0),K{$dataRow}+L{$dataRow},\"\"))");
        $sheet->setCellValue("X{$dataRow}", "=SUM(O{$dataRow}:W{$dataRow})");
        $sheet->setCellValue("Y{$dataRow}", "=K{$dataRow}+L{$dataRow}-X{$dataRow}");
        $sheet->setCellValue("AA{$dataRow}", "=Y{$dataRow}-Z{$dataRow}");
        $sheet->setCellValue("AD{$dataRow}", "=IF(AC{$dataRow}<>\"Open\",\"\",IF(F{$dataRow}=\"\",\"\",TODAY()-F{$dataRow}))");
        $sheet->setCellValue(
            "AE{$dataRow}",
            "=IF(AD{$dataRow}=\"\",\"\",IF(AD{$dataRow}<0,\"Not yet due\",IF(AD{$dataRow}<=30,\"1 - 30 days\",IF(AD{$dataRow}<=60,\"31 - 60 days\",IF(AD{$dataRow}<=90,\"61 - 90 days\",IF(AD{$dataRow}<=120,\"91 - 120 days\",IF(AD{$dataRow}<=180,\"121 - 180 days\",IF(AD{$dataRow}<=365,\"181 - 365 days\",\"Over 365 days\"))))))))"
        );
        $sheet->setCellValue("AF{$dataRow}", "=IF(AC{$dataRow}<>\"Open\",\"Not aged\",IF(ABS(AA{$dataRow})<0.005,\"Settled\",IF(F{$dataRow}=\"\",\"Within terms\",IF(AND(H{$dataRow}<>\"\",TODAY()>H{$dataRow}),\"Overdue\",\"Within terms\"))))");
        $sheet->setCellValue("AG{$dataRow}", "=IF(AC{$dataRow}=\"Open\",\"Yes\",\"No\")");
        $sheet->setCellValue("AO{$dataRow}", "=IF(I{$dataRow}=\"NGN\",AA{$dataRow},IF(AND(I{$dataRow}=\"USD\",J{$dataRow}>0),AA{$dataRow}*J{$dataRow}+AM{$dataRow},\"\"))");
        $sheet->setCellValue("AP{$dataRow}", "=IF(I{$dataRow}=\"NGN\",X{$dataRow},IF(AND(I{$dataRow}=\"USD\",J{$dataRow}>0),X{$dataRow}*J{$dataRow}+AN{$dataRow},\"\"))");
        $sheet->setCellValue("AQ{$dataRow}", "=IF(I{$dataRow}=\"NGN\",AB{$dataRow},IF(AND(I{$dataRow}=\"USD\",J{$dataRow}>0),AB{$dataRow}*J{$dataRow},\"\"))");
        $dataRow++;
    }

    $lastRow = max(7, $dataRow - 1);
    accountReceivablesExcelRows($sheet, 7, $lastRow, count($headers));
    accountReceivablesExcelFormulaStyle($sheet, "H7:H{$lastRow}");
    accountReceivablesExcelFormulaStyle($sheet, "N7:N{$lastRow}");
    accountReceivablesExcelFormulaStyle($sheet, "X7:Y{$lastRow}");
    accountReceivablesExcelFormulaStyle($sheet, "AA7:AA{$lastRow}");
    accountReceivablesExcelFormulaStyle($sheet, "AD7:AG{$lastRow}");
    accountReceivablesExcelFormulaStyle($sheet, "AO7:AQ{$lastRow}");
    $sheet->getStyle("F7:H{$lastRow}")->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
    accountReceivablesExcelMoney($sheet, "J7:AB{$lastRow}");
    accountReceivablesExcelMoney($sheet, "AM7:AQ{$lastRow}");
    $sheet->getStyle("AD7:AD{$lastRow}")->getNumberFormat()->setFormatCode('0');
    $sheet->setAutoFilter("A6:AQ6");
    $sheet->freezePane('A7');
    accountReceivablesExcelWidths($sheet, [
        'A' => 8, 'B' => 30, 'C' => 28, 'D' => 14, 'E' => 18, 'F' => 14, 'G' => 12, 'H' => 14,
        'I' => 10, 'J' => 12, 'K' => 17, 'L' => 15, 'M' => 16, 'N' => 17, 'O' => 15, 'P' => 18,
        'Q' => 19, 'R' => 14, 'S' => 20, 'T' => 14, 'U' => 14, 'V' => 15, 'W' => 17, 'X' => 17,
        'Y' => 17, 'Z' => 17, 'AA' => 17, 'AB' => 22, 'AC' => 25, 'AD' => 16, 'AE' => 18, 'AF' => 15,
        'AG' => 12, 'AH' => 20, 'AI' => 36, 'AJ' => 20, 'AK' => 20, 'AL' => 15,
        'AM' => 21, 'AN' => 21, 'AO' => 20, 'AP' => 22, 'AQ' => 24,
    ]);
    $sheet->getStyle("AI7:AI{$lastRow}")->getAlignment()->setWrapText(true);
    $sheet->getPageSetup()->setPrintArea("A1:AQ{$lastRow}");
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 6);

    return ['sheet' => $sheet, 'row_map' => $rowMap, 'first_row' => 7, 'last_row' => $lastRow];
}

function accountReceivablesExcelSumifs(string $sumColumn, int $first, int $last, array $criteria): string
{
    $parts = ["'Invoice Register'!\${$sumColumn}\${$first}:\${$sumColumn}\${$last}"];
    foreach ($criteria as [$column, $criterion]) {
        $parts[] = "'Invoice Register'!\${$column}\${$first}:\${$column}\${$last}";
        $parts[] = $criterion;
    }
    return '=SUMIFS(' . implode(',', $parts) . ')';
}

function accountReceivablesExcelBuildAgeingSheet(Spreadsheet $book, array $bundle, int $registerFirst, int $registerLast, array $drillAnchors = []): array
{
    $sheet = accountReceivablesExcelNamedSheet($book, 'Ageing Report');
    $sheet->getTabColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_TEAL);
    $bands = $bundle['bands'];
    $lastColumnCount = 5 + count($bands);
    accountReceivablesExcelBrandSheet($sheet, 'AGEING REPORT', 'Project exposure by currency and standard ageing bands · ' . (string) ($bundle['period_label'] ?? 'Entire Invoice Register'), max(10, $lastColumnCount));
    $sectionRows = [];
    $current = 5;
    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        accountReceivablesExcelSectionTitle($sheet, $current, "{$currency} RECEIVABLES", $lastColumnCount);
        $current++;
        $headers = ['Project', 'Client', 'Total', '% of Currency'];
        foreach ($bands as $band) {
            $headers[] = (string) $band['label'];
        }
        $headers[] = 'Unaged Open';
        $sheet->fromArray($headers, null, "A{$current}");
        foreach ($bands as $index => $band) {
            accountReceivablesExcelSetText($sheet, accountReceivablesExcelColumn(5 + $index) . $current, $band['label'] ?? '');
        }
        accountReceivablesExcelHeader($sheet, $current, count($headers));
        $headerRow = $current;
        $current++;
        $firstData = $current;
        $rows = $bundle['ageing']['currency'][$currency]['rows'] ?? [];
        foreach ($rows as $project) {
            $projectName = trim((string) ($project['project_name'] ?? '')) ?: 'Unassigned project';
            $clientName = trim((string) ($project['client_name'] ?? '')) ?: 'Unassigned client';
            $drillKey = accountReceivablesExcelDrillKey($currency, $projectName, $clientName);
            accountReceivablesExcelSetText($sheet, "A{$current}", $projectName);
            accountReceivablesExcelSetText($sheet, "B{$current}", $clientName);
            if (isset($drillAnchors['projects'][$drillKey])) {
                accountReceivablesExcelInternalLink($sheet, "A{$current}", 'Band Details', (int) $drillAnchors['projects'][$drillKey]);
            }
            $bandStartCol = 5;
            foreach ($bands as $index => $band) {
                $col = accountReceivablesExcelColumn($bandStartCol + $index);
                $rawLabel = (string) ($band['label'] ?? '');
                $label = str_replace('"', '""', $rawLabel);
                $sheet->setCellValue(
                    "{$col}{$current}",
                    accountReceivablesExcelSumifs('AA', $registerFirst, $registerLast, [
                        ['B', "\$A{$current}"], ['C', "\$B{$current}"], ['I', '"' . $currency . '"'], ['AC', '"Open"'], ['AE', '"' . $label . '"'],
                    ])
                );
                if (isset($drillAnchors['bands'][$drillKey][$rawLabel])) {
                    accountReceivablesExcelInternalLink($sheet, "{$col}{$current}", 'Band Details', (int) $drillAnchors['bands'][$drillKey][$rawLabel]);
                }
            }
            $lastBandCol = accountReceivablesExcelColumn($bandStartCol + count($bands) - 1);
            if ($bands === []) {
                $sheet->setCellValue("C{$current}", 0);
            } else {
                $sheet->setCellValue("C{$current}", "=SUM(E{$current}:{$lastBandCol}{$current})");
            }
            $unagedCol = accountReceivablesExcelColumn($bandStartCol + count($bands));
            $sheet->setCellValue(
                "{$unagedCol}{$current}",
                '=' . substr(accountReceivablesExcelSumifs('AA', $registerFirst, $registerLast, [
                    ['B', "\$A{$current}"], ['C', "\$B{$current}"], ['I', '"' . $currency . '"'], ['AC', '"Open"'],
                ]), 1) . "-C{$current}"
            );
            if (isset($drillAnchors['bands'][$drillKey]['__unaged__'])) {
                accountReceivablesExcelInternalLink($sheet, "{$unagedCol}{$current}", 'Band Details', (int) $drillAnchors['bands'][$drillKey]['__unaged__']);
            }
            $current++;
        }
        $lastData = max($firstData, $current - 1);
        $totalRow = $current;
        $sheet->setCellValue("A{$totalRow}", "{$currency} TOTAL");
        $sheet->mergeCells("A{$totalRow}:B{$totalRow}");
        if ($current === $firstData) {
            $sheet->setCellValue("C{$totalRow}", 0);
        } else {
            $sheet->setCellValue("C{$totalRow}", "=SUM(C{$firstData}:C" . ($current - 1) . ')');
        }
        foreach ($bands as $index => $band) {
            $col = accountReceivablesExcelColumn(5 + $index);
            $sheet->setCellValue("{$col}{$totalRow}", $current === $firstData ? 0 : "=SUM({$col}{$firstData}:{$col}" . ($current - 1) . ')');
        }
        $unagedCol = accountReceivablesExcelColumn(5 + count($bands));
        $sheet->setCellValue("{$unagedCol}{$totalRow}", $current === $firstData ? 0 : "=SUM({$unagedCol}{$firstData}:{$unagedCol}" . ($current - 1) . ')');
        for ($row = $firstData; $row < $totalRow; $row++) {
            $sheet->setCellValue("D{$row}", "=IFERROR(C{$row}/C\${$totalRow},0)");
        }
        $sheet->setCellValue("D{$totalRow}", '=IF(C' . $totalRow . '=0,0,1)');

        accountReceivablesExcelRows($sheet, $firstData, $totalRow - 1, count($headers));
        $endColumn = accountReceivablesExcelColumn(count($headers));
        $sheet->getStyle("A{$totalRow}:{$endColumn}{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL]]],
        ]);
        accountReceivablesExcelMoney($sheet, "C{$firstData}:C{$totalRow}");
        accountReceivablesExcelPercent($sheet, "D{$firstData}:D{$totalRow}");
        if (count($headers) >= 5) {
            accountReceivablesExcelMoney($sheet, "E{$firstData}:{$endColumn}{$totalRow}");
            if ($totalRow > $firstData) {
                accountReceivablesExcelFormulaStyle($sheet, "C{$firstData}:{$endColumn}" . ($totalRow - 1));
            }
        }
        $sectionRows[$currency] = ['header' => $headerRow, 'first' => $firstData, 'last' => $totalRow - 1, 'total' => $totalRow, 'unaged_col' => $unagedCol];
        $current += 3;
    }
    accountReceivablesExcelWidths($sheet, ['A' => 32, 'B' => 30, 'C' => 18, 'D' => 14]);
    for ($index = 0; $index < count($bands) + 1; $index++) {
        $sheet->getColumnDimension(accountReceivablesExcelColumn(5 + $index))->setWidth(17);
    }
    $sheet->freezePane('C7');
    $sheet->getPageSetup()->setPrintArea('A1:' . accountReceivablesExcelColumn($lastColumnCount) . max(10, $current));
    return ['sheet' => $sheet, 'sections' => $sectionRows];
}

function accountReceivablesExcelWriteBandDetailTable(
    Worksheet $sheet,
    array $sources,
    array $rowMap,
    int $headerRow
): int {
    $headers = [
        'Invoice No.', 'Invoice Date', 'Due Date', 'Days Outstanding', 'Ageing Band', 'Status',
        'Net Receivable', 'Amount Received', 'Outstanding', 'WHT Credit Note', 'Position', 'Remarks',
    ];
    $sheet->fromArray($headers, null, "A{$headerRow}");
    accountReceivablesExcelHeader($sheet, $headerRow, count($headers));

    $row = $headerRow + 1;
    $firstData = $row;
    foreach ($sources as $source) {
        $registerRow = $rowMap[(int) ($source['id'] ?? 0)] ?? null;
        if (!$registerRow) {
            continue;
        }

        $links = ['E', 'F', 'H', 'AD', 'AE', 'AF', 'Y', 'Z', 'AA', 'AB', 'AC', 'AI'];
        foreach ($links as $index => $sourceColumn) {
            $target = accountReceivablesExcelColumn($index + 1);
            $sheet->setCellValue("{$target}{$row}", "='Invoice Register'!{$sourceColumn}{$registerRow}");
        }
        $row++;
    }

    $lastData = $row - 1;
    if ($lastData >= $firstData) {
        accountReceivablesExcelRows($sheet, $firstData, $lastData, count($headers));
        accountReceivablesExcelFormulaStyle($sheet, "A{$firstData}:L{$lastData}");
        $sheet->getStyle("B{$firstData}:C{$lastData}")->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
        accountReceivablesExcelMoney($sheet, "G{$firstData}:J{$lastData}");
        $sheet->getStyle("L{$firstData}:L{$lastData}")->getAlignment()->setWrapText(true);

        $totalRow = $row;
        $sheet->mergeCells("A{$totalRow}:H{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'SECTION TOTAL');
        $sheet->setCellValue("I{$totalRow}", "=SUM(I{$firstData}:I{$lastData})");
        $sheet->getStyle("A{$totalRow}:L{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL]]],
        ]);
        accountReceivablesExcelMoney($sheet, "I{$totalRow}:I{$totalRow}");
        $row++;
    } else {
        $sheet->mergeCells("A{$row}:L{$row}");
        $sheet->setCellValue("A{$row}", 'No invoice entries in this selection.');
        $sheet->getStyle("A{$row}:L{$row}")->getFont()->getColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_MUTED);
        $row++;
    }

    return $row;
}

function accountReceivablesExcelBuildBandDetail(Spreadsheet $book, array $bundle, array $rowMap): array
{
    $sheet = accountReceivablesExcelNamedSheet($book, 'Band Details');
    $sheet->getTabColor()->setRGB('3D7DA7');
    accountReceivablesExcelBrandSheet(
        $sheet,
        'BAND DETAILS',
        'Click a project or ageing amount on the Ageing Report to jump to its supporting invoices · ' . (string) ($bundle['period_label'] ?? 'Entire Invoice Register'),
        12
    );

    $sheet->mergeCells('A5:L5');
    $sheet->setCellValue('A5', 'Drill-down evidence is pre-grouped by project and ageing band so management can trace every Ageing Report value to the exact Invoice Register lines.');
    $sheet->getStyle('A5:L5')->applyFromArray([
        'font' => ['size' => 9, 'italic' => true, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_MUTED]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_BLUE_SOFT]],
        'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(5)->setRowHeight(30);

    $allByProject = [];
    $openByProject = [];
    $knownBands = [];
    foreach ($bundle['bands'] as $band) {
        $knownBands[(string) ($band['label'] ?? '')] = true;
    }

    foreach ($bundle['rows'] as $source) {
        $currency = strtoupper((string) ($source['currency'] ?? ''));
        $project = trim((string) ($source['project_name'] ?? '')) ?: 'Unassigned project';
        $client = trim((string) ($source['client_name'] ?? '')) ?: 'Unassigned client';
        $key = accountReceivablesExcelDrillKey($currency, $project, $client);
        $allByProject[$key][] = $source;
        if ((string) ($source['position'] ?? '') === 'Open') {
            $openByProject[$key][] = $source;
        }
    }

    $anchors = ['projects' => [], 'bands' => []];
    $current = 7;

    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        foreach (($bundle['ageing']['currency'][$currency]['rows'] ?? []) as $projectRow) {
            $project = trim((string) ($projectRow['project_name'] ?? '')) ?: 'Unassigned project';
            $client = trim((string) ($projectRow['client_name'] ?? '')) ?: 'Unassigned client';
            $key = accountReceivablesExcelDrillKey($currency, $project, $client);
            $projectSources = $allByProject[$key] ?? [];
            $openSources = $openByProject[$key] ?? [];
            if ($projectSources === [] || $openSources === []) {
                continue;
            }

            $anchors['projects'][$key] = $current;
            accountReceivablesExcelSectionTitle($sheet, $current, "{$currency} · {$project} · {$client} · ALL PROJECT REGISTER ENTRIES", 12);
            $current++;
            $current = accountReceivablesExcelWriteBandDetailTable($sheet, $projectSources, $rowMap, $current);
            $current += 2;

            foreach ($bundle['bands'] as $band) {
                $label = (string) ($band['label'] ?? '');
                $matching = array_values(array_filter(
                    $openSources,
                    static fn(array $source): bool => (string) ($source['ageing_band'] ?? '') === $label
                ));
                if ($matching === []) {
                    continue;
                }

                $anchors['bands'][$key][$label] = $current;
                accountReceivablesExcelSectionTitle($sheet, $current, "{$currency} · {$project} · {$label}", 12);
                $current++;
                $current = accountReceivablesExcelWriteBandDetailTable($sheet, $matching, $rowMap, $current);
                $current += 2;
            }

            $unaged = array_values(array_filter(
                $openSources,
                static fn(array $source): bool => !isset($knownBands[(string) ($source['ageing_band'] ?? '')])
            ));
            if ($unaged !== []) {
                $anchors['bands'][$key]['__unaged__'] = $current;
                accountReceivablesExcelSectionTitle($sheet, $current, "{$currency} · {$project} · UNAGED OPEN", 12);
                $current++;
                $current = accountReceivablesExcelWriteBandDetailTable($sheet, $unaged, $rowMap, $current);
                $current += 2;
            }
        }
    }

    accountReceivablesExcelWidths($sheet, [
        'A' => 20, 'B' => 14, 'C' => 14, 'D' => 16, 'E' => 18, 'F' => 14,
        'G' => 17, 'H' => 17, 'I' => 17, 'J' => 18, 'K' => 25, 'L' => 42,
    ]);
    $sheet->freezePane('A6');
    $sheet->getPageSetup()->setPrintArea("A1:L" . max(12, $current));
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);

    return ['sheet' => $sheet, 'anchors' => $anchors];
}

function accountReceivablesExcelBuildDeductions(Spreadsheet $book, array $bundle, int $registerFirst, int $registerLast): array
{
    $sheet = accountReceivablesExcelNamedSheet($book, 'Deductions & Tax');
    $sheet->getTabColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_AMBER);
    $categoryColumns = [
        ['O', 'Retention'], ['P', 'Advance Amortisation'], ['Q', 'Admin & Other Charges'], ['R', 'WHT'],
        ['S', 'VAT Deducted at Source'], ['T', 'NCD Levy'], ['U', 'Stamp Duty'], ['V', 'Bank Charges'], ['W', 'Other Deductions'],
    ];
    $headers = ['Project', 'Client', 'Gross Invoiced', 'VAT Charged', 'Total Deductions', 'WHT Credit Note Outstanding'];
    foreach ($categoryColumns as [, $label]) {
        $headers[] = $label;
    }
    $lastColumn = count($headers);
    accountReceivablesExcelBrandSheet($sheet, 'DEDUCTIONS & TAX', 'Historical deductions across Open, Settled and Review positions · ' . (string) ($bundle['period_label'] ?? 'Entire Invoice Register'), $lastColumn);
    $current = 5;
    $sections = [];
    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        accountReceivablesExcelSectionTitle($sheet, $current, "{$currency} DEDUCTIONS", $lastColumn);
        $current++;
        $sheet->fromArray($headers, null, "A{$current}");
        accountReceivablesExcelHeader($sheet, $current, $lastColumn);
        $current++;
        $first = $current;
        foreach (($bundle['deductions']['currency'][$currency]['rows'] ?? []) as $project) {
            accountReceivablesExcelSetText($sheet, "A{$current}", $project['project_name'] ?? '');
            accountReceivablesExcelSetText($sheet, "B{$current}", $project['client_name'] ?? '');
            $common = [['B', "\$A{$current}"], ['C', "\$B{$current}"], ['I', '"' . $currency . '"']];
            $sheet->setCellValue("C{$current}", accountReceivablesExcelSumifs('N', $registerFirst, $registerLast, $common));
            $sheet->setCellValue("D{$current}", accountReceivablesExcelSumifs('L', $registerFirst, $registerLast, $common));
            $sheet->setCellValue("E{$current}", accountReceivablesExcelSumifs('X', $registerFirst, $registerLast, $common));
            $sheet->setCellValue("F{$current}", accountReceivablesExcelSumifs('AB', $registerFirst, $registerLast, $common));
            foreach ($categoryColumns as $index => [$sourceColumn]) {
                $target = accountReceivablesExcelColumn(7 + $index);
                $sheet->setCellValue("{$target}{$current}", accountReceivablesExcelSumifs($sourceColumn, $registerFirst, $registerLast, $common));
            }
            $current++;
        }
        $last = $current - 1;
        $total = $current;
        $sheet->setCellValue("A{$total}", "{$currency} TOTAL");
        $sheet->mergeCells("A{$total}:B{$total}");
        for ($column = 3; $column <= $lastColumn; $column++) {
            $col = accountReceivablesExcelColumn($column);
            $sheet->setCellValue("{$col}{$total}", $last >= $first ? "=SUM({$col}{$first}:{$col}{$last})" : 0);
        }
        accountReceivablesExcelRows($sheet, $first, $last, $lastColumn);
        $end = accountReceivablesExcelColumn($lastColumn);
        $sheet->getStyle("A{$total}:{$end}{$total}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
        ]);
        accountReceivablesExcelMoney($sheet, "C{$first}:{$end}{$total}");
        if ($last >= $first) {
            accountReceivablesExcelFormulaStyle($sheet, "C{$first}:{$end}{$last}");
        }
        $sections[$currency] = ['first' => $first, 'last' => $last, 'total' => $total];
        $current += 3;
    }
    accountReceivablesExcelWidths($sheet, ['A' => 32, 'B' => 30, 'C' => 17, 'D' => 15, 'E' => 17, 'F' => 22]);
    for ($col = 7; $col <= $lastColumn; $col++) {
        $sheet->getColumnDimension(accountReceivablesExcelColumn($col))->setWidth(18);
    }
    $sheet->freezePane('C7');
    $sheet->getPageSetup()->setPrintArea('A1:' . accountReceivablesExcelColumn($lastColumn) . max(10, $current));
    return ['sheet' => $sheet, 'sections' => $sections];
}

function accountReceivablesExcelBuildDashboard(
    Spreadsheet $book,
    array $bundle,
    int $registerFirst,
    int $registerLast,
    array $ageingSections
): void {
    $sheet = accountReceivablesExcelNamedSheet($book, 'Management Dashboard');
    $sheet->getTabColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_TEAL);
    accountReceivablesExcelBrandSheet($sheet, 'RECEIVABLES MANAGEMENT DASHBOARD', 'Executive management pack with live Excel formulas · ' . (string) ($bundle['period_label'] ?? 'Entire Invoice Register'), 12, true);

    $fxRate = (float) ($bundle['settings']['usd_ngn_rate'] ?? 0);
    $sheet->setCellValue('A4', 'Report Date');
    $sheet->setCellValue('B4', '=TODAY()');
    $sheet->setCellValue('D4', 'Ageing Basis');
    accountReceivablesExcelSetText($sheet, 'E4', 'Invoice Date');
    $sheet->setCellValue('G4', 'FX Conversion');
    accountReceivablesExcelSetText($sheet, 'H4', 'Historical rates');
    $sheet->setCellValue('J4', 'Formula Engine');
    accountReceivablesExcelSetText($sheet, 'K4', 'LIVE');
    foreach (['A4', 'D4', 'G4', 'J4'] as $cell) {
        $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_MUTED);
    }
    $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
    $sheet->getStyle('A4:L4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ACCOUNT_RECEIVABLES_REPORT_BLUE_SOFT);

    $ngnTotalRow = $ageingSections['NGN']['total'];
    $usdTotalRow = $ageingSections['USD']['total'];
    accountReceivablesExcelKpi($sheet, 'A6:C8', 'NGN Reported Receivable', "='Ageing Report'!C{$ngnTotalRow}");
    accountReceivablesExcelKpi($sheet, 'D6:F8', 'USD Reported Receivable', "='Ageing Report'!C{$usdTotalRow}");
    accountReceivablesExcelKpi($sheet, 'G6:I8', 'Combined Exposure (NGN Eq.)', '=SUMIFS(\'Invoice Register\'!$AO$' . $registerFirst . ':$AO$' . $registerLast . ',\'Invoice Register\'!$AC$' . $registerFirst . ':$AC$' . $registerLast . ',"Open",\'Invoice Register\'!$AE$' . $registerFirst . ':$AE$' . $registerLast . ',"<>")');
    accountReceivablesExcelKpi($sheet, 'J6:L8', 'Open Register Items', '=COUNTIF(\'Invoice Register\'!$AC$' . $registerFirst . ':$AC$' . $registerLast . ',"Open")', '#,##0');

    accountReceivablesExcelKpi($sheet, 'A10:C12', 'WHT Credit Notes (NGN Eq.)', '=SUM(\'Invoice Register\'!$AQ$' . $registerFirst . ':$AQ$' . $registerLast . ')');
    accountReceivablesExcelKpi($sheet, 'D10:F12', 'Review Balance (NGN Eq.)', '=SUMIFS(\'Invoice Register\'!$AO$' . $registerFirst . ':$AO$' . $registerLast . ',\'Invoice Register\'!$AC$' . $registerFirst . ':$AC$' . $registerLast . ',"Review - not in reported receivable")');
    accountReceivablesExcelKpi($sheet, 'G10:I12', 'Total Deductions (NGN Eq.)', '=SUM(\'Invoice Register\'!$AP$' . $registerFirst . ':$AP$' . $registerLast . ')');
    accountReceivablesExcelKpi($sheet, 'J10:L12', 'Unaged Open Items', '=COUNTIFS(\'Invoice Register\'!$AC$' . $registerFirst . ':$AC$' . $registerLast . ',"Open",\'Invoice Register\'!$AE$' . $registerFirst . ':$AE$' . $registerLast . ',"")', '#,##0');

    accountReceivablesExcelSectionTitle($sheet, 14, 'AGEING PROFILE', 6);
    $sheet->fromArray(['Ageing Band', 'NGN', 'USD', 'USD in NGN · Historical', 'Combined NGN Eq.', '% of Combined'], null, 'A15');
    accountReceivablesExcelHeader($sheet, 15, 6);
    $ageingRow = 16;
    foreach ($bundle['bands'] as $index => $band) {
        $bandCol = accountReceivablesExcelColumn(5 + $index);
        accountReceivablesExcelSetText($sheet, "A{$ageingRow}", $band['label'] ?? '');
        $sheet->setCellValue("B{$ageingRow}", "='Ageing Report'!{$bandCol}{$ngnTotalRow}");
        $sheet->setCellValue("C{$ageingRow}", "='Ageing Report'!{$bandCol}{$usdTotalRow}");
        $sheet->setCellValue("D{$ageingRow}", "=SUMIFS('Invoice Register'!\$AO\$" . $registerFirst . ":\$AO\$" . $registerLast . ",'Invoice Register'!\$I\$" . $registerFirst . ":\$I\$" . $registerLast . ",\"USD\",'Invoice Register'!\$AC\$" . $registerFirst . ":\$AC\$" . $registerLast . ",\"Open\",'Invoice Register'!\$AE\$" . $registerFirst . ":\$AE\$" . $registerLast . ",A{$ageingRow})");
        $sheet->setCellValue("E{$ageingRow}", "=B{$ageingRow}+D{$ageingRow}");
        $sheet->setCellValue("F{$ageingRow}", "=IFERROR(E{$ageingRow}/\$E\$" . (16 + count($bundle['bands'])) . ',0)');
        $ageingRow++;
    }
    $ageingTotal = $ageingRow;
    $sheet->setCellValue("A{$ageingTotal}", 'TOTAL');
    for ($col = 2; $col <= 5; $col++) {
        $letter = accountReceivablesExcelColumn($col);
        $sheet->setCellValue("{$letter}{$ageingTotal}", count($bundle['bands']) ? "=SUM({$letter}16:{$letter}" . ($ageingTotal - 1) . ')' : 0);
    }
    $sheet->setCellValue("F{$ageingTotal}", '=IF(E' . $ageingTotal . '=0,0,1)');
    accountReceivablesExcelRows($sheet, 16, $ageingTotal - 1, 6);
    $sheet->getStyle("A{$ageingTotal}:F{$ageingTotal}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
    ]);
    accountReceivablesExcelMoney($sheet, "B16:E{$ageingTotal}");
    accountReceivablesExcelPercent($sheet, "F16:F{$ageingTotal}");
    if ($ageingTotal > 16) {
        accountReceivablesExcelFormulaStyle($sheet, "B16:F" . ($ageingTotal - 1));
    }

    $projectTitleRow = 14;
    $sheet->mergeCells("H{$projectTitleRow}:L{$projectTitleRow}");
    $sheet->setCellValue("H{$projectTitleRow}", 'LARGEST NGN PROJECT EXPOSURES');
    $sheet->getStyle("H{$projectTitleRow}:L{$projectTitleRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_NAVY]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL_SOFT]],
    ]);
    $sheet->fromArray(['Project', 'Client', 'Outstanding', '% NGN Book', 'Oldest Band Amount'], null, 'H15');
    $sheet->getStyle('H15:L15')->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ACCOUNT_RECEIVABLES_REPORT_TEAL_DARK]],
    ]);
    $projectRow = 16;
    $riskBandLabel = (string) ($bundle['dashboard']['risk_band']['label'] ?? '');
    $riskBandIndex = null;
    foreach ($bundle['bands'] as $index => $band) {
        if ((string) $band['label'] === $riskBandLabel) {
            $riskBandIndex = $index;
            break;
        }
    }
    $ngnFirst = (int) $ageingSections['NGN']['first'];
    $ngnLast = (int) $ageingSections['NGN']['last'];
    $maxProjects = min(8, max(0, $ngnLast - $ngnFirst + 1));
    for ($index = 0; $index < $maxProjects; $index++) {
        $sourceRow = $ngnFirst + $index;
        $sheet->setCellValue("H{$projectRow}", "='Ageing Report'!A{$sourceRow}");
        $sheet->setCellValue("I{$projectRow}", "='Ageing Report'!B{$sourceRow}");
        $sheet->setCellValue("J{$projectRow}", "='Ageing Report'!C{$sourceRow}");
        $sheet->setCellValue("K{$projectRow}", "='Ageing Report'!D{$sourceRow}");
        if ($riskBandIndex !== null) {
            $riskColumn = accountReceivablesExcelColumn(5 + $riskBandIndex);
            $sheet->setCellValue("L{$projectRow}", "='Ageing Report'!{$riskColumn}{$sourceRow}");
        } else {
            $sheet->setCellValue("L{$projectRow}", 0);
        }
        $projectRow++;
    }
    accountReceivablesExcelRows($sheet, 16, max(16, $projectRow - 1), 12);
    accountReceivablesExcelMoney($sheet, 'J16:J' . max(16, $projectRow - 1));
    accountReceivablesExcelPercent($sheet, 'K16:K' . max(16, $projectRow - 1));
    accountReceivablesExcelMoney($sheet, 'L16:L' . max(16, $projectRow - 1));

    if (count($bundle['bands']) > 0) {
        $labels = [new DataSeriesValues('String', "'Management Dashboard'!\$A\$16:\$A\$" . ($ageingTotal - 1), null, count($bundle['bands']))];
        $values = [new DataSeriesValues('Number', "'Management Dashboard'!\$E\$16:\$E\$" . ($ageingTotal - 1), null, count($bundle['bands']))];
        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            [new DataSeriesValues('String', "'Management Dashboard'!\$E\$15", null, 1)],
            $labels,
            $values
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);
        $plot = new PlotArea(null, [$series]);
        $chart = new Chart('AgeingProfileChart', new Title('Combined Receivables by Ageing Band (NGN Equivalent)'), new Legend(Legend::POSITION_BOTTOM, null, false), $plot);
        $chart->setTopLeftPosition('A' . ($ageingTotal + 3));
        $chart->setBottomRightPosition('G' . ($ageingTotal + 20));
        $sheet->addChart($chart);
    }

    accountReceivablesExcelWidths($sheet, ['A' => 22, 'B' => 18, 'C' => 18, 'D' => 18, 'E' => 19, 'F' => 15, 'G' => 3, 'H' => 29, 'I' => 26, 'J' => 18, 'K' => 15, 'L' => 18]);
    $sheet->freezePane('A5');
    $printEnd = max(38, $ageingTotal + 20, $projectRow + 3);
    $sheet->getPageSetup()->setPrintArea("A1:L{$printEnd}");
}

function accountReceivablesBuildManagementWorkbook(array $bundle): Spreadsheet
{
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('AcctLab')
        ->setCompany('Lambert Electromec Limited')
        ->setTitle('Receivables Management Pack')
        ->setSubject('Receivables management reporting')
        ->setDescription('Formula-driven management pack covering dashboard, ageing, invoice register, band detail and deductions.');

    // Create the five management sheets in the exact circulation order requested.
    $book->getActiveSheet()->setTitle('Management Dashboard');
    foreach (['Ageing Report', 'Invoice Register', 'Band Details', 'Deductions & Tax'] as $sheetName) {
        $sheet = $book->createSheet();
        $sheet->setTitle($sheetName);
    }

    // Populate dependency sheets first; creation order above remains unchanged.
    $register = accountReceivablesExcelBuildInvoiceRegister($book, $bundle);
    $bandDetail = accountReceivablesExcelBuildBandDetail($book, $bundle, $register['row_map']);
    $ageing = accountReceivablesExcelBuildAgeingSheet(
        $book,
        $bundle,
        $register['first_row'],
        $register['last_row'],
        $bandDetail['anchors'] ?? []
    );
    accountReceivablesExcelBuildDeductions($book, $bundle, $register['first_row'], $register['last_row']);
    accountReceivablesExcelBuildDashboard($book, $bundle, $register['first_row'], $register['last_row'], $ageing['sections']);

    $book->setActiveSheetIndexByName('Management Dashboard');
    return $book;
}

