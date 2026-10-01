<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/connection.php';
require_once dirname(__DIR__) . '/includes/procurementDocumentStorageService.php';
require_once dirname(__DIR__) . '/includes/accountDocumentStorageService.php';

function documentQaScalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('Document QA query failed: ' . $conn->error);
    }
    $row = $result->fetch_assoc() ?: [];
    $result->free();
    return (int) (array_values($row)[0] ?? 0);
}

function documentQaIndexNames(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare(
        'SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_values(array_unique(array_map(static fn(array $row): string => (string) $row['INDEX_NAME'], $rows)));
}

function documentQaR2Sample(mysqli $conn, string $table, int $limit, callable $verify): array
{
    $limit = max(1, min(25, $limit));
    $result = $conn->query(
        "SELECT * FROM {$table}
         WHERE status = 'ACTIVE'
         ORDER BY uploaded_at DESC, id DESC
         LIMIT {$limit}"
    );
    if (!$result) {
        throw new RuntimeException('Unable to load R2 verification sample for ' . $table . '.');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $checked = 0;
    $failures = [];
    foreach ($rows as $row) {
        try {
            $verify($row);
            $checked++;
        } catch (Throwable $error) {
            $failures[] = [
                'document_id' => (int) ($row['id'] ?? 0),
                'storage_key' => (string) ($row['storage_key'] ?? ''),
                'message' => $error->getMessage(),
            ];
        }
    }
    return ['sampled' => count($rows), 'verified' => $checked, 'failures' => $failures];
}

try {
    $args = array_slice($argv ?? [], 1);
    $checkR2 = in_array('--r2', $args, true);
    $sampleLimit = 10;
    foreach ($args as $arg) {
        if (preg_match('/^--limit=(\d+)$/', (string) $arg, $matches)) {
            $sampleLimit = max(1, min(25, (int) $matches[1]));
        }
    }

    procurementDocumentAssertStorageReady($conn);
    accountDocumentAssertStorageReady($conn);
    $settings = procurementDocumentSettings($conn);

    $procurementIndexes = documentQaIndexNames($conn, 'procurement_purchase_documents');
    $accountIndexes = documentQaIndexNames($conn, 'account_documents');
    $requiredProcurementIndexes = [
        'idx_procurement_document_request_status',
        'idx_procurement_document_group_status',
        'idx_procurement_document_expiry',
    ];
    $requiredAccountIndexes = [
        'idx_account_document_entity_status',
        'idx_account_document_group_status',
        'idx_account_document_expiry',
    ];

    $checks = [
        'r2_storage_configured' => ($settings['storage_configured'] ?? false) === true,
        'procurement_required_indexes' => array_diff($requiredProcurementIndexes, $procurementIndexes) === [],
        'account_required_indexes' => array_diff($requiredAccountIndexes, $accountIndexes) === [],
        'procurement_orphan_requests' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM procurement_purchase_documents d
             LEFT JOIN procurement_requests r ON r.id = d.procurement_request_id
             WHERE r.id IS NULL") === 0,
        'account_unknown_owner_types' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents
             WHERE entity_type NOT IN ('supplier_fund_request','advance_payment_request','fx_fund_request','compass_fund_request','payment_batch')") === 0,
        'account_orphan_payment_batches' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents d
             LEFT JOIN account_payment_batches b ON b.id = d.entity_id
             WHERE d.entity_type = 'payment_batch' AND b.id IS NULL") === 0,
        'account_orphan_supplier_requests' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents d
             LEFT JOIN supplier_fund_request_table r ON r.id = d.entity_id
             WHERE d.entity_type = 'supplier_fund_request' AND r.id IS NULL") === 0,
        'account_orphan_advance_requests' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents d
             LEFT JOIN advance_payment_request r ON r.id = d.entity_id
             WHERE d.entity_type = 'advance_payment_request' AND r.id IS NULL") === 0,
        'account_orphan_fx_requests' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents d
             LEFT JOIN fx_fund_request_table r ON r.id = d.entity_id
             WHERE d.entity_type = 'fx_fund_request' AND r.id IS NULL") === 0,
        'account_orphan_compass_requests' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents d
             LEFT JOIN compass_fund_request_table r ON r.id = d.entity_id
             WHERE d.entity_type = 'compass_fund_request' AND r.id IS NULL") === 0,
        'procurement_single_active_version_per_group' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM (
                SELECT document_group_uuid
                FROM procurement_purchase_documents
                WHERE status = 'ACTIVE'
                GROUP BY document_group_uuid
                HAVING COUNT(*) > 1
             ) duplicate_groups") === 0,
        'account_single_active_version_per_group' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM (
                SELECT document_group_uuid
                FROM account_documents
                WHERE status = 'ACTIVE'
                GROUP BY document_group_uuid
                HAVING COUNT(*) > 1
             ) duplicate_groups") === 0,
        'procurement_active_upload_metadata_complete' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM procurement_purchase_documents
             WHERE status = 'ACTIVE' AND (uploaded_at IS NULL OR uploaded_by_user_id IS NULL OR uploaded_by_email = '')") === 0,
        'account_active_upload_metadata_complete' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents
             WHERE status = 'ACTIVE' AND (uploaded_at IS NULL OR uploaded_by_user_id IS NULL OR uploaded_by_email = '')") === 0,
        'procurement_superseded_audit_metadata_complete' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM procurement_purchase_documents
             WHERE status = 'SUPERSEDED' AND (superseded_by_document_id IS NULL OR superseded_at IS NULL)") === 0,
        'account_superseded_audit_metadata_complete' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents
             WHERE status = 'SUPERSEDED' AND (superseded_by_document_id IS NULL OR superseded_at IS NULL)") === 0,
        'procurement_no_expired_pending_uploads' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM procurement_purchase_documents
             WHERE status = 'PENDING_UPLOAD' AND upload_expires_at IS NOT NULL AND upload_expires_at < UTC_TIMESTAMP()") === 0,
        'account_no_expired_pending_uploads' => documentQaScalar($conn,
            "SELECT COUNT(*) FROM account_documents
             WHERE status = 'PENDING_UPLOAD' AND upload_expires_at IS NOT NULL AND upload_expires_at < UTC_TIMESTAMP()") === 0,
    ];

    $r2 = null;
    if ($checkR2) {
        $r2 = [
            'procurement' => documentQaR2Sample(
                $conn,
                'procurement_purchase_documents',
                $sampleLimit,
                static fn(array $row): array => procurementDocumentVerifyStoredObject($row)
            ),
            'account' => documentQaR2Sample(
                $conn,
                'account_documents',
                $sampleLimit,
                static fn(array $row): array => accountDocumentVerifyStoredObject($row)
            ),
        ];
        $checks['procurement_r2_sample'] = $r2['procurement']['failures'] === [];
        $checks['account_r2_sample'] = $r2['account']['failures'] === [];
    }

    $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
    $payload = [
        'status' => $failed === [] ? 'Success' : 'Failed',
        'checks' => $checks,
        'failed_checks' => $failed,
        'shared_document_policy' => [
            'max_file_size_mb' => $settings['max_file_size_mb'] ?? null,
            'allowed_extensions' => $settings['allowed_extensions'] ?? [],
        ],
        'r2_object_verification' => $checkR2 ? $r2 : 'Skipped (run with --r2 to verify recent active objects).',
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Document storage final QA failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
