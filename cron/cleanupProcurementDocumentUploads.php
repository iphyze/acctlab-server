<?php

declare(strict_types=1);

/**
 * Marks expired two-phase procurement document uploads as FAILED and makes a
 * best-effort attempt to remove any orphan object that reached R2.
 *
 * Safe to run repeatedly. The indexed (status, upload_expires_at) lookup keeps
 * the cleanup bounded; override the batch size with PROCUREMENT_DOCUMENT_CLEANUP_LIMIT.
 */

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/procurementDocumentStorageService.php';

try {
    $limit = max(1, min(500, (int) (getenv('PROCUREMENT_DOCUMENT_CLEANUP_LIMIT') ?: 100)));
    $result = procurementDocumentCleanupExpiredUploads($conn, $limit);
    $result['healthy'] = true;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    error_log('Procurement document upload cleanup failed: ' . $error->getMessage());
    echo json_encode([
        'healthy' => false,
        'message' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
