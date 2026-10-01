<?php

declare(strict_types=1);

require_once __DIR__ . '/accountProcurementDocumentService.php';
require_once __DIR__ . '/procurementDocumentStorageService.php';

const ACCOUNT_DOCUMENT_STATUS_PENDING = 'PENDING_UPLOAD';
const ACCOUNT_DOCUMENT_STATUS_ACTIVE = 'ACTIVE';
const ACCOUNT_DOCUMENT_STATUS_SUPERSEDED = 'SUPERSEDED';
const ACCOUNT_DOCUMENT_STATUS_FAILED = 'FAILED';
const ACCOUNT_DOCUMENT_STATUS_DELETED = 'DELETED';

const ACCOUNT_DOCUMENT_ENTITY_PAYMENT_BATCH = 'payment_batch';

const ACCOUNT_DOCUMENT_TYPES = [
    'PAYMENT_EVIDENCE',
    'PAYMENT_VOUCHER',
    'JOURNAL_VOUCHER',
    'BANK_ADVICE',
    'RECEIPT',
    'SUPPORTING_SCHEDULE',
    'APPROVAL_MEMO',
    'OTHER',
];

function accountDocumentAssertStorageReady(mysqli $conn): void
{
    static $ready = [];
    $key = spl_object_id($conn);
    if (($ready[$key] ?? false) === true) {
        return;
    }

    // Account documents intentionally reuse procurement_document_settings so
    // Super Admin maintains one upload-size/type policy across both systems.
    procurementDocumentAssertStorageReady($conn);

    try {
        $probe = $conn->prepare(
            'SELECT id, entity_type, entity_id, document_group_uuid,
                    version_number, supersedes_document_id, superseded_by_document_id,
                    document_type, original_filename, file_extension, mime_type,
                    file_size, storage_key, etag, status, remarks,
                    uploaded_by_user_id, uploaded_by_email, upload_started_at,
                    upload_expires_at, uploaded_at, superseded_by_user_id,
                    superseded_at, deleted_by_user_id, deleted_at, deletion_reason
             FROM account_documents WHERE 1 = 0'
        );
        if (!$probe) {
            throw new RuntimeException('probe failed');
        }
        $probe->execute();
        $probe->close();
        $ready[$key] = true;
    } catch (Throwable) {
        throw new RuntimeException(
            'Account document storage is unavailable. Apply database/20260905_account_documents_r2_upload_foundation.sql.',
            503
        );
    }
}

function accountDocumentNormalizeType(mixed $value): string
{
    $type = strtoupper(trim((string) ($value ?? 'OTHER')));
    $type = str_replace([' ', '-'], '_', $type);
    if (!in_array($type, ACCOUNT_DOCUMENT_TYPES, true)) {
        throw new RuntimeException('Account document type is invalid.', 422);
    }
    return $type;
}

function accountDocumentSettings(mysqli $conn): array
{
    $settings = procurementDocumentSettings($conn);
    $settings['document_types'] = ACCOUNT_DOCUMENT_TYPES;
    return $settings;
}

function accountDocumentValidateFileMetadata(
    mysqli $conn,
    string $filename,
    string $mimeType,
    int $fileSize
): array {
    $settings = accountDocumentSettings($conn);
    $filename = procurementDocumentCleanFilename($filename);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $settings['allowed_extensions'], true)) {
        throw new RuntimeException('This file format is not allowed for Account documents.', 422);
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

function accountDocumentNormalizeOwnerType(mixed $value): string
{
    $type = strtolower(trim((string) $value));
    if ($type === ACCOUNT_DOCUMENT_ENTITY_PAYMENT_BATCH) {
        return $type;
    }
    return accountProcurementDocumentNormalizeAccountRequestType($type);
}

function accountDocumentAssertOwner(mysqli $conn, string $entityType, int $entityId): array
{
    $entityType = accountDocumentNormalizeOwnerType($entityType);
    if ($entityId <= 0) {
        throw new RuntimeException('A valid Account document owner is required.', 422);
    }

    if ($entityType === ACCOUNT_DOCUMENT_ENTITY_PAYMENT_BATCH) {
        $stmt = $conn->prepare('SELECT id, request_type, batch_reference, processing_method, processing_reference FROM account_payment_batches WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the payment-processing batch.', 500);
        }
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$batch) {
            throw new RuntimeException('Payment-processing batch not found.', 404);
        }
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'owner_label' => trim((string) ($batch['batch_reference'] ?? '')) ?: ('Payment batch #' . $entityId),
            'request_type' => (string) ($batch['request_type'] ?? ''),
            'processing_method' => (string) ($batch['processing_method'] ?? ''),
            'processing_reference' => (string) ($batch['processing_reference'] ?? ''),
        ];
    }

    accountProcurementDocumentAssertAccountRequestExists($conn, $entityType, $entityId);
    return [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'account_request_type' => $entityType,
        'account_request_id' => $entityId,
    ];
}

