<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountScumlReportService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];
    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Unauthorized: Only Admins can access this resource', 401);
    }

    [$periodFrom, $periodTo] = accountScumlResolvePeriod($_GET);
    $responseData = accountScumlFetchData($conn, $periodFrom, $periodTo);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Payments fetched successfully by period',
        'meta' => [
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
        ],
        'data' => $responseData,
    ]);
} catch (Throwable $e) {
    error_log('SCUML report error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
