<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';

/**
 * Canonical procurement-request runtime writer.
 * Local Final and Local Advance requests, handoffs and request events now use
 * canonical storage directly; historical consolidation assets are not required.
 */
function procurementRequestCanonicalAssertReady(mysqli $conn): void
{
    static $ready = [];
    $key = spl_object_id($conn);
    if (isset($ready[$key])) {
        return;
    }

    foreach (['procurement_requests', 'procurement_request_handoffs', 'workflow_events'] as $table) {
        if (!procurementRequestCanonicalRuntimeObjectType($conn, $table) === 'BASE TABLE') {
            throw new RuntimeException('Canonical procurement request storage is incomplete: ' . $table, 500);
        }
    }
    foreach (['purchase_number_normalized', 'purchase_value', 'po_id', 'po_percentage', 'expected_payment'] as $column) {
        if (!procurementRequestCanonicalRuntimeColumnExists($conn, 'procurement_requests', $column)) {
            throw new RuntimeException(
                'Canonical procurement request storage is not flattened. Run reduceProcurementRequestTables.php --action=apply.',
                500
            );
        }
    }
    $ready[$key] = true;
}

function procurementRequestCanonicalNextLegacyId(mysqli $conn, string $requestType): int
{
    procurementRequestCanonicalAssertReady($conn);
    if (!in_array($requestType, [
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
    ], true)) {
        throw new InvalidArgumentException('Unsupported procurement request type: ' . $requestType);
    }

    $stmt = $conn->prepare(
        'SELECT legacy_source_id
         FROM procurement_requests
         WHERE request_type = ?
         ORDER BY legacy_source_id DESC
         LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('s', $requestType);
    $stmt->execute();
    $lastId = (int) ($stmt->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $stmt->close();

    $nextId = $lastId + 1;
    $sourceTable = $requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL
        ? 'procurement_local_final_purchases'
        : 'procurement_local_advance_purchases';
    $sequenceStmt = $conn->prepare(
        "SELECT AUTO_INCREMENT
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'
         LIMIT 1"
    );
    $sequenceStmt->bind_param('s', $sourceTable);
    $sequenceStmt->execute();
    $autoIncrement = (int) ($sequenceStmt->get_result()->fetch_assoc()['AUTO_INCREMENT'] ?? 0);
    $sequenceStmt->close();

    return max($nextId, $autoIncrement);
}

function procurementRequestCanonicalResolveRequestId(
    mysqli $conn,
    string $requestType,
    int $legacyRequestId,
    bool $forUpdate = false
): int {
    procurementRequestCanonicalAssertReady($conn);
    $sourceTable = $requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL
        ? 'procurement_local_final_purchases'
        : ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE
            ? 'procurement_local_advance_purchases'
            : ($requestType === PROCUREMENT_REQUEST_TYPE_FX_FINAL
                ? PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE
                : ($requestType === PROCUREMENT_REQUEST_TYPE_FX_ADVANCE
                    ? PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE
                    : '')));
    if ($sourceTable === '' || $legacyRequestId <= 0) {
        throw new InvalidArgumentException('Unsupported canonical procurement request mapping.');
    }

    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ? AND legacy_source_id = ?
         LIMIT 1{$lock}"
    );
    $stmt->bind_param('ssi', $requestType, $sourceTable, $legacyRequestId);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();

    if ($id <= 0) {
        if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL) {
            procurementRequestCanonicalSyncLocalFinalRequest($conn, $legacyRequestId);
        } elseif ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE) {
            procurementRequestCanonicalSyncLocalAdvanceRequest($conn, $legacyRequestId);
        } elseif ($requestType === PROCUREMENT_REQUEST_TYPE_FX_FINAL) {
            procurementRequestCanonicalSyncFxFinalRequest($conn, $legacyRequestId);
        } elseif ($requestType === PROCUREMENT_REQUEST_TYPE_FX_ADVANCE) {
            procurementRequestCanonicalSyncFxAdvanceRequest($conn, $legacyRequestId);
        }
        $stmt = $conn->prepare(
            'SELECT id FROM procurement_requests
             WHERE request_type = ? AND legacy_source_table = ? AND legacy_source_id = ? LIMIT 1'
        );
        $stmt->bind_param('ssi', $requestType, $sourceTable, $legacyRequestId);
        $stmt->execute();
        $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }

    if ($id <= 0) {
        throw new RuntimeException('Canonical procurement request mapping could not be resolved.', 500);
    }
    return $id;
}

function procurementRequestCanonicalRecordEvent(
    mysqli $conn,
    string $requestType,
    int $legacyRequestId,
    string $eventType,
    int $actorId,
    string $actorEmail,
    ?string $detailsJson
): int {
    $requestId = procurementRequestCanonicalResolveRequestId(
        $conn,
        $requestType,
        $legacyRequestId,
        true
    );

    return workflowEventRecordRequest(
        $conn,
        $requestId,
        $requestType,
        $eventType,
        $actorId,
        $actorEmail,
        $detailsJson
    );
}

