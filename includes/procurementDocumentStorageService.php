<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';

const PROCUREMENT_DOCUMENT_STATUS_PENDING = 'PENDING_UPLOAD';
const PROCUREMENT_DOCUMENT_STATUS_ACTIVE = 'ACTIVE';
const PROCUREMENT_DOCUMENT_STATUS_SUPERSEDED = 'SUPERSEDED';
const PROCUREMENT_DOCUMENT_STATUS_FAILED = 'FAILED';
const PROCUREMENT_DOCUMENT_STATUS_DELETED = 'DELETED';

const PROCUREMENT_DOCUMENT_TYPES = [
    'PURCHASE_ORDER',
    'INVOICE',
    'GRN',
    'QUOTATION',
    'PROFORMA_INVOICE',
    'DELIVERY_NOTE',
    'APPROVAL_MEMO',
    'OTHER',
];

const PROCUREMENT_DOCUMENT_SUPPORTED_EXTENSIONS = [
    'pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'doc', 'docx', 'csv',
];

function procurementDocumentAssertStorageReady(mysqli $conn): void
{
    static $ready = [];
    $key = spl_object_id($conn);
    if (($ready[$key] ?? false) === true) {
        return;
    }

    try {
        $probe = $conn->prepare(
            'SELECT d.id, d.procurement_request_id, d.document_group_uuid, d.version_number,
                    d.supersedes_document_id, d.superseded_by_document_id, d.document_type,
                    d.original_filename, d.file_extension, d.mime_type, d.file_size, d.storage_key,
                    d.etag, d.status, d.remarks, d.uploaded_by_user_id, d.uploaded_by_email,
                    d.upload_started_at, d.upload_expires_at, d.uploaded_at,
                    d.superseded_by_user_id, d.superseded_at, d.deleted_by_user_id,
                    d.deleted_at, d.deletion_reason,
                    s.max_file_size_bytes, s.allowed_extensions_json
             FROM procurement_purchase_documents d
             LEFT JOIN procurement_document_settings s ON s.id = 1
             WHERE 1 = 0'
        );
        if (!$probe) {
            throw new RuntimeException('probe failed');
        }
        $probe->execute();
        $probe->close();
        $ready[$key] = true;
    } catch (Throwable) {
        throw new RuntimeException(
            'Purchase document storage is unavailable. Apply database/20260904_procurement_purchase_documents_r2_foundation.sql.',
            503
        );
    }
}

function procurementDocumentMimeMap(): array
{
    return [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'csv' => ['text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/plain'],
    ];
}

function procurementDocumentPreviewableExtensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'csv'];
}

function procurementDocumentDefaultSettings(): array
{
    return [
        'max_file_size_bytes' => 10 * 1024 * 1024,
        'allowed_extensions' => PROCUREMENT_DOCUMENT_SUPPORTED_EXTENSIONS,
    ];
}

function procurementDocumentNormalizeExtensions(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : explode(',', $value);
    }
    if (!is_array($value)) {
        return [];
    }

    $supported = array_flip(PROCUREMENT_DOCUMENT_SUPPORTED_EXTENSIONS);
    $normalized = [];
    foreach ($value as $extension) {
        $extension = strtolower(ltrim(trim((string) $extension), '.'));
        if ($extension !== '' && isset($supported[$extension])) {
            $normalized[] = $extension;
        }
    }
    return array_values(array_unique($normalized));
}

function procurementDocumentSettings(mysqli $conn): array
{
    procurementDocumentAssertStorageReady($conn);
    $defaults = procurementDocumentDefaultSettings();
    $row = $conn->query(
        'SELECT max_file_size_bytes, allowed_extensions_json, updated_by, updated_at
         FROM procurement_document_settings WHERE id = 1 LIMIT 1'
    )->fetch_assoc() ?: [];

    $extensions = procurementDocumentNormalizeExtensions($row['allowed_extensions_json'] ?? []);
    if ($extensions === []) {
        $extensions = $defaults['allowed_extensions'];
    }
    $maxBytes = (int) ($row['max_file_size_bytes'] ?? $defaults['max_file_size_bytes']);
    if ($maxBytes <= 0) {
        $maxBytes = $defaults['max_file_size_bytes'];
    }

    return [
        'max_file_size_bytes' => $maxBytes,
        'max_file_size_mb' => round($maxBytes / 1048576, 2),
        'allowed_extensions' => $extensions,
        'supported_extensions' => PROCUREMENT_DOCUMENT_SUPPORTED_EXTENSIONS,
        'document_types' => PROCUREMENT_DOCUMENT_TYPES,
        'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        'updated_at' => $row['updated_at'] ?? null,
        'storage_configured' => procurementDocumentR2Configured(),
    ];
}

