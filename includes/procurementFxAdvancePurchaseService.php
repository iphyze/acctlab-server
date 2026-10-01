<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementLocalAdvancePurchaseService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';
require_once __DIR__ . '/fxFundRequestService.php';

const PROCUREMENT_FX_ADVANCE_CURRENCIES = ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR'];
const PROCUREMENT_FX_ADVANCE_SCOPE = 'fx_advance_purchase';
const PROCUREMENT_FX_ADVANCE_MAX_BATCH = 100;

function procurementEnsureFxAdvancePurchaseStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    procurementRequestCanonicalFxAdvanceAssertReady($conn);
}

function procurementFxAdvanceNormalizeCurrency(mixed $value): string
{
    $currency = strtoupper(trim((string) $value));
    if (!in_array($currency, PROCUREMENT_FX_ADVANCE_CURRENCIES, true)) {
        throw new RuntimeException(
            'Currency must be one of: ' . implode(', ', PROCUREMENT_FX_ADVANCE_CURRENCIES) . '.',
            400
        );
    }
    return $currency;
}

function procurementFxAdvanceBuildPayload(mysqli $conn, array $data): array
{
    // Deliberately reuse Local Advance commercial, supplier, WHT/VAT and
    // percentage validation. Currency is the FX-only commercial dimension.
    $payload = procurementLocalAdvanceBuildPayload($conn, $data);
    $payload['currency'] = procurementFxAdvanceNormalizeCurrency($data['currency'] ?? '');
    $payload['contact_person'] = procurementLocalAdvanceOptionalText($data, 'contact_person', 255);
    $payload['phone_number'] = procurementLocalAdvanceOptionalText($data, 'phone_number', 80);
    $payload['request_scope'] = PROCUREMENT_FX_ADVANCE_SCOPE;
    return $payload;
}

function procurementFxAdvanceNextPublicId(mysqli $conn): int
{
    $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $stmt = $conn->prepare(
        'SELECT legacy_source_id FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ?
         ORDER BY legacy_source_id DESC LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('ss', $requestType, $source);
    $stmt->execute();
    $lastId = (int) ($stmt->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $stmt->close();
    return $lastId + 1;
}

function procurementFxAdvanceCanonicalRequestId(mysqli $conn, int $publicId, bool $forUpdate = false): int
{
    if ($publicId <= 0) {
        throw new RuntimeException('A valid FX Advance Purchase ID is required.', 400);
    }
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ? AND legacy_source_id = ?
           AND deleted_at IS NULL LIMIT 1{$lock}"
    );
    $stmt->bind_param('ssi', $requestType, $source, $publicId);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($id <= 0) {
        throw new RuntimeException('FX Advance Purchase not found.', 404);
    }
    return $id;
}

function procurementFxAdvanceRecordEvent(
    mysqli $conn,
    int $publicId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $canonicalId = procurementFxAdvanceCanonicalRequestId($conn, $publicId, true);
    $detailsJson = $details === [] ? null : json_encode(
        $details,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $eventId = workflowEventRecordRequest(
        $conn,
        $canonicalId,
        PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE,
        $eventType,
        (int) ($actor['id'] ?? 0),
        (string) ($actor['email'] ?? 'system'),
        $detailsJson
    );

    $notificationDetails = $details;
    if ($eventId > 0) {
        $notificationDetails['procurement_event_id'] = $eventId;
    }
    if (function_exists('procurementNotificationPublishFxAdvanceEvent')) {
        procurementNotificationPublishFxAdvanceEvent($conn, $publicId, $eventType, $actor, $notificationDetails);
    }
    if (function_exists('procurementNotificationMirrorFxAdvanceEventToAccount')) {
        procurementNotificationMirrorFxAdvanceEventToAccount($conn, $publicId, $eventType, $actor, $notificationDetails);
    }
    return $eventId;
}

function procurementFxAdvanceEvents(mysqli $conn, int $publicId): array
{
    if ($publicId <= 0) return [];
    $eventSource = workflowEventReadSource($conn, WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST);
    $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $stmt = $conn->prepare(
        "SELECT COALESCE(e.legacy_source_id, e.id) AS id, e.event_type, e.actor_user_id,
                e.actor_email, e.details_json, e.created_at
         FROM {$eventSource} e
         INNER JOIN procurement_requests r ON r.id = e.request_id
         WHERE r.request_type = ? AND r.legacy_source_table = ? AND r.legacy_source_id = ?
         ORDER BY e.created_at DESC, e.id DESC"
    );
    $stmt->bind_param('ssi', $requestType, $source, $publicId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$event) {
        $event['id'] = (int) $event['id'];
        $event['actor_user_id'] = (int) ($event['actor_user_id'] ?? 0);
        $decoded = json_decode((string) ($event['details_json'] ?? ''), true);
        $event['details'] = is_array($decoded) ? $decoded : null;
        unset($event['details_json']);
    }
    unset($event);
    return $rows;
}

function procurementFxAdvanceFetchPoByNormalized(
    mysqli $conn,
    string $normalized,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_pos
         WHERE request_scope = ? AND po_number_normalized = ? LIMIT 1{$lock}"
    );
    $stmt->bind_param('ss', $scope, $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceFetchPo(mysqli $conn, int $poId, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_pos
         WHERE request_scope = ? AND id = ? LIMIT 1{$lock}"
    );
    $stmt->bind_param('si', $scope, $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceFetchPoRevision(mysqli $conn, int $revisionId, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions
         WHERE request_scope = ? AND id = ? LIMIT 1{$lock}"
    );
    $stmt->bind_param('si', $scope, $revisionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceEnsureInitialPoRevision(mysqli $conn, int $poId, int $actorId): array
{
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions
         WHERE request_scope = ? AND po_id = ? AND revision_number = 1 LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('si', $scope, $poId);
    $stmt->execute();
    $revision = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($revision) {
        return $revision;
    }

    $po = procurementFxAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The FX Advance PO could not be found for revision initialization.', 409);
    }
    $reference = procurementFxAdvancePoRevisionReference((string) $po['po_number'], 1);
    $snapshotJson = procurementLocalAdvancePoCommercialSnapshotJson($po);
    $snapshotHash = hash('sha256', $snapshotJson);

    $insert = $conn->prepare(
        "INSERT INTO procurement_local_advance_po_revisions
            (po_id, request_scope, currency, revision_number, revision_reference,
             previous_revision_id, revision_status, amendment_type, amendment_reason,
             po_number, po_number_normalized, project_id, project_code, project_name,
             supplier_id, supplier_name, supplier_ledger, wht_status, wht_rate, wht_amount,
             purchase_value, po_subtotal, po_discount, po_other_charges, po_vat_status,
             po_vat_rate, po_vat_amount, po_value, advance_base_amount, po_status,
             commercial_snapshot_json, snapshot_hash, is_locked,
             created_by, created_at, updated_by, updated_at)
         SELECT p.id, p.request_scope, p.currency, 1, ?, NULL, 'Current', 'Initial', NULL,
                p.po_number, p.po_number_normalized, p.project_id, p.project_code, p.project_name,
                p.supplier_id, p.supplier_name, p.supplier_ledger, p.wht_status, p.wht_rate, p.wht_amount,
                p.purchase_value, p.po_subtotal, p.po_discount, p.po_other_charges, p.po_vat_status,
                p.po_vat_rate, p.po_vat_amount, p.po_value, p.advance_base_amount, p.po_status,
                ?, ?, 0, ?, p.created_at, ?, p.updated_at
         FROM procurement_local_advance_pos p
         WHERE p.id = ? AND p.request_scope = ?"
    );
    $insert->bind_param('sssiiis', $reference, $snapshotJson, $snapshotHash, $actorId, $actorId, $poId, $scope);
    $insert->execute();
    $revisionId = (int) $conn->insert_id;
    $insert->close();
    if ($revisionId <= 0) {
        throw new RuntimeException('The initial FX Advance PO revision could not be created.', 500);
    }

    $update = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET current_revision_id = ?, current_revision_number = 1, amendment_status = 'None',
             updated_by = ?, updated_at = NOW()
         WHERE id = ? AND request_scope = ?"
    );
    $update->bind_param('iiis', $revisionId, $actorId, $poId, $scope);
    $update->execute();
    $update->close();

    return procurementFxAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
}

function procurementFxAdvanceCreatePo(mysqli $conn, array $payload, int $actorId): array
{
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "INSERT INTO procurement_local_advance_pos
            (request_scope, currency, po_number, po_number_normalized,
             project_id, project_code, project_name, supplier_id, supplier_name, supplier_ledger,
             wht_status, wht_rate, wht_amount, purchase_value, po_subtotal, po_discount,
             po_other_charges, po_vat_status, po_vat_rate, po_vat_amount, po_value,
             advance_base_amount, po_status, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $params = [
        $scope, $payload['currency'], $payload['po_number'], $payload['po_number_normalized'],
        $payload['project_id'], $payload['project_code'], $payload['project_name'],
        $payload['supplier_id'], $payload['supplier_name'], $payload['supplier_ledger'],
        $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'], $payload['purchase_value'],
        $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'],
        $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'],
        $payload['po_value'], $payload['advance_base_amount'], $payload['po_status'], $actorId, $actorId,
    ];
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $poId = (int) $conn->insert_id;
    $created = $stmt->affected_rows === 1;
    $stmt->close();

    $revision = procurementFxAdvanceEnsureInitialPoRevision($conn, $poId, $actorId);
    return [
        'id' => $poId,
        'created' => $created,
        'revision_id' => (int) ($revision['id'] ?? 0),
        'revision_number' => (int) ($revision['revision_number'] ?? 1),
        'revision_snapshot_json' => (string) ($revision['commercial_snapshot_json'] ?? ''),
    ];
}

function procurementFxAdvanceCommercialComparison(array $po, array $payload): array
{
    $fields = [
        'currency', 'project_id', 'project_code', 'project_name', 'supplier_id', 'supplier_name',
        'supplier_ledger', 'wht_status', 'wht_rate', 'wht_amount', 'purchase_value',
        'po_subtotal', 'po_discount', 'po_other_charges', 'po_vat_status', 'po_vat_rate',
        'po_vat_amount', 'po_value', 'advance_base_amount',
    ];
    $differences = [];
    foreach ($fields as $field) {
        $left = $po[$field] ?? null;
        $right = $payload[$field] ?? null;
        if ($left === null && $right === null) {
            continue;
        }
        if ((string) $left !== (string) $right) {
            $differences[] = $field;
        }
    }
    return $differences;
}

function procurementFxAdvanceAssertExistingPoCompatible(array $po, array $payload): void
{
    if ((string) ($po['po_status'] ?? '') !== (string) $payload['po_status']) {
        throw new RuntimeException(
            'This FX PO already exists with status ' . ($po['po_status'] ?? '') . '. Use the PO status action to change it.',
            409
        );
    }
    if (procurementFxAdvanceCommercialComparison($po, $payload) !== []) {
        throw new RuntimeException(
            'This FX PO already exists with different currency, project, supplier, tax or value details. Reuse the existing PO details.',
            409
        );
    }
}

function procurementFxAdvanceAssertPoOpen(array $po): void
{
    if (in_array((string) ($po['po_status'] ?? ''), ['Closed', 'Cancelled'], true)) {
        throw new RuntimeException('This FX PO is closed and cannot accept another Advance request.', 409);
    }
}

function procurementFxAdvanceAllocatedUnits(mysqli $conn, int $poId, ?int $excludeId = null): int
{
    $relation = procurementRequestCanonicalFxAdvanceReadRelation();
    $sql = "SELECT COALESCE(SUM(po_percentage), 0) AS allocated
            FROM {$relation} r
            WHERE r.po_id = ? AND r.deleted_at IS NULL AND r.payment_status <> 'Cancelled'";
    if ($excludeId !== null) {
        $sql .= ' AND r.id <> ?';
    }
    $stmt = $conn->prepare($sql);
    if ($excludeId !== null) {
        $stmt->bind_param('ii', $poId, $excludeId);
    } else {
        $stmt->bind_param('i', $poId);
    }
    $stmt->execute();
    $allocated = (float) ($stmt->get_result()->fetch_assoc()['allocated'] ?? 0);
    $stmt->close();
    return (int) round($allocated * PROCUREMENT_LOCAL_ADVANCE_PERCENT_SCALE);
}

function procurementFxAdvanceAssertAllocationAvailable(
    mysqli $conn,
    int $poId,
    int $requestedUnits,
    ?int $excludeId = null
): array {
    $allocated = procurementFxAdvanceAllocatedUnits($conn, $poId, $excludeId);
    $total = $allocated + $requestedUnits;
    if ($total > PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX) {
        throw new RuntimeException(
            'The cumulative FX Advance percentage for this PO cannot exceed 100%.',
            409
        );
    }
    return [
        'allocated_units' => $allocated,
        'total_units' => $total,
        'available_units' => max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $total),
    ];
}

function procurementFxAdvanceCountOtherActiveRequests(mysqli $conn, int $poId, int $excludeId): int
{
    $relation = procurementRequestCanonicalFxAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM {$relation} r
         WHERE r.po_id = ? AND r.id <> ? AND r.deleted_at IS NULL AND r.payment_status <> 'Cancelled'"
    );
    $stmt->bind_param('ii', $poId, $excludeId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function procurementFxAdvanceUpdatePoCommercials(mysqli $conn, int $poId, array $payload, int $actorId): void
{
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_pos SET
            currency = ?, po_number = ?, po_number_normalized = ?, project_id = ?, project_code = ?, project_name = ?,
            supplier_id = ?, supplier_name = ?, supplier_ledger = ?, wht_status = ?, wht_rate = ?, wht_amount = ?,
            purchase_value = ?, po_subtotal = ?, po_discount = ?, po_other_charges = ?, po_vat_status = ?,
            po_vat_rate = ?, po_vat_amount = ?, po_value = ?, advance_base_amount = ?, updated_by = ?,
            updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ?"
    );
    $params = [
        $payload['currency'], $payload['po_number'], $payload['po_number_normalized'], $payload['project_id'],
        $payload['project_code'], $payload['project_name'], $payload['supplier_id'], $payload['supplier_name'],
        $payload['supplier_ledger'], $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'],
        $payload['purchase_value'], $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'],
        $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'], $payload['po_value'],
        $payload['advance_base_amount'], $actorId, $poId, $scope,
    ];
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    $po = procurementFxAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The FX Advance PO could not be refreshed.', 409);
    }
    $revisionId = (int) ($po['current_revision_id'] ?? 0);
    if ($revisionId <= 0) {
        procurementFxAdvanceEnsureInitialPoRevision($conn, $poId, $actorId);
        return;
    }
    $revision = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true);
    if ($revision && (int) ($revision['is_locked'] ?? 0) === 1) {
        throw new RuntimeException(
            'This FX PO revision is locked because payment activity has started. Use PO Amendment instead.',
            409
        );
    }
    $snapshotJson = procurementLocalAdvancePoCommercialSnapshotJson($po);
    $snapshotHash = hash('sha256', $snapshotJson);
    $reference = procurementFxAdvancePoRevisionReference((string) $po['po_number'], (int) ($revision['revision_number'] ?? 1));
    $update = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions revision
         INNER JOIN procurement_local_advance_pos po ON po.id = revision.po_id
         SET revision.request_scope = po.request_scope, revision.currency = po.currency,
             revision.revision_reference = ?, revision.po_number = po.po_number,
             revision.po_number_normalized = po.po_number_normalized,
             revision.project_id = po.project_id, revision.project_code = po.project_code,
             revision.project_name = po.project_name, revision.supplier_id = po.supplier_id,
             revision.supplier_name = po.supplier_name, revision.supplier_ledger = po.supplier_ledger,
             revision.wht_status = po.wht_status, revision.wht_rate = po.wht_rate,
             revision.wht_amount = po.wht_amount, revision.purchase_value = po.purchase_value,
             revision.po_subtotal = po.po_subtotal, revision.po_discount = po.po_discount,
             revision.po_other_charges = po.po_other_charges, revision.po_vat_status = po.po_vat_status,
             revision.po_vat_rate = po.po_vat_rate, revision.po_vat_amount = po.po_vat_amount,
             revision.po_value = po.po_value, revision.advance_base_amount = po.advance_base_amount,
             revision.po_status = po.po_status, revision.commercial_snapshot_json = ?,
             revision.snapshot_hash = ?, revision.updated_by = ?, revision.updated_at = NOW(),
             revision.version = revision.version + 1
         WHERE revision.id = ? AND revision.request_scope = ? AND revision.is_locked = 0"
    );
    $update->bind_param('sssiis', $reference, $snapshotJson, $snapshotHash, $actorId, $revisionId, $scope);
    $update->execute();
    $update->close();
}

