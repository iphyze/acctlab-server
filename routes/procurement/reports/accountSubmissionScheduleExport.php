<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementAccountSubmissionScheduleService.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const PROCUREMENT_ACCOUNT_SCHEDULE_NAVY = '0A1D29';
const PROCUREMENT_ACCOUNT_SCHEDULE_NAVY_SOFT = '102D3A';
const PROCUREMENT_ACCOUNT_SCHEDULE_TEAL = '18A79D';
const PROCUREMENT_ACCOUNT_SCHEDULE_TEAL_DARK = '0B6D68';
const PROCUREMENT_ACCOUNT_SCHEDULE_TEAL_SOFT = 'E8F7F5';
const PROCUREMENT_ACCOUNT_SCHEDULE_BORDER = 'D8E5E9';
const PROCUREMENT_ACCOUNT_SCHEDULE_TEXT = '20343D';
const PROCUREMENT_ACCOUNT_SCHEDULE_MUTED = '6B7F88';
const PROCUREMENT_ACCOUNT_SCHEDULE_WHITE = 'FFFFFF';
const PROCUREMENT_ACCOUNT_SCHEDULE_ALT = 'F4F9FA';
const PROCUREMENT_ACCOUNT_SCHEDULE_MAX_EXPORT_ROWS = 50000;

function procurementAccountScheduleExcelColumn(int $index): string
{
    return Coordinate::stringFromColumnIndex($index);
}

function procurementAccountScheduleExcelDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y');
    } catch (Throwable) {
        return $value;
    }
}

function procurementAccountScheduleExcelUserName(array $user): string
{
    $name = trim((string) ($user['full_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $name = trim((string) ($user['fname'] ?? '') . ' ' . (string) ($user['lname'] ?? ''));
    return $name !== '' ? $name : 'Procurement Team';
}

function procurementAccountScheduleExcelSupplierLabel(array $report): string
{
    $supplierId = (int) ($report['meta']['supplier_id'] ?? 0);
    if ($supplierId <= 0) {
        return 'All Suppliers';
    }
    $label = trim((string) ($report['meta']['supplier_label'] ?? ''));
    return $label !== '' ? $label : 'Selected Supplier';
}

function procurementAccountScheduleExcelFilterList(array $values, string $allLabel): string
{
    $clean = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $values)));
    return $clean === [] ? $allLabel : implode(', ', $clean);
}

function procurementAccountScheduleExcelScopeDefinitions(): array
{
    return [
        'fx_advance' => [
            'label' => 'FX Advance',
            'sheet' => 'FX Advance',
            'title' => 'FX ADVANCE PAYMENT REQUEST SCHEDULE',
            'headers' => [
                'S/N', 'PO Number', 'Site / Project', 'Supplier Name', 'Currency', 'PO Amount',
                'Advance %', 'PO Date', 'Contact Person', 'Phone Number', 'Remark', 'PO Status',
                'Payment Status', 'Sent to Account',
            ],
            'widths' => [7, 22, 31, 30, 10, 17, 12, 14, 22, 18, 28, 14, 16, 16],
            'money_columns' => ['F'],
            'percentage_column' => 'G',
            'status_columns' => ['L', 'M'],
        ],
        'fx_final' => [
            'label' => 'FX Final',
            'sheet' => 'FX Final',
            'title' => 'FX FINAL INVOICES PAYMENT REQUEST SCHEDULE',
            'headers' => [
                'S/N', 'PO Number', 'Purchase Number', 'GRN Number', 'Project', 'Supplier Name',
                'Invoice No.', 'Currency', 'PO Value', 'Purchase Value', 'Remark', 'PO Status',
                'Payment Status', 'Sent to Account',
            ],
            'widths' => [7, 22, 18, 17, 30, 30, 18, 10, 17, 18, 28, 14, 16, 16],
            'money_columns' => ['I', 'J'],
            'percentage_column' => null,
            'status_columns' => ['L', 'M'],
        ],
        'local_advance' => [
            'label' => 'Local Advance',
            'sheet' => 'Local Advance',
            'title' => 'LOCAL ADVANCE PAYMENT REQUEST SCHEDULE',
            'headers' => [
                'S/N', 'PO Number', 'Site / Project', 'Supplier Name', 'PO Value', 'Purchase Value',
                'Advance %', 'PO Date', 'Remark', 'PO Status', 'Payment Status', 'Sent to Account',
            ],
            'widths' => [7, 22, 31, 30, 17, 18, 12, 14, 28, 14, 16, 16],
            'money_columns' => ['E', 'F'],
            'percentage_column' => 'G',
            'status_columns' => ['J', 'K'],
        ],
        'local_final' => [
            'label' => 'Local Final',
            'sheet' => 'Local Final',
            'title' => 'LOCAL FINAL INVOICES PAYMENT REQUEST SCHEDULE',
            'headers' => [
                'S/N', 'PO Number', 'Purchase Number', 'GRN Number', 'Project', 'Supplier Name',
                'Invoice No.', 'PO Value', 'Purchase Value', 'Remark', 'PO Status', 'Payment Status',
                'Sent to Account',
            ],
            'widths' => [7, 22, 18, 17, 30, 30, 18, 17, 18, 28, 14, 16, 16],
            'money_columns' => ['H', 'I'],
            'percentage_column' => null,
            'status_columns' => ['K', 'L'],
        ],
    ];
}