function procurementDocumentSaveSettings(mysqli $conn, array $payload, array $actor): array
{
    procurementDocumentAssertStorageReady($conn);
    $current = procurementDocumentSettings($conn);

    $maxMb = array_key_exists('max_file_size_mb', $payload)
        ? (float) $payload['max_file_size_mb']
        : ((int) $current['max_file_size_bytes'] / 1048576);
    if (!is_finite($maxMb) || $maxMb < 1 || $maxMb > 100) {
        throw new RuntimeException('Maximum file size must be between 1 MB and 100 MB.', 422);
    }
    $maxBytes = (int) round($maxMb * 1048576);

    $extensions = array_key_exists('allowed_extensions', $payload)
        ? procurementDocumentNormalizeExtensions($payload['allowed_extensions'])
        : $current['allowed_extensions'];
    if ($extensions === []) {
        throw new RuntimeException('Select at least one supported document format.', 422);
    }

    $json = json_encode($extensions, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to save document configuration.', 500);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $stmt = $conn->prepare(
        'INSERT INTO procurement_document_settings
            (id, max_file_size_bytes, allowed_extensions_json, updated_by)
         VALUES (1, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            max_file_size_bytes = VALUES(max_file_size_bytes),
            allowed_extensions_json = VALUES(allowed_extensions_json),
            updated_by = VALUES(updated_by),
            updated_at = NOW()'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare document configuration.', 500);
    }
    $stmt->bind_param('isi', $maxBytes, $json, $actorId);
    $stmt->execute();
    $stmt->close();

    return procurementDocumentSettings($conn);
}

function procurementDocumentR2Configured(): bool
{
    foreach (['R2_ACCOUNT_ID', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET'] as $key) {
        if (trim((string) envValue($key, '')) === '') {
            return false;
        }
    }
    return true;
}

function procurementDocumentR2Config(): array
{
    $accountId = trim((string) envValue('R2_ACCOUNT_ID', ''));
    $accessKey = trim((string) envValue('R2_ACCESS_KEY_ID', ''));
    $secretKey = trim((string) envValue('R2_SECRET_ACCESS_KEY', ''));
    $bucket = trim((string) envValue('R2_BUCKET', ''));
    if ($accountId === '' || $accessKey === '' || $secretKey === '' || $bucket === '') {
        throw new RuntimeException('Cloudflare R2 storage credentials are not configured on the API server.', 503);
    }
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,62}[a-z0-9]$/', $bucket)) {
        throw new RuntimeException('R2_BUCKET is invalid.', 500);
    }

    $endpoint = rtrim(trim((string) envValue(
        'R2_ENDPOINT',
        'https://' . $accountId . '.r2.cloudflarestorage.com'
    )), '/');
    $parts = parse_url($endpoint);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('R2_ENDPOINT must be a valid HTTPS endpoint.', 500);
    }
    $path = trim((string) ($parts['path'] ?? ''), '/');
    if ($path !== '') {
        throw new RuntimeException('R2_ENDPOINT must not contain a path.', 500);
    }

    return [
        'account_id' => $accountId,
        'access_key_id' => $accessKey,
        'secret_access_key' => $secretKey,
        'bucket' => $bucket,
        'endpoint' => $endpoint,
        'host' => (string) $parts['host'],
        'region' => trim((string) envValue('R2_REGION', 'auto')) ?: 'auto',
        'upload_ttl' => max(60, min(3600, (int) envValue('R2_UPLOAD_URL_TTL_SECONDS', 600))),
        'access_ttl' => max(60, min(3600, (int) envValue('R2_ACCESS_URL_TTL_SECONDS', 300))),
    ];
}

