<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountProcurementDocumentService.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    requireAdmin();
    $accountRequestType = (string) ($_GET['account_request_type'] ?? '');
    $accountRequestId = (int) ($_GET['account_request_id'] ?? 0);
    $data = accountProcurementDocumentList($conn, $accountRequestType, $accountRequestId);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => $data['linked']
            ? 'ProcureDesk supporting documents fetched successfully.'
            : 'This Account request has no ProcureDesk source purchase.',
        'data' => $data,
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Account procurement documents error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load ProcureDesk supporting documents.' : $error->getMessage(),
    ]);
}