function procurementAccountScheduleExcelRowForScope(string $scope, array $row, int $serial): array
{
    $site = trim((string) ($row['site'] ?? '')) ?: (trim((string) ($row['project_name'] ?? '')) ?: (string) ($row['project_code'] ?? ''));
    $project = trim((string) ($row['project_code'] ?? ''));
    $projectName = trim((string) ($row['project_name'] ?? ''));
    if ($project === '') {
        $project = $projectName;
    } elseif ($projectName !== '' && strcasecmp($project, $projectName) !== 0) {
        $project .= ' — ' . $projectName;
    }

    return match ($scope) {
        'fx_advance' => [
            $serial,
            $row['po_number'] ?? null,
            $site ?: null,
            $row['supplier_name'] ?? 'Supplier not recorded',
            $row['currency'] ?? 'UNKNOWN',
            (float) ($row['po_value'] ?? 0),
            $row['percentage'] === null ? null : (float) $row['percentage'],
            procurementAccountScheduleExcelDate($row['po_date'] ?? null),
            $row['contact_person'] ?? null,
            $row['phone_number'] ?? null,
            $row['remark'] ?? null,
            $row['status'] ?? null,
            $row['payment_status'] ?? null,
            procurementAccountScheduleExcelDate($row['date_sent_to_account'] ?? null),
        ],
        'local_advance' => [
            $serial,
            $row['po_number'] ?? null,
            $site ?: null,
            $row['supplier_name'] ?? 'Supplier not recorded',
            (float) ($row['po_value'] ?? 0),
            (float) ($row['purchase_value'] ?? 0),
            $row['percentage'] === null ? null : (float) $row['percentage'],
            procurementAccountScheduleExcelDate($row['po_date'] ?? null),
            $row['remark'] ?? null,
            $row['status'] ?? null,
            $row['payment_status'] ?? null,
            procurementAccountScheduleExcelDate($row['date_sent_to_account'] ?? null),
        ],
        'fx_final' => [
            $serial,
            $row['po_number'] ?? null,
            $row['purchase_number'] ?? null,
            $row['grn_number'] ?? null,
            $project ?: null,
            $row['supplier_name'] ?? 'Supplier not recorded',
            $row['invoice_number'] ?? null,
            $row['currency'] ?? 'UNKNOWN',
            (float) ($row['po_value'] ?? 0),
            (float) ($row['purchase_value'] ?? 0),
            $row['remark'] ?? null,
            $row['status'] ?? null,
            $row['payment_status'] ?? null,
            procurementAccountScheduleExcelDate($row['date_sent_to_account'] ?? null),
        ],
        default => [
            $serial,
            $row['po_number'] ?? null,
            $row['purchase_number'] ?? null,
            $row['grn_number'] ?? null,
            $project ?: null,
            $row['supplier_name'] ?? 'Supplier not recorded',
            $row['invoice_number'] ?? null,
            (float) ($row['po_value'] ?? 0),
            (float) ($row['purchase_value'] ?? 0),
            $row['remark'] ?? null,
            $row['status'] ?? null,
            $row['payment_status'] ?? null,
            procurementAccountScheduleExcelDate($row['date_sent_to_account'] ?? null),
        ],
    };
}

