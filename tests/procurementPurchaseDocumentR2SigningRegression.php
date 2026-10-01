<?php

declare(strict_types=1);

putenv('R2_ACCOUNT_ID=1234567890abcdef');
putenv('R2_ACCESS_KEY_ID=TESTACCESS123');
putenv('R2_SECRET_ACCESS_KEY=TESTSECRET456789');
putenv('R2_BUCKET=test-bucket');
putenv('R2_ENDPOINT=https://1234567890abcdef.r2.cloudflarestorage.com');
putenv('R2_REGION=auto');

require_once dirname(__DIR__) . '/includes/procurementDocumentStorageService.php';

$result = procurementDocumentPresignR2(
    'PUT',
    'procurement/2026/test space/file.pdf',
    600,
    'application/pdf',
    new DateTimeImmutable('2026-09-04T15:00:00Z')
);

$expected = 'https://1234567890abcdef.r2.cloudflarestorage.com/test-bucket/procurement/2026/test%20space/file.pdf'
    . '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
    . '&X-Amz-Credential=TESTACCESS123%2F20260904%2Fauto%2Fs3%2Faws4_request'
    . '&X-Amz-Date=20260904T150000Z'
    . '&X-Amz-Expires=600'
    . '&X-Amz-SignedHeaders=content-type%3Bhost'
    . '&X-Amz-Signature=a541cac10e13faf732c82bd38a8280ee06d20dfe9859aa95582c13c9311d2aca';

$checks = [
    'signature_matches_aws_s3_sigv4_query_vector' => ($result['url'] ?? '') === $expected,
    'content_type_is_bound_to_upload_signature' => ($result['required_headers']['Content-Type'] ?? '') === 'application/pdf',
    'short_lived_expiry_is_returned' => (int) ($result['expires_in'] ?? 0) === 600,
];
$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
