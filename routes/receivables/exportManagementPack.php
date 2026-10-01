<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/accountReceivablesReportService.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
}

try {
    $actor = requireAdmin();
    $query = [
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'project_name' => $_GET['project_name'] ?? null,
        'client_name' => $_GET['client_name'] ?? null,
    ];
    $bundle = accountReceivablesReportBundle($conn, $actor, $query);
    if (($bundle['rows'] ?? []) === []) {
        throw new RuntimeException('No Invoice Register entries match the selected report filters.', 422);
    }

    $spreadsheet = accountReceivablesBuildManagementWorkbook($bundle);
    $reportingDate = preg_replace('/[^0-9-]/', '', (string) ($bundle['settings']['reporting_date'] ?? date('Y-m-d')));
    $period = $bundle['period'] ?? [];
    $dateFrom = preg_replace('/[^0-9-]/', '', (string) ($period['date_from'] ?? ''));
    $dateTo = preg_replace('/[^0-9-]/', '', (string) ($period['date_to'] ?? ''));
    $projectName = trim((string) ($period['project_name'] ?? ''));
    $clientName = trim((string) ($period['client_name'] ?? ''));
    $filenameParts = [];
    foreach ([['project', $projectName], ['client', $clientName]] as [$prefix, $value]) {
        if ($value === '') {
            continue;
        }
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-');
        if ($slug !== '') {
            $filenameParts[] = $prefix . '-' . substr($slug, 0, 48);
        }
    }
    if ($dateFrom !== '' || $dateTo !== '') {
        $filenameParts[] = ($dateFrom !== '' ? $dateFrom : 'beginning') . '-to-' . ($dateTo !== '' ? $dateTo : 'latest');
    }
    if ($filenameParts === []) {
        $filenameParts[] = $reportingDate ?: date('Y-m-d');
    }
    $filename = 'Lambert-Receivables-Management-Pack-' . implode('-', $filenameParts) . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->setIncludeCharts(true);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
} catch (Throwable $error) {
    if (!headers_sent()) {
        $status = (int) $error->getCode();
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        jsonResponse([
            'status' => 'Failed',
            'message' => $status >= 500 ? 'Unable to generate the Receivables management workbook.' : $error->getMessage(),
        ], $status);
    }
    throw $error;
}
