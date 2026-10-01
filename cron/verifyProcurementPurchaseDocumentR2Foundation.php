<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/procurementDocumentStorageService.php';

$checks = [];
try {
    procurementDocumentAssertStorageReady($conn);
    $checks['storage_ready'] = true;
} catch (Throwable $error) {
    $checks['storage_ready'] = false;
    $checks['storage_error'] = $error->getMessage();
}

try {
    $settings = procurementDocumentSettings($conn);
    $checks['default_or_saved_settings_valid'] =
        (int) ($settings['max_file_size_bytes'] ?? 0) > 0
        && array_diff($settings['allowed_extensions'] ?? [], PROCUREMENT_DOCUMENT_SUPPORTED_EXTENSIONS) === []
        && ($settings['allowed_extensions'] ?? []) !== [];
    $checks['configured_max_mb'] = $settings['max_file_size_mb'] ?? null;
    $checks['configured_extensions'] = $settings['allowed_extensions'] ?? [];
} catch (Throwable $error) {
    $checks['default_or_saved_settings_valid'] = false;
    $checks['settings_error'] = $error->getMessage();
}

$permissions = ['documents.view', 'documents.manage', 'documents.configure'];
$stmt = $conn->prepare(
    "SELECT code FROM procurement_permissions WHERE code IN (?,?,?) AND is_active = 1"
);
$stmt->bind_param('sss', ...$permissions);
$stmt->execute();
$found = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'code');
$stmt->close();
$checks['permissions_seeded'] = count(array_intersect($permissions, $found)) === count($permissions);

$checks['r2_credentials_present'] = procurementDocumentR2Configured();
if ($checks['r2_credentials_present']) {
    try {
        $signed = procurementDocumentPresignR2(
            'PUT',
            'procurement/verification/no-upload.txt',
            60,
            'text/plain'
        );
        $checks['presigner_healthy'] =
            str_starts_with((string) ($signed['url'] ?? ''), 'https://')
            && str_contains((string) ($signed['url'] ?? ''), 'X-Amz-Signature=')
            && (($signed['required_headers']['Content-Type'] ?? null) === 'text/plain');
    } catch (Throwable $error) {
        $checks['presigner_healthy'] = false;
        $checks['presigner_error'] = $error->getMessage();
    }
} else {
    $checks['presigner_healthy'] = null;
}

$healthy = !in_array(false, array_filter(
    $checks,
    static fn(mixed $value, string $key): bool => !in_array($key, ['r2_credentials_present', 'presigner_healthy'], true),
    ARRAY_FILTER_USE_BOTH
), true)
    && ($checks['r2_credentials_present'] ? $checks['presigner_healthy'] === true : true);

// Missing credentials are reported clearly but do not make schema verification fail;
// deployments may run this immediately after migration and before .env is populated.
$checks['healthy'] = $healthy;

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($healthy ? 0 : 1);