function procurementDocumentAwsEncode(string $value): string
{
    return str_replace('%7E', '~', rawurlencode($value));
}

function procurementDocumentCanonicalUri(string $bucket, string $key): string
{
    $segments = array_merge([$bucket], explode('/', trim($key, '/')));
    return '/' . implode('/', array_map('procurementDocumentAwsEncode', $segments));
}

function procurementDocumentCanonicalQuery(array $query): string
{
    ksort($query, SORT_STRING);
    $parts = [];
    foreach ($query as $key => $value) {
        $parts[] = procurementDocumentAwsEncode((string) $key) . '=' . procurementDocumentAwsEncode((string) $value);
    }
    return implode('&', $parts);
}

function procurementDocumentSigningKey(string $secret, string $date, string $region): string
{
    $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
    return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
}

/**
 * Generate an AWS Signature V4 query-authenticated URL for R2's S3 endpoint.
 * PUT URLs bind Content-Type into the signature so the browser cannot silently
 * switch to a different declared MIME type.
 */
function procurementDocumentPresignR2(
    string $method,
    string $storageKey,
    int $expires,
    ?string $contentType = null,
    ?DateTimeImmutable $now = null
): array {
    $config = procurementDocumentR2Config();
    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'PUT', 'HEAD', 'DELETE'], true)) {
        throw new InvalidArgumentException('Unsupported R2 presigned operation.');
    }
    $expires = max(1, min(604800, $expires));
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));
    $amzDate = $now->format('Ymd\THis\Z');
    $date = $now->format('Ymd');
    $scope = $date . '/' . $config['region'] . '/s3/aws4_request';

    $headers = ['host' => $config['host']];
    if ($contentType !== null && trim($contentType) !== '') {
        $headers['content-type'] = strtolower(trim($contentType));
    }
    ksort($headers, SORT_STRING);
    $signedHeaders = implode(';', array_keys($headers));
    $canonicalHeaders = '';
    foreach ($headers as $name => $value) {
        $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $value) ?? $value) . "\n";
    }

    $query = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => $config['access_key_id'] . '/' . $scope,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => (string) $expires,
        'X-Amz-SignedHeaders' => $signedHeaders,
    ];
    $canonicalQuery = procurementDocumentCanonicalQuery($query);
    $canonicalUri = procurementDocumentCanonicalUri($config['bucket'], $storageKey);
    $canonicalRequest = implode("\n", [
        $method,
        $canonicalUri,
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        'UNSIGNED-PAYLOAD',
    ]);
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);
    $signature = hash_hmac(
        'sha256',
        $stringToSign,
        procurementDocumentSigningKey($config['secret_access_key'], $date, $config['region'])
    );

    return [
        'url' => $config['endpoint'] . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature,
        'expires_in' => $expires,
        'expires_at' => $now->modify('+' . $expires . ' seconds')->format(DateTimeInterface::ATOM),
        'required_headers' => $contentType !== null && trim($contentType) !== ''
            ? ['Content-Type' => strtolower(trim($contentType))]
            : [],
    ];
}

function procurementDocumentUtcSqlTimestamp(?string $value): ?int
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $utc = new DateTimeZone('UTC');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, $utc);
    if ($parsed instanceof DateTimeImmutable) {
        return $parsed->getTimestamp();
    }

    try {
        return (new DateTimeImmutable($text, $utc))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

function procurementDocumentUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function procurementDocumentCleanFilename(string $filename): string
{
    $filename = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '');
    $filename = basename(str_replace('\\', '/', $filename));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        throw new RuntimeException('A valid filename is required.', 422);
    }
    if (strlen($filename) > 255) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $maxBase = max(1, 250 - strlen($extension));
        $filename = substr($base, 0, $maxBase) . ($extension !== '' ? '.' . $extension : '');
    }
    return $filename;
}

function procurementDocumentNormalizeMime(string $mime): string
{
    return strtolower(trim(explode(';', $mime, 2)[0] ?? ''));
}