function procurementFxAdvanceFetchRecord(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $relation = procurementRequestCanonicalFxAdvanceReadRelation();
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT r.*,
                COALESCE(pr.po_number, p.po_number) AS po_number,
                COALESCE(pr.project_id, p.project_id) AS project_id,
                COALESCE(pr.project_code, p.project_code) AS project_code,
                COALESCE(pr.project_name, p.project_name) AS project_name,
                COALESCE(pr.supplier_id, p.supplier_id) AS supplier_id,
                COALESCE(pr.supplier_name, p.supplier_name) AS supplier_name,
                COALESCE(pr.supplier_ledger, p.supplier_ledger) AS supplier_ledger,
                COALESCE(pr.wht_status, p.wht_status) AS wht_status,
                COALESCE(pr.wht_rate, p.wht_rate) AS wht_rate,
                COALESCE(pr.wht_amount, p.wht_amount) AS wht_amount,
                CASE WHEN pr.id IS NOT NULL THEN pr.purchase_value ELSE p.purchase_value END AS purchase_value,
                COALESCE(pr.po_subtotal, p.po_subtotal) AS po_subtotal,
                COALESCE(pr.po_discount, p.po_discount) AS po_discount,
                COALESCE(pr.po_other_charges, p.po_other_charges) AS po_other_charges,
                COALESCE(pr.po_vat_status, p.po_vat_status) AS po_vat_status,
                COALESCE(pr.po_vat_rate, p.po_vat_rate) AS po_vat_rate,
                COALESCE(pr.po_vat_amount, p.po_vat_amount) AS po_vat_amount,
                COALESCE(pr.po_value, p.po_value) AS po_value,
                COALESCE(pr.advance_base_amount, p.advance_base_amount) AS advance_base_amount,
                p.po_status, p.current_revision_id, p.current_revision_number, p.amendment_status,
                pr.revision_reference AS po_revision_reference,
                pr.revision_status AS po_revision_status,
                pr.is_locked AS po_revision_is_locked,
                ffr.payment_status AS account_payment_status,
                ffr.payment_currency AS account_payment_currency,
                ffr.payment_amount AS amount_paid,
                ffr.exchange_rate AS account_exchange_rate,
                ffr.fx_instruction_letter_id
         FROM {$relation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id AND p.request_scope = ?
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id AND pr.request_scope = ?
         LEFT JOIN fx_fund_request_table ffr ON ffr.id = r.fx_fund_request_id
         WHERE r.id = ? AND r.deleted_at IS NULL LIMIT 1{$lock}"
    );
    $stmt->bind_param('ssi', $scope, $scope, $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceSerializeRecord(array $row): array
{
    foreach ([
        'id', 'canonical_request_id', 'po_id', 'po_revision_id', 'po_revision_number',
        'project_id', 'supplier_id', 'fx_fund_request_id', 'previous_fx_fund_request_id',
        'approved_by', 'approval_reversed_by', 'retrieved_by', 'created_by', 'updated_by',
        'version', 'handoff_revision', 'current_revision_id', 'current_revision_number',
        'fx_instruction_letter_id',
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }
    $row['is_editable'] = ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending'
        && empty($row['fx_fund_request_id']);
    $row['is_deletable'] = $row['is_editable'] && ($row['handoff_status'] ?? '') !== 'Retrieved';
    return $row;
}

function procurementFxAdvanceBatchIds(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Select at least one FX Advance Purchase.', 400);
    }
    $ids = array_values(array_unique(array_filter(
        array_map(static fn(mixed $id): int => (int) $id, $value),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        throw new RuntimeException('No valid FX Advance Purchase IDs were supplied.', 400);
    }
    if (count($ids) > PROCUREMENT_FX_ADVANCE_MAX_BATCH) {
        throw new RuntimeException('A maximum of 100 records can be processed at once.', 400);
    }
    return $ids;
}

function procurementFxAdvanceUpdatePoStatusOne(
    mysqli $conn,
    int $requestId,
    string $poStatus,
    array $actor,
    string $reason = ''
): array {
    $reason = trim($reason);
    if ($poStatus === 'Cancelled' && $reason === '') {
        throw new RuntimeException('A reason is required when cancelling an FX PO.', 400);
    }
    if ($poStatus === 'Cancelled') {
        return procurementFxAdvanceCancelPoOne($conn, $requestId, $actor, $reason);
    }

    $conn->begin_transaction();
    try {
        $record = procurementFxAdvanceFetchRecord($conn, $requestId, true);
        if (!$record) {
            throw new RuntimeException('FX Advance Purchase not found.', 404);
        }
        $poId = (int) ($record['po_id'] ?? 0);
        $po = procurementFxAdvanceFetchPo($conn, $poId, true);
        if (!$po) {
            throw new RuntimeException('The linked FX PO could not be found.', 409);
        }

        $previousStatus = (string) ($po['po_status'] ?? '');
        if ($previousStatus !== $poStatus) {
            $actorId = (int) ($actor['id'] ?? 0);
            $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
            $update = $conn->prepare(
                'UPDATE procurement_local_advance_pos
                 SET po_status = ?, updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE id = ? AND request_scope = ?'
            );
            $update->bind_param('siis', $poStatus, $actorId, $poId, $scope);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException('The FX Advance PO changed before its status could be updated.', 409);
            }
            $update->close();

            $relation = procurementRequestCanonicalFxAdvanceReadRelation();
            $idsStmt = $conn->prepare(
                "SELECT id FROM {$relation} source WHERE po_id = ? AND deleted_at IS NULL"
            );
            $idsStmt->bind_param('i', $poId);
            $idsStmt->execute();
            $affectedIds = array_map(
                static fn(array $row): int => (int) $row['id'],
                $idsStmt->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $idsStmt->close();

            foreach ($affectedIds as $id) {
                procurementFxAdvanceRecordEvent($conn, $id, 'po_status_updated', $actor, [
                    'previous_po_status' => $previousStatus,
                    'po_status' => $poStatus,
                    'reason' => $reason !== '' ? $reason : null,
                ]);
            }
            procurementRequestCanonicalSyncRequests(
                $conn,
                PROCUREMENT_REQUEST_TYPE_FX_ADVANCE,
                $affectedIds
            );
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $requestId) ?? []);
}

function procurementFxAdvanceCancelPoOne(
    mysqli $conn,
    int $purchaseId,
    array $actor,
    string $reason
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required when cancelling an FX PO.', 400);
    }

    procurementSupplierAdjustmentEnsureStorage($conn);
    $conn->begin_transaction();
    try {
        $record = procurementFxAdvanceFetchRecord($conn, $purchaseId, true);
        if (!$record) {
            throw new RuntimeException('FX Advance Purchase not found.', 404);
        }
        $poId = (int) ($record['po_id'] ?? 0);
        $po = procurementFxAdvanceFetchPo($conn, $poId, true);
        if (!$po) {
            throw new RuntimeException('The linked FX PO could not be found.', 409);
        }
        if ((string) ($po['po_status'] ?? '') === 'Cancelled') {
            $conn->commit();
            return procurementFxAdvanceSerializeRecord($record);
        }

        $rows = procurementFxAdvanceFetchAllocationRows($conn, $poId, true);
        foreach ($rows as $row) {
            if (procurementFxAdvanceAllocationPaymentStatus($row) === 'Processing') {
                throw new RuntimeException(
                    'Complete or cancel all Account FX processing payments before cancelling this PO.',
                    409
                );
            }
        }

        $actorId = (int) ($actor['id'] ?? 0);
        procurementSupplierAdjustmentCancelOpenPayablesForPo(
            $conn,
            'fx_advance_purchase',
            $poId,
            $actorId,
            $reason
        );

        $recoverableTotalCents = 0;
        $cancelledPending = [];
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            $status = procurementFxAdvanceAllocationPaymentStatus($row);
            $fundRequestId = (int) ($row['linked_fx_fund_request_id'] ?? 0);

            if ($status === 'Pending') {
                if ($fundRequestId > 0) {
                    $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
                    $stmt->bind_param('i', $fundRequestId);
                    $stmt->execute();
                    $fundRequest = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                    if (!$fundRequest
                        || (string) ($fundRequest['request_type'] ?? '') !== 'Advance'
                        || (string) ($fundRequest['payment_status'] ?? '') !== 'Pending'
                        || (int) ($fundRequest['fx_instruction_letter_id'] ?? 0) > 0
                        || !empty($fundRequest['processed_at'])) {
                        throw new RuntimeException(
                            'A linked Account FX request changed before cancellation completed.',
                            409
                        );
                    }
                    procurementFxAdvanceArchiveHandoff(
                        $conn,
                        $rowId,
                        max(1, (int) ($row['handoff_revision'] ?? 1)),
                        $fundRequestId,
                        $fundRequest,
                        $actor,
                        'Cancelled',
                        'procurement',
                        $reason
                    );
                    $delete = $conn->prepare(
                        "DELETE FROM fx_fund_request_table
                         WHERE id = ? AND request_type = 'Advance' AND payment_status = 'Pending'
                           AND fx_instruction_letter_id IS NULL AND processed_at IS NULL"
                    );
                    $delete->bind_param('i', $fundRequestId);
                    $delete->execute();
                    if ($delete->affected_rows !== 1) {
                        $delete->close();
                        throw new RuntimeException('The linked FX Advance Fund Request could not be removed safely.', 409);
                    }
                    $delete->close();
                }

                $scopeSql = procurementRequestCanonicalFxAdvanceScopeSql();
                $pendingUpdate = $conn->prepare(
                    "UPDATE procurement_requests
                     SET payment_status = 'Cancelled', payment_status_source = 'procuredesk',
                         payment_status_updated_at = NOW(), handoff_status = 'Not Sent',
                         account_request_type = NULL, account_request_id = NULL,
                         previous_account_request_id = COALESCE(NULLIF(?, 0), previous_account_request_id),
                         updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE legacy_source_id = ? AND {$scopeSql} AND deleted_at IS NULL"
                );
                $pendingUpdate->bind_param('iii', $fundRequestId, $actorId, $rowId);
                $pendingUpdate->execute();
                $pendingUpdate->close();
                $cancelledPending[] = $rowId;
                continue;
            }

            if ($status !== 'Paid') {
                continue;
            }

            $paidCents = procurementLocalAdvanceMoneyToCents(
                $row['account_payable_amount_effective'] ?? $row['expected_payment'] ?? 0,
                'Paid FX Commitment',
                true
            );
            if ($paidCents <= 0) {
                continue;
            }

            $supplierId = (int) ($row['exact_revision_supplier_id'] ?? $po['supplier_id'] ?? 0);
            $supplierName = trim((string) ($row['exact_revision_supplier_name'] ?? $po['supplier_name'] ?? ''));
            $supplierLedger = trim((string) ($row['exact_revision_supplier_ledger'] ?? $po['supplier_ledger'] ?? ''));
            $currency = procurementFxAdvanceNormalizeCurrency($row['currency'] ?? $po['currency'] ?? '');
            $alreadyRecordedCents = procurementLocalAdvanceMoneyToCents(
                procurementSupplierAdjustmentRecoverableRecordedForSourceSupplier(
                    $conn,
                    'fx_advance_purchase',
                    $rowId,
                    $supplierId,
                    $currency
                ),
                'Existing FX Recovery',
                true
            );
            $newRecoveryCents = max(0, $paidCents - $alreadyRecordedCents);
            if ($newRecoveryCents <= 0) {
                continue;
            }

            $exactRevisionId = (int) ($row['po_revision_id'] ?? 0);
            $exactRevision = $exactRevisionId > 0
                ? procurementFxAdvanceFetchPoRevision($conn, $exactRevisionId, true)
                : null;
            $snapshotSource = $exactRevision ?: $po;
            $originSnapshot = procurementLocalAdvancePoCommercialSnapshot($snapshotSource);
            $revisedSnapshot = $originSnapshot;
            $revisedSnapshot['po_status'] = 'Cancelled';

            procurementSupplierAdjustmentCreate($conn, [
                'source_type' => 'fx_advance_purchase',
                'source_purchase_id' => $rowId,
                'source_po_id' => $poId,
                'source_revision_id' => $exactRevisionId,
                'source_revision_number' => (int) ($row['po_revision_number'] ?? 0),
                'adjustment_kind' => 'Cancellation',
                'adjustment_direction' => 'Recoverable',
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'supplier_ledger' => $supplierLedger,
                'currency' => $currency,
                'amount' => procurementLocalAdvanceCents($newRecoveryCents),
                'reason' => $reason,
                'origin_snapshot' => $originSnapshot,
                'revised_snapshot' => $revisedSnapshot,
            ], $actorId);
            $recoverableTotalCents += $newRecoveryCents;
        }

        $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
        $cancelDrafts = $conn->prepare(
            "UPDATE procurement_local_advance_po_revisions
             SET revision_status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(),
                 cancellation_reason = ?, updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE po_id = ? AND request_scope = ? AND revision_status IN ('Draft', 'Pending Approval')"
        );
        $cancelDrafts->bind_param('isiis', $actorId, $reason, $actorId, $poId, $scope);
        $cancelDrafts->execute();
        $cancelDrafts->close();

        $poUpdate = $conn->prepare(
            "UPDATE procurement_local_advance_pos
             SET po_status = 'Cancelled', amendment_status = 'Cancelled',
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE id = ? AND request_scope = ?"
        );
        $poUpdate->bind_param('iis', $actorId, $poId, $scope);
        $poUpdate->execute();
        if ($poUpdate->affected_rows !== 1) {
            $poUpdate->close();
            throw new RuntimeException('The FX Advance PO changed before cancellation completed.', 409);
        }
        $poUpdate->close();

        $affectedIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $rows
        );
        foreach ($affectedIds as $affectedId) {
            procurementFxAdvanceRecordEvent($conn, $affectedId, 'po_cancelled', $actor, [
                'reason' => $reason,
                'po_id' => $poId,
                'currency' => (string) ($po['currency'] ?? ''),
                'recoverable_total' => procurementLocalAdvanceCents($recoverableTotalCents),
                'pending_handoffs_cancelled' => $cancelledPending,
            ]);
        }
        procurementRequestCanonicalSyncRequests(
            $conn,
            PROCUREMENT_REQUEST_TYPE_FX_ADVANCE,
            $affectedIds
        );
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $purchaseId) ?? []);
}