function procurementRequestCanonicalUpsertHandoff(
    mysqli $conn,
    string $requestType,
    int $legacyRequestId,
    int $revision,
    string $accountRequestType,
    int $accountRequestId,
    string $handoffStatus,
    int $sentBy,
    ?int $retrievedBy = null,
    ?string $retrievalSource = null,
    ?string $retrievalReason = null,
    ?string $accountRequestSnapshotJson = null,
    ?int $poRevisionId = null,
    ?int $poRevisionNumber = null,
    ?string $poSnapshotJson = null
): void {
    $requestId = procurementRequestCanonicalResolveRequestId($conn, $requestType, $legacyRequestId, true);
    $stmt = $conn->prepare(
        "INSERT INTO procurement_request_handoffs
            (request_id, request_type, legacy_source_table, legacy_source_id,
             revision, po_revision_id, po_revision_number, po_snapshot_json,
             account_request_type, account_request_id, handoff_status,
             sent_by, sent_at, retrieved_by, retrieved_at,
             retrieval_source, retrieval_reason, account_request_snapshot_json)
         VALUES (?, ?, NULL, NULL, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?, NOW(),
                 NULLIF(?, 0), CASE WHEN NULLIF(?, 0) IS NULL THEN NULL ELSE NOW() END, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            po_revision_id = VALUES(po_revision_id),
            po_revision_number = VALUES(po_revision_number),
            po_snapshot_json = VALUES(po_snapshot_json),
            account_request_type = VALUES(account_request_type),
            account_request_id = VALUES(account_request_id),
            handoff_status = VALUES(handoff_status),
            retrieved_by = VALUES(retrieved_by),
            retrieved_at = VALUES(retrieved_at),
            retrieval_source = VALUES(retrieval_source),
            retrieval_reason = VALUES(retrieval_reason),
            account_request_snapshot_json = VALUES(account_request_snapshot_json)"
    );
    $retrievedByValue = $retrievedBy ?? 0;
    $stmt->bind_param(
        'isiiissisiiisss',
        $requestId,
        $requestType,
        $revision,
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson,
        $accountRequestType,
        $accountRequestId,
        $handoffStatus,
        $sentBy,
        $retrievedByValue,
        $retrievedByValue,
        $retrievalSource,
        $retrievalReason,
        $accountRequestSnapshotJson
    );
    $stmt->execute();
    $stmt->close();
}

function procurementRequestCanonicalSyncRequest(mysqli $conn, string $requestType, int $legacyRequestId): void
{
    if ($legacyRequestId <= 0) {
        return;
    }
    procurementRequestCanonicalAssertReady($conn);

    if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL) {
        procurementRequestCanonicalSyncLocalFinalRequest($conn, $legacyRequestId);
        procurementRequestCanonicalSyncLocalFinalEvents($conn, $legacyRequestId);
        procurementRequestCanonicalSyncLocalFinalHandoffs($conn, $legacyRequestId);
        return;
    }
    if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE) {
        procurementRequestCanonicalSyncLocalAdvanceRequest($conn, $legacyRequestId);
        procurementRequestCanonicalSyncLocalAdvanceEvents($conn, $legacyRequestId);
        procurementRequestCanonicalSyncLocalAdvanceHandoffs($conn, $legacyRequestId);
        return;
    }
    if ($requestType === PROCUREMENT_REQUEST_TYPE_FX_FINAL) {
        procurementRequestCanonicalSyncFxFinalRequest($conn, $legacyRequestId);
        return;
    }
    if ($requestType === PROCUREMENT_REQUEST_TYPE_FX_ADVANCE) {
        procurementRequestCanonicalSyncFxAdvanceRequest($conn, $legacyRequestId);
        return;
    }
    throw new InvalidArgumentException('Unsupported procurement request type: ' . $requestType);
}

function procurementRequestCanonicalSyncRequests(mysqli $conn, string $requestType, array $legacyRequestIds): void
{
    foreach (array_values(array_unique(array_filter(array_map('intval', $legacyRequestIds)))) as $id) {
        procurementRequestCanonicalSyncRequest($conn, $requestType, $id);
    }
}

function procurementRequestCanonicalTrySyncRequest(
    mysqli $conn,
    string $requestType,
    int $legacyRequestId,
    string $operation = 'runtime_write'
): bool {
    if ($legacyRequestId <= 0) {
        return true;
    }
    try {
        $conn->begin_transaction();
        procurementRequestCanonicalSyncRequest($conn, $requestType, $legacyRequestId);
        $conn->commit();
        return true;
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable) {
        }
        error_log(sprintf(
            'Procurement canonical mirror failed [%s] type=%s legacy_id=%d error=%s',
            $operation,
            $requestType,
            $legacyRequestId,
            $error->getMessage()
        ));
        return false;
    }
}

