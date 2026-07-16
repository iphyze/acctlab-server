<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    $receipt = cashFetchReceipt($conn, cashReceiptId(), (int) $account['id']);

    $backendRoot = dirname(__DIR__, 2);
    $storageRoot = realpath($backendRoot . '/storage/cash-receipts');
    $filePath = realpath($backendRoot . '/' . ltrim((string) $receipt['storage_path'], '/'));
    if ($storageRoot === false || $filePath === false || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        throw new RuntimeException('The receipt file is unavailable.', 404);
    }

    $downloadName = cashReceiptPublicName((string) $receipt['original_filename']);
    header('Content-Type: ' . $receipt['mime_type']);
    header('Content-Length: ' . filesize($filePath));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($filePath);
    exit;
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to download the receipt.');
}
