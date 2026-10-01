<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountScumlReportService.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 404);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];
    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Unauthorized: Only Admins can access this resource', 401);
    }

    [$periodFrom, $periodTo] = accountScumlResolvePeriod($_GET);
    $data = accountScumlFetchData($conn, $periodFrom, $periodTo);
    $spreadsheet = accountBuildScumlWorkbook($data, $periodFrom, $periodTo);

    $fileName = sprintf('SCUML-Report-%s-to-%s.xlsx', $periodFrom, $periodTo);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
} catch (Throwable $e) {
    error_log('SCUML export error: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code($e->getCode() ?: 500);
    }
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