function procurementRequestCanonicalSyncAdvancePo(mysqli $conn, int $poId): void
{
    if ($poId <= 0) {
        return;
    }
    procurementRequestCanonicalAssertReady($conn);
    $stmt = $conn->prepare(
        "SELECT legacy_source_id AS id
         FROM procurement_requests
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND po_id = ?
         ORDER BY legacy_source_id ASC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    procurementRequestCanonicalSyncRequests($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE, $ids);
}

function procurementRequestCanonicalUpsertSql(array $columns, array $select, string $fromSql): string
{
    $immutable = ['legacy_source_table', 'legacy_source_id'];
    $updates = [];
    foreach ($columns as $column) {
        if (!in_array($column, $immutable, true)) {
            $updates[] = "{$column} = VALUES({$column})";
        }
    }
    return 'INSERT INTO procurement_requests (' . implode(', ', $columns) . ') SELECT '
        . implode(', ', $select) . ' ' . $fromSql
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
}

function procurementRequestCanonicalCommonColumns(): array
{
    return [
        'request_type', 'request_number', 'legacy_source_table', 'legacy_source_id',
        'po_number', 'purchase_number', 'project_id', 'project_code', 'project_name',
        'supplier_id', 'supplier_name', 'supplier_ledger', 'wht_status', 'wht_rate', 'wht_amount',
        'account_wht_override_status', 'account_wht_override_rate', 'account_wht_override_amount',
        'account_payable_amount', 'account_wht_adjustment_reason', 'account_wht_adjusted_by',
        'account_wht_adjusted_at', 'remark', 'transaction_date', 'date_received', 'po_status',
        'payment_status', 'payment_status_source', 'payment_status_updated_at', 'approval_status',
        'handoff_status', 'handoff_revision', 'account_request_type', 'account_request_id',
        'previous_account_request_id', 'approved_by', 'approved_at', 'approval_reversed_by',
        'approval_reversed_at', 'retrieved_by', 'retrieved_at', 'retrieval_reason', 'retrieval_source',
        'account_amount_paid', 'account_processing_method', 'account_processing_reference',
        'account_processing_started_at', 'account_expected_completion_at', 'account_completion_mode',
        'account_confirmation_status', 'account_payment_reference', 'account_paid_at',
        'account_payment_remarks', 'account_payment_batch_id', 'created_by', 'created_at',
        'updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'version',
    ];
}

function procurementRequestCanonicalSyncLocalFinalRequest(mysqli $conn, int $legacyRequestId): void
{
    procurementRequestCanonicalAssertReady($conn);
    if ($legacyRequestId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = 'local_final_purchase'
           AND legacy_source_table = 'procurement_local_final_purchases'
           AND legacy_source_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $legacyRequestId);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0) > 0;
    $stmt->close();

    if (!$exists) {
        throw new RuntimeException('Canonical Local Final Purchase row could not be resolved.', 500);
    }
}

function procurementRequestCanonicalSyncLocalAdvanceRequest(mysqli $conn, int $legacyRequestId): void
{
    procurementRequestCanonicalAssertReady($conn);
    if ($legacyRequestId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = 'local_advance_purchase'
           AND legacy_source_table = 'procurement_local_advance_purchases'
           AND legacy_source_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $legacyRequestId);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0) > 0;
    $stmt->close();

    if (!$exists) {
        throw new RuntimeException('Canonical Local Advance Purchase row could not be resolved.', 500);
    }
}


function procurementRequestCanonicalSyncFxFinalRequest(mysqli $conn, int $legacyRequestId): void
{
    procurementRequestCanonicalAssertReady($conn);
    if ($legacyRequestId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ?
           AND legacy_source_id = ? LIMIT 1"
    );
    $requestType = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE;
    $stmt->bind_param('ssi', $requestType, $source, $legacyRequestId);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0) > 0;
    $stmt->close();

    if (!$exists) {
        throw new RuntimeException('Canonical FX Final Purchase row could not be resolved.', 500);
    }
}

function procurementRequestCanonicalSyncFxAdvanceRequest(mysqli $conn, int $legacyRequestId): void
{
    procurementRequestCanonicalAssertReady($conn);
    if ($legacyRequestId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ?
           AND legacy_source_id = ? LIMIT 1"
    );
    $requestType = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $stmt->bind_param('ssi', $requestType, $source, $legacyRequestId);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0) > 0;
    $stmt->close();

    if (!$exists) {
        throw new RuntimeException('Canonical FX Advance Purchase row could not be resolved.', 500);
    }
}