function procurementFxAdvanceFundRequestSnapshot(array $request): string
{
    return (string) json_encode(
        $request,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function procurementFxAdvanceCreateHandoff(
    mysqli $conn,
    int $purchaseId,
    int $revision,
    int $fundRequestId,
    int $actorId
): void {
    $record = procurementFxAdvanceFetchRecord($conn, $purchaseId, true);
    if (!$record) {
        throw new RuntimeException('The FX Advance Purchase revision link could not be found.', 409);
    }
    $poRevisionId = (int) ($record['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($record['po_revision_number'] ?? 1));
    $poSnapshotJson = trim((string) ($record['po_snapshot_json'] ?? ''));
    if ($poRevisionId <= 0 || $poSnapshotJson === '') {
        $poRevision = procurementFxAdvanceEnsureInitialPoRevision($conn, (int) $record['po_id'], $actorId);
        $poRevisionId = (int) ($poRevision['id'] ?? 0);
        $poRevisionNumber = max(1, (int) ($poRevision['revision_number'] ?? 1));
        $poSnapshotJson = (string) ($poRevision['commercial_snapshot_json'] ?? '');
    }

    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_FX_ADVANCE,
        $purchaseId,
        $revision,
        'fx_fund_request',
        $fundRequestId,
        'In Account',
        $actorId,
        null,
        null,
        null,
        null,
        $poRevisionId,
        $poRevisionNumber,
        $poSnapshotJson
    );
}

function procurementFxAdvanceArchiveHandoff(
    mysqli $conn,
    int $purchaseId,
    int $revision,
    int $fundRequestId,
    array $fundRequest,
    array $actor,
    string $status,
    string $source,
    string $reason
): void {
    $record = procurementFxAdvanceFetchRecord($conn, $purchaseId, true);
    if (!$record) {
        throw new RuntimeException('The FX Advance Purchase revision link could not be found.', 409);
    }
    $poRevisionId = (int) ($record['po_revision_id'] ?? 0);
    $poRevisionNumber = max(1, (int) ($record['po_revision_number'] ?? 1));
    $poSnapshotJson = trim((string) ($record['po_snapshot_json'] ?? ''));

    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_FX_ADVANCE,
        $purchaseId,
        $revision,
        'fx_fund_request',
        $fundRequestId,
        $status,
        (int) ($actor['id'] ?? 0),
        (int) ($actor['id'] ?? 0),
        $source,
        $reason,
        procurementFxAdvanceFundRequestSnapshot($fundRequest),
        $poRevisionId > 0 ? $poRevisionId : null,
        $poRevisionNumber,
        $poSnapshotJson !== '' ? $poSnapshotJson : null
    );
}

function procurementFxAdvanceCreateFundRequest(mysqli $conn, array $purchase, int $actorId, bool $poLockHeld = false): array
{
    $approvalDate = (string) (($conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc()['approval_date'] ?? date('Y-m-d')));
    $payload = fxFundRequestNormalizePayload([
        'request_type' => 'Advance',
        'suppliers_name' => (string) ($purchase['supplier_name'] ?? ''),
        'suppliers_id' => (int) ($purchase['supplier_id'] ?? 0),
        'po_number' => (string) ($purchase['po_number'] ?? ''),
        'date_received' => $approvalDate,
        'project_code' => (string) ($purchase['project_code'] ?? ''),
        'currency' => (string) ($purchase['currency'] ?? ''),
        'sub_total' => $purchase['po_subtotal'] ?? '0.00',
        'discount' => $purchase['po_discount'] ?? '0.00',
        'other_charges' => $purchase['po_other_charges'] ?? '0.00',
        'vat_rate' => $purchase['po_vat_rate'] ?? '0',
        'wht_rate' => $purchase['wht_rate'] ?? '0',
        'percentage' => $purchase['po_percentage'] ?? null,
    ]);
    fxFundRequestAssertSupplierAndProject($conn, $payload);

    $expectedCents = procurementLocalAdvanceMoneyToCents(
        $purchase['expected_payment'] ?? '0.00',
        'Expected Payment'
    );
    $fundCents = procurementLocalAdvanceMoneyToCents(
        $payload['payable_amount'] ?? '0.00',
        'FX Fund Request Payable Amount'
    );
    if ($expectedCents !== $fundCents) {
        throw new RuntimeException(
            'The FX Advance Purchase totals no longer match the Account FX Fund Request calculation.',
            409
        );
    }

    $lockName = null;
    if (!$poLockHeld) {
        $lockName = fxFundRequestAcquireAdvancePoLock($conn, (string) $payload['po_number']);
    }
    try {
        fxFundRequestAssertAdvancePercentageAvailable(
            $conn,
            (string) $payload['po_number'],
            (float) $payload['percentage'],
            null,
            (string) $payload['currency']
        );

        $requestType = 'Advance';
        $supplierName = (string) $payload['suppliers_name'];
        $supplierId = (int) $payload['suppliers_id'];
        $contactPerson = trim((string) ($purchase['contact_person'] ?? ''));
        $phoneNumber = trim((string) ($purchase['phone_number'] ?? ''));
        $poNumber = (string) $payload['po_number'];
        $dateReceived = (string) $payload['date_received'];
        $projectCode = (string) $payload['project_code'];
        $currency = (string) $payload['currency'];
        $subTotal = (float) $payload['sub_total'];
        $discount = (float) $payload['discount'];
        $otherCharges = (float) $payload['other_charges'];
        $vatRate = (float) $payload['vat_rate'];
        $vatAmount = (float) $payload['vat_amount'];
        $whtRate = (float) $payload['wht_rate'];
        $whtAmount = (float) $payload['wht_amount'];
        $percentage = (float) $payload['percentage'];
        $payableAmount = (float) $payload['payable_amount'];

        $stmt = $conn->prepare(
            "INSERT INTO fx_fund_request_table
                (request_type, suppliers_name, suppliers_id, contact_person, phone_number, invoice_number, purchase_number,
                 po_number, invoice_date, purchase_date, date_received, project_code, currency,
                 sub_total, discount, other_charges, vat_rate, vat_amount, wht_rate, wht_amount,
                 percentage, payable_amount, payment_status, created_by, updated_by)
             VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULL, NULL, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)"
        );
        $stmt->bind_param(
            'ssissssssdddddddddii',
            $requestType,
            $supplierName,
            $supplierId,
            $contactPerson,
            $phoneNumber,
            $poNumber,
            $dateReceived,
            $projectCode,
            $currency,
            $subTotal,
            $discount,
            $otherCharges,
            $vatRate,
            $vatAmount,
            $whtRate,
            $whtAmount,
            $percentage,
            $payableAmount,
            $actorId,
            $actorId
        );
        $stmt->execute();
        $fundRequestId = (int) $stmt->insert_id;
        $stmt->close();
        if ($fundRequestId <= 0) {
            throw new RuntimeException('The FX Advance Fund Request could not be created.', 500);
        }
    } finally {
        if (!$poLockHeld) {
            fxFundRequestReleaseAdvancePoLock($conn, $lockName);
        }
    }

    return ['id' => $fundRequestId, 'payable_amount' => $payableAmount];
}

function procurementFxAdvanceApproveOne(mysqli $conn, int $id, array $actor): array
{
    $preflight = procurementFxAdvanceFetchRecord($conn, $id, false);
    if (!$preflight) throw new RuntimeException('FX Advance Purchase not found.', 404);
    $poLockName = fxFundRequestAcquireAdvancePoLock($conn, (string) ($preflight['po_number'] ?? ''));
    $conn->begin_transaction();
    try {
        $purchase = procurementFxAdvanceFetchRecord($conn, $id, true);
        if (!$purchase) throw new RuntimeException('FX Advance Purchase not found.', 404);
        if ((string) ($purchase['approval_status'] ?? '') !== 'Unapproved') {
            throw new RuntimeException('This FX Advance Purchase is already approved.', 409);
        }
        if ((string) ($purchase['payment_status'] ?? '') !== 'Pending') {
            throw new RuntimeException('Only pending FX Advance Purchases can be approved.', 409);
        }
        if ((string) ($purchase['po_status'] ?? '') === 'Cancelled') {
            throw new RuntimeException('A cancelled PO cannot be approved.', 409);
        }
        if (!empty($purchase['fx_fund_request_id'])) {
            throw new RuntimeException('This purchase still has an active Account handoff.', 409);
        }
        $previousHandoffStatus = (string) ($purchase['handoff_status'] ?? 'Not Sent');
        if (!in_array($previousHandoffStatus, ['Not Sent', 'Retrieved'], true)) {
            throw new RuntimeException('This FX Advance Purchase is not eligible for approval or resubmission.', 409);
        }

        $po = procurementFxAdvanceFetchPo($conn, (int) $purchase['po_id'], true);
        if (!$po) throw new RuntimeException('The linked FX Advance PO could not be found.', 409);
        procurementFxAdvanceAssertPoOpen($po);
        procurementFxAdvanceAssertAllocationAvailable(
            $conn,
            (int) $purchase['po_id'],
            procurementLocalAdvancePercentUnits((string) $purchase['po_percentage']),
            $id
        );

        $revision = max(0, (int) ($purchase['handoff_revision'] ?? 0)) + 1;
        $actorId = (int) ($actor['id'] ?? 0);
        $created = procurementFxAdvanceCreateFundRequest($conn, $purchase, $actorId, true);
        $fundRequestId = (int) $created['id'];
        procurementFxAdvanceCreateHandoff($conn, $id, $revision, $fundRequestId, $actorId);
        $payable = number_format((float) ($purchase['expected_payment'] ?? 0), 2, '.', '');
        $scope = procurementRequestCanonicalFxAdvanceScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Approved', handoff_status = 'In Account', handoff_revision = ?,
                 account_request_type = 'fx_fund_request', account_request_id = ?, account_payable_amount = ?,
                 approved_by = ?, approved_at = NOW(), date_received = CURRENT_DATE(),
                 approval_reversed_by = NULL, approval_reversed_at = NULL,
                 retrieved_by = NULL, retrieved_at = NULL, retrieval_reason = NULL, retrieval_source = NULL,
                 payment_status = 'Pending', payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                 account_amount_paid = 0.00, account_processing_method = NULL,
                 account_processing_reference = NULL, account_processing_started_at = NULL,
                 account_expected_completion_at = NULL, account_completion_mode = NULL,
                 account_confirmation_status = 'Not Scheduled', account_payment_reference = NULL,
                 account_paid_at = NULL, account_payment_remarks = NULL, account_payment_batch_id = NULL,
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
        );
        $update->bind_param('iisiii', $revision, $fundRequestId, $payable, $actorId, $actorId, $id);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('FX Advance Purchase approval state could not be saved.', 409);
        }
        $update->close();

        $eventType = $previousHandoffStatus === 'Retrieved' ? 'resubmitted_to_account' : 'approved';
        procurementFxAdvanceRecordEvent($conn, $id, $eventType, $actor, [
            'fx_fund_request_id' => $fundRequestId,
            'handoff_revision' => $revision,
            'previous_handoff_status' => $previousHandoffStatus,
            'currency' => (string) ($purchase['currency'] ?? ''),
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'po_percentage' => (string) ($purchase['po_percentage'] ?? ''),
            'payable_amount' => $payable,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_ADVANCE, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    } finally {
        fxFundRequestReleaseAdvancePoLock($conn, $poLockName);
    }
    return procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $id) ?? []);
}

function procurementFxAdvanceReverseApprovalOne(mysqli $conn, int $id, array $actor, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('An approval-reversal reason is required.', 400);

    $conn->begin_transaction();
    try {
        $purchase = procurementFxAdvanceFetchRecord($conn, $id, true);
        if (!$purchase) throw new RuntimeException('FX Advance Purchase not found.', 404);
        if ((string) ($purchase['approval_status'] ?? '') !== 'Approved' || empty($purchase['fx_fund_request_id'])) {
            throw new RuntimeException('Only an approved FX Advance Purchase can be unapproved.', 409);
        }
        $fundRequestId = (int) $purchase['fx_fund_request_id'];
        $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $fundRequestId);
        $stmt->execute();
        $fundRequest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fundRequest || (string) ($fundRequest['request_type'] ?? '') !== 'Advance') {
            throw new RuntimeException('The linked FX Advance Fund Request could not be found. Reversal was blocked.', 409);
        }
        if ((string) $fundRequest['payment_status'] !== 'Pending'
            || $fundRequest['fx_instruction_letter_id'] !== null
            || $fundRequest['processed_at'] !== null) {
            throw new RuntimeException('Approval cannot be reversed after Account has started processing the FX Fund Request.', 409);
        }

        $revision = max(1, (int) ($purchase['handoff_revision'] ?? 1));
        procurementFxAdvanceArchiveHandoff($conn, $id, $revision, $fundRequestId, $fundRequest, $actor, 'Approval Reversed', 'procurement', $reason);
        $delete = $conn->prepare("DELETE FROM fx_fund_request_table WHERE id = ? AND request_type = 'Advance' AND payment_status = 'Pending' AND fx_instruction_letter_id IS NULL AND processed_at IS NULL");
        $delete->bind_param('i', $fundRequestId);
        $delete->execute();
        if ($delete->affected_rows !== 1) {
            $delete->close();
            throw new RuntimeException('The linked FX Advance Fund Request could not be removed safely.', 409);
        }
        $delete->close();

        $actorId = (int) ($actor['id'] ?? 0);
        $scope = procurementRequestCanonicalFxAdvanceScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Not Sent', account_request_type = NULL,
                 account_request_id = NULL, previous_account_request_id = ?, approved_by = NULL, approved_at = NULL,
                 approval_reversed_by = ?, approval_reversed_at = NOW(), payment_status = 'Pending',
                 payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
        );
        $update->bind_param('iiii', $fundRequestId, $actorId, $actorId, $id);
        $update->execute();
        $update->close();

        procurementFxAdvanceRecordEvent($conn, $id, 'approval_reversed', $actor, [
            'reason' => $reason,
            'removed_fx_fund_request_id' => $fundRequestId,
            'handoff_revision' => $revision,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_ADVANCE, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $id) ?? []);
}

