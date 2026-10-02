<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierWhtReportService.php';
require_once 'includes/accountSupplierWhtReportExcelService.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

date_default_timezone_set('Africa/Lagos');

try {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $writeConn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $report = accountSupplierWhtExportReport($writeConn, $_GET);
    $workbook = accountSupplierWhtExcelBuildWorkbook($report);
    $fileName = accountSupplierWhtExcelFileName($report);

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($workbook);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');
    $workbook->disconnectWorksheets();
    exit;
} catch (Throwable $error) {
    error_log('Supplier WHT Excel export error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