function procurementDocumentValidateFileMetadata(
    mysqli $conn,
    string $filename,
    string $mimeType,
    int $fileSize
): array {
    $settings = procurementDocumentSettings($conn);
    $filename = procurementDocumentCleanFilename($filename);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $settings['allowed_extensions'], true)) {
        throw new RuntimeException('This file format is not allowed for procurement documents.', 422);
    }
    if ($fileSize <= 0 || $fileSize > (int) $settings['max_file_size_bytes']) {
        throw new RuntimeException(
            'The document must be between 1 byte and ' . rtrim(rtrim(number_format((float) $settings['max_file_size_mb'], 2), '0'), '.') . ' MB.',
            422
        );
    }

    $mimeType = procurementDocumentNormalizeMime($mimeType);
    $allowedMimes = procurementDocumentMimeMap()[$extension] ?? [];
    if ($mimeType === '' || !in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('The selected file MIME type does not match its allowed document format.', 422);
    }

    return [
        'filename' => $filename,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'can_preview' => in_array($extension, procurementDocumentPreviewableExtensions(), true),
    ];
}

function procurementDocumentNormalizeType(mixed $value): string
{
    $type = strtoupper(trim((string) ($value ?? 'OTHER')));
    $type = str_replace([' ', '-'], '_', $type);
    if (!in_array($type, PROCUREMENT_DOCUMENT_TYPES, true)) {
        throw new RuntimeException('Document type is invalid.', 422);
    }
    return $type;
}

function procurementDocumentFetchPurchase(mysqli $conn, int $requestId): array
{
    if ($requestId <= 0) {
        throw new RuntimeException('A valid procurement request is required.', 422);
    }
    $stmt = $conn->prepare(
        "SELECT id, request_type, request_number, legacy_source_id, po_number, purchase_number,
                currency, project_code, project_name, supplier_name, created_at
         FROM procurement_requests
         WHERE id = ? AND deleted_at IS NULL
           AND request_type IN ('local_final_purchase','local_advance_purchase','fx_final_purchase','fx_advance_purchase')
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to resolve the procurement purchase.', 500);
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Procurement purchase not found.', 404);
    }
    $row['id'] = (int) $row['id'];
    $row['legacy_source_id'] = (int) $row['legacy_source_id'];
    return $row;
}

function procurementDocumentPurchasePermission(string $requestType): string
{
    return match ($requestType) {
        'local_final_purchase' => 'payments.local_final.view',
        'local_advance_purchase' => 'payments.local_advance.view',
        'fx_final_purchase' => 'payments.fx_final.view',
        'fx_advance_purchase' => 'payments.fx_advance.view',
        default => '',
    };
}

function procurementDocumentAssertPurchasePermission(array $actor, string $requestType): void
{
    if ((string) ($actor['role'] ?? '') === 'super_admin') {
        return;
    }
    $permission = procurementDocumentPurchasePermission($requestType);
    if ($permission === '' || !in_array($permission, $actor['permissions'] ?? [], true)) {
        throw new RuntimeException('You are not permitted to access documents for this purchase.', 403);
    }
}

function procurementDocumentBuildStorageKey(array $purchase, string $groupUuid, int $version, string $extension): string
{
    $year = substr((string) ($purchase['created_at'] ?? ''), 0, 4);
    if (!preg_match('/^20\d{2}$/', $year)) {
        $year = gmdate('Y');
    }
    $type = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $purchase['request_type'])) ?? 'purchase';
    $objectUuid = procurementDocumentUuidV4();
    return sprintf(
        'procurement/%s/%s/%d/%s/v%d/%s.%s',
        $year,
        trim($type, '_'),
        (int) $purchase['id'],
        $groupUuid,
        max(1, $version),
        $objectUuid,
        strtolower($extension)
    );
}

