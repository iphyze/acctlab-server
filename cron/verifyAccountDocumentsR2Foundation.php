<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/connection.php';
require_once dirname(__DIR__) . '/includes/accountDocumentStorageService.php';

try {
    accountDocumentAssertStorageReady($conn);
    $settings = accountDocumentSettings($conn);
    $indexes = [];
    $result = $conn->query(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_documents'"
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $indexes[] = (string) $row['INDEX_NAME'];
    }
    $required = [
        'idx_account_document_entity_status',
        'idx_account_document_group_status',
        'idx_account_document_expiry',
    ];
    $missing = array_values(array_diff($required, array_unique($indexes)));
    if ($missing !== []) {
        throw new RuntimeException('Missing Account document indexes: ' . implode(', ', $missing));
    }

    echo json_encode([
        'status' => 'Success',
        'table' => 'account_documents',
        'shared_max_file_size_mb' => $settings['max_file_size_mb'],
        'shared_allowed_extensions' => $settings['allowed_extensions'],
        'account_document_types' => $settings['document_types'],
        'r2_storage_configured' => $settings['storage_configured'],
        'required_indexes' => $required,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Account document R2 verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
