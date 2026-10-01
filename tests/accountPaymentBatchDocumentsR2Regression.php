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

$service = $read('includes/accountDocumentStorageService.php');
$listRoute = $read('routes/request/account-documents/index.php');
$accessRoute = $read('routes/request/account-documents/accessUrl.php');
$migration = $read('database/20260905_account_documents_r2_upload_foundation.sql');

$checks = [];
$checks['existing_generic_account_documents_table_reused'] = str_contains($migration, 'entity_type VARCHAR(60)')
    && str_contains($migration, 'entity_id BIGINT UNSIGNED')
    && !str_contains($migration, 'payment_batch_documents');
$checks['canonical_payment_batch_owner_supported'] = str_contains($service, "ACCOUNT_DOCUMENT_ENTITY_PAYMENT_BATCH = 'payment_batch'")
    && str_contains($service, 'FROM account_payment_batches WHERE id = ? LIMIT 1');
$checks['fund_request_owner_compatibility_preserved'] = str_contains($service, 'accountProcurementDocumentNormalizeAccountRequestType')
    && str_contains($service, 'accountProcurementDocumentAssertAccountRequestExists');
$checks['generic_owner_api_supported'] = str_contains($listRoute, "\$_GET['entity_type']")
    && str_contains($listRoute, "\$_GET['entity_id']")
    && str_contains($accessRoute, "\$_GET['entity_type']")
    && str_contains($accessRoute, "\$_GET['entity_id']");
$checks['legacy_request_api_aliases_preserved'] = str_contains($listRoute, "\$_GET['account_request_type']")
    && str_contains($listRoute, "\$_GET['account_request_id']")
    && str_contains($service, "\$payload['account_request_type']")
    && str_contains($service, "\$payload['account_request_id']");
$checks['payment_batch_storage_key_isolated'] = str_contains($service, "'acctlab/%s/%s/%d/%s/v%d/%s.%s'")
    && str_contains($service, 'accountDocumentBuildStorageKey');
$checks['r2_versioning_and_missing_object_guards_preserved'] = str_contains($service, "status = 'SUPERSEDED'")
    && str_contains($service, 'accountDocumentVerifyStoredObject')
    && str_contains($service, "procurementDocumentPresignR2('PUT'")
    && str_contains($service, "procurementDocumentPresignR2('GET'");
$checks['no_new_database_table_required'] = substr_count($migration, 'CREATE TABLE IF NOT EXISTS account_documents') === 1
    && !str_contains($migration, 'ALTER TABLE account_documents');

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) {
    echo sprintf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
}
if ($failed !== []) {
    fwrite(STDERR, 'Payment batch Account-document regression failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Payment batch Account-document R2 regression passed.' . PHP_EOL;