function accountDocumentBuildStorageKey(
    string $accountRequestType,
    int $accountRequestId,
    string $groupUuid,
    int $version,
    string $extension
): string {
    $type = preg_replace('/[^a-z0-9_]+/', '_', strtolower($accountRequestType)) ?? 'account_request';
    return sprintf(
        'acctlab/%s/%s/%d/%s/v%d/%s.%s',
        gmdate('Y'),
        trim($type, '_'),
        $accountRequestId,
        $groupUuid,
        max(1, $version),
        procurementDocumentUuidV4(),
        strtolower($extension)
    );
}

function accountDocumentFetch(mysqli $conn, int $documentId, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare("SELECT * FROM account_documents WHERE id = ? LIMIT 1{$lock}");
    if (!$stmt) {
        throw new RuntimeException('Unable to read the Account document.', 500);
    }
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function accountDocumentSerialize(array $row): array
{
    foreach ([
        'id', 'entity_id', 'version_number', 'supersedes_document_id',
        'superseded_by_document_id', 'file_size', 'uploaded_by_user_id',
        'superseded_by_user_id', 'deleted_by_user_id',
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }
    $extension = strtolower((string) ($row['file_extension'] ?? ''));
    $row['can_preview'] = in_array($extension, procurementDocumentPreviewableExtensions(), true)
        && (string) ($row['status'] ?? '') === ACCOUNT_DOCUMENT_STATUS_ACTIVE;
    $row['is_active_version'] = (string) ($row['status'] ?? '') === ACCOUNT_DOCUMENT_STATUS_ACTIVE;
    $row['owner_type'] = $row['entity_type'] ?? null;
    $row['owner_id'] = isset($row['entity_id']) ? (int) $row['entity_id'] : null;
    // Preserve the original Fund Request aliases for existing clients.
    if (in_array((string) ($row['entity_type'] ?? ''), ACCOUNT_PROCUREMENT_DOCUMENT_REQUEST_TYPES, true)) {
        $row['account_request_type'] = $row['entity_type'];
        $row['account_request_id'] = isset($row['entity_id']) ? (int) $row['entity_id'] : null;
    } else {
        $row['account_request_type'] = null;
        $row['account_request_id'] = null;
    }
    unset($row['storage_key']);
    return $row;
}

function accountDocumentList(
    mysqli $conn,
    string $entityType,
    int $entityId,
    bool $includeHistory = true
): array {
    accountDocumentAssertStorageReady($conn);
    $owner = accountDocumentAssertOwner($conn, $entityType, $entityId);
    $where = $includeHistory
        ? "status IN ('ACTIVE','SUPERSEDED')"
        : "status = 'ACTIVE'";

    $stmt = $conn->prepare(
        "SELECT *
         FROM account_documents
         WHERE entity_type = ? AND entity_id = ? AND {$where}
         ORDER BY document_group_uuid, version_number DESC, id DESC"
    );
    $stmt->bind_param('si', $owner['entity_type'], $owner['entity_id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'owner' => $owner,
        'documents' => array_map('accountDocumentSerialize', $rows),
        'settings' => accountDocumentSettings($conn),
    ];
}

function accountDocumentCreateUploadIntent(mysqli $conn, array $payload, array $actor): array
{
    accountDocumentAssertStorageReady($conn);
    $config = procurementDocumentR2Config();
    $entityType = accountDocumentNormalizeOwnerType($payload['entity_type'] ?? $payload['account_request_type'] ?? '');
    $entityId = (int) ($payload['entity_id'] ?? $payload['account_request_id'] ?? 0);
    accountDocumentAssertOwner($conn, $entityType, $entityId);

    $file = accountDocumentValidateFileMetadata(
        $conn,
        (string) ($payload['filename'] ?? ''),
        (string) ($payload['mime_type'] ?? ''),
        (int) ($payload['file_size'] ?? 0)
    );
    $replaceId = max(0, (int) ($payload['replace_document_id'] ?? 0));
    $documentType = accountDocumentNormalizeType($payload['document_type'] ?? 'OTHER');
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
            $existing = accountDocumentFetch($conn, $replaceId, true);
            if (!$existing
                || (string) $existing['entity_type'] !== $entityType
                || (int) $existing['entity_id'] !== $entityId) {
                throw new RuntimeException('The Account document selected for replacement was not found on this record.', 404);
            }
            if ((string) $existing['status'] !== ACCOUNT_DOCUMENT_STATUS_ACTIVE) {
                throw new RuntimeException('Only the current active Account document version can be replaced.', 409);
            }
            $groupUuid = (string) $existing['document_group_uuid'];
            $stmt = $conn->prepare(
                'SELECT COALESCE(MAX(version_number), 0) AS max_version
                 FROM account_documents WHERE document_group_uuid = ? FOR UPDATE'
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

        $storageKey = accountDocumentBuildStorageKey(
            $entityType,
            $entityId,
            $groupUuid,
            $version,
            $file['extension']
        );
        $actorId = (int) ($actor['id'] ?? 0);
        $actorEmail = trim((string) ($actor['email'] ?? '')) ?: 'account-user';
        $expiresAtSql = gmdate('Y-m-d H:i:s', time() + (int) $config['upload_ttl']);
        $stmt = $conn->prepare(
            "INSERT INTO account_documents
                (entity_type, entity_id, document_group_uuid, version_number,
                 supersedes_document_id, document_type, original_filename, file_extension,
                 mime_type, file_size, storage_key, status, remarks, uploaded_by_user_id,
                 uploaded_by_email, upload_started_at, upload_expires_at)
             VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, 'PENDING_UPLOAD', NULLIF(?, ''), ?, ?, UTC_TIMESTAMP(), ?)"
        );
        $supersedesForBind = $supersedesId ?? 0;
        $stmt->bind_param(
            'sisiissssississ',
            $entityType,
            $entityId,
            $groupUuid,
            $version,
            $supersedesForBind,
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

    $signed = procurementDocumentPresignR2('PUT', $storageKey, (int) $config['upload_ttl'], $file['mime_type']);
    return [
        'document_id' => $documentId,
        'upload_url' => $signed['url'],
        'required_headers' => $signed['required_headers'],
        'expires_in' => $signed['expires_in'],
        'expires_at' => $signed['expires_at'],
        'document' => accountDocumentSerialize(accountDocumentFetch($conn, $documentId) ?: []),
    ];
}

function accountDocumentMarkUploadFailed(mysqli $conn, int $documentId): void
{
    $stmt = $conn->prepare(
        "UPDATE account_documents
         SET status = 'FAILED', updated_at = NOW()
         WHERE id = ? AND status = 'PENDING_UPLOAD'"
    );
    if ($stmt) {
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $stmt->close();
    }
}

function accountDocumentVerifyStoredObject(array $document): array
{
    try {
        $head = procurementDocumentR2Head((string) ($document['storage_key'] ?? ''), 410);
    } catch (Throwable $error) {
        if ((int) $error->getCode() === 410) {
            throw new RuntimeException(
                'This Account document is missing from Cloudflare R2. Upload a replacement version to restore it.',
                410
            );
        }
        throw $error;
    }

    $expectedSize = (int) ($document['file_size'] ?? 0);
    $expectedMime = procurementDocumentNormalizeMime((string) ($document['mime_type'] ?? ''));
    if ($head['size'] === null || (int) $head['size'] !== $expectedSize) {
        throw new RuntimeException(
            'The file stored in Cloudflare R2 no longer matches the recorded Account document size. Upload a replacement version before using it.',
            409
        );
    }
    if ((string) $head['content_type'] !== $expectedMime) {
        throw new RuntimeException(
            'The file stored in Cloudflare R2 no longer matches the recorded Account document type. Upload a replacement version before using it.',
            409
        );
    }
    return $head;
}

function accountDocumentCompleteUpload(mysqli $conn, int $documentId, array $actor): array
{
    accountDocumentAssertStorageReady($conn);
    $document = accountDocumentFetch($conn, $documentId);
    if (!$document) {
        throw new RuntimeException('Account document upload was not found.', 404);
    }
    accountDocumentAssertOwner(
        $conn,
        (string) $document['entity_type'],
        (int) $document['entity_id']
    );

    if ((string) $document['status'] !== ACCOUNT_DOCUMENT_STATUS_PENDING) {
        if ((string) $document['status'] === ACCOUNT_DOCUMENT_STATUS_ACTIVE) {
            return accountDocumentSerialize($document);
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
        accountDocumentMarkUploadFailed($conn, $documentId);
        procurementDocumentBestEffortDeleteR2((string) $document['storage_key']);
        throw new RuntimeException('The uploaded file did not match the approved size/type. Please upload it again.', 409);
    }

    $conn->begin_transaction();
    try {
        $current = accountDocumentFetch($conn, $documentId, true);
        if (!$current || (string) $current['status'] !== ACCOUNT_DOCUMENT_STATUS_PENDING) {
            throw new RuntimeException('This upload has already been finalized.', 409);
        }

        $supersedesId = (int) ($current['supersedes_document_id'] ?? 0);
        if ($supersedesId > 0) {
            $previous = accountDocumentFetch($conn, $supersedesId, true);
            if (!$previous || (string) $previous['status'] !== ACCOUNT_DOCUMENT_STATUS_ACTIVE) {
                throw new RuntimeException('The Account document being replaced has already changed. Refresh and try again.', 409);
            }
            if ((string) $previous['document_group_uuid'] !== (string) $current['document_group_uuid']) {
                throw new RuntimeException('Account document version history is inconsistent.', 409);
            }
            $actorId = (int) ($actor['id'] ?? 0);
            $stmt = $conn->prepare(
                "UPDATE account_documents
                 SET status = 'SUPERSEDED', superseded_by_document_id = ?,
                     superseded_by_user_id = ?, superseded_at = UTC_TIMESTAMP(), updated_at = NOW()
                 WHERE id = ? AND status = 'ACTIVE'"
            );
            $stmt->bind_param('iii', $documentId, $actorId, $supersedesId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('The Account document being replaced changed before completion. Refresh and try again.', 409);
            }
            $stmt->close();
        }

        $etag = (string) ($head['etag'] ?? '');
        $stmt = $conn->prepare(
            "UPDATE account_documents
             SET status = 'ACTIVE', etag = NULLIF(?, ''), uploaded_at = UTC_TIMESTAMP(), updated_at = NOW()
             WHERE id = ? AND status = 'PENDING_UPLOAD'"
        );
        $stmt->bind_param('si', $etag, $documentId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Unable to finalize the uploaded Account document.', 409);
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $final = accountDocumentFetch($conn, $documentId);
    if (!$final) {
        throw new RuntimeException('The uploaded Account document could not be reloaded.', 500);
    }
    return accountDocumentSerialize($final);
}

function accountDocumentAccessUrl(
    mysqli $conn,
    string $entityType,
    int $entityId,
    int $documentId,
    string $mode
): array {
    accountDocumentAssertStorageReady($conn);
    $owner = accountDocumentAssertOwner($conn, $entityType, $entityId);
    $document = accountDocumentFetch($conn, $documentId);
    if (!$document
        || (string) $document['entity_type'] !== $owner['entity_type']
        || (int) $document['entity_id'] !== $owner['entity_id']
        || (string) $document['status'] !== ACCOUNT_DOCUMENT_STATUS_ACTIVE) {
        throw new RuntimeException('Active Account document not found for this record.', 404);
    }

    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['preview', 'download'], true)) {
        throw new RuntimeException('Document access mode must be preview or download.', 422);
    }
    $extension = strtolower((string) ($document['file_extension'] ?? ''));
    if ($mode === 'preview' && !in_array($extension, procurementDocumentPreviewableExtensions(), true)) {
        throw new RuntimeException('This document format is download-only in the secure viewer.', 409);
    }

    accountDocumentVerifyStoredObject($document);
    $config = procurementDocumentR2Config();
    $signed = procurementDocumentPresignR2('GET', (string) $document['storage_key'], (int) $config['access_ttl']);

    return [
        'document' => accountDocumentSerialize($document),
        'mode' => $mode,
        'url' => $signed['url'],
        'expires_in' => $signed['expires_in'],
        'expires_at' => $signed['expires_at'],
        'download_name' => (string) $document['original_filename'],
    ];
}

function accountDocumentCleanupExpiredUploads(mysqli $conn, int $limit = 100): array
{
    accountDocumentAssertStorageReady($conn);
    $limit = max(1, min(500, $limit));
    $rows = [];
    $result = $conn->query(
        "SELECT id, storage_key
         FROM account_documents
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
        if ($documentId <= 0) continue;
        $stmt = $conn->prepare(
            "UPDATE account_documents
             SET status = 'FAILED', updated_at = NOW()
             WHERE id = ? AND status = 'PENDING_UPLOAD'
               AND upload_expires_at IS NOT NULL
               AND upload_expires_at < UTC_TIMESTAMP()"
        );
        if (!$stmt) continue;
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
