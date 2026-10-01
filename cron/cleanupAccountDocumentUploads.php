<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/connection.php';
require_once dirname(__DIR__) . '/includes/accountDocumentStorageService.php';

try {
    $limit = isset($argv[1]) ? (int) $argv[1] : 100;
    $result = accountDocumentCleanupExpiredUploads($conn, $limit);
    echo json_encode(['status' => 'Success', 'data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Account document upload cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