function procurementAccountScheduleExcelStatusStyle(Worksheet $sheet, string $column, int $firstRow, int $lastRow): void
{
    if ($lastRow < $firstRow) {
        return;
    }
    for ($row = $firstRow; $row <= $lastRow; $row++) {
        $status = strtolower(trim((string) $sheet->getCell($column . $row)->getValue()));
        if ($status === '') {
            continue;
        }
        $fill = 'EAF2FB';
        $font = PROCUREMENT_ACCOUNT_SCHEDULE_TEAL_DARK;
        if (str_contains($status, 'pending') || str_contains($status, 'processing') || str_contains($status, 'unclosed')) {
            $fill = 'FFF5DE';
            $font = '9A6500';
        } elseif (str_contains($status, 'paid') || str_contains($status, 'closed') || str_contains($status, 'approved')) {
            $fill = 'E8F6EF';
            $font = '2B966F';
        } elseif (str_contains($status, 'return') || str_contains($status, 'failed') || str_contains($status, 'cancel')) {
            $fill = 'FCEDED';
            $font = 'B84040';
        }
        $sheet->getStyle($column . $row)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $font]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }
}

function procurementAccountScheduleExcelBuildSheet(
    Spreadsheet $spreadsheet,
    string $scope,
    array $definition,
    array $rows,
    array $report,
    array $user,
    bool $firstSheet
): void {
    $sheet = $firstSheet ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $sheet->setTitle($definition['sheet']);
    $sheet->setShowGridlines(false);
    $sheet->getTabColor()->setRGB(PROCUREMENT_ACCOUNT_SCHEDULE_TEAL);

    $headers = $definition['headers'];
    $lastColumnIndex = count($headers);
    $lastColumn = procurementAccountScheduleExcelColumn($lastColumnIndex);
    $dateFrom = procurementAccountScheduleExcelDate((string) ($report['meta']['date_from'] ?? ''));
    $dateTo = procurementAccountScheduleExcelDate((string) ($report['meta']['date_to'] ?? ''));
    $supplier = procurementAccountScheduleExcelSupplierLabel($report);
    $currency = strtoupper((string) ($report['meta']['currency'] ?? 'ALL'));
    $currencyLabel = $currency === 'ALL' ? 'All Currencies' : $currency;
    $statusLabel = procurementAccountScheduleExcelFilterList((array) ($report['meta']['po_statuses'] ?? []), 'All PO Statuses');
    $paymentLabel = procurementAccountScheduleExcelFilterList((array) ($report['meta']['payment_statuses'] ?? []), 'All Payment Statuses');
    $generatedBy = procurementAccountScheduleExcelUserName($user);

    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->mergeCells("A3:{$lastColumn}3");
    $sheet->mergeCells("A4:{$lastColumn}4");
    $sheet->setCellValue('A1', 'LAMBERT ELECTROMEC LIMITED  |  PROCUREDESK');
    $sheet->setCellValue('A2', $definition['title']);
    $sheet->setCellValue('A3', "Period: {$dateFrom} — {$dateTo}  •  {$supplier}  •  {$currencyLabel}  •  " . number_format(count($rows)) . ' records');
    $sheet->setCellValue('A4', "PO Status: {$statusLabel}  •  Payment Status: {$paymentLabel}  •  Generated by {$generatedBy} on " . date('d M Y, H:i'));

    $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_TEAL_DARK]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
        'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_NAVY]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    foreach ([3, 4] as $row) {
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['size' => 8.5, 'color' => ['rgb' => $row === 3 ? 'D7E7EC' : PROCUREMENT_ACCOUNT_SCHEDULE_MUTED]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $row === 3 ? PROCUREMENT_ACCOUNT_SCHEDULE_NAVY_SOFT : 'F3F8FA']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }
    $sheet->getRowDimension(1)->setRowHeight(22);
    $sheet->getRowDimension(2)->setRowHeight(32);
    $sheet->getRowDimension(3)->setRowHeight(22);
    $sheet->getRowDimension(4)->setRowHeight(23);

    $headerRow = 6;
    $sheet->fromArray($headers, null, "A{$headerRow}");
    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_WHITE]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_TEAL_DARK]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_TEAL]]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(30);

    $dataFirstRow = 7;
    $rowNumber = $dataFirstRow;
    foreach ($rows as $index => $row) {
        $sheet->fromArray(procurementAccountScheduleExcelRowForScope($scope, $row, $index + 1), null, 'A' . $rowNumber++);
    }
    $dataLastRow = $rowNumber - 1;

    if ($dataLastRow >= $dataFirstRow) {
        $sheet->getStyle("A{$dataFirstRow}:{$lastColumn}{$dataLastRow}")->applyFromArray([
            'font' => ['size' => 8.5, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_TEXT]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_BORDER]]],
        ]);
        for ($row = $dataFirstRow; $row <= $dataLastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(24);
            if (($row - $dataFirstRow) % 2 === 1) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(PROCUREMENT_ACCOUNT_SCHEDULE_ALT);
            }
        }
        foreach ($definition['money_columns'] as $column) {
            $sheet->getStyle("{$column}{$dataFirstRow}:{$column}{$dataLastRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;–');
            $sheet->getStyle("{$column}{$dataFirstRow}:{$column}{$dataLastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        if ($definition['percentage_column']) {
            $column = $definition['percentage_column'];
            $sheet->getStyle("{$column}{$dataFirstRow}:{$column}{$dataLastRow}")->getNumberFormat()->setFormatCode('0.##"%"');
            $sheet->getStyle("{$column}{$dataFirstRow}:{$column}{$dataLastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        foreach ($definition['status_columns'] as $column) {
            procurementAccountScheduleExcelStatusStyle($sheet, $column, $dataFirstRow, $dataLastRow);
        }
    } else {
        $sheet->mergeCells("A7:{$lastColumn}8");
        $sheet->setCellValue('A7', 'No purchases matched this schedule and the applied filters.');
        $sheet->getStyle("A7:{$lastColumn}8")->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_MUTED]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F6FAFB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $dataLastRow = 8;
    }

    $signatureStart = $dataLastRow + 3;
    $middleColumnIndex = max(2, (int) floor($lastColumnIndex / 2));
    $middleColumn = procurementAccountScheduleExcelColumn($middleColumnIndex);
    $rightStart = procurementAccountScheduleExcelColumn($middleColumnIndex + 2);
    $sheet->mergeCells("A{$signatureStart}:{$middleColumn}{$signatureStart}");
    $sheet->mergeCells("{$rightStart}{$signatureStart}:{$lastColumn}{$signatureStart}");
    $sheet->setCellValue("A{$signatureStart}", 'Prepared / Submitted by Procurement: ______________________________');
    $sheet->setCellValue("{$rightStart}{$signatureStart}", 'Received by Account: ______________________________');
    $sheet->mergeCells('A' . ($signatureStart + 1) . ':' . $middleColumn . ($signatureStart + 1));
    $sheet->mergeCells($rightStart . ($signatureStart + 1) . ':' . $lastColumn . ($signatureStart + 1));
    $sheet->setCellValue('A' . ($signatureStart + 1), 'Date: ____________________');
    $sheet->setCellValue($rightStart . ($signatureStart + 1), 'Date: ____________________');
    $sheet->getStyle("A{$signatureStart}:{$lastColumn}" . ($signatureStart + 1))->applyFromArray([
        'font' => ['size' => 8.5, 'color' => ['rgb' => PROCUREMENT_ACCOUNT_SCHEDULE_MUTED]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    foreach ($definition['widths'] as $index => $width) {
        $sheet->getColumnDimension(procurementAccountScheduleExcelColumn($index + 1))->setWidth($width);
    }

    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A6:{$lastColumn}6");
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0)
        ->setRowsToRepeatAtTopByStartAndEnd(1, 6)
        ->setPrintArea("A1:{$lastColumn}" . ($signatureStart + 1));
    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.45)->setLeft(0.25)->setRight(0.25);
    $sheet->getHeaderFooter()->setOddFooter('&LProcureDesk Account Submission Schedule&CPage &P of &N&R' . date('d M Y H:i'));
}

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    $user = procurementRequireAnyPermission($conn, procurementSupplierActivityReportPermissionCodes());
    $visibleScopes = procurementAccountSubmissionScheduleVisibleScopes($user);
    if ($visibleScopes === []) {
        throw new RuntimeException('You are not permitted to export procurement schedules.', 403);
    }

    $reportQuery = $_GET;
    $reportQuery['page'] = 1;
    $reportQuery['limit'] = 1;
    $reportQuery['include_options'] = 1;
    $report = procurementAccountSubmissionSchedule($conn, $user, $reportQuery);
    $total = (int) ($report['meta']['total'] ?? 0);
    if ($total > PROCUREMENT_ACCOUNT_SCHEDULE_MAX_EXPORT_ROWS) {
        throw new RuntimeException(
            'The filtered schedule contains more than ' . number_format(PROCUREMENT_ACCOUNT_SCHEDULE_MAX_EXPORT_ROWS) . ' rows. Narrow the filters before exporting.',
            422
        );
    }

    $filters = procurementAccountSubmissionSchedulePrepareFilters($_GET, $visibleScopes);
    $base = procurementAccountSubmissionScheduleBaseSql($filters['request_types']);
    $filtered = procurementAccountSubmissionScheduleBuildWhere($filters);
    $types = $base['types'] . $filtered['types'];
    $params = array_merge($base['params'], $filtered['params']);
    $derived = '(' . $base['sql'] . ') schedule';
    $rows = procurementSupplierActivityReportFetchAll(
        $conn,
        "SELECT schedule.*
         FROM {$derived}
         WHERE {$filtered['sql']}
         ORDER BY schedule.sent_to_account_date ASC, schedule.scope_label ASC, schedule.po_number ASC, schedule.canonical_id ASC
         LIMIT ?",
        $types . 'i',
        array_merge($params, [PROCUREMENT_ACCOUNT_SCHEDULE_MAX_EXPORT_ROWS])
    );
    $rows = array_map('procurementAccountSubmissionScheduleSerializeRow', $rows);

    if (!class_exists(Spreadsheet::class)) {
        throw new RuntimeException('Account Submission Schedule Excel export requires PhpSpreadsheet.', 500);
    }

    $definitions = procurementAccountScheduleExcelScopeDefinitions();
    $rowsByScope = [
        'local_final' => [],
        'local_advance' => [],
        'fx_final' => [],
        'fx_advance' => [],
    ];
    foreach ($rows as $row) {
        $scopeKey = match ((string) ($row['request_type'] ?? '')) {
            'local_final_purchase' => 'local_final',
            'local_advance_purchase' => 'local_advance',
            'fx_final_purchase' => 'fx_final',
            'fx_advance_purchase' => 'fx_advance',
            default => null,
        };
        if ($scopeKey !== null) {
            $rowsByScope[$scopeKey][] = $row;
        }
    }

    $selectedScopeKeys = (array) ($filters['selected_scopes'] ?? []);
    $orderedScopes = ['fx_advance', 'fx_final', 'local_advance', 'local_final'];
    $exportScopes = array_values(array_filter(
        $orderedScopes,
        static fn(string $scope): bool => in_array($scope, $selectedScopeKeys, true)
    ));
    if ($exportScopes === []) {
        throw new RuntimeException('No permitted schedule scope is available for export.', 403);
    }

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('ProcureDesk')
        ->setCompany('Lambert Electromec Limited')
        ->setTitle('Account Submission Schedule')
        ->setSubject((string) ($report['meta']['date_from'] ?? '') . ' to ' . (string) ($report['meta']['date_to'] ?? ''))
        ->setDescription('Print-ready procurement schedules for purchases sent to Account.');

    foreach ($exportScopes as $index => $scope) {
        procurementAccountScheduleExcelBuildSheet(
            $spreadsheet,
            $scope,
            $definitions[$scope],
            $rowsByScope[$scope] ?? [],
            $report,
            $user,
            $index === 0
        );
    }

    $spreadsheet->setActiveSheetIndex(0);
    $dateFrom = (string) ($report['meta']['date_from'] ?? '');
    $dateTo = (string) ($report['meta']['date_to'] ?? '');
    $scopeSlug = (string) ($filters['scope'] ?? 'all');
    $scopeSlug = $scopeSlug === 'all' ? 'All' : preg_replace('/[^A-Za-z0-9]+/', '-', $scopeSlug);
    $filename = 'ProcureDesk-Account-Submission-Schedule-' . $scopeSlug . '-' . $dateFrom . '-to-' . $dateTo . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('ProcureDesk Account Submission Schedule Excel export error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to export the Account Submission Schedule.' : $error->getMessage(),
    ], $status);
}