function procurementDocumentFetch(mysqli $conn, int $documentId, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT d.*, r.request_type, r.request_number, r.legacy_source_id, r.po_number, r.purchase_number
         FROM procurement_purchase_documents d
         INNER JOIN procurement_requests r ON r.id = d.procurement_request_id AND r.deleted_at IS NULL
         WHERE d.id = ? LIMIT 1{$lock}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read the procurement document.', 500);
    }
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementDocumentSerialize(array $row): array
{
    foreach ([
        'id', 'procurement_request_id', 'version_number', 'supersedes_document_id',
        'superseded_by_document_id', 'file_size', 'uploaded_by_user_id',
        'superseded_by_user_id', 'deleted_by_user_id', 'legacy_source_id',
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }
    $extension = strtolower((string) ($row['file_extension'] ?? ''));
    $row['can_preview'] = in_array($extension, procurementDocumentPreviewableExtensions(), true)
        && (string) ($row['status'] ?? '') === PROCUREMENT_DOCUMENT_STATUS_ACTIVE;
    $row['is_active_version'] = (string) ($row['status'] ?? '') === PROCUREMENT_DOCUMENT_STATUS_ACTIVE;
    unset($row['storage_key']);
    return $row;
}

function procurementDocumentList(mysqli $conn, int $requestId, bool $includeHistory = false): array
{
    procurementDocumentAssertStorageReady($conn);
    $purchase = procurementDocumentFetchPurchase($conn, $requestId);
    $where = $includeHistory
        ? "d.status IN ('ACTIVE','SUPERSEDED')"
        : "d.status = 'ACTIVE'";
    $stmt = $conn->prepare(
        "SELECT d.*, r.request_type, r.request_number, r.legacy_source_id, r.po_number, r.purchase_number
         FROM procurement_purchase_documents d
         INNER JOIN procurement_requests r ON r.id = d.procurement_request_id
         WHERE d.procurement_request_id = ? AND {$where}
         ORDER BY d.document_group_uuid, d.version_number DESC, d.id DESC"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [
        'purchase' => $purchase,
        'documents' => array_map('procurementDocumentSerialize', $rows),
    ];
}

function procurementDocumentCreateUploadIntent(mysqli $conn, array $payload, array $actor): array
{
    procurementDocumentAssertStorageReady($conn);
    $config = procurementDocumentR2Config();
    $requestId = (int) ($payload['procurement_request_id'] ?? 0);
    $purchase = procurementDocumentFetchPurchase($conn, $requestId);
    procurementDocumentAssertPurchasePermission($actor, (string) $purchase['request_type']);

    $file = procurementDocumentValidateFileMetadata(
        $conn,
        (string) ($payload['filename'] ?? ''),
        (string) ($payload['mime_type'] ?? ''),
        (int) ($payload['file_size'] ?? 0)
    );
    $replaceId = max(0, (int) ($payload['replace_document_id'] ?? 0));
    $documentType = procurementDocumentNormalizeType($payload['document_type'] ?? 'OTHER');
    $remarks = trim((string) ($payload['remarks'] ?? ''));
    if (strlen($remarks) > 1000) {
        throw new RuntimeException('Document remarks cannot exceed 1000 characters.', 422);
    }

    $conn->begin_transaction();
    try {
        $groupUuid = procurementDocumentUuidV4();
        $version = 1;
        $supersedesId = null;
        if ($replaceId > 0) {
            $existing = procurementDocumentFetch($conn, $replaceId, true);
            if (!$existing || (int) $existing['procurement_request_id'] !== $requestId) {
                throw new RuntimeException('The document selected for replacement was not found on this purchase.', 404);
            }
            if ((string) $existing['status'] !== PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
                throw new RuntimeException('Only the current active document version can be replaced.', 409);
            }
            $groupUuid = (string) $existing['document_group_uuid'];
            $stmt = $conn->prepare(
                'SELECT COALESCE(MAX(version_number), 0) AS max_version
                 FROM procurement_purchase_documents
                 WHERE document_group_uuid = ? FOR UPDATE'
            );
            $stmt->bind_param('s', $groupUuid);
            $stmt->execute();
            $version = ((int) ($stmt->get_result()->fetch_assoc()['max_version'] ?? 0)) + 1;
            $stmt->close();
            $supersedesId = $replaceId;
            if (!array_key_exists('document_type', $payload)) {
                $documentType = (string) $existing['document_type'];
            }
        }

        $storageKey = procurementDocumentBuildStorageKey($purchase, $groupUuid, $version, $file['extension']);
        $actorId = (int) $actor['id'];
        $actorEmail = (string) $actor['email'];
        $expiresAtSql = gmdate('Y-m-d H:i:s', time() + (int) $config['upload_ttl']);
        $stmt = $conn->prepare(
            "INSERT INTO procurement_purchase_documents
                (procurement_request_id, document_group_uuid, version_number,
                 supersedes_document_id, document_type, original_filename,
                 file_extension, mime_type, file_size, storage_key, status, remarks,
                 uploaded_by_user_id, uploaded_by_email, upload_expires_at)
             VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, 'PENDING_UPLOAD', NULLIF(?, ''), ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the document upload.', 500);
        }
        $supersedes = $supersedesId ?? 0;
        $stmt->bind_param(
            'isiissssississ',
            $requestId,
            $groupUuid,
            $version,
            $supersedes,
            $documentType,
            $file['filename'],
            $file['extension'],
            $file['mime_type'],
            $file['file_size'],
            $storageKey,
            $remarks,
            $actorId,
            $actorEmail,
            $expiresAtSql
        );
        $stmt->execute();
        $documentId = (int) $stmt->insert_id;
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    try {
        $signed = procurementDocumentPresignR2(
            'PUT',
            $storageKey,
            (int) $config['upload_ttl'],
            $file['mime_type']
        );
    } catch (Throwable $error) {
        procurementDocumentMarkUploadFailed($conn, $documentId);
        throw $error;
    }

    return [
        'document_id' => $documentId,
        'upload_url' => $signed['url'],
        'required_headers' => $signed['required_headers'],
        'expires_in' => $signed['expires_in'],
        'expires_at' => $signed['expires_at'],
        'file' => [
            'name' => $file['filename'],
            'extension' => $file['extension'],
            'mime_type' => $file['mime_type'],
            'file_size' => $file['file_size'],
        ],
        'replacement' => $replaceId > 0,
    ];
}

function procurementDocumentR2Head(string $storageKey, int $missingStatusCode = 409): array
{
    $config = procurementDocumentR2Config();
    $signed = procurementDocumentPresignR2('HEAD', $storageKey, min(120, (int) $config['access_ttl']));
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to verify R2 documents.', 503);
    }

    $headers = [];
    $curl = curl_init($signed['url']);
    curl_setopt_array($curl, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $length = strlen($line);
            $position = strpos($line, ':');
            if ($position !== false) {
                $name = strtolower(trim(substr($line, 0, $position)));
                $value = trim(substr($line, $position + 1));
                $headers[$name] = $value;
            }
            return $length;
        },
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($status !== 200) {
        if ($status === 404) {
            throw new RuntimeException(
                'The document metadata exists, but the file is missing from Cloudflare R2. Upload a replacement version to restore access.',
                $missingStatusCode
            );
        }
        throw new RuntimeException(
            'Cloudflare R2 could not be reached to verify this document' . ($error !== '' ? ': ' . $error : '.'),
            503
        );
    }

    return [
        'size' => isset($headers['content-length']) ? (int) $headers['content-length'] : null,
        'content_type' => procurementDocumentNormalizeMime((string) ($headers['content-type'] ?? '')),
        'etag' => trim((string) ($headers['etag'] ?? ''), "\"' "),
    ];
}

function procurementDocumentMarkUploadFailed(mysqli $conn, int $documentId): void
{
    if ($documentId <= 0) {
        return;
    }
    try {
        $stmt = $conn->prepare(
            "UPDATE procurement_purchase_documents
             SET status = 'FAILED', updated_at = NOW()
             WHERE id = ? AND status = 'PENDING_UPLOAD'"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Unable to mark procurement document upload as failed: ' . $error->getMessage());
    }
}

function procurementDocumentVerifyStoredObject(array $document): array
{
    $storageKey = (string) ($document['storage_key'] ?? '');
    if ($storageKey === '') {
        throw new RuntimeException('Document storage metadata is incomplete.', 409);
    }

    $head = procurementDocumentR2Head($storageKey, 410);
    $expectedSize = (int) ($document['file_size'] ?? 0);
    $expectedMime = procurementDocumentNormalizeMime((string) ($document['mime_type'] ?? ''));

    if ($head['size'] === null || (int) $head['size'] !== $expectedSize) {
        throw new RuntimeException(
            'The file stored in Cloudflare R2 no longer matches the recorded document size. Upload a replacement version before using it.',
            409
        );
    }
    if ($expectedMime !== '' && (string) ($head['content_type'] ?? '') !== $expectedMime) {
        throw new RuntimeException(
            'The file stored in Cloudflare R2 no longer matches the recorded document type. Upload a replacement version before using it.',
            409
        );
    }

    return $head;
}

function procurementDocumentPrepareAccessUrl(array $document, string $mode): array
{
    if ((string) ($document['status'] ?? '') !== PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
        throw new RuntimeException('Active procurement document not found.', 404);
    }

    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['preview', 'download'], true)) {
        throw new RuntimeException('Document access mode must be preview or download.', 422);
    }

    $extension = strtolower((string) ($document['file_extension'] ?? ''));
    $canPreview = in_array($extension, procurementDocumentPreviewableExtensions(), true);
    if ($mode === 'preview' && !$canPreview) {
        throw new RuntimeException('This document format is download-only in the secure viewer.', 409);
    }

    // Validate the object immediately before issuing a signed read URL so stale
    // metadata never sends users to a raw R2 404/XML response.
    procurementDocumentVerifyStoredObject($document);

    $config = procurementDocumentR2Config();
    $signed = procurementDocumentPresignR2('GET', (string) $document['storage_key'], (int) $config['access_ttl']);

    return [
        'document' => procurementDocumentSerialize($document),
        'mode' => $mode,
        'url' => $signed['url'],
        'expires_in' => $signed['expires_in'],
        'expires_at' => $signed['expires_at'],
        'download_name' => (string) $document['original_filename'],
    ];
}