function procurementFxAdvanceRetrieveOne(
    mysqli $conn,
    int $id,
    array $actor,
    string $reason,
    string $source = 'procurement'
): array {
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('A return or retrieval reason is required.', 400);
    $source = strtolower(trim($source)) === 'account' ? 'account' : 'procurement';

    $conn->begin_transaction();
    try {
        $purchase = procurementFxAdvanceFetchRecord($conn, $id, true);
        if (!$purchase) throw new RuntimeException('FX Advance Purchase not found.', 404);
        if ((string) ($purchase['approval_status'] ?? '') !== 'Approved'
            || (string) ($purchase['handoff_status'] ?? '') !== 'In Account'
            || empty($purchase['fx_fund_request_id'])) {
            throw new RuntimeException('Only an FX Advance Purchase currently awaiting Account payment can be retrieved.', 409);
        }
        if ((string) ($purchase['payment_status'] ?? '') !== 'Pending') {
            throw new RuntimeException('This FX Advance Purchase cannot be retrieved after Account has started processing it.', 409);
        }

        $fundRequestId = (int) $purchase['fx_fund_request_id'];
        $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $fundRequestId);
        $stmt->execute();
        $fundRequest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fundRequest || (string) ($fundRequest['request_type'] ?? '') !== 'Advance') {
            throw new RuntimeException('The active FX Advance Fund Request could not be found.', 409);
        }
        if ((string) $fundRequest['payment_status'] !== 'Pending'
            || $fundRequest['fx_instruction_letter_id'] !== null
            || $fundRequest['processed_at'] !== null) {
            throw new RuntimeException('This FX Advance request is already being processed and cannot be returned.', 409);
        }

        $revision = max(1, (int) ($purchase['handoff_revision'] ?? 1));
        procurementFxAdvanceArchiveHandoff($conn, $id, $revision, $fundRequestId, $fundRequest, $actor, 'Retrieved', $source, $reason);
        $delete = $conn->prepare("DELETE FROM fx_fund_request_table WHERE id = ? AND request_type = 'Advance' AND payment_status = 'Pending' AND fx_instruction_letter_id IS NULL AND processed_at IS NULL");
        $delete->bind_param('i', $fundRequestId);
        $delete->execute();
        if ($delete->affected_rows !== 1) {
            $delete->close();
            throw new RuntimeException('The FX Advance Fund Request changed before it could be returned.', 409);
        }
        $delete->close();

        $actorId = (int) ($actor['id'] ?? 0);
        $scope = procurementRequestCanonicalFxAdvanceScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Retrieved', account_request_type = NULL,
                 account_request_id = NULL, previous_account_request_id = ?, approved_by = NULL, approved_at = NULL,
                 retrieved_by = ?, retrieved_at = NOW(), retrieval_reason = ?, retrieval_source = ?,
                 payment_status = 'Pending', payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
        );
        $update->bind_param('iissii', $fundRequestId, $actorId, $reason, $source, $actorId, $id);
        $update->execute();
        $update->close();

        $eventType = $source === 'account' ? 'returned_by_account' : 'retrieved_from_account';
        procurementFxAdvanceRecordEvent($conn, $id, $eventType, $actor, [
            'reason' => $reason,
            'source' => $source,
            'removed_fx_fund_request_id' => $fundRequestId,
            'handoff_revision' => $revision,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_ADVANCE, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $id) ?? []);
}

function procurementFxAdvanceRetrieveFundRequestOne(mysqli $conn, int $fundRequestId, array $actor, string $reason): array
{
    $stmt = $conn->prepare(
        "SELECT legacy_source_id FROM procurement_requests
         WHERE request_type = ? AND account_request_id = ? AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND deleted_at IS NULL LIMIT 1"
    );
    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $stmt->bind_param('si', $type, $fundRequestId);
    $stmt->execute();
    $purchaseId = (int) ($stmt->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $stmt->close();
    if ($purchaseId <= 0) {
        throw new RuntimeException('This FX Fund Request is not linked to an active ProcureDesk FX Advance Purchase.', 404);
    }
    return procurementFxAdvanceRetrieveOne($conn, $purchaseId, $actor, $reason, 'account');
}

function procurementFxAdvanceMapAccountPaymentStatus(string $status): string
{
    $status = trim($status);
    if (strcasecmp($status, 'Unconfirmed') === 0) return 'Processing';
    if (in_array($status, PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES, true)) return $status;
    if (strcasecmp($status, 'Pending') === 0) return 'Pending';
    return 'Processing';
}

function procurementFxAdvancePaymentProcessingContext(mysqli $conn, int $fundRequestId): array
{
    $stmt = $conn->prepare(
        "SELECT i.batch_id AS canonical_batch_id, i.status AS item_status,
                i.processing_started_at, i.expected_completion_at, i.paid_at, i.payment_reference,
                b.processing_method, b.processing_reference, b.processing_business_days,
                b.completion_mode, b.status AS batch_status
         FROM account_payment_batch_items i
         INNER JOIN account_payment_batches b ON b.id = i.batch_id AND b.request_type = i.request_type
         WHERE i.request_type = 'fx_advance_purchase' AND i.request_id = ?
         ORDER BY i.id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $fundRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function procurementFxAdvanceSyncFundRequestToProcurement(
    mysqli $conn,
    int $fundRequestId,
    int $actorId,
    string $actorEmail,
    string $eventType = 'account_payment_status_updated'
): array {
    $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $fundRequestId);
    $stmt->execute();
    $fundRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fundRequest || (string) ($fundRequest['request_type'] ?? '') !== 'Advance') return [];
    $processingContext = procurementFxAdvancePaymentProcessingContext($conn, $fundRequestId);

    $link = $conn->prepare(
        "SELECT legacy_source_id, payment_status FROM procurement_requests
         WHERE request_type = ? AND account_request_id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE"
    );
    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $link->bind_param('si', $type, $fundRequestId);
    $link->execute();
    $purchase = $link->get_result()->fetch_assoc();
    $link->close();
    if (!$purchase) return [];

    $purchaseId = (int) $purchase['legacy_source_id'];
    $previousStatus = (string) ($purchase['payment_status'] ?? 'Pending');
    $paymentStatus = procurementFxAdvanceMapAccountPaymentStatus((string) ($fundRequest['payment_status'] ?? 'Pending'));
    $payable = number_format((float) ($fundRequest['payable_amount'] ?? 0), 2, '.', '');
    $amountPaid = $paymentStatus === 'Paid' ? $payable : '0.00';
    $paidAt = $paymentStatus === 'Paid' ? date('Y-m-d H:i:s') : null;
    $instructionId = (int) ($fundRequest['fx_instruction_letter_id'] ?? 0);
    $paymentReference = $instructionId > 0 ? 'FX Instruction #' . $instructionId : null;
    $paymentCurrency = trim((string) ($fundRequest['payment_currency'] ?? ''));
    $paymentAmount = $fundRequest['payment_amount'] === null ? null : number_format((float) $fundRequest['payment_amount'], 2, '.', '');
    $rate = $fundRequest['exchange_rate'] === null ? null : number_format((float) $fundRequest['exchange_rate'], 10, '.', '');
    $remarks = null;
    if ($paymentCurrency !== '' && $paymentAmount !== null) {
        $remarks = 'FX payment allocation: ' . $paymentCurrency . ' ' . $paymentAmount;
        if ($rate !== null) $remarks .= ' | Effective rate: ' . $rate;
    }
    $method = trim((string) ($processingContext['processing_method'] ?? '')) ?: ($instructionId > 0 ? 'FX Instruction' : null);
    $processingReference = trim((string) ($processingContext['processing_reference'] ?? '')) ?: $paymentReference;
    $processingStartedAt = $processingContext['processing_started_at'] ?? null;
    $expectedCompletionAt = $processingContext['expected_completion_at'] ?? null;
    $completionMode = trim((string) ($processingContext['completion_mode'] ?? '')) ?: null;
    $batchId = (int) ($processingContext['canonical_batch_id'] ?? 0);
    $itemStatus = trim((string) ($processingContext['item_status'] ?? ''));
    $confirmationStatus = match ($itemStatus) {
        'Paid' => 'Confirmed',
        'Awaiting Confirmation' => 'Due',
        'Processing', 'Delayed' => 'Scheduled',
        'Failed' => 'Failed',
        'Cancelled' => 'Cancelled',
        default => $expectedCompletionAt !== null ? 'Scheduled' : 'Not Scheduled',
    };
    if ($paymentStatus === 'Paid' && !empty($processingContext['paid_at'])) {
        $paidAt = (string) $processingContext['paid_at'];
    }
    if ($remarks !== null && $completionMode !== null) {
        $remarks .= ' | Completion: ' . $completionMode;
        if ($expectedCompletionAt !== null && $completionMode !== 'Immediate') {
            $remarks .= ' | Expected: ' . $expectedCompletionAt;
        }
    }

    $scope = procurementRequestCanonicalFxAdvanceScopeSql();
    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = ?, payment_status_source = 'account', payment_status_updated_at = NOW(),
             account_payable_amount = ?, account_amount_paid = ?, account_processing_method = ?,
             account_processing_reference = ?, account_processing_started_at = COALESCE(?, account_processing_started_at),
             account_expected_completion_at = ?, account_completion_mode = ?, account_confirmation_status = ?,
             account_payment_reference = ?, account_paid_at = ?, account_payment_remarks = ?, account_payment_batch_id = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
    );
    $batchIdValue = $batchId > 0 ? $batchId : null;
    $update->bind_param(
        'ssssssssssssiii',
        $paymentStatus,
        $payable,
        $amountPaid,
        $method,
        $processingReference,
        $processingStartedAt,
        $expectedCompletionAt,
        $completionMode,
        $confirmationStatus,
        $paymentReference,
        $paidAt,
        $remarks,
        $batchIdValue,
        $actorId,
        $purchaseId
    );
    $update->execute();
    $update->close();

    if ((int) ($purchaseId) > 0 && in_array($paymentStatus, ['Processing', 'Paid'], true)) {
        $request = procurementFxAdvanceFetchRecord($conn, $purchaseId, true);
        $poRevisionId = (int) ($request['po_revision_id'] ?? 0);
        if ($poRevisionId > 0) {
            procurementLocalAdvanceLockPoRevision($conn, $poRevisionId, 'FX Account payment activity recorded');
        }
    }

    $actor = ['id' => $actorId, 'email' => $actorEmail !== '' ? $actorEmail : 'account-user'];
    procurementFxAdvanceRecordEvent($conn, $purchaseId, $eventType, $actor, [
        'fx_fund_request_id' => $fundRequestId,
        'previous_payment_status' => $previousStatus,
        'payment_status' => $paymentStatus,
        'request_currency' => (string) ($fundRequest['currency'] ?? ''),
        'payable_amount' => $payable,
        'payment_currency' => $paymentCurrency !== '' ? $paymentCurrency : null,
        'payment_amount' => $paymentAmount,
        'exchange_rate' => $rate,
        'fx_instruction_letter_id' => $instructionId > 0 ? $instructionId : null,
        'payment_batch_id' => $batchId > 0 ? $batchId : null,
        'completion_mode' => $completionMode,
        'expected_completion_at' => $expectedCompletionAt,
        'confirmation_status' => $confirmationStatus,
    ]);
    procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_ADVANCE, $purchaseId);

    return [
        'purchase_id' => $purchaseId,
        'fund_request_id' => $fundRequestId,
        'previous_status' => $previousStatus,
        'payment_status' => $paymentStatus,
    ];
}

function procurementFxAdvanceLinkedPurchaseForFundRequest(
    mysqli $conn,
    int $fundRequestId,
    bool $forUpdate = false
): ?array {
    if ($fundRequestId <= 0) return null;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT legacy_source_id AS id, approval_status, payment_status, handoff_status,
                account_request_id AS fx_fund_request_id, po_id, po_revision_id, currency
         FROM procurement_requests
         WHERE request_type = ? AND account_request_id = ? AND deleted_at IS NULL LIMIT 1{$lock}"
    );
    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $stmt->bind_param('si', $type, $fundRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceAssertFundRequestsCanBeDeleted(mysqli $conn, array $fundRequestIds): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $fundRequestIds))));
    if ($ids === []) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $requestType = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $stmt = $conn->prepare(
        "SELECT legacy_source_id, account_request_id FROM procurement_requests
         WHERE request_type = ? AND account_request_id IN ($placeholders)
           AND approval_status = 'Approved' AND handoff_status = 'In Account' AND deleted_at IS NULL"
    );
    $params = array_merge([$requestType], $ids);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($links !== []) {
        throw new RuntimeException(
            'ProcureDesk-linked FX Advance Fund Requests cannot be deleted in Account. Return them to Procurement while they are still Pending.',
            409
        );
    }
}

/**
 * FX Advance PO amendment / revision / reconciliation parity.
 *
 * The Local Advance PO tables remain the shared PO infrastructure. Every read
 * and write below is explicitly isolated by request_scope + currency so FX
 * revisions cannot cross-contaminate Local Advance history.
 */
function procurementFxAdvancePoRevisionReference(string $poNumber, int $revisionNumber): string
{
    return procurementLocalAdvanceSubstring(
        'FX-ADV:' . trim($poNumber) . '/REV-' . max(1, $revisionNumber),
        160
    );
}

