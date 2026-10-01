<?php

declare(strict_types=1);

/**
 * Read-only final QA verifier for ProcureDesk/AcctLab R2 purchase documents.
 * It validates schema/index health and document-version consistency without
 * changing procurement, Account, approval or payment state.
 */

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/procurementDocumentStorageService.php';

function procurementDocumentQaCount(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Unable to execute document QA query: ' . $conn->error);
    }
    $row = $result->fetch_assoc() ?: [];
    $result->free();
    return (int) ($row['total'] ?? 0);
}

function procurementDocumentQaIndexes(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'procurement_purchase_documents'"
    );
    if (!$result) {
        return [];
    }
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = (string) ($row['INDEX_NAME'] ?? '');
    }
    $result->free();
    return array_values(array_filter($indexes));
}

$checks = [];
$metrics = [];
try {
    procurementDocumentAssertStorageReady($conn);
    $checks['storage_ready'] = true;

    $settings = procurementDocumentSettings($conn);
    $checks['settings_valid'] =
        (int) ($settings['max_file_size_bytes'] ?? 0) >= 1048576
        && (int) ($settings['max_file_size_bytes'] ?? 0) <= 104857600
        && ($settings['allowed_extensions'] ?? []) !== []
        && array_diff($settings['allowed_extensions'] ?? [], PROCUREMENT_DOCUMENT_SUPPORTED_EXTENSIONS) === [];

    $indexes = procurementDocumentQaIndexes($conn);
    $requiredIndexes = [
        'uq_procurement_document_storage_key',
        'uq_procurement_document_group_version',
        'idx_procurement_document_request_status',
        'idx_procurement_document_group_status',
        'idx_procurement_document_expiry',
    ];
    $missingIndexes = array_values(array_diff($requiredIndexes, $indexes));
    $checks['report_and_cleanup_indexes_present'] = $missingIndexes === [];
    $metrics['missing_indexes'] = $missingIndexes;

    $metrics['documents_total'] = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_purchase_documents"
    );
    $metrics['active_documents'] = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_purchase_documents WHERE status = 'ACTIVE'"
    );
    $metrics['superseded_documents'] = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_purchase_documents WHERE status = 'SUPERSEDED'"
    );
    $metrics['failed_uploads'] = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total FROM procurement_purchase_documents WHERE status = 'FAILED'"
    );
    $metrics['expired_pending_uploads'] = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_purchase_documents
         WHERE status = 'PENDING_UPLOAD'
           AND upload_expires_at IS NOT NULL
           AND upload_expires_at < UTC_TIMESTAMP()"
    );

    $duplicateActive = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total FROM (
            SELECT document_group_uuid
            FROM procurement_purchase_documents
            WHERE status = 'ACTIVE'
            GROUP BY document_group_uuid
            HAVING COUNT(*) > 1
         ) duplicate_groups"
    );
    $checks['one_active_version_per_document_group'] = $duplicateActive === 0;
    $metrics['duplicate_active_groups'] = $duplicateActive;

    $invalidActiveMetadata = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_purchase_documents
         WHERE status = 'ACTIVE'
           AND (uploaded_at IS NULL OR file_size <= 0 OR storage_key = '' OR original_filename = '')"
    );
    $checks['active_metadata_complete'] = $invalidActiveMetadata === 0;
    $metrics['invalid_active_metadata'] = $invalidActiveMetadata;

    $brokenSuperseded = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_purchase_documents d
         LEFT JOIN procurement_purchase_documents n ON n.id = d.superseded_by_document_id
         WHERE d.status = 'SUPERSEDED'
           AND (d.superseded_by_document_id IS NULL
                OR n.id IS NULL
                OR n.document_group_uuid <> d.document_group_uuid
                OR n.version_number <= d.version_number)"
    );
    $checks['replacement_history_consistent'] = $brokenSuperseded === 0;
    $metrics['broken_superseded_links'] = $brokenSuperseded;

    $orphanRequests = procurementDocumentQaCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_purchase_documents d
         LEFT JOIN procurement_requests r ON r.id = d.procurement_request_id
         WHERE r.id IS NULL"
    );
    $checks['canonical_request_links_valid'] = $orphanRequests === 0;
    $metrics['orphan_request_links'] = $orphanRequests;

    // Expired pending uploads are recoverable housekeeping rather than data
    // corruption. Surface them as a warning and clear them with the cleanup job.
    $checks['expired_upload_cleanup_recommended'] = $metrics['expired_pending_uploads'] > 0;

    $remoteId = max(0, (int) (getenv('PROCUREMENT_DOCUMENT_VERIFY_REMOTE_ID') ?: 0));
    if ($remoteId > 0) {
        $document = procurementDocumentFetch($conn, $remoteId);
        if (!$document || (string) ($document['status'] ?? '') !== PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
            $checks['optional_remote_object_check'] = false;
            $metrics['optional_remote_object_error'] = 'Requested verification document is not active.';
        } else {
            try {
                procurementDocumentVerifyStoredObject($document);
                $checks['optional_remote_object_check'] = true;
            } catch (Throwable $error) {
                $checks['optional_remote_object_check'] = false;
                $metrics['optional_remote_object_error'] = $error->getMessage();
            }
        }
    } else {
        $checks['optional_remote_object_check'] = null;
    }
} catch (Throwable $error) {
    $checks['storage_ready'] = false;
    $metrics['error'] = $error->getMessage();
}

$hardChecks = $checks;
unset($hardChecks['expired_upload_cleanup_recommended'], $hardChecks['optional_remote_object_check']);
$healthy = !in_array(false, $hardChecks, true)
    && (($checks['optional_remote_object_check'] ?? null) !== false);

$output = [
    'healthy' => $healthy,
    'checks' => $checks,
    'metrics' => $metrics,
    'cleanup_command' => $metrics['expired_pending_uploads'] ?? 0
        ? 'php cron/cleanupProcurementDocumentUploads.php'
        : null,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($healthy ? 0 : 1);
