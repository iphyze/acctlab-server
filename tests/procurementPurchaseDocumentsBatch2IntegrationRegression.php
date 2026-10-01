<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$canonical = file_get_contents($root . '/includes/procurementRequestCanonicalReadService.php');
$documents = file_get_contents($root . '/includes/procurementDocumentStorageService.php');
$listRoute = file_get_contents($root . '/routes/procurement/documents/index.php');
$settingsRoute = file_get_contents($root . '/routes/procurement/documents/settings.php');

$checks = [
    'local_canonical_reads_expose_document_anchor' =>
        str_contains($canonical, "\$row['procurement_request_id'] = isset(\$row['canonical_request_id'])")
        && str_contains($canonical, "? (int) \$row['canonical_request_id']"),
    'legacy_public_id_is_preserved_separately' =>
        str_contains($canonical, "\$row['id'] = isset(\$row['legacy_id']) ? (int) \$row['legacy_id'] : null;"),
    'document_api_remains_canonical_request_based' =>
        str_contains($documents, 'procurement_request_id')
        && str_contains($documents, 'procurementDocumentFetchPurchase')
        && str_contains($listRoute, "\$_GET['procurement_request_id']"),
    'settings_remain_super_admin_controlled' =>
        str_contains($settingsRoute, 'procurementRequireSuperAdmin($conn)')
        && str_contains($documents, 'max_file_size_mb')
        && str_contains($documents, 'allowed_extensions'),
    'versioned_replacement_remains_auditable' =>
        str_contains($documents, "status = 'SUPERSEDED'")
        && str_contains($documents, "'document_replaced'")
        && str_contains($documents, 'supersedes_document_id'),
    'r2_object_verification_remains_server_side' =>
        str_contains($documents, 'procurementDocumentR2Head')
        && str_contains($documents, 'The uploaded file did not match the approved size/type.'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
