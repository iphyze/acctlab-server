<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Missing expected file: ' . $relative);
    }
    return (string) file_get_contents($path);
};

$checks = [];
$migration = $read('database/20260905_account_documents_r2_upload_foundation.sql');
$service = $read('includes/accountDocumentStorageService.php');
$router = $read('index.php');
$procurementStorage = $read('includes/procurementDocumentStorageService.php');
$cors = $read('config/procurement-document-r2-cors.example.json');

$checks['single_account_document_table'] = str_contains($migration, 'CREATE TABLE IF NOT EXISTS account_documents')
    && !str_contains($migration, 'CREATE TABLE IF NOT EXISTS account_document_settings');
$checks['generic_account_entity_anchor_is_future_ready'] = str_contains($migration, 'entity_type VARCHAR(60)')
    && str_contains($migration, 'entity_id BIGINT UNSIGNED')
    && str_contains($migration, 'idx_account_document_entity_status');
$checks['shared_super_admin_policy'] = str_contains($service, 'procurementDocumentSettings($conn)')
    && str_contains($service, "\$settings['document_types'] = ACCOUNT_DOCUMENT_TYPES");
$checks['requested_account_types_present'] = str_contains($service, "'PAYMENT_EVIDENCE'")
    && str_contains($service, "'PAYMENT_VOUCHER'")
    && str_contains($service, "'JOURNAL_VOUCHER'")
    && str_contains($service, "'BANK_ADVICE'")
    && str_contains($service, "'SUPPORTING_SCHEDULE'");
$checks['versioned_replacement'] = str_contains($migration, 'document_group_uuid')
    && str_contains($migration, 'version_number')
    && str_contains($migration, 'supersedes_document_id')
    && str_contains($service, "status = 'SUPERSEDED'");
$checks['all_four_account_request_types_reused'] = str_contains($service, 'accountProcurementDocumentNormalizeAccountRequestType')
    && str_contains($service, 'accountProcurementDocumentAssertAccountRequestExists');
$checks['private_r2_direct_upload'] = str_contains($service, "procurementDocumentPresignR2('PUT'")
    && str_contains($service, 'procurementDocumentR2Head')
    && str_contains($service, "procurementDocumentPresignR2('GET'");
$checks['utc_expiry_fix_preserved'] = str_contains($procurementStorage, 'procurementDocumentUtcSqlTimestamp')
    && str_contains($service, 'procurementDocumentUtcSqlTimestamp');
$checks['r2_missing_object_guard'] = str_contains($service, 'accountDocumentVerifyStoredObject')
    && str_contains($service, 'missing from Cloudflare R2');
$checks['routes_registered'] = str_contains($router, "'/request/account-documents'")
    && str_contains($router, "'/request/account-documents/upload-intent'")
    && str_contains($router, "'/request/account-documents/complete-upload'")
    && str_contains($router, "'/request/account-documents/access-url'");
$checks['acctlab_cors_put_ready'] = str_contains($cors, 'http://localhost:5173')
    && str_contains($cors, '"PUT"');
$checks['no_procurement_workflow_mutation'] = !str_contains($migration, 'ALTER TABLE procurement_requests')
    && !str_contains($migration, 'ALTER TABLE procurement_request_handoffs');

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) {
    echo sprintf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
}
if ($failed !== []) {
    fwrite(STDERR, 'Account document R2 foundation regression failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Account document R2 upload foundation regression passed.' . PHP_EOL;