function procurementFxAdvanceFetchPoRevisionByNumber(
    mysqli $conn,
    int $poId,
    int $revisionNumber,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions
         WHERE request_scope = ? AND po_id = ? AND revision_number = ? LIMIT 1{$lock}"
    );
    $stmt->bind_param('sii', $scope, $poId, $revisionNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceResolveCurrentPoRevision(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): array {
    $po = procurementFxAdvanceFetchPo($conn, $poId, $forUpdate);
    if (!$po) {
        throw new RuntimeException('The FX Advance PO could not be found.', 404);
    }
    $revisionId = (int) ($po['current_revision_id'] ?? 0);
    if ($revisionId <= 0) {
        return procurementFxAdvanceEnsureInitialPoRevision($conn, $poId, (int) ($po['updated_by'] ?? $po['created_by'] ?? 0));
    }
    $revision = procurementFxAdvanceFetchPoRevision($conn, $revisionId, $forUpdate);
    if (!$revision) {
        throw new RuntimeException('The current FX Advance PO revision could not be resolved.', 409);
    }
    if ((string) ($revision['currency'] ?? '') !== (string) ($po['currency'] ?? '')) {
        throw new RuntimeException('The FX Advance PO revision currency does not match its PO.', 409);
    }
    return $revision;
}

function procurementFxAdvanceFetchPendingAmendment(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $stmt = $conn->prepare(
        "SELECT * FROM procurement_local_advance_po_revisions
         WHERE request_scope = ? AND po_id = ?
           AND revision_status IN ('Draft', 'Pending Approval')
         ORDER BY revision_number DESC, id DESC LIMIT 1{$lock}"
    );
    $stmt->bind_param('si', $scope, $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceRepresentativeRequestId(mysqli $conn, int $poId): int
{
    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $stmt = $conn->prepare(
        "SELECT legacy_source_id
         FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ? AND po_id = ? AND deleted_at IS NULL
         ORDER BY CASE WHEN request_variant = 'Original' THEN 0 ELSE 1 END, legacy_source_id ASC
         LIMIT 1"
    );
    $stmt->bind_param('ssi', $type, $source, $poId);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $stmt->close();
    return $id;
}

function procurementFxAdvanceRecordPoWorkflowEvent(
    mysqli $conn,
    int $poId,
    ?int $revisionId,
    ?int $reconciliationId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $po = procurementFxAdvanceFetchPo($conn, $poId);
    if (!$po) {
        throw new RuntimeException('The FX Advance PO audit context could not be resolved.', 409);
    }
    $revision = $revisionId && $revisionId > 0
        ? procurementFxAdvanceFetchPoRevision($conn, $revisionId)
        : procurementFxAdvanceResolveCurrentPoRevision($conn, $poId);
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $actionAt = procurementLocalAdvancePoAuditTimestamp($conn);
    $resolvedRevisionId = (int) ($revision['id'] ?? 0);
    $resolvedReconciliationId = max(0, (int) ($reconciliationId ?? 0));
    $details = array_merge($details, [
        'request_scope' => PROCUREMENT_FX_ADVANCE_SCOPE,
        'currency' => (string) ($po['currency'] ?? ''),
        'po_id' => $poId,
        'po_number' => (string) ($po['po_number'] ?? ''),
        'revision_id' => $resolvedRevisionId,
        'revision_number' => max(1, (int) ($revision['revision_number'] ?? 1)),
        'revision_reference' => (string) ($revision['revision_reference'] ?? ''),
        'reconciliation_id' => $resolvedReconciliationId,
        'actor_user_id' => (int) $actorInfo['id'],
        'actor_email' => (string) $actorInfo['email'],
        'action_at' => $actionAt,
    ]);
    $detailsJson = procurementLocalAdvancePoAuditJson($details);
    $eventKey = 'fx-po:' . $poId . ':revision:' . $resolvedRevisionId
        . ':reconciliation:' . $resolvedReconciliationId . ':' . $eventType;
    $eventId = workflowEventRecordAdvancePo(
        $conn,
        $poId,
        $resolvedRevisionId,
        $resolvedReconciliationId,
        $eventType,
        $eventKey,
        (int) $actorInfo['id'],
        (string) $actorInfo['email'],
        $detailsJson,
        $actionAt
    );
    if ($eventId <= 0) {
        throw new RuntimeException('The FX Advance PO amendment audit event could not be recorded.', 500);
    }

    $purchaseId = procurementFxAdvanceRepresentativeRequestId($conn, $poId);
    if ($purchaseId > 0) {
        $notificationDetails = array_merge($details, ['procurement_event_id' => $eventId]);
        procurementNotificationPublishFxAdvanceEvent($conn, $purchaseId, $eventType, $actorInfo, $notificationDetails);
        procurementNotificationMirrorFxAdvanceEventToAccount($conn, $purchaseId, $eventType, $actorInfo, $notificationDetails);
    }
    return $eventId;
}

function procurementFxAdvanceBuildAmendmentPayload(
    mysqli $conn,
    array $currentRevision,
    array $data
): array {
    $currentCurrency = procurementFxAdvanceNormalizeCurrency($currentRevision['currency'] ?? '');
    if (array_key_exists('currency', $data) && trim((string) $data['currency']) !== '') {
        $incomingCurrency = procurementFxAdvanceNormalizeCurrency($data['currency']);
        if ($incomingCurrency !== $currentCurrency) {
            throw new RuntimeException(
                'The currency cannot be changed after FX Advance payment activity has started. Create a new PO for the new currency instead.',
                409
            );
        }
    }
    $payload = procurementLocalAdvanceBuildAmendmentPayload($conn, $currentRevision, $data);
    $payload['currency'] = $currentCurrency;
    $payload['request_scope'] = PROCUREMENT_FX_ADVANCE_SCOPE;
    return $payload;
}

function procurementFxAdvancePoPaymentSummary(
    mysqli $conn,
    int $poId,
    bool $forUpdate = false
): array {
    if ($forUpdate) {
        $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
        $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
        $lockStmt = $conn->prepare(
            "SELECT r.id, r.account_request_id
             FROM procurement_requests r
             WHERE r.request_type = ? AND r.legacy_source_table = ?
               AND r.po_id = ? AND r.deleted_at IS NULL FOR UPDATE"
        );
        $lockStmt->bind_param('ssi', $type, $source, $poId);
        $lockStmt->execute();
        $rows = $lockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lockStmt->close();
        $fundIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['account_request_id'] ?? 0),
            $rows
        ))));
        if ($fundIds !== []) {
            $placeholders = implode(',', array_fill(0, count($fundIds), '?'));
            $types = str_repeat('i', count($fundIds));
            $fundLock = $conn->prepare("SELECT id FROM fx_fund_request_table WHERE id IN ({$placeholders}) FOR UPDATE");
            $fundLock->bind_param($types, ...$fundIds);
            $fundLock->execute();
            $fundLock->get_result()->fetch_all(MYSQLI_ASSOC);
            $fundLock->close();
        }
    }

    $relation = procurementRequestCanonicalFxAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN r.request_type = 'Original' AND r.payment_status <> 'Cancelled'
                    THEN r.po_percentage ELSE 0 END), 0) AS allocated_percentage,
            COALESCE(SUM(CASE
                WHEN r.payment_status <> 'Cancelled'
                    THEN COALESCE(ffr.payable_amount, r.account_expected_payment, r.expected_payment, 0)
                ELSE 0 END), 0) AS previous_committed_amount,
            COALESCE(SUM(CASE
                WHEN COALESCE(ffr.payment_status, r.payment_status) = 'Paid'
                    THEN COALESCE(ffr.payable_amount, r.account_expected_payment, r.expected_payment, 0)
                ELSE 0 END), 0) AS total_paid,
            COALESCE(SUM(CASE
                WHEN COALESCE(ffr.payment_status, r.payment_status) IN ('Processing', 'Unconfirmed')
                    THEN COALESCE(ffr.payable_amount, r.account_expected_payment, r.expected_payment, 0)
                ELSE 0 END), 0) AS total_processing,
            COALESCE(SUM(CASE
                WHEN COALESCE(ffr.payment_status, r.payment_status) = 'Pending'
                    THEN COALESCE(ffr.payable_amount, r.account_expected_payment, r.expected_payment, 0)
                ELSE 0 END), 0) AS total_pending,
            SUM(CASE
                WHEN COALESCE(ffr.payment_status, r.payment_status) IN ('Processing', 'Paid', 'Unconfirmed')
                     OR ffr.fx_instruction_letter_id IS NOT NULL
                     OR r.account_processing_started_at IS NOT NULL
                     OR r.account_paid_at IS NOT NULL
                     OR r.account_payment_batch_id IS NOT NULL
                THEN 1 ELSE 0 END) AS payment_activity_count,
            SUM(CASE
                WHEN r.approval_status = 'Unapproved' AND r.payment_status <> 'Cancelled'
                THEN 1 ELSE 0 END) AS unapproved_count
         FROM {$relation} r
         LEFT JOIN fx_fund_request_table ffr ON ffr.id = r.fx_fund_request_id
         WHERE r.po_id = ? AND r.deleted_at IS NULL"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'allocated_percentage' => procurementLocalAdvancePercentString(
            procurementLocalAdvancePercentUnitsAllowZero($row['allocated_percentage'] ?? 0)
        ),
        'allocated_percentage_units' => procurementLocalAdvancePercentUnitsAllowZero(
            $row['allocated_percentage'] ?? 0
        ),
        'previous_committed_amount' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['previous_committed_amount'] ?? 0, 'Previous FX Committed Amount')
        ),
        'total_paid' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['total_paid'] ?? 0, 'Total FX Paid')
        ),
        'total_processing' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['total_processing'] ?? 0, 'Total FX Processing')
        ),
        'total_pending' => procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($row['total_pending'] ?? 0, 'Total FX Pending')
        ),
        'payment_activity_count' => (int) ($row['payment_activity_count'] ?? 0),
        'unapproved_count' => (int) ($row['unapproved_count'] ?? 0),
    ];
}

function procurementFxAdvanceRevisedCommittedCents(
    mysqli $conn,
    int $poId,
    array $revision
): int {
    $relation = procurementRequestCanonicalFxAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT po_percentage, account_wht_override_status
         FROM {$relation} source
         WHERE po_id = ? AND request_type = 'Original'
           AND deleted_at IS NULL AND payment_status <> 'Cancelled'
         ORDER BY id ASC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $total = 0;
    foreach ($rows as $row) {
        $total += (int) procurementLocalAdvanceRevisionRequestFinancials($revision, $row)['expected_payment_cents'];
    }
    return $total;
}

function procurementFxAdvanceCreatePoAmendment(
    mysqli $conn,
    int $poId,
    array $data,
    array $actor
): array {
    if ($poId <= 0) {
        throw new RuntimeException('A valid FX Advance PO ID is required.', 400);
    }
    $reason = trim((string) ($data['reason'] ?? $data['amendment_reason'] ?? ''));
    if ($reason === '') {
        throw new RuntimeException('An amendment reason is required.', 400);
    }
    $amendmentType = procurementLocalAdvanceAmendmentType($data['amendment_type'] ?? 'Commercial');
    $actorId = (int) ($actor['id'] ?? 0);
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;

    $po = procurementFxAdvanceFetchPo($conn, $poId, true);
    if (!$po) {
        throw new RuntimeException('The FX Advance PO could not be found.', 404);
    }
    if ((string) ($po['po_status'] ?? '') === 'Cancelled') {
        throw new RuntimeException('A Cancelled FX Advance PO cannot be amended.', 409);
    }
    $currentRevision = procurementFxAdvanceResolveCurrentPoRevision($conn, $poId, true);
    if ((int) ($currentRevision['is_locked'] ?? 0) !== 1) {
        throw new RuntimeException(
            'This FX PO has no locked payment revision. Use the normal FX Advance Purchase edit flow instead.',
            409
        );
    }
    if (procurementFxAdvanceFetchPendingAmendment($conn, $poId, true)) {
        throw new RuntimeException('This FX Advance PO already has an amendment awaiting review.', 409);
    }

    $paymentSummary = procurementFxAdvancePoPaymentSummary($conn, $poId, true);
    if ((int) $paymentSummary['payment_activity_count'] <= 0) {
        throw new RuntimeException(
            'No Account payment activity has been recorded for this FX PO. Retrieve and edit the original request instead.',
            409
        );
    }
    if ((int) $paymentSummary['unapproved_count'] > 0) {
        throw new RuntimeException(
            'Resolve or remove all unapproved FX Advance requests on this PO before submitting an amendment.',
            409
        );
    }

    $payload = procurementFxAdvanceBuildAmendmentPayload($conn, $currentRevision, $data);
    $changes = procurementLocalAdvanceAmendmentChangeSummary($currentRevision, $payload);
    if ($changes === []) {
        throw new RuntimeException('The FX PO amendment does not contain any commercial change.', 409);
    }

    $revisionNumber = max(
        (int) ($po['current_revision_number'] ?? 1),
        (int) ($currentRevision['revision_number'] ?? 1)
    ) + 1;
    $revisionReference = procurementFxAdvancePoRevisionReference((string) $currentRevision['po_number'], $revisionNumber);
    $snapshotJson = procurementLocalAdvancePoCommercialSnapshotJson($payload);
    $changeSummaryJson = (string) json_encode(
        $changes,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    $snapshotHash = hash('sha256', $snapshotJson);
    $previousRevisionId = (int) $currentRevision['id'];
    $currency = (string) $po['currency'];

    $stmt = $conn->prepare(
        "INSERT INTO procurement_local_advance_po_revisions
            (po_id, request_scope, currency, revision_number, revision_reference, previous_revision_id,
             revision_status, amendment_type, amendment_reason,
             po_number, po_number_normalized, project_id, project_code, project_name,
             supplier_id, supplier_name, supplier_ledger, wht_status, wht_rate, wht_amount,
             purchase_value, po_subtotal, po_discount, po_other_charges, po_vat_status,
             po_vat_rate, po_vat_amount, po_value, advance_base_amount, po_status,
             commercial_snapshot_json, change_summary_json, snapshot_hash, is_locked,
             created_by, submitted_by, submitted_at, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, 'Pending Approval', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), ?)"
    );
    $params = [
        $poId, $scope, $currency, $revisionNumber, $revisionReference, $previousRevisionId,
        $amendmentType, $reason,
        $payload['po_number'], $payload['po_number_normalized'], $payload['project_id'],
        $payload['project_code'], $payload['project_name'], $payload['supplier_id'],
        $payload['supplier_name'], $payload['supplier_ledger'], $payload['wht_status'],
        $payload['wht_rate'], $payload['wht_amount'], $payload['purchase_value'],
        $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'],
        $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'],
        $payload['po_value'], $payload['advance_base_amount'], $payload['po_status'],
        $snapshotJson, $changeSummaryJson, $snapshotHash, $actorId, $actorId, $actorId,
    ];
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $revisionId = (int) $conn->insert_id;
    $stmt->close();
    if ($revisionId <= 0) {
        throw new RuntimeException('The FX Advance PO amendment could not be created.', 500);
    }

    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Pending Approval', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ?"
    );
    $updatePo->bind_param('iiss', $actorId, $poId, $scope, $currency);
    $updatePo->execute();
    $updatePo->close();

    $fresh = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    procurementFxAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        null,
        'po_amendment_submitted',
        $actor,
        [
            'reason' => $reason,
            'amendment_type' => $amendmentType,
            'change_summary' => $changes,
            'previous_revision_id' => $previousRevisionId,
        ]
    );
    return $fresh;
}

