<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$index = $read('index.php');
$service = $read('includes/procurementDocumentStorageService.php');
$migration = $read('database/20260904_procurement_purchase_documents_r2_foundation.sql');
$auth = $read('includes/procurementAuthService.php');
$listRoute = $read('routes/procurement/documents/index.php');
$intentRoute = $read('routes/procurement/documents/uploadIntent.php');
$completeRoute = $read('routes/procurement/documents/completeUpload.php');
$accessRoute = $read('routes/procurement/documents/accessUrl.php');
$settingsRoute = $read('routes/procurement/documents/settings.php');
$envExample = $read('config/procurement-document-r2.env.example');
$corsExample = $read('config/procurement-document-r2-cors.example.json');

$checks = [
    'document_routes_registered' =>
        str_contains($index, "'/procurement/documents' => 'routes/procurement/documents/index.php'")
        && str_contains($index, "'/procurement/documents/upload-intent' => 'routes/procurement/documents/uploadIntent.php'")
        && str_contains($index, "'/procurement/documents/complete-upload' => 'routes/procurement/documents/completeUpload.php'")
        && str_contains($index, "'/procurement/documents/access-url' => 'routes/procurement/documents/accessUrl.php'")
        && str_contains($index, "'/procurement/documents/settings' => 'routes/procurement/documents/settings.php'"),
    'metadata_and_settings_storage_added' =>
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS procurement_purchase_documents')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS procurement_document_settings')
        && str_contains($migration, 'procurement_request_id BIGINT UNSIGNED NOT NULL')
        && str_contains($migration, 'document_group_uuid CHAR(36) NOT NULL')
        && str_contains($migration, 'version_number INT UNSIGNED NOT NULL DEFAULT 1')
        && str_contains($migration, 'supersedes_document_id BIGINT UNSIGNED NULL')
        && str_contains($migration, 'storage_key VARCHAR(512) NOT NULL'),
    'default_policy_is_10mb_and_requested_formats' =>
        str_contains($migration, '10485760')
        && str_contains($migration, '["pdf","jpg","jpeg","png","xls","xlsx","doc","docx","csv"]')
        && str_contains($service, "'pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'doc', 'docx', 'csv'"),
    'super_admin_configuration_is_server_side' =>
        str_contains($settingsRoute, 'procurementRequireSuperAdmin')
        && str_contains($service, 'Maximum file size must be between 1 MB and 100 MB.')
        && str_contains($service, 'procurementDocumentNormalizeExtensions'),
    'document_permissions_are_seeded_for_fresh_and_existing_databases' =>
        str_contains($migration, "'documents.view'")
        && str_contains($migration, "'documents.manage'")
        && str_contains($migration, "'documents.configure'")
        && str_contains($auth, "['documents.view', 'View purchase documents'")
        && str_contains($auth, "'documents.view', 'documents.manage'"),
    'all_four_purchase_types_are_canonical_targets' =>
        str_contains($service, "request_type IN ('local_final_purchase','local_advance_purchase','fx_final_purchase','fx_advance_purchase')")
        && str_contains($service, "'local_final_purchase' => 'payments.local_final.view'")
        && str_contains($service, "'local_advance_purchase' => 'payments.local_advance.view'")
        && str_contains($service, "'fx_final_purchase' => 'payments.fx_final.view'")
        && str_contains($service, "'fx_advance_purchase' => 'payments.fx_advance.view'"),
    'private_direct_r2_upload_uses_short_lived_content_type_bound_presigned_put' =>
        (bool) preg_match("/procurementDocumentPresignR2\\(\\s*'PUT'/", $service)
        && str_contains($service, "'Content-Type' => strtolower(trim(\$contentType))")
        && str_contains($service, "'X-Amz-Expires'")
        && str_contains($service, "'UNSIGNED-PAYLOAD'")
        && str_contains($service, 'R2_UPLOAD_URL_TTL_SECONDS'),
    'credentials_stay_on_backend_env' =>
        str_contains($envExample, 'R2_ACCOUNT_ID=')
        && str_contains($envExample, 'R2_ACCESS_KEY_ID=')
        && str_contains($envExample, 'R2_SECRET_ACCESS_KEY=')
        && str_contains($envExample, 'R2_BUCKET=')
        && !str_contains($index, 'R2_SECRET_ACCESS_KEY'),
    'browser_r2_cors_template_supports_direct_upload_and_read' =>
        str_contains($corsExample, '"http://localhost:5174"')
        && str_contains($corsExample, '"PUT"')
        && str_contains($corsExample, '"GET"')
        && str_contains($corsExample, '"HEAD"')
        && str_contains($corsExample, '"Content-Type"')
        && str_contains($corsExample, '"ETag"'),
    'upload_is_two_phase_and_r2_object_is_verified_before_activation' =>
        str_contains($intentRoute, 'procurementDocumentCreateUploadIntent')
        && str_contains($completeRoute, 'procurementDocumentCompleteUpload')
        && str_contains($service, 'procurementDocumentR2Head')
        && str_contains($service, "status = 'ACTIVE'")
        && str_contains($service, 'uploaded file did not match the approved size/type'),
    'replacement_is_versioned_not_overwritten' =>
        str_contains($service, 'replace_document_id')
        && str_contains($service, 'document_group_uuid')
        && str_contains($service, 'supersedes_document_id')
        && str_contains($service, "status = 'SUPERSEDED'")
        && str_contains($service, "'document_replaced'"),
    'preview_and_download_are_private_short_lived_get_urls' =>
        str_contains($accessRoute, 'procurementDocumentAccessUrl')
        && str_contains($service, "in_array(\$mode, ['preview', 'download'], true)")
        && str_contains($service, "procurementDocumentPresignR2('GET'")
        && str_contains($service, 'R2_ACCESS_URL_TTL_SECONDS'),
    'office_files_are_download_only_and_native_formats_can_preview' =>
        str_contains($service, "return ['pdf', 'jpg', 'jpeg', 'png', 'csv'];")
        && str_contains($service, 'This document format is download-only in the secure viewer.'),
    'writes_are_csrf_and_permission_protected' =>
        str_contains($intentRoute, 'procurementRequireCsrfToken')
        && str_contains($intentRoute, "procurementRequirePermission(\$conn, 'documents.manage')")
        && str_contains($completeRoute, 'procurementRequireCsrfToken')
        && str_contains($completeRoute, "procurementRequirePermission(\$conn, 'documents.manage')"),
    'reads_are_permission_protected' =>
        str_contains($listRoute, "procurementRequirePermission(\$conn, 'documents.view')")
        && str_contains($accessRoute, "procurementRequirePermission(\$conn, 'documents.view')"),
    'document_completion_is_audited_in_canonical_workflow_events' =>
        str_contains($service, 'workflowEventRecordRequest')
        && str_contains($service, "'document_uploaded'")
        && str_contains($service, "'document_replaced'"),
    'no_purchase_or_payment_logic_is_changed_by_document_service' =>
        !str_contains($service, 'UPDATE procurement_requests SET payment_status')
        && !str_contains($service, 'supplier_fund_request_table')
        && !str_contains($service, 'advance_payment_request')
        && !str_contains($service, 'fx_fund_request_table')
        && !str_contains($service, 'compass_fund_request_table'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
