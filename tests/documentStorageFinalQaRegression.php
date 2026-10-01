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

$processing = $read('includes/accountPaymentProcessingService.php');
$verifier = $read('cron/verifyDocumentStorageFinalQa.php');
$cleanup = $read('cron/cleanupDocumentUploads.php');
$accountList = $read('routes/request/account-documents/index.php');
$accountUpload = $read('routes/request/account-documents/uploadIntent.php');
$accountAccess = $read('routes/request/account-documents/accessUrl.php');
$settings = $read('routes/procurement/documents/settings.php');

$checks = [];
$checks['payment_register_uses_preaggregated_active_document_counts'] =
    str_contains($processing, "WHERE entity_type = 'payment_batch' AND status = 'ACTIVE'")
    && str_contains($processing, 'GROUP BY entity_id')
    && str_contains($processing, 'COALESCE(batch_docs.active_document_count, 0) AS document_count');
$checks['payment_register_has_migration_safe_zero_fallback'] =
    str_contains($processing, "return ['join' => '', 'select' => '0 AS document_count']")
    && str_contains($processing, "information_schema.TABLES");
$checks['payment_batch_payload_exposes_document_indicator'] =
    str_contains($processing, "\$row['document_count'] = (int)")
    && str_contains($processing, "\$batch['document_count'] = (int)")
    && str_contains($processing, "\$row['has_documents'] = \$row['document_count'] > 0");
$checks['final_verifier_checks_storage_integrity_and_audit_metadata'] =
    str_contains($verifier, 'procurement_orphan_requests')
    && str_contains($verifier, 'account_orphan_payment_batches')
    && str_contains($verifier, 'single_active_version_per_group')
    && str_contains($verifier, 'superseded_audit_metadata_complete')
    && str_contains($verifier, 'no_expired_pending_uploads');
$checks['final_verifier_supports_real_r2_object_sampling'] =
    str_contains($verifier, "in_array('--r2'")
    && str_contains($verifier, 'procurementDocumentVerifyStoredObject')
    && str_contains($verifier, 'accountDocumentVerifyStoredObject');
$checks['unified_cleanup_covers_both_document_stores'] =
    str_contains($cleanup, 'procurementDocumentCleanupExpiredUploads')
    && str_contains($cleanup, 'accountDocumentCleanupExpiredUploads');
$checks['account_document_routes_remain_authenticated'] =
    str_contains($accountList, 'requireAdmin();')
    && str_contains($accountUpload, '$actor = requireAdmin();')
    && str_contains($accountAccess, 'requireAdmin();');
$checks['document_configuration_remains_super_admin_controlled'] =
    str_contains($settings, 'procurementRequireSuperAdmin')
    && str_contains($settings, 'procurementWriteAuditLog');
$checks['no_new_document_table_or_migration_required'] =
    !str_contains($processing, 'CREATE TABLE')
    && !str_contains($verifier, 'CREATE TABLE')
    && !str_contains($cleanup, 'CREATE TABLE');

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) {
    echo sprintf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
}
if ($failed !== []) {
    fwrite(STDERR, 'Document storage final QA regression failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Document storage final QA regression passed.' . PHP_EOL;