function procurementDocumentCleanupExpiredUploads(mysqli $conn, int $limit = 100): array
{
    procurementDocumentAssertStorageReady($conn);
    $limit = max(1, min(500, $limit));

    $rows = [];
    $result = $conn->query(
        "SELECT id, storage_key
         FROM procurement_purchase_documents
         WHERE status = 'PENDING_UPLOAD'
           AND upload_expires_at IS NOT NULL
           AND upload_expires_at < UTC_TIMESTAMP()
         ORDER BY upload_expires_at ASC, id ASC
         LIMIT {$limit}"
    );
    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    $failed = 0;
    $cleanupAttempted = 0;
    foreach ($rows as $row) {
        $documentId = (int) ($row['id'] ?? 0);
        if ($documentId <= 0) {
            continue;
        }

        $stmt = $conn->prepare(
            "UPDATE procurement_purchase_documents
             SET status = 'FAILED', updated_at = NOW()
             WHERE id = ?
               AND status = 'PENDING_UPLOAD'
               AND upload_expires_at IS NOT NULL
               AND upload_expires_at < UTC_TIMESTAMP()"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        if ($changed) {
            $failed++;
            $storageKey = trim((string) ($row['storage_key'] ?? ''));
            if ($storageKey !== '') {
                $cleanupAttempted++;
                procurementDocumentBestEffortDeleteR2($storageKey);
            }
        }
    }

    return [
        'scanned' => count($rows),
        'expired_marked_failed' => $failed,
        'r2_cleanup_attempted' => $cleanupAttempted,
        'limit' => $limit,
    ];
}

