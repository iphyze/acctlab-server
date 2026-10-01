<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/connection.php';
require_once dirname(__DIR__) . '/includes/procurementDocumentStorageService.php';
require_once dirname(__DIR__) . '/includes/accountDocumentStorageService.php';

try {
    $limit = isset($argv[1]) ? (int) $argv[1] : 100;
    $limit = max(1, min(500, $limit));

    $procurement = procurementDocumentCleanupExpiredUploads($conn, $limit);
    $account = accountDocumentCleanupExpiredUploads($conn, $limit);

    echo json_encode([
        'status' => 'Success',
        'limit_per_document_store' => $limit,
        'procurement_documents' => $procurement,
        'account_documents' => $account,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Unified document upload cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