function procurementRequestCanonicalSyncLocalFinalEvents(mysqli $conn, int $legacyRequestId): void
{
    // Runtime request events are canonical-only. Historical source-event migration was
    // completed by the workflow-event consolidation and is no longer replayed here.
    procurementRequestCanonicalAssertReady($conn);
    if ($legacyRequestId <= 0) {
        return;
    }
}

function procurementRequestCanonicalSyncLocalAdvanceEvents(mysqli $conn, int $legacyRequestId): void
{
    // Runtime request events are canonical-only. Historical source-event migration was
    // completed by the workflow-event consolidation and is no longer replayed here.
    procurementRequestCanonicalAssertReady($conn);
    if ($legacyRequestId <= 0) {
        return;
    }
}

function procurementRequestCanonicalSyncLocalFinalHandoffs(mysqli $conn, int $legacyRequestId): void
{
    if (!procurementRequestCanonicalRuntimeObjectExists($conn, 'procurement_local_final_purchase_handoffs')) {
        return;
    }
    $id = (int) $legacyRequestId;
    $conn->query(
        "INSERT INTO procurement_request_handoffs
            (request_id, request_type, legacy_source_table, legacy_source_id, revision,
             account_request_type, account_request_id, handoff_status, sent_by, sent_at,
             retrieved_by, retrieved_at, retrieval_source, retrieval_reason, account_request_snapshot_json)
         SELECT r.id, 'local_final_purchase', 'procurement_local_final_purchase_handoffs', h.id,
                h.revision, 'supplier_fund_request', h.supplier_fund_request_id, h.handoff_status,
                h.sent_by, h.sent_at, h.retrieved_by, h.retrieved_at,
                h.retrieval_source, h.retrieval_reason, h.supplier_request_snapshot_json
         FROM procurement_local_final_purchase_handoffs h
         INNER JOIN procurement_requests r
           ON r.legacy_source_table = 'procurement_local_final_purchases'
          AND r.legacy_source_id = h.purchase_id
         WHERE h.purchase_id = {$id}
         ON DUPLICATE KEY UPDATE revision = VALUES(revision), account_request_id = VALUES(account_request_id),
             handoff_status = VALUES(handoff_status), sent_by = VALUES(sent_by), sent_at = VALUES(sent_at),
             retrieved_by = VALUES(retrieved_by), retrieved_at = VALUES(retrieved_at),
             retrieval_source = VALUES(retrieval_source), retrieval_reason = VALUES(retrieval_reason),
             account_request_snapshot_json = VALUES(account_request_snapshot_json)"
    );
}

function procurementRequestCanonicalSyncLocalAdvanceHandoffs(mysqli $conn, int $legacyRequestId): void
{
    if (!procurementRequestCanonicalRuntimeObjectExists($conn, 'procurement_local_advance_purchase_handoffs')) {
        return;
    }
    $id = (int) $legacyRequestId;
    $conn->query(
        "INSERT INTO procurement_request_handoffs
            (request_id, request_type, legacy_source_table, legacy_source_id, revision,
             po_revision_id, po_revision_number, po_snapshot_json,
             account_request_type, account_request_id, handoff_status, sent_by, sent_at,
             retrieved_by, retrieved_at, retrieval_source, retrieval_reason, account_request_snapshot_json)
         SELECT r.id, 'local_advance_purchase', 'procurement_local_advance_purchase_handoffs', h.id,
                h.revision, h.po_revision_id, h.po_revision_number, h.po_snapshot_json,
                'advance_payment_request', h.advance_payment_request_id, h.handoff_status,
                h.sent_by, h.sent_at, h.retrieved_by, h.retrieved_at,
                h.retrieval_source, h.retrieval_reason, h.advance_request_snapshot_json
         FROM procurement_local_advance_purchase_handoffs h
         INNER JOIN procurement_requests r
           ON r.legacy_source_table = 'procurement_local_advance_purchases'
          AND r.legacy_source_id = h.purchase_id
         WHERE h.purchase_id = {$id}
         ON DUPLICATE KEY UPDATE revision = VALUES(revision), po_revision_id = VALUES(po_revision_id),
             po_revision_number = VALUES(po_revision_number), po_snapshot_json = VALUES(po_snapshot_json),
             account_request_id = VALUES(account_request_id), handoff_status = VALUES(handoff_status),
             sent_by = VALUES(sent_by), sent_at = VALUES(sent_at), retrieved_by = VALUES(retrieved_by),
             retrieved_at = VALUES(retrieved_at), retrieval_source = VALUES(retrieval_source),
             retrieval_reason = VALUES(retrieval_reason),
             account_request_snapshot_json = VALUES(account_request_snapshot_json)"
    );
}