function procurementFxAdvanceFetchReconciliation(
    mysqli $conn,
    int $reconciliationId,
    bool $forUpdate = false
): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $stmt = $conn->prepare(
        "SELECT reconciliation.*,
                supplementary.account_request_id AS supplementary_fx_fund_request_id
         FROM procurement_local_advance_po_revision_reconciliations reconciliation
         LEFT JOIN procurement_requests supplementary
           ON supplementary.request_type = ?
          AND supplementary.legacy_source_table = ?
          AND supplementary.legacy_source_id = reconciliation.supplementary_purchase_id
          AND supplementary.deleted_at IS NULL
         WHERE reconciliation.request_scope = ? AND reconciliation.id = ? LIMIT 1{$lock}"
    );
    $stmt->bind_param('sssi', $type, $source, $scope, $reconciliationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxAdvanceFetchAllocationRows(mysqli $conn, int $poId, bool $forUpdate = false): array
{
    if ($forUpdate) {
        procurementFxAdvancePoPaymentSummary($conn, $poId, true);
    }
    $relation = procurementRequestCanonicalFxAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT r.*,
                ffr.id AS linked_fx_fund_request_id,
                ffr.payment_status AS account_payment_status,
                ffr.payable_amount AS account_payable_amount_effective,
                ffr.payment_currency,
                ffr.payment_amount,
                ffr.exchange_rate,
                ffr.fx_instruction_letter_id
         FROM {$relation} r
         LEFT JOIN fx_fund_request_table ffr ON ffr.id = r.fx_fund_request_id
         WHERE r.po_id = ? AND r.deleted_at IS NULL AND r.payment_status <> 'Cancelled'
         ORDER BY CASE WHEN r.request_type = 'Original' THEN 0 ELSE 1 END, r.id ASC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function procurementFxAdvanceAllocationPaymentStatus(array $row): string
{
    $status = trim((string) ($row['account_payment_status'] ?? $row['payment_status'] ?? 'Pending'));
    if ($status === 'Unconfirmed') {
        return 'Processing';
    }
    return in_array($status, ['Pending', 'Processing', 'Paid', 'Cancelled'], true) ? $status : 'Pending';
}

function procurementFxAdvanceAllocationIsLocked(array $row): bool
{
    $status = procurementFxAdvanceAllocationPaymentStatus($row);
    return $status !== 'Pending'
        || (int) ($row['fx_instruction_letter_id'] ?? 0) > 0
        || !empty($row['account_processing_started_at'])
        || !empty($row['account_paid_at'])
        || !empty($row['account_payment_batch_id']);
}

function procurementFxAdvanceUpdatePendingAllocationToRevision(
    mysqli $conn,
    array $row,
    array $revision,
    array $financials,
    int $actorId
): void {
    $purchaseId = (int) $row['id'];
    $fundRequestId = (int) ($row['linked_fx_fund_request_id'] ?? 0);
    if ($fundRequestId <= 0) {
        throw new RuntimeException('The pending FX Advance allocation has no linked AcctLab request.', 409);
    }
    $scope = procurementRequestCanonicalFxAdvanceScopeSql();
    $revisionId = (int) $revision['id'];
    $revisionNumber = (int) $revision['revision_number'];
    $snapshotJson = (string) $revision['commercial_snapshot_json'];
    $expected = (string) $financials['expected_payment'];
    $currency = (string) $revision['currency'];

    $purchaseUpdate = $conn->prepare(
        "UPDATE procurement_requests
         SET po_revision_id = ?, po_revision_number = ?, po_snapshot_json = ?,
             expected_payment = ?, account_expected_payment = ?, currency = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL
           AND payment_status = 'Pending' AND account_request_id = ?"
    );
    $purchaseUpdate->bind_param(
        'iissssiii',
        $revisionId,
        $revisionNumber,
        $snapshotJson,
        $expected,
        $expected,
        $currency,
        $actorId,
        $purchaseId,
        $fundRequestId
    );
    $purchaseUpdate->execute();
    if ($purchaseUpdate->affected_rows !== 1) {
        $purchaseUpdate->close();
        throw new RuntimeException('A pending FX Advance allocation changed during amendment synchronization.', 409);
    }
    $purchaseUpdate->close();

    $supplierName = (string) $revision['supplier_name'];
    $supplierId = (int) $revision['supplier_id'];
    $poNumber = (string) $revision['po_number'];
    $projectCode = (string) $revision['project_code'];
    $subTotal = (string) $revision['po_subtotal'];
    $discount = (string) $revision['po_discount'];
    $otherCharges = (string) $revision['po_other_charges'];
    $vatRate = (string) $financials['vat_rate'];
    $vatAmount = (string) $financials['vat'];
    $whtRate = (string) $financials['wht_rate'];
    $whtAmount = (string) $financials['wht'];
    $percentage = number_format((float) $financials['po_percentage'], 2, '.', '');

    $fundUpdate = $conn->prepare(
        "UPDATE fx_fund_request_table
         SET suppliers_name = ?, suppliers_id = ?, po_number = ?, project_code = ?, currency = ?,
             sub_total = ?, discount = ?, other_charges = ?, vat_rate = ?, vat_amount = ?,
             wht_rate = ?, wht_amount = ?, percentage = ?, payable_amount = ?,
             updated_by = ?, updated_at = NOW()
         WHERE id = ? AND request_type = 'Advance' AND payment_status = 'Pending'
           AND fx_instruction_letter_id IS NULL"
    );
    $params = [
        $supplierName, $supplierId, $poNumber, $projectCode, $currency,
        $subTotal, $discount, $otherCharges, $vatRate, $vatAmount,
        $whtRate, $whtAmount, $percentage, $expected, $actorId, $fundRequestId,
    ];
    $fundUpdate->bind_param(str_repeat('s', count($params)), ...$params);
    $fundUpdate->execute();
    if ($fundUpdate->affected_rows !== 1) {
        $fundUpdate->close();
        throw new RuntimeException('The pending AcctLab FX request started processing before amendment synchronization completed.', 409);
    }
    $fundUpdate->close();

    $handoffRevision = max(1, (int) ($row['handoff_revision'] ?? 1));
    procurementFxAdvanceCreateHandoff($conn, $purchaseId, $handoffRevision, $fundRequestId, $actorId);
}

function procurementFxAdvanceCancelUnprocessedSupplementary(
    mysqli $conn,
    array $row,
    int $currentReconciliationId,
    int $actorId
): void {
    $purchaseId = (int) $row['id'];
    $fundRequestId = (int) ($row['linked_fx_fund_request_id'] ?? 0);
    $scope = procurementRequestCanonicalFxAdvanceScopeSql();
    $purchaseUpdate = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = 'Cancelled', payment_status_source = 'procuredesk',
             payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND {$scope}
           AND request_variant = 'Supplementary' AND payment_status = 'Pending' AND deleted_at IS NULL"
    );
    $purchaseUpdate->bind_param('ii', $actorId, $purchaseId);
    $purchaseUpdate->execute();
    if ($purchaseUpdate->affected_rows !== 1) {
        $purchaseUpdate->close();
        throw new RuntimeException('A prior supplementary FX request changed during amendment synchronization.', 409);
    }
    $purchaseUpdate->close();

    if ($fundRequestId > 0) {
        $fundUpdate = $conn->prepare(
            "UPDATE fx_fund_request_table
             SET payment_status = 'Cancelled', updated_by = ?, updated_at = NOW()
             WHERE id = ? AND request_type = 'Advance' AND payment_status = 'Pending'
               AND fx_instruction_letter_id IS NULL"
        );
        $fundUpdate->bind_param('ii', $actorId, $fundRequestId);
        $fundUpdate->execute();
        if ($fundUpdate->affected_rows !== 1) {
            $fundUpdate->close();
            throw new RuntimeException('A prior supplementary AcctLab FX request started processing before it could be superseded.', 409);
        }
        $fundUpdate->close();
    }

    $priorReconciliationId = (int) ($row['revision_reconciliation_id'] ?? 0);
    if ($priorReconciliationId > 0 && $priorReconciliationId !== $currentReconciliationId) {
        $reason = 'Superseded by a later approved FX PO revision.';
        $scopeValue = PROCUREMENT_FX_ADVANCE_SCOPE;
        $reconciliationUpdate = $conn->prepare(
            "UPDATE procurement_local_advance_po_revision_reconciliations
             SET reconciliation_status = 'Cancelled', resolution_type = 'Superseded',
                 resolution_notes = ?, resolved_by = ?, resolved_at = NOW(),
                 updated_by = ?, updated_at = NOW()
             WHERE id = ? AND request_scope = ?
               AND reconciliation_status = 'Pending Supplementary Payment'"
        );
        $reconciliationUpdate->bind_param('siiis', $reason, $actorId, $actorId, $priorReconciliationId, $scopeValue);
        $reconciliationUpdate->execute();
        $reconciliationUpdate->close();
    }
}

function procurementFxAdvanceCreateSupplementaryPurchaseAndRequest(
    mysqli $conn,
    array $revision,
    int $reconciliationId,
    int $supplementaryCents,
    ?int $parentPurchaseId,
    array $actor
): array {
    if ($supplementaryCents <= 0) {
        throw new RuntimeException('A positive supplementary FX amount is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $poId = (int) $revision['po_id'];
    $revisionId = (int) $revision['id'];
    $revisionNumber = (int) $revision['revision_number'];
    $snapshotJson = (string) $revision['commercial_snapshot_json'];
    $currency = procurementFxAdvanceNormalizeCurrency($revision['currency'] ?? '');
    $baseCents = max(1, procurementLocalAdvanceMoneyToCents($revision['advance_base_amount'], 'Revised FX Expected Payment Base'));
    $percentageUnits = min(
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX,
        max(1, procurementLocalAdvanceMultiplyDivideRounded(
            $supplementaryCents,
            PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX,
            $baseCents
        ))
    );
    $percentage = procurementLocalAdvancePercentString($percentageUnits);
    $expectedPayment = procurementLocalAdvanceCents($supplementaryCents);
    $purchaseNumber = procurementLocalAdvanceSubstring(
        trim((string) $revision['po_number']) . '/REV-' . $revisionNumber . '-FX-SUPP',
        120
    );
    $remark = 'Supplementary FX payment created from approved PO amendment ' . (string) $revision['revision_reference'];

    $purchaseId = procurementFxAdvanceNextPublicId($conn);
    $requestNumber = 'FXAP-SUP-' . date('Y') . '-' . str_pad((string) $purchaseId, 7, '0', STR_PAD_LEFT);
    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $variant = 'Supplementary';
    $parentValue = max(0, (int) ($parentPurchaseId ?? 0));
    $contactPerson = '';
    $phoneNumber = '';
    if ($parentValue > 0) {
        $parentRequest = procurementFxAdvanceFetchRecord($conn, $parentValue, true);
        if ($parentRequest) {
            $contactPerson = trim((string) ($parentRequest['contact_person'] ?? ''));
            $phoneNumber = trim((string) ($parentRequest['phone_number'] ?? ''));
        }
    }
    $insert = $conn->prepare(
        "INSERT INTO procurement_requests
            (request_type, request_number, legacy_source_table, legacy_source_id, currency,
             po_id, po_revision_id, po_revision_number, request_variant,
             legacy_parent_purchase_id, revision_reconciliation_id, po_snapshot_json,
             purchase_number, po_percentage, expected_payment, account_expected_payment, remark,
             contact_person, phone_number,
             transaction_date, date_received, payment_status, payment_status_source,
             payment_status_updated_at, approval_status, handoff_status, handoff_revision,
             account_request_type, approved_by, approved_at, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''),
                 CURDATE(), CURDATE(), 'Pending', 'procuredesk', NOW(),
                 'Approved', 'Not Sent', 1, 'fx_fund_request', ?, NOW(), ?, ?)"
    );
    $params = [
        $type, $requestNumber, $source, $purchaseId, $currency,
        $poId, $revisionId, $revisionNumber, $variant, $parentValue,
        $reconciliationId, $snapshotJson, $purchaseNumber, $percentage,
        $expectedPayment, $expectedPayment, $remark, $contactPerson, $phoneNumber,
        $actorId, $actorId, $actorId,
    ];
    $insert->bind_param(str_repeat('s', count($params)), ...$params);
    $insert->execute();
    $insert->close();

    $requestType = 'Advance';
    $supplierName = (string) $revision['supplier_name'];
    $supplierId = (int) $revision['supplier_id'];
    $poNumber = (string) $revision['po_number'];
    $dateReceived = (string) (($conn->query('SELECT CURRENT_DATE() AS d')->fetch_assoc()['d'] ?? date('Y-m-d')));
    $projectCode = (string) $revision['project_code'];
    $subTotal = (string) $revision['po_subtotal'];
    $discount = (string) $revision['po_discount'];
    $otherCharges = (string) $revision['po_other_charges'];
    $vatRate = (string) $revision['po_vat_rate'];
    $vatAmount = (string) $revision['po_vat_amount'];
    $whtRate = (string) $revision['wht_rate'];
    $whtAmount = (string) $revision['wht_amount'];
    $accountPercentage = number_format($percentageUnits / PROCUREMENT_LOCAL_ADVANCE_PERCENT_SCALE, 2, '.', '');

    $fundInsert = $conn->prepare(
        "INSERT INTO fx_fund_request_table
            (request_type, suppliers_name, suppliers_id, contact_person, phone_number, invoice_number, purchase_number,
             po_number, invoice_date, purchase_date, date_received, project_code, currency,
             sub_total, discount, other_charges, vat_rate, vat_amount, wht_rate, wht_amount,
             percentage, payable_amount, payment_status, created_by, updated_by)
         VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULL, NULL, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)"
    );
    $fundParams = [
        $requestType, $supplierName, $supplierId, $contactPerson, $phoneNumber, $poNumber, $dateReceived, $projectCode, $currency,
        $subTotal, $discount, $otherCharges, $vatRate, $vatAmount, $whtRate, $whtAmount,
        $accountPercentage, $expectedPayment, $actorId, $actorId,
    ];
    $fundInsert->bind_param(str_repeat('s', count($fundParams)), ...$fundParams);
    $fundInsert->execute();
    $fundRequestId = (int) $conn->insert_id;
    $fundInsert->close();
    if ($fundRequestId <= 0) {
        throw new RuntimeException('The supplementary AcctLab FX Fund Request could not be created.', 500);
    }

    $linkUpdate = $conn->prepare(
        "UPDATE procurement_requests
         SET account_request_type = 'fx_fund_request', account_request_id = ?, handoff_status = 'In Account',
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE request_type = ? AND legacy_source_table = ? AND legacy_source_id = ?"
    );
    $linkUpdate->bind_param('iissi', $fundRequestId, $actorId, $type, $source, $purchaseId);
    $linkUpdate->execute();
    if ($linkUpdate->affected_rows !== 1) {
        $linkUpdate->close();
        throw new RuntimeException('The supplementary FX ProcureDesk request could not be linked to AcctLab.', 409);
    }
    $linkUpdate->close();
    procurementFxAdvanceCreateHandoff($conn, $purchaseId, 1, $fundRequestId, $actorId);

    return [
        'purchase_id' => $purchaseId,
        'fx_fund_request_id' => $fundRequestId,
        'request_number' => $requestNumber,
        'amount' => $expectedPayment,
        'percentage' => $percentage,
        'currency' => $currency,
    ];
}

function procurementFxAdvanceSynchronizeApprovedAmendmentToAccount(
    mysqli $conn,
    int $reconciliationId,
    array $actor
): array {
    $reconciliation = procurementFxAdvanceFetchReconciliation($conn, $reconciliationId, true);
    if (!$reconciliation) {
        throw new RuntimeException('The approved FX PO reconciliation could not be found.', 409);
    }
    $revision = procurementFxAdvanceFetchPoRevision($conn, (int) $reconciliation['revision_id'], true);
    if (!$revision || (string) $revision['revision_status'] !== 'Current') {
        throw new RuntimeException('The approved FX PO revision is not current for AcctLab synchronization.', 409);
    }
    if ((string) $revision['currency'] !== (string) $reconciliation['currency']) {
        throw new RuntimeException('The FX reconciliation currency does not match the approved PO revision.', 409);
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $rows = procurementFxAdvanceFetchAllocationRows($conn, (int) $reconciliation['po_id'], true);
    $targetCommittedCents = 0;
    $allocatedAfterCents = 0;
    $pendingReallocatedCents = 0;
    $allocations = [];
    $parentPurchaseId = null;

    foreach ($rows as $row) {
        $requestType = (string) ($row['request_type'] ?? 'Original');
        $purchaseId = (int) $row['id'];
        if ($parentPurchaseId === null && $requestType === 'Original') {
            $parentPurchaseId = $purchaseId;
        }
        $previousCents = procurementLocalAdvanceMoneyToCents(
            $row['account_payable_amount_effective'] ?? $row['account_expected_payment'] ?? $row['expected_payment'] ?? 0,
            'FX Committed Fund Request'
        );
        $paymentStatus = procurementFxAdvanceAllocationPaymentStatus($row);
        $isLocked = procurementFxAdvanceAllocationIsLocked($row);
        $allocationAction = 'Preserved';
        $revisedCents = $previousCents;
        $amountPaidCents = $paymentStatus === 'Paid' ? $previousCents : 0;
        $amountProcessingCents = $paymentStatus === 'Processing' ? $previousCents : 0;
        $amountPendingCents = $paymentStatus === 'Pending' ? $previousCents : 0;

        if ($requestType === 'Original') {
            $financials = procurementLocalAdvanceRevisionRequestFinancials($revision, $row);
            $revisedCents = (int) $financials['expected_payment_cents'];
            $targetCommittedCents += $revisedCents;
            if (!$isLocked && $paymentStatus === 'Pending') {
                procurementFxAdvanceUpdatePendingAllocationToRevision($conn, $row, $revision, $financials, $actorId);
                $allocationAction = 'Reallocated';
                $allocatedAfterCents += $revisedCents;
                $pendingReallocatedCents += $revisedCents - $previousCents;
                $amountPendingCents = $revisedCents;
            } else {
                $allocatedAfterCents += $previousCents;
            }
        } elseif ($requestType === 'Supplementary') {
            if (!$isLocked && $paymentStatus === 'Pending') {
                procurementFxAdvanceCancelUnprocessedSupplementary($conn, $row, $reconciliationId, $actorId);
                $allocationAction = 'Superseded';
                $revisedCents = 0;
                $amountPendingCents = 0;
            } else {
                $allocatedAfterCents += $previousCents;
            }
        } else {
            $allocatedAfterCents += $previousCents;
        }

        $allocations[] = [
            'procurement_purchase_id' => $purchaseId,
            'fx_fund_request_id' => (int) ($row['linked_fx_fund_request_id'] ?? 0),
            'request_type' => $requestType,
            'allocation_action' => $allocationAction,
            'payment_status' => $paymentStatus,
            'exact_po_revision_id' => (int) ($row['po_revision_id'] ?? 0),
            'exact_po_revision_number' => (int) ($row['po_revision_number'] ?? 0),
            'current_po_revision_id' => (int) $revision['id'],
            'current_po_revision_number' => (int) $revision['revision_number'],
            'previous_expected_amount' => procurementLocalAdvanceCents($previousCents),
            'revised_expected_amount' => procurementLocalAdvanceCents($revisedCents),
            'amount_paid' => procurementLocalAdvanceCents($amountPaidCents),
            'amount_processing' => procurementLocalAdvanceCents($amountProcessingCents),
            'amount_pending' => procurementLocalAdvanceCents($amountPendingCents),
            'recovery_allocated_amount' => '0.00',
            'currency' => (string) $revision['currency'],
        ];
    }

    $adjustmentCents = $targetCommittedCents - $allocatedAfterCents;
    $direction = $adjustmentCents > 0 ? 'Increase' : ($adjustmentCents < 0 ? 'Decrease' : 'No Change');
    $status = $direction === 'Increase'
        ? 'Pending Supplementary Payment'
        : ($direction === 'Decrease' ? 'Recovery Required' : 'Resolved');
    $supplementaryCents = max(0, $adjustmentCents);
    $recoveryCents = max(0, -$adjustmentCents);
    $supplementary = null;

    if ($supplementaryCents > 0) {
        $supplementary = procurementFxAdvanceCreateSupplementaryPurchaseAndRequest(
            $conn,
            $revision,
            $reconciliationId,
            $supplementaryCents,
            $parentPurchaseId,
            $actor
        );
        $allocations[] = [
            'procurement_purchase_id' => (int) $supplementary['purchase_id'],
            'fx_fund_request_id' => (int) $supplementary['fx_fund_request_id'],
            'request_type' => 'Supplementary',
            'allocation_action' => 'Supplementary',
            'payment_status' => 'Pending',
            'exact_po_revision_id' => (int) $revision['id'],
            'exact_po_revision_number' => (int) $revision['revision_number'],
            'current_po_revision_id' => (int) $revision['id'],
            'current_po_revision_number' => (int) $revision['revision_number'],
            'previous_expected_amount' => '0.00',
            'revised_expected_amount' => (string) $supplementary['amount'],
            'amount_paid' => '0.00',
            'amount_processing' => '0.00',
            'amount_pending' => (string) $supplementary['amount'],
            'recovery_allocated_amount' => '0.00',
            'currency' => (string) $revision['currency'],
        ];
    }

    if ($recoveryCents > 0) {
        $remainingRecovery = $recoveryCents;
        foreach ($allocations as &$allocation) {
            if ($remainingRecovery <= 0 || $allocation['allocation_action'] !== 'Preserved') {
                continue;
            }
            $exposureCents = procurementLocalAdvanceMoneyToCents($allocation['amount_paid'], 'FX Recovery Paid Allocation')
                + procurementLocalAdvanceMoneyToCents($allocation['amount_processing'], 'FX Recovery Processing Allocation');
            if ($exposureCents <= 0) {
                continue;
            }
            $allocatedRecovery = min($remainingRecovery, $exposureCents);
            $allocation['recovery_allocated_amount'] = procurementLocalAdvanceCents($allocatedRecovery);
            $remainingRecovery -= $allocatedRecovery;
        }
        unset($allocation);
        if ($remainingRecovery > 0) {
            throw new RuntimeException('The FX recovery amount could not be allocated to paid or processing requests.', 409);
        }
    }

    $resolutionType = $status === 'Resolved'
        ? ($pendingReallocatedCents !== 0 ? 'Pending Requests Reallocated' : 'No Financial Adjustment')
        : null;
    $resolutionNotes = $status === 'Resolved'
        ? ($pendingReallocatedCents !== 0
            ? 'Unprocessed FX Fund Requests were recalculated against the approved PO revision.'
            : 'The approved FX amendment requires no additional payment or recovery.')
        : null;
    $snapshotJson = (string) json_encode(
        $allocations,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    $supplementaryPurchaseId = (int) ($supplementary['purchase_id'] ?? 0);
    $resolvedBy = $status === 'Resolved' ? $actorId : null;
    $resolvedAt = $status === 'Resolved' ? date('Y-m-d H:i:s') : null;
    $reconciliationAmount = procurementLocalAdvanceCents(abs($adjustmentCents));

    $update = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET reconciliation_direction = ?, reconciliation_status = ?, reconciliation_amount = ?,
             pending_reallocated_amount = ?, supplementary_amount = ?, recovery_amount = ?,
             supplementary_purchase_id = NULLIF(?, 0),
             resolution_type = ?, resolution_notes = ?, allocation_snapshot_json = ?,
             account_sync_status = 'Synced', account_synced_by = ?, account_synced_at = NOW(),
             account_sync_error = NULL, resolved_by = ?, resolved_at = ?,
             updated_by = ?, updated_at = NOW()
         WHERE id = ? AND request_scope = ? AND currency = ?"
    );
    $params = [
        $direction, $status, $reconciliationAmount,
        procurementLocalAdvanceCents($pendingReallocatedCents),
        procurementLocalAdvanceCents($supplementaryCents),
        procurementLocalAdvanceCents($recoveryCents),
        $supplementaryPurchaseId, $resolutionType, $resolutionNotes, $snapshotJson,
        $actorId, $resolvedBy, $resolvedAt, $actorId, $reconciliationId,
        PROCUREMENT_FX_ADVANCE_SCOPE, (string) $revision['currency'],
    ];
    $update->bind_param(str_repeat('s', count($params)), ...$params);
    $update->execute();
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The FX PO reconciliation could not be synchronized to AcctLab.', 409);
    }
    $update->close();

    return [
        'direction' => $direction,
        'status' => $status,
        'currency' => (string) $revision['currency'],
        'reconciliation_amount' => $reconciliationAmount,
        'pending_reallocated_amount' => procurementLocalAdvanceCents($pendingReallocatedCents),
        'supplementary_amount' => procurementLocalAdvanceCents($supplementaryCents),
        'recovery_amount' => procurementLocalAdvanceCents($recoveryCents),
        'supplementary_purchase_id' => $supplementaryPurchaseId ?: null,
        'supplementary_fx_fund_request_id' => $supplementary['fx_fund_request_id'] ?? null,
        'allocations' => $allocations,
        'resolution_type' => $resolutionType,
        'resolution_notes' => $resolutionNotes,
    ];
}

function procurementFxAdvanceApprovePoAmendment(
    mysqli $conn,
    int $revisionId,
    array $actor
): array {
    if ($revisionId <= 0) {
        throw new RuntimeException('A valid FX PO amendment revision is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $revision = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The FX PO amendment could not be found.', 404);
    }
    if ((string) $revision['revision_status'] !== 'Pending Approval') {
        throw new RuntimeException('Only a pending FX PO amendment can be approved.', 409);
    }
    $currency = procurementFxAdvanceNormalizeCurrency($revision['currency'] ?? '');
    $poId = (int) $revision['po_id'];
    $po = procurementFxAdvanceFetchPo($conn, $poId, true);
    if (!$po || (string) $po['currency'] !== $currency) {
        throw new RuntimeException('The linked FX Advance PO or its currency could not be resolved.', 409);
    }
    $previousRevisionId = (int) ($revision['previous_revision_id'] ?? 0);
    if ($previousRevisionId <= 0 || $previousRevisionId !== (int) ($po['current_revision_id'] ?? 0)) {
        throw new RuntimeException('The FX PO changed after this amendment was submitted. Cancel it and create a new amendment.', 409);
    }
    $previousRevision = procurementFxAdvanceFetchPoRevision($conn, $previousRevisionId, true);
    if (!$previousRevision || (string) $previousRevision['revision_status'] !== 'Current'
        || (string) $previousRevision['currency'] !== $currency) {
        throw new RuntimeException('The previous FX PO revision is no longer current.', 409);
    }

    $paymentSummary = procurementFxAdvancePoPaymentSummary($conn, $poId, true);
    $revisedBaseCents = procurementLocalAdvanceMoneyToCents($revision['advance_base_amount'], 'Revised FX Expected Payment Base');
    $revisedCommittedCents = procurementFxAdvanceRevisedCommittedCents($conn, $poId, $revision);
    $previousCommittedCents = procurementLocalAdvanceMoneyToCents($paymentSummary['previous_committed_amount'], 'Previous FX Committed Amount');
    $deltaCents = $revisedCommittedCents - $previousCommittedCents;
    $direction = $deltaCents > 0 ? 'Increase' : ($deltaCents < 0 ? 'Decrease' : 'No Change');
    $status = $direction === 'Increase' ? 'Pending Supplementary Payment' : ($direction === 'Decrease' ? 'Recovery Required' : 'Resolved');
    $resolutionType = $direction === 'No Change' ? 'No Financial Adjustment' : null;
    $previousPoCents = procurementLocalAdvanceMoneyToCents($previousRevision['po_value'], 'Previous FX PO Value');
    $revisedPoCents = procurementLocalAdvanceMoneyToCents($revision['po_value'], 'Revised FX PO Value');
    $previousBaseCents = procurementLocalAdvanceMoneyToCents($previousRevision['advance_base_amount'], 'Previous FX Expected Payment Base');

    $insert = $conn->prepare(
        "INSERT INTO procurement_local_advance_po_revision_reconciliations
            (po_id, request_scope, currency, revision_id, previous_revision_id,
             previous_po_value, revised_po_value, po_value_delta,
             previous_advance_base_amount, revised_advance_base_amount, advance_base_delta,
             allocated_percentage_at_revision, previous_committed_amount, revised_committed_amount,
             total_paid_at_revision, total_processing_at_revision, total_pending_at_revision,
             reconciliation_amount, reconciliation_direction, reconciliation_status,
             resolution_type, account_sync_status, created_by, resolved_by, resolved_at, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?)"
    );
    $params = [
        $poId, $scope, $currency, $revisionId, $previousRevisionId,
        procurementLocalAdvanceCents($previousPoCents), procurementLocalAdvanceCents($revisedPoCents), procurementLocalAdvanceCents($revisedPoCents - $previousPoCents),
        procurementLocalAdvanceCents($previousBaseCents), procurementLocalAdvanceCents($revisedBaseCents), procurementLocalAdvanceCents($revisedBaseCents - $previousBaseCents),
        $paymentSummary['allocated_percentage'], procurementLocalAdvanceCents($previousCommittedCents), procurementLocalAdvanceCents($revisedCommittedCents),
        $paymentSummary['total_paid'], $paymentSummary['total_processing'], $paymentSummary['total_pending'],
        procurementLocalAdvanceCents(abs($deltaCents)), $direction, $status, $resolutionType,
        $actorId, $direction === 'No Change' ? $actorId : null, $direction === 'No Change' ? date('Y-m-d H:i:s') : null, $actorId,
    ];
    $insert->bind_param(str_repeat('s', count($params)), ...$params);
    $insert->execute();
    $reconciliationId = (int) $conn->insert_id;
    $insert->close();
    if ($reconciliationId <= 0) {
        throw new RuntimeException('The FX PO reconciliation could not be created.', 500);
    }

    $supersede = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Superseded', superseded_at = NOW(), updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ? AND revision_status = 'Current'"
    );
    $supersede->bind_param('iiss', $actorId, $previousRevisionId, $scope, $currency);
    $supersede->execute();
    if ($supersede->affected_rows !== 1) {
        $supersede->close();
        throw new RuntimeException('The current FX PO revision changed before approval completed.', 409);
    }
    $supersede->close();

    $approve = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Current', approved_by = ?, approved_at = NOW(),
             is_locked = 1, locked_at = COALESCE(locked_at, NOW()),
             locked_reason = COALESCE(locked_reason, 'Approved amendment to paid FX PO'),
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ? AND revision_status = 'Pending Approval'"
    );
    $approve->bind_param('iiiss', $actorId, $actorId, $revisionId, $scope, $currency);
    $approve->execute();
    if ($approve->affected_rows !== 1) {
        $approve->close();
        throw new RuntimeException('The FX PO amendment changed before approval completed.', 409);
    }
    $approve->close();

    $amendmentStatus = $direction === 'Increase' ? 'Supplementary Required' : ($direction === 'Decrease' ? 'Recovery Required' : 'Approved');
    $updatePo = $conn->prepare(
        "UPDATE procurement_local_advance_pos p
         INNER JOIN procurement_local_advance_po_revisions revision
           ON revision.id = ? AND revision.request_scope = ? AND revision.currency = ?
         SET p.po_number = revision.po_number,
             p.po_number_normalized = revision.po_number_normalized,
             p.project_id = revision.project_id,
             p.project_code = revision.project_code,
             p.project_name = revision.project_name,
             p.supplier_id = revision.supplier_id,
             p.supplier_name = revision.supplier_name,
             p.supplier_ledger = revision.supplier_ledger,
             p.wht_status = revision.wht_status,
             p.wht_rate = revision.wht_rate,
             p.wht_amount = revision.wht_amount,
             p.purchase_value = revision.purchase_value,
             p.po_subtotal = revision.po_subtotal,
             p.po_discount = revision.po_discount,
             p.po_other_charges = revision.po_other_charges,
             p.po_vat_status = revision.po_vat_status,
             p.po_vat_rate = revision.po_vat_rate,
             p.po_vat_amount = revision.po_vat_amount,
             p.po_value = revision.po_value,
             p.advance_base_amount = revision.advance_base_amount,
             p.po_status = revision.po_status,
             p.current_revision_id = revision.id,
             p.current_revision_number = revision.revision_number,
             p.amendment_status = ?,
             p.updated_by = ?, p.updated_at = NOW(), p.version = p.version + 1
         WHERE p.id = ? AND p.request_scope = ? AND p.currency = ?"
    );
    $updatePo->bind_param('isssiiss', $revisionId, $scope, $currency, $amendmentStatus, $actorId, $poId, $scope, $currency);
    $updatePo->execute();
    if ($updatePo->affected_rows !== 1) {
        $updatePo->close();
        throw new RuntimeException('The amended FX PO could not be activated.', 409);
    }
    $updatePo->close();

    $accountSync = procurementFxAdvanceSynchronizeApprovedAmendmentToAccount($conn, $reconciliationId, $actor);
    $amendmentStatus = (string) $accountSync['status'] === 'Pending Supplementary Payment'
        ? 'Supplementary Required'
        : ((string) $accountSync['status'] === 'Recovery Required' ? 'Recovery Required' : 'Approved');
    $statusUpdate = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = ?, updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ?"
    );
    $statusUpdate->bind_param('siiss', $amendmentStatus, $actorId, $poId, $scope, $currency);
    $statusUpdate->execute();
    $statusUpdate->close();

    $freshRevision = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    $freshReconciliation = procurementFxAdvanceFetchReconciliation($conn, $reconciliationId, true) ?? [];
    procurementFxAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        $revisionId,
        $reconciliationId,
        'po_amendment_approved',
        $actor,
        [
            'reason' => (string) ($freshRevision['amendment_reason'] ?? ''),
            'amendment_type' => (string) ($freshRevision['amendment_type'] ?? ''),
            'previous_committed_amount' => (string) ($freshReconciliation['previous_committed_amount'] ?? '0.00'),
            'revised_committed_amount' => (string) ($freshReconciliation['revised_committed_amount'] ?? '0.00'),
            'reconciliation_direction' => (string) ($freshReconciliation['reconciliation_direction'] ?? 'No Change'),
            'reconciliation_status' => (string) ($freshReconciliation['reconciliation_status'] ?? 'Resolved'),
            'reconciliation_amount' => (string) ($freshReconciliation['reconciliation_amount'] ?? '0.00'),
            'supplementary_purchase_id' => (int) ($freshReconciliation['supplementary_purchase_id'] ?? 0),
            'supplementary_fx_fund_request_id' => (int) ($freshReconciliation['supplementary_fx_fund_request_id'] ?? 0),
            'account_sync' => $accountSync,
        ]
    );
    return ['revision' => $freshRevision, 'reconciliation' => $freshReconciliation, 'account_sync' => $accountSync];
}

