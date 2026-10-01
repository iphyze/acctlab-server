<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$storage = $read('includes/procurementDocumentStorageService.php');
$account = $read('includes/accountProcurementDocumentService.php');
$cleanup = $read('cron/cleanupProcurementDocumentUploads.php');
$verifier = $read('cron/verifyProcurementPurchaseDocumentsFinalQa.php');
$migration = $read('database/20260904_procurement_purchase_documents_r2_foundation.sql');
$procuredeskAccess = $read('routes/procurement/documents/accessUrl.php');
$accountAccess = $read('routes/request/procurement-documents/accessUrl.php');

$checks = [
    'access_verifies_r2_object_before_issuing_signed_get' =>
        str_contains($storage, 'function procurementDocumentPrepareAccessUrl')
        && str_contains($storage, 'procurementDocumentVerifyStoredObject($document);')
        && str_contains($storage, "procurementDocumentPresignR2('GET'")
        && strpos($storage, 'procurementDocumentVerifyStoredObject($document);') < strpos($storage, "procurementDocumentPresignR2('GET'"),
    'missing_r2_object_returns_actionable_gone_status' =>
        str_contains($storage, 'missing from Cloudflare R2')
        && str_contains($storage, 'procurementDocumentR2Head($storageKey, 410)')
        && str_contains($storage, 'Upload a replacement version to restore access.'),
    'procuredesk_and_acctlab_share_the_hardened_read_path' =>
        str_contains($storage, 'return procurementDocumentPrepareAccessUrl($document, $mode);')
        && str_contains($account, 'return procurementDocumentPrepareAccessUrl($document, $mode);')
        && str_contains($procuredeskAccess, 'procurementDocumentAccessUrl')
        && str_contains($accountAccess, 'accountProcurementDocumentAccessUrl'),
    'failed_presign_and_expired_uploads_are_recoverable' =>
        str_contains($storage, 'procurementDocumentMarkUploadFailed($conn, $documentId);')
        && str_contains($storage, 'function procurementDocumentCleanupExpiredUploads')
        && str_contains($storage, "status = 'PENDING_UPLOAD'")
        && str_contains($storage, 'upload_expires_at < UTC_TIMESTAMP()')
        && str_contains($cleanup, 'procurementDocumentCleanupExpiredUploads'),
    'cleanup_uses_existing_index_and_no_new_schema_is_needed' =>
        str_contains($migration, 'idx_procurement_document_expiry (status, upload_expires_at)')
        && str_contains($verifier, "'idx_procurement_document_expiry'")
        && !str_contains($cleanup, 'ALTER TABLE')
        && !str_contains($verifier, 'ALTER TABLE'),
    'history_payload_excludes_failed_upload_noise' =>
        str_contains($storage, "d.status IN ('ACTIVE','SUPERSEDED')")
        && !str_contains($storage, "d.status IN ('ACTIVE','SUPERSEDED','FAILED')"),
    'acctlab_source_resolution_is_single_query_and_manual_safe' =>
        str_contains($account, 'FROM {$table} a')
        && str_contains($account, 'LEFT JOIN procurement_request_handoffs h')
        && str_contains($account, 'LEFT JOIN procurement_requests r')
        && str_contains($account, "throw new RuntimeException('Account fund request not found.', 404)")
        && str_contains($account, "if (empty(\$row['procurement_request_id']))"),
    'final_qa_verifier_checks_integrity_and_performance_indexes' =>
        str_contains($verifier, 'one_active_version_per_document_group')
        && str_contains($verifier, 'replacement_history_consistent')
        && str_contains($verifier, 'canonical_request_links_valid')
        && str_contains($verifier, 'report_and_cleanup_indexes_present')
        && str_contains($verifier, 'PROCUREMENT_DOCUMENT_VERIFY_REMOTE_ID'),
    'document_functionality_stays_out_of_payment_and_approval_logic' =>
        !str_contains($storage, 'UPDATE supplier_fund_request_table')
        && !str_contains($storage, 'UPDATE advance_payment_request')
        && !str_contains($storage, 'payment_status =')
        && !str_contains($account, 'UPDATE procurement_requests')
        && !str_contains($account, 'approval_status ='),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
