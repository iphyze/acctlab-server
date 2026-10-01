<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/accountAdvancePaymentService.php';
require_once __DIR__ . '/../includes/accountSupplierPaymentService.php';
require_once __DIR__ . '/../includes/accountPaymentReminderService.php';

date_default_timezone_set('Africa/Lagos');

try {
    accountAdvanceEnsurePaymentStorage($conn);
    accountSupplierEnsurePaymentStorage($conn);
    $result = accountPaymentReminderBackfillOverdue($conn);
    echo json_encode(['status' => 'Success', 'data' => $result], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
