<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementAccountSubmissionScheduleService.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    $user = procurementRequireAnyPermission(
        $conn,
        procurementSupplierActivityReportPermissionCodes()
    );

    jsonResponse(procurementAccountSubmissionSchedule($conn, $user, $_GET));
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('ProcureDesk Account Submission Schedule error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to load the Account Submission Schedule.' : $error->getMessage(),
    ], $status);
}