function procurementDocumentBestEffortDeleteR2(string $storageKey): void
{
    try {
        $config = procurementDocumentR2Config();
        $signed = procurementDocumentPresignR2('DELETE', $storageKey, min(120, (int) $config['access_ttl']));
        if (!function_exists('curl_init')) {
            return;
        }
        $curl = curl_init($signed['url']);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        curl_exec($curl);
        curl_close($curl);
    } catch (Throwable $error) {
        error_log('Unable to clean up failed procurement R2 object: ' . $error->getMessage());
    }
}

function procurementDocumentCompleteUpload(mysqli $conn, int $documentId, array $actor): array
{
    procurementDocumentAssertStorageReady($conn);
    $document = procurementDocumentFetch($conn, $documentId);
    if (!$document) {
        throw new RuntimeException('Document upload was not found.', 404);
    }
    procurementDocumentAssertPurchasePermission($actor, (string) $document['request_type']);
    if ((string) $document['status'] !== PROCUREMENT_DOCUMENT_STATUS_PENDING) {
        if ((string) $document['status'] === PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
            return procurementDocumentSerialize($document);
        }
        throw new RuntimeException('This upload can no longer be completed.', 409);
    }
    $uploadExpiryTimestamp = procurementDocumentUtcSqlTimestamp($document['upload_expires_at'] ?? null);
    if ($uploadExpiryTimestamp !== null && $uploadExpiryTimestamp < time()) {
        throw new RuntimeException('The upload session expired. Please start the upload again.', 409);
    }

    $head = procurementDocumentR2Head((string) $document['storage_key']);
    $expectedSize = (int) $document['file_size'];
    $expectedMime = procurementDocumentNormalizeMime((string) $document['mime_type']);
    if ($head['size'] === null || (int) $head['size'] !== $expectedSize || $head['content_type'] !== $expectedMime) {
        procurementDocumentMarkUploadFailed($conn, $documentId);
        procurementDocumentBestEffortDeleteR2((string) $document['storage_key']);
        throw new RuntimeException('The uploaded file did not match the approved size/type. Please upload it again.', 409);
    }

    $conn->begin_transaction();
    try {
        $current = procurementDocumentFetch($conn, $documentId, true);
        if (!$current || (string) $current['status'] !== PROCUREMENT_DOCUMENT_STATUS_PENDING) {
            throw new RuntimeException('This upload has already been finalized.', 409);
        }

        $supersedesId = (int) ($current['supersedes_document_id'] ?? 0);
        if ($supersedesId > 0) {
            $previous = procurementDocumentFetch($conn, $supersedesId, true);
            if (!$previous || (string) $previous['status'] !== PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
                throw new RuntimeException('The document being replaced has already changed. Refresh and try again.', 409);
            }
            if ((string) $previous['document_group_uuid'] !== (string) $current['document_group_uuid']) {
                throw new RuntimeException('Document version history is inconsistent.', 409);
            }
            $actorId = (int) $actor['id'];
            $stmt = $conn->prepare(
                "UPDATE procurement_purchase_documents
                 SET status = 'SUPERSEDED', superseded_by_document_id = ?,
                     superseded_by_user_id = ?, superseded_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND status = 'ACTIVE'"
            );
            $stmt->bind_param('iii', $documentId, $actorId, $supersedesId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The document being replaced changed before completion. Refresh and try again.', 409);
            }
            $stmt->close();
        }

        $etag = (string) ($head['etag'] ?? '');
        $stmt = $conn->prepare(
            "UPDATE procurement_purchase_documents
             SET status = 'ACTIVE', etag = NULLIF(?, ''), uploaded_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'PENDING_UPLOAD'"
        );
        $stmt->bind_param('si', $etag, $documentId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Unable to finalize the uploaded document.', 409);
        }
        $stmt->close();

        $eventType = $supersedesId > 0 ? 'document_replaced' : 'document_uploaded';
        $details = json_encode([
            'document_id' => $documentId,
            'document_group_uuid' => (string) $current['document_group_uuid'],
            'version_number' => (int) $current['version_number'],
            'supersedes_document_id' => $supersedesId > 0 ? $supersedesId : null,
            'document_type' => (string) $current['document_type'],
            'original_filename' => (string) $current['original_filename'],
            'file_extension' => (string) $current['file_extension'],
            'file_size' => (int) $current['file_size'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        workflowEventRecordRequest(
            $conn,
            (int) $current['procurement_request_id'],
            (string) $current['request_type'],
            $eventType,
            (int) $actor['id'],
            (string) $actor['email'],
            is_string($details) ? $details : null
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $final = procurementDocumentFetch($conn, $documentId);
    if (!$final) {
        throw new RuntimeException('The uploaded document could not be reloaded.', 500);
    }
    return procurementDocumentSerialize($final);
}

function procurementDocumentAccessUrl(mysqli $conn, int $documentId, string $mode, array $actor): array
{
    procurementDocumentAssertStorageReady($conn);
    $document = procurementDocumentFetch($conn, $documentId);
    if (!$document || (string) $document['status'] !== PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
        throw new RuntimeException('Active procurement document not found.', 404);
    }
    procurementDocumentAssertPurchasePermission($actor, (string) $document['request_type']);

    return procurementDocumentPrepareAccessUrl($document, $mode);
}
