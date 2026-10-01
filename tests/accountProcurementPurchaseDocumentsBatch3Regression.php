<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$index = $read('index.php');
$service = $read('includes/accountProcurementDocumentService.php');
$listRoute = $read('routes/request/procurement-documents/index.php');
$accessRoute = $read('routes/request/procurement-documents/accessUrl.php');
$storage = $read('includes/procurementDocumentStorageService.php');
$cors = $read('config/procurement-document-r2-cors.example.json');

$checks = [
    'acctlab_read_only_document_routes_registered' =>
        str_contains($index, "'/request/procurement-documents' => 'routes/request/procurement-documents/index.php'")
        && str_contains($index, "'/request/procurement-documents/access-url' => 'routes/request/procurement-documents/accessUrl.php'"),
    'account_auth_is_used_not_procurement_auth' =>
        str_contains($listRoute, "require_once 'includes/authMiddleware.php'")
        && str_contains($listRoute, 'requireAdmin();')
        && str_contains($accessRoute, 'requireAdmin();')
        && !str_contains($listRoute, 'procurementRequirePermission')
        && !str_contains($accessRoute, 'procurementRequirePermission'),
    'all_procurement_backed_account_request_types_are_supported' =>
        str_contains($service, "'supplier_fund_request' => ['local_final_purchase']")
        && str_contains($service, "'advance_payment_request' => ['local_advance_purchase']")
        && str_contains($service, "'fx_fund_request' => ['fx_final_purchase', 'fx_advance_purchase']")
        && str_contains($service, "'compass_fund_request' => ['local_final_purchase', 'local_advance_purchase']"),
    'manual_account_requests_resolve_safely_without_fake_documents' =>
        str_contains($service, "'linked' => false")
        && str_contains($service, "'documents' => []")
        && str_contains($service, "throw new RuntimeException('Account fund request not found.', 404)"),
    'canonical_handoff_history_resolves_the_source_purchase' =>
        str_contains($service, 'LEFT JOIN procurement_request_handoffs h')
        && str_contains($service, 'LEFT JOIN procurement_requests r')
        && str_contains($service, 'h.account_request_type = ?')
        && str_contains($service, 'h.account_request_id = a.id')
        && str_contains($service, 'h.revision DESC'),
    'account_only_sees_current_active_document_versions' =>
        str_contains($service, 'procurementDocumentList($conn, (int) $purchase[\'procurement_request_id\'], false)')
        && str_contains($storage, "d.status = 'ACTIVE'"),
    'document_access_is_bound_to_the_resolved_account_purchase' =>
        str_contains($service, "(int) (\$document['procurement_request_id'] ?? 0) !== (int) \$purchase['procurement_request_id']")
        && str_contains($service, 'Active procurement document not found for this Account request.'),
    'preview_and_download_reuse_private_r2_signed_get_rules' =>
        str_contains($service, 'procurementDocumentPrepareAccessUrl($document, $mode)')
        && str_contains($storage, "in_array(\$mode, ['preview', 'download'], true)")
        && str_contains($storage, 'procurementDocumentPreviewableExtensions()')
        && str_contains($storage, "procurementDocumentPresignR2('GET'")
        && str_contains($storage, 'download-only in the secure viewer'),
    'acctlab_routes_are_strictly_read_only' =>
        !str_contains($listRoute, 'POST')
        && !str_contains($accessRoute, 'POST')
        && !str_contains($service, 'INSERT INTO procurement_purchase_documents')
        && !str_contains($service, 'UPDATE procurement_purchase_documents')
        && !str_contains($service, 'DELETE FROM procurement_purchase_documents'),
    'r2_cors_example_covers_both_frontend_origins_for_secure_downloads' =>
        str_contains($cors, '"http://localhost:5173"')
        && str_contains($cors, '"http://localhost:5174"')
        && str_contains($cors, 'your-acctlab-domain.example')
        && str_contains($cors, 'your-procuredesk-domain.example'),
    'payment_and_procurement_business_logic_are_not_mutated' =>
        !str_contains($service, 'UPDATE supplier_fund_request_table')
        && !str_contains($service, 'UPDATE advance_payment_request')
        && !str_contains($service, 'UPDATE fx_fund_request_table')
        && !str_contains($service, 'UPDATE compass_fund_request_table')
        && !str_contains($service, 'payment_status ='),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