function procurementFxAdvanceRejectPoAmendment(
    mysqli $conn,
    int $revisionId,
    string $reason,
    array $actor
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('An FX PO amendment rejection reason is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $revision = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The FX PO amendment could not be found.', 404);
    }
    if ((string) $revision['revision_status'] !== 'Pending Approval') {
        throw new RuntimeException('Only a pending FX PO amendment can be rejected.', 409);
    }
    $currency = (string) $revision['currency'];
    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ? AND revision_status = 'Pending Approval'"
    );
    $stmt->bind_param('isiiss', $actorId, $reason, $actorId, $revisionId, $scope, $currency);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The FX PO amendment changed before rejection completed.', 409);
    }
    $stmt->close();
    $poId = (int) $revision['po_id'];
    $update = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Rejected', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ?"
    );
    $update->bind_param('iiss', $actorId, $poId, $scope, $currency);
    $update->execute();
    $update->close();
    $fresh = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    procurementFxAdvanceRecordPoWorkflowEvent($conn, $poId, $revisionId, null, 'po_amendment_rejected', $actor, ['reason' => $reason]);
    return $fresh;
}

function procurementFxAdvanceCancelPoAmendment(
    mysqli $conn,
    int $revisionId,
    string $reason,
    array $actor
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('An FX PO amendment cancellation reason is required.', 400);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $revision = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true);
    if (!$revision) {
        throw new RuntimeException('The FX PO amendment could not be found.', 404);
    }
    if (!in_array((string) $revision['revision_status'], ['Draft', 'Pending Approval'], true)) {
        throw new RuntimeException('Only a draft or pending FX PO amendment can be cancelled.', 409);
    }
    if ((int) ($revision['created_by'] ?? 0) !== $actorId && (string) ($actor['role'] ?? '') !== 'super_admin') {
        throw new RuntimeException('Only the amendment creator or a Super Admin can cancel it.', 403);
    }
    $currency = (string) $revision['currency'];
    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revisions
         SET revision_status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ?
           AND revision_status IN ('Draft', 'Pending Approval')"
    );
    $stmt->bind_param('isiiss', $actorId, $reason, $actorId, $revisionId, $scope, $currency);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The FX PO amendment changed before cancellation completed.', 409);
    }
    $stmt->close();
    $poId = (int) $revision['po_id'];
    $update = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Cancelled', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ?"
    );
    $update->bind_param('iiss', $actorId, $poId, $scope, $currency);
    $update->execute();
    $update->close();
    $fresh = procurementFxAdvanceFetchPoRevision($conn, $revisionId, true) ?? [];
    procurementFxAdvanceRecordPoWorkflowEvent($conn, $poId, $revisionId, null, 'po_amendment_cancelled', $actor, ['reason' => $reason]);
    return $fresh;
}

