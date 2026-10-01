<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementDocumentStorageService.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }
    procurementEnsureAuthenticationTables($conn);
    procurementDocumentAssertStorageReady($conn);
    $actor = procurementRequirePermission($conn, 'documents.view');
    $requestId = (int) ($_GET['procurement_request_id'] ?? 0);
    $includeHistory = filter_var($_GET['include_history'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $purchase = procurementDocumentFetchPurchase($conn, $requestId);
    procurementDocumentAssertPurchasePermission($actor, (string) $purchase['request_type']);
    $data = procurementDocumentList($conn, $requestId, $includeHistory);
    $data['settings'] = procurementDocumentSettings($conn);
    jsonResponse(['status' => 'Success', 'data' => $data]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement document list error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load procurement documents.' : $error->getMessage(),
    ], $status);
}
