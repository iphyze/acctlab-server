<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../includes/procurementDocumentStorageService.php');
if ($service === false) {
    fwrite(STDERR, "Unable to read procurement document storage service.\n");
    exit(1);
}

$checks = [
    'UTC parser exists' => str_contains($service, 'function procurementDocumentUtcSqlTimestamp'),
    'Expiry parser explicitly uses UTC' => str_contains($service, "new DateTimeZone('UTC')"),
    'Completion uses UTC expiry helper' => str_contains($service, "procurementDocumentUtcSqlTimestamp(\$document['upload_expires_at'] ?? null)"),
    'Legacy timezone-sensitive strtotime comparison removed' => !str_contains($service, "strtotime((string) \$document['upload_expires_at']) < time()"),
    'Upload expiry is still stored from UTC clock' => str_contains($service, "gmdate('Y-m-d H:i:s', time() + (int) \$config['upload_ttl'])"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed) {
    fwrite(STDERR, "Procurement document upload-expiry timezone regression failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Procurement document upload-expiry timezone regression passed.\n";