function procurementFxAdvanceResolveRecoveryReconciliation(
    mysqli $conn,
    int $reconciliationId,
    mixed $resolutionTypeValue,
    string $reference,
    string $notes,
    array $actor
): array {
    $resolutionType = procurementLocalAdvanceValidateStatus(
        $resolutionTypeValue,
        PROCUREMENT_LOCAL_ADVANCE_RECOVERY_RESOLUTION_TYPES,
        'Resolution Type'
    );
    $reference = trim($reference);
    $notes = trim($notes);
    if ($reference === '') {
        throw new RuntimeException('An FX recovery, refund or supplier-credit reference is required.', 400);
    }
    if ($notes === '') {
        throw new RuntimeException('FX reconciliation resolution notes are required.', 400);
    }
    $reconciliation = procurementFxAdvanceFetchReconciliation($conn, $reconciliationId, true);
    if (!$reconciliation) {
        throw new RuntimeException('The FX PO reconciliation could not be found.', 404);
    }
    if ((string) $reconciliation['reconciliation_direction'] !== 'Decrease'
        || (string) $reconciliation['reconciliation_status'] !== 'Recovery Required') {
        throw new RuntimeException('Only an unresolved FX decrease reconciliation can be resolved manually.', 409);
    }
    $actorId = (int) ($actor['id'] ?? 0);
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $currency = (string) $reconciliation['currency'];
    $stmt = $conn->prepare(
        "UPDATE procurement_local_advance_po_revision_reconciliations
         SET reconciliation_status = 'Resolved', resolution_type = ?, recovery_reference = ?,
             resolution_notes = ?, resolved_by = ?, resolved_at = NOW(), updated_by = ?, updated_at = NOW()
         WHERE id = ? AND request_scope = ? AND currency = ?
           AND reconciliation_status = 'Recovery Required'"
    );
    $stmt->bind_param('sssiiiss', $resolutionType, $reference, $notes, $actorId, $actorId, $reconciliationId, $scope, $currency);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The FX PO reconciliation changed before resolution completed.', 409);
    }
    $stmt->close();
    $poId = (int) $reconciliation['po_id'];
    $update = $conn->prepare(
        "UPDATE procurement_local_advance_pos
         SET amendment_status = 'Resolved', updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE id = ? AND request_scope = ? AND currency = ?"
    );
    $update->bind_param('iiss', $actorId, $poId, $scope, $currency);
    $update->execute();
    $update->close();
    $fresh = procurementFxAdvanceFetchReconciliation($conn, $reconciliationId, true) ?? [];
    procurementFxAdvanceRecordPoWorkflowEvent(
        $conn,
        $poId,
        (int) ($fresh['revision_id'] ?? 0),
        $reconciliationId,
        'po_reconciliation_resolved',
        $actor,
        [
            'resolution_type' => $resolutionType,
            'recovery_reference' => $reference,
            'resolution_notes' => $notes,
            'reconciliation_direction' => 'Decrease',
            'reconciliation_status' => 'Resolved',
            'reconciliation_amount' => (string) ($fresh['reconciliation_amount'] ?? '0.00'),
        ]
    );
    return $fresh;
}

function procurementFxAdvanceSerializePoRevision(array $revision): array
{
    return procurementLocalAdvanceSerializePoRevision($revision);
}

function procurementFxAdvanceSerializeReconciliation(array $reconciliation): array
{
    $serialized = procurementLocalAdvanceSerializeReconciliation($reconciliation);
    if (array_key_exists('supplementary_fx_fund_request_id', $serialized)) {
        $serialized['supplementary_fx_fund_request_id'] = $serialized['supplementary_fx_fund_request_id'] === null
            ? null
            : (int) $serialized['supplementary_fx_fund_request_id'];
    }
    $decoded = json_decode((string) ($serialized['allocation_snapshot_json'] ?? ''), true);
    $serialized['allocation_snapshot'] = is_array($decoded) ? $decoded : null;
    unset($serialized['allocation_snapshot_json']);
    return $serialized;
}

function procurementFxAdvanceListPoAmendments(mysqli $conn, int $poId): array
{
    if ($poId <= 0) {
        throw new RuntimeException('A valid FX Advance PO ID is required.', 400);
    }
    $po = procurementFxAdvanceFetchPo($conn, $poId);
    if (!$po) {
        throw new RuntimeException('The FX Advance PO could not be found.', 404);
    }
    $scope = PROCUREMENT_FX_ADVANCE_SCOPE;
    $currency = (string) $po['currency'];
    $revisionStmt = $conn->prepare(
        "SELECT revision.*,
                CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS rejected_by_name
         FROM procurement_local_advance_po_revisions revision
         LEFT JOIN user_table cu ON cu.id = revision.created_by
         LEFT JOIN user_table au ON au.id = revision.approved_by
         LEFT JOIN user_table ru ON ru.id = revision.rejected_by
         WHERE revision.po_id = ? AND revision.request_scope = ? AND revision.currency = ?
         ORDER BY revision.revision_number DESC, revision.id DESC"
    );
    $revisionStmt->bind_param('iss', $poId, $scope, $currency);
    $revisionStmt->execute();
    $revisions = array_map('procurementFxAdvanceSerializePoRevision', $revisionStmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $revisionStmt->close();

    $type = PROCUREMENT_REQUEST_TYPE_FX_ADVANCE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
    $reconciliationStmt = $conn->prepare(
        "SELECT reconciliation.*, revision.revision_number, revision.revision_reference,
                supplementary.account_request_id AS supplementary_fx_fund_request_id,
                CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS resolved_by_name
         FROM procurement_local_advance_po_revision_reconciliations reconciliation
         INNER JOIN procurement_local_advance_po_revisions revision
           ON revision.id = reconciliation.revision_id
          AND revision.request_scope = reconciliation.request_scope
          AND revision.currency = reconciliation.currency
         LEFT JOIN procurement_requests supplementary
           ON supplementary.request_type = ? AND supplementary.legacy_source_table = ?
          AND supplementary.legacy_source_id = reconciliation.supplementary_purchase_id
          AND supplementary.deleted_at IS NULL
         LEFT JOIN user_table cu ON cu.id = reconciliation.created_by
         LEFT JOIN user_table ru ON ru.id = reconciliation.resolved_by
         WHERE reconciliation.po_id = ? AND reconciliation.request_scope = ? AND reconciliation.currency = ?
         ORDER BY reconciliation.created_at DESC, reconciliation.id DESC"
    );
    $reconciliationStmt->bind_param('ssiss', $type, $source, $poId, $scope, $currency);
    $reconciliationStmt->execute();
    $reconciliations = array_map(
        'procurementFxAdvanceSerializeReconciliation',
        $reconciliationStmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $reconciliationStmt->close();

    return [
        'po' => $po,
        'payment_summary' => procurementFxAdvancePoPaymentSummary($conn, $poId),
        'revisions' => $revisions,
        'reconciliations' => $reconciliations,
        'events' => procurementLocalAdvanceListPoWorkflowEvents($conn, $poId),
    ];
}
