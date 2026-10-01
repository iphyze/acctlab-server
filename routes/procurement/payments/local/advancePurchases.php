<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';
require_once 'includes/procurementRequestCanonicalReadService.php';

function localAdvancePurchaseErrorResponse(Throwable $error): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement Local Advance Purchase error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process Local Advance Purchase.' : $error->getMessage(),
    ], $status);
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    procurementEnsureAuthenticationTables($conn);
    if ($method === 'GET') {
        procurementAssertLocalAdvancePurchaseReadStorage($conn);
    } else {
        procurementLocalAdvanceEnsureStorage($conn);
        procurementNotificationEnsureStorage($conn);
    }

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'payments.local_advance.view');
        $canonicalResponse = procurementRequestCanonicalLocalAdvanceGetResponse($conn, $_GET);
        if ($canonicalResponse !== null) {
            jsonResponse($canonicalResponse);
        }
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
            $record = procurementLocalAdvanceFetchRecord($conn, $id);
            if (!$record) {
                throw new RuntimeException('Local Advance Purchase not found.', 404);
            }

            $response = [
                'record' => procurementLocalAdvanceSerializeRecord($record),
                'replacement_links' => procurementPurchaseReplacementLinks(
                    $conn,
                    'local_advance_purchase',
                    $id
                ),
            ];
            if (filter_var($_GET['include_events'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $response['events'] = procurementRequestCanonicalLocalAdvanceEvents($conn, $id);
                $response['handoffs'] = procurementRequestCanonicalLocalAdvanceHandoffs($conn, $id);
            }

            jsonResponse(['status' => 'Success', 'data' => $response]);
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim((string) ($_GET['search'] ?? ''));
        $approvalStatus = trim((string) ($_GET['approval_status'] ?? ''));
        $paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
        $poStatus = trim((string) ($_GET['po_status'] ?? ''));
        $handoffStatus = trim((string) ($_GET['handoff_status'] ?? ''));
        $year = (int) ($_GET['year'] ?? 0);
        $projectId = (int) ($_GET['project_id'] ?? 0);
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);

        if ($approvalStatus !== '') {
            $approvalStatus = procurementLocalAdvanceValidateStatus(
                $approvalStatus,
                PROCUREMENT_LOCAL_ADVANCE_APPROVAL_STATUSES,
                'Approval Status'
            );
        }
        if ($paymentStatus !== '') {
            $paymentStatus = procurementLocalAdvanceValidateStatus(
                $paymentStatus,
                PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES,
                'Payment Status'
            );
        }
        if ($poStatus !== '') {
            $poStatus = procurementLocalAdvanceValidateStatus(
                $poStatus,
                PROCUREMENT_LOCAL_ADVANCE_PO_STATUSES,
                'PO Status'
            );
        }
        if ($handoffStatus !== '') {
            $handoffStatus = procurementLocalAdvanceValidateStatus(
                $handoffStatus,
                PROCUREMENT_LOCAL_ADVANCE_HANDOFF_STATUSES,
                'Account Handoff Status'
            );
        }
        if ($year !== 0 && ($year < 2000 || $year > 2100)) {
            throw new RuntimeException('Year filter is invalid.', 400);
        }

        $allowedSortFields = [
            'created_at' => 'r.created_at',
            'updated_at' => 'r.updated_at',
            'approved_at' => 'r.approved_at',
            'retrieved_at' => 'r.retrieved_at',
            'transaction_date' => 'r.transaction_date',
            'date_received' => 'r.date_received',
            'request_number' => 'r.request_number',
            'purchase_number' => 'r.purchase_number',
            'po_number' => 'COALESCE(pr.po_number, p.po_number)',
            'supplier_name' => 'COALESCE(pr.supplier_name, p.supplier_name)',
            'project_code' => 'COALESCE(pr.project_code, p.project_code)',
            'po_percentage' => 'r.po_percentage',
            'expected_payment' => 'r.expected_payment',
            'po_value' => 'COALESCE(pr.po_value, p.po_value)',
            'approval_status' => 'r.approval_status',
            'payment_status' => 'r.payment_status',
            'po_status' => 'p.po_status',
            'handoff_status' => 'r.handoff_status',
        ];
        $sortBy = (string) ($_GET['sort_by'] ?? 'created_at');
        $sortColumn = $allowedSortFields[$sortBy] ?? $allowedSortFields['created_at'];
        $sortOrder = strtoupper((string) ($_GET['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['r.deleted_at IS NULL'];
        $params = [];
        $types = '';
        if ($search !== '') {
            $where[] = '(r.request_number LIKE ? OR r.purchase_number LIKE ? OR COALESCE(pr.po_number, p.po_number) LIKE ? OR COALESCE(pr.project_code, p.project_code) LIKE ? OR COALESCE(pr.project_name, p.project_name) LIKE ? OR COALESCE(pr.supplier_name, p.supplier_name) LIKE ? OR COALESCE(pr.supplier_ledger, p.supplier_ledger) LIKE ?)';
            $like = '%' . $search . '%';
            for ($index = 0; $index < 7; $index++) {
                $params[] = $like;
            }
            $types .= 'sssssss';
        }
        if ($approvalStatus !== '') {
            $where[] = 'r.approval_status = ?';
            $params[] = $approvalStatus;
            $types .= 's';
        }
        if ($paymentStatus !== '') {
            $where[] = 'r.payment_status = ?';
            $params[] = $paymentStatus;
            $types .= 's';
        }
        if ($poStatus !== '') {
            $where[] = 'p.po_status = ?';
            $params[] = $poStatus;
            $types .= 's';
        }
        if ($handoffStatus !== '') {
            $where[] = 'r.handoff_status = ?';
            $params[] = $handoffStatus;
            $types .= 's';
        }
        if ($year > 0) {
            $where[] = 'r.transaction_date >= ? AND r.transaction_date < ?';
            $params[] = sprintf('%04d-01-01', $year);
            $params[] = sprintf('%04d-01-01', $year + 1);
            $types .= 'ss';
        }
        if ($projectId > 0) {
            $where[] = 'COALESCE(pr.project_id, p.project_id) = ?';
            $params[] = $projectId;
            $types .= 'i';
        }
        if ($supplierId > 0) {
            $where[] = 'COALESCE(pr.supplier_id, p.supplier_id) = ?';
            $params[] = $supplierId;
            $types .= 'i';
        }
        $whereSql = implode(' AND ', $where);
        $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();

        $count = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM {$localAdvanceRelation} r
             INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
             LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
             WHERE $whereSql"
        );
        if ($params !== []) {
            $count->bind_param($types, ...$params);
        }
        $count->execute();
        $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
        $count->close();

        // Register projection: exclude PO snapshots/revision payloads and other detail-only columns.
        $list = $conn->prepare(
            "SELECT
                    r.id,
                    r.request_number,
                    r.po_id,
                    r.po_revision_id,
                    r.po_revision_number,
                    r.purchase_number,
                    r.po_percentage,
                    r.expected_payment,
                    r.account_wht_override_status,
                    r.account_wht_override_amount,
                    r.account_expected_payment,
                    r.transaction_date,
                    r.date_received,
                    r.payment_status,
                    r.approval_status,
                    r.handoff_status,
                    r.handoff_revision,
                    r.account_request_type,
                    r.advance_payment_request_id,
                    r.previous_advance_payment_request_id,
                    r.approved_at,
                    r.retrieved_at,
                    r.retrieval_reason,
                    r.retrieval_source,
                    r.account_amount_paid,
                    r.account_processing_started_at,
                    r.account_payment_batch_id,
                    r.created_at,
                    r.version,
                    COALESCE(pr.po_number, p.po_number) AS po_number,
                    COALESCE(pr.project_id, p.project_id) AS project_id,
                    COALESCE(pr.project_code, p.project_code) AS project_code,
                    COALESCE(pr.project_name, p.project_name) AS project_name,
                    COALESCE(pr.supplier_id, p.supplier_id) AS supplier_id,
                    COALESCE(pr.supplier_name, p.supplier_name) AS supplier_name,
                    COALESCE(pr.supplier_ledger, p.supplier_ledger) AS supplier_ledger,
                    COALESCE(pr.wht_status, p.wht_status) AS wht_status,
                    COALESCE(pr.wht_amount, p.wht_amount) AS wht_amount,
                    COALESCE(pr.po_vat_status, p.po_vat_status) AS po_vat_status,
                    COALESCE(pr.po_vat_amount, p.po_vat_amount) AS po_vat_amount,
                    COALESCE(pr.po_value, p.po_value) AS po_value,
                    p.po_status AS po_status,
                    p.version AS po_version,
                    p.amendment_status,
                    COALESCE(pr.is_locked, 0) AS po_revision_is_locked,
                    COALESCE(r.account_expected_payment, r.expected_payment, apr.advance_payment, cfr.amount) AS account_advance_payment,
                    COALESCE(apr.payment_status, cfr.payment_status) AS account_payment_status,
                    COALESCE(apr.amount_paid, cfr.amount_paid, r.account_amount_paid, 0.00) AS amount_paid,
                    CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                    CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                    CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
             FROM {$localAdvanceRelation} r
             INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
             LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
             LEFT JOIN advance_payment_request apr
               ON apr.id = r.advance_payment_request_id
              AND (r.account_request_type IS NULL OR r.account_request_type = 'advance_payment_request')
             LEFT JOIN compass_fund_request_table cfr
               ON cfr.id = r.advance_payment_request_id
              AND r.account_request_type = 'compass_fund_request'
             LEFT JOIN user_table cu ON cu.id = r.created_by
             LEFT JOIN user_table au ON au.id = r.approved_by
             LEFT JOIN user_table rtu ON rtu.id = r.retrieved_by
             WHERE $whereSql
             ORDER BY $sortColumn $sortOrder, r.id DESC
             LIMIT ? OFFSET ?"
        );
        $listParams = array_merge($params, [$limit, $offset]);
        $listTypes = $types . 'ii';
        $list->bind_param($listTypes, ...$listParams);
        $list->execute();
        $rows = $list->get_result()->fetch_all(MYSQLI_ASSOC);
        $list->close();
        $allocationByPo = procurementLocalAdvanceAllocatedUnitsForPoIds(
            $conn,
            array_column($rows, 'po_id')
        );
        foreach ($rows as &$row) {
            $poId = (int) ($row['po_id'] ?? 0);
            $row['allocated_percentage'] = procurementLocalAdvancePercentString(
                $allocationByPo[$poId] ?? 0
            );
            $row = procurementLocalAdvanceSerializeRecord($row);
        }
        unset($row);

        jsonResponse([
            'status' => 'Success',
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil($total / $limit),
                'filters' => [
                    'search' => $search,
                    'approval_status' => $approvalStatus,
                    'payment_status' => $paymentStatus,
                    'po_status' => $poStatus,
                    'handoff_status' => $handoffStatus,
                    'year' => $year ?: null,
                    'project_id' => $projectId ?: null,
                    'supplier_id' => $supplierId ?: null,
                ],
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
        ]);
    }

    if ($method === 'POST') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.local_advance.create');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $payload = procurementLocalAdvanceBuildPayload($conn, $data);

        $conn->begin_transaction();
        try {
            $actorId = (int) $actor['id'];
            $poResult = procurementLocalAdvanceCreatePo($conn, $payload, $actorId);
            $poId = (int) $poResult['id'];
            $po = procurementLocalAdvanceFetchPo($conn, $poId, true);
            if (!$po) {
                throw new RuntimeException('Unable to initialize the Local Advance PO.', 500);
            }
            procurementLocalAdvanceAssertExistingPoCompatible($po, $payload);
            if (!(bool) $poResult['created']) {
                procurementLocalAdvanceAssertPoOpen($po);
            }
            $allocation = procurementLocalAdvanceAssertAllocationAvailable(
                $conn,
                $poId,
                (int) $payload['percentage_units']
            );

            $poRevisionId = (int) $poResult['revision_id'];
            $poRevisionNumber = (int) $poResult['revision_number'];
            $poSnapshotJson = (string) $poResult['revision_snapshot_json'];
            $id = procurementRequestCanonicalNextLegacyId(
                $conn,
                PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE
            );
            $requestNumber = 'LAP-' . date('Y') . '-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);
            $canonicalType = PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_TYPE;
            $canonicalSource = PROCUREMENT_REQUEST_CANONICAL_LOCAL_ADVANCE_SOURCE;
            $requestVariant = 'Original';
            $stmt = $conn->prepare(
                "INSERT INTO procurement_requests
                    (request_type, request_number, legacy_source_table, legacy_source_id,
                     po_id, po_revision_id, po_revision_number, request_variant, po_snapshot_json,
                     purchase_number, po_percentage, expected_payment, remark,
                     transaction_date, payment_status, payment_status_source, payment_status_updated_at,
                     approval_status, handoff_status, handoff_revision, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(),
                         'Pending', 'procuredesk', NOW(), 'Unapproved', 'Not Sent', 0, ?, ?)"
            );
            $params = [
                $canonicalType, $requestNumber, $canonicalSource, $id,
                $poId, $poRevisionId, $poRevisionNumber, $requestVariant, $poSnapshotJson,
                $payload['purchase_number'], $payload['po_percentage'],
                $payload['expected_payment'], $payload['remark'], $actorId, $actorId,
            ];
            $stmt->bind_param('sssiiiissssssii', ...$params);
            $stmt->execute();
            $stmt->close();

            $replacementOfId = (int) ($data['replacement_of_id'] ?? 0);
            $replacementReason = trim((string) ($data['replacement_reason'] ?? ''));
            if ($replacementOfId > 0) {
                $source = procurementLocalAdvanceFetchRecord($conn, $replacementOfId, true);
                if (!$source || (string) ($source['po_status'] ?? '') !== 'Cancelled') {
                    throw new RuntimeException('A replacement can only be linked to a cancelled Local Advance Purchase.', 409);
                }
                procurementPurchaseReplacementCreate(
                    $conn,
                    'local_advance_purchase',
                    $replacementOfId,
                    'local_advance_purchase',
                    $id,
                    $actorId,
                    $replacementReason
                );
                procurementLocalAdvanceRecordEvent($conn, $replacementOfId, 'replacement_created', $actor, [
                    'replacement_purchase_id' => $id,
                    'reason' => $replacementReason !== '' ? $replacementReason : null,
                ]);
            }

            procurementLocalAdvanceRecordEvent($conn, $id, 'created', $actor, [
                'request_number' => $requestNumber,
                'po_number' => $payload['po_number'],
                'po_revision_id' => $poRevisionId,
                'po_revision_number' => $poRevisionNumber,
                'po_percentage' => $payload['po_percentage'],
                'expected_payment' => $payload['expected_payment'],
                'allocated_after' => procurementLocalAdvancePercentString((int) $allocation['total_units']),
                'available_after' => procurementLocalAdvancePercentString((int) $allocation['available_units']),
                'replacement_of_id' => $replacementOfId > 0 ? $replacementOfId : null,
            ]);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        procurementRequestCanonicalTrySyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
            $id,
            'local_advance_create'
        );

        jsonResponse([
            'status' => 'Success',
            'message' => 'Local Advance Purchase created successfully.',
            'data' => procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $id) ?? []),
        ], 201);
    }

    if ($method === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.local_advance.update');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('A valid Local Advance Purchase ID is required.', 400);
        }
        $payload = procurementLocalAdvanceBuildPayload($conn, $data);

        $conn->begin_transaction();
        try {
            $existing = procurementLocalAdvanceFetchRecord($conn, $id, true);
            if (!$existing) {
                throw new RuntimeException('Local Advance Purchase not found.', 404);
            }
            if ((string) $existing['approval_status'] !== 'Unapproved') {
                throw new RuntimeException('Approved Local Advance Purchases are closed and cannot be edited.', 409);
            }
            if ((string) $existing['payment_status'] !== 'Pending') {
                throw new RuntimeException('Only pending Local Advance Purchases can be edited.', 409);
            }
            if ((string) ($existing['handoff_status'] ?? '') === 'Retrieved'
                && (string) $actor['role'] !== 'super_admin'
                && !in_array('payments.local_advance.edit_retrieved', $actor['permissions'], true)) {
                throw new RuntimeException('You are not permitted to edit purchases returned by Account.', 403);
            }
            if (array_key_exists('version', $data) && (int) $data['version'] !== (int) $existing['version']) {
                throw new RuntimeException('This record was updated by another user. Refresh and try again.', 409);
            }

            $actorId = (int) $actor['id'];
            $currentPoId = (int) $existing['po_id'];
            $currentPo = procurementLocalAdvanceFetchPo($conn, $currentPoId, true);
            if (!$currentPo) {
                throw new RuntimeException('The linked PO could not be found.', 409);
            }

            $targetPoId = $currentPoId;
            $targetPoRevisionId = (int) ($existing['po_revision_id'] ?? 0);
            $targetPoRevisionNumber = max(1, (int) ($existing['po_revision_number'] ?? 1));
            $targetPoSnapshotJson = (string) ($existing['po_snapshot_json'] ?? '');
            if ((string) $currentPo['po_number_normalized'] === $payload['po_number_normalized']) {
                if ((string) $currentPo['po_status'] !== $payload['po_status']) {
                    throw new RuntimeException('Use the PO status action to change PO Status.', 409);
                }
                $otherActive = procurementLocalAdvanceCountOtherActiveRequests($conn, $currentPoId, $id);
                if ($otherActive > 0) {
                    procurementLocalAdvanceAssertExistingPoCompatible($currentPo, $payload);
                } else {
                    procurementLocalAdvanceUpdatePoCommercials($conn, $currentPoId, $payload, $actorId);
                }
                $allocation = procurementLocalAdvanceAssertAllocationAvailable(
                    $conn,
                    $currentPoId,
                    (int) $payload['percentage_units'],
                    $id
                );
                if ($targetPoRevisionId <= 0 || trim($targetPoSnapshotJson) === '') {
                    $targetRevision = procurementLocalAdvanceResolveCurrentPoRevision($conn, $currentPoId, true);
                    $targetPoRevisionId = (int) $targetRevision['id'];
                    $targetPoRevisionNumber = (int) $targetRevision['revision_number'];
                    $targetPoSnapshotJson = (string) $targetRevision['commercial_snapshot_json'];
                } else {
                    $targetRevision = procurementLocalAdvanceFetchPoRevision($conn, $targetPoRevisionId, true);
                    if ($targetRevision) {
                        $targetPoRevisionNumber = (int) $targetRevision['revision_number'];
                        $targetPoSnapshotJson = (string) $targetRevision['commercial_snapshot_json'];
                    }
                }
            } else {
                $targetPoResult = procurementLocalAdvanceCreatePo($conn, $payload, $actorId);
                $targetPoId = (int) $targetPoResult['id'];
                $targetPoRevisionId = (int) $targetPoResult['revision_id'];
                $targetPoRevisionNumber = (int) $targetPoResult['revision_number'];
                $targetPoSnapshotJson = (string) $targetPoResult['revision_snapshot_json'];
                $targetPo = procurementLocalAdvanceFetchPo($conn, $targetPoId, true);
                if (!$targetPo) {
                    throw new RuntimeException('The target PO could not be initialized.', 500);
                }
                procurementLocalAdvanceAssertExistingPoCompatible($targetPo, $payload);
                if (!(bool) $targetPoResult['created']) {
                    procurementLocalAdvanceAssertPoOpen($targetPo);
                }
                $allocation = procurementLocalAdvanceAssertAllocationAvailable(
                    $conn,
                    $targetPoId,
                    (int) $payload['percentage_units']
                );
            }

            $canonicalScope = procurementRequestCanonicalLocalAdvanceScopeSql();
            $stmt = $conn->prepare(
                "UPDATE procurement_requests SET
                    po_id = ?, po_revision_id = ?, po_revision_number = ?, po_snapshot_json = ?,
                    purchase_number = ?, po_percentage = ?, expected_payment = ?, remark = ?,
                    account_wht_override_status = NULL, account_wht_override_rate = NULL,
                    account_wht_override_amount = NULL, account_expected_payment = NULL,
                    account_wht_adjustment_reason = NULL, account_wht_adjusted_by = NULL,
                    account_wht_adjusted_at = NULL,
                    updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$canonicalScope}"
            );
            $params = [
                $targetPoId, $targetPoRevisionId, $targetPoRevisionNumber, $targetPoSnapshotJson,
                $payload['purchase_number'], $payload['po_percentage'],
                $payload['expected_payment'], $payload['remark'], $actorId, $id,
            ];
            $stmt->bind_param('iiisssssii', ...$params);
            $stmt->execute();
            $stmt->close();

            procurementLocalAdvanceRecordEvent($conn, $id, 'updated', $actor, [
                'previous_version' => (int) $existing['version'],
                'previous_po_number' => (string) $existing['po_number'],
                'po_number' => $payload['po_number'],
                'previous_po_revision_id' => (int) ($existing['po_revision_id'] ?? 0),
                'po_revision_id' => $targetPoRevisionId,
                'po_revision_number' => $targetPoRevisionNumber,
                'previous_po_percentage' => (string) $existing['po_percentage'],
                'po_percentage' => $payload['po_percentage'],
                'expected_payment' => $payload['expected_payment'],
                'allocated_after' => procurementLocalAdvancePercentString((int) $allocation['total_units']),
                'available_after' => procurementLocalAdvancePercentString((int) $allocation['available_units']),
            ]);
            procurementRequestCanonicalSyncRequest(
                $conn,
                PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
                $id
            );
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        jsonResponse([
            'status' => 'Success',
            'message' => 'Local Advance Purchase updated successfully.',
            'data' => procurementLocalAdvanceSerializeRecord(procurementLocalAdvanceFetchRecord($conn, $id) ?? []),
        ]);
    }

    if ($method === 'DELETE') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.local_advance.delete');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $ids = procurementLocalAdvanceBatchIds($data['ids'] ?? $data['requestIds'] ?? null);
        $reason = trim((string) ($data['reason'] ?? ''));
        $successful = [];
        $failed = [];

        foreach ($ids as $id) {
            $conn->begin_transaction();
            try {
                $record = procurementLocalAdvanceFetchRecord($conn, $id, true);
                if (!$record) {
                    throw new RuntimeException('Local Advance Purchase not found.', 404);
                }
                if ((string) $record['approval_status'] !== 'Unapproved' || !empty($record['advance_payment_request_id'])) {
                    throw new RuntimeException('Approved Local Advance Purchases cannot be deleted.', 409);
                }
                if ((string) $record['payment_status'] !== 'Pending') {
                    throw new RuntimeException('Only pending Local Advance Purchases can be deleted.', 409);
                }
                if ((string) ($record['handoff_status'] ?? '') === 'Retrieved') {
                    throw new RuntimeException('A record returned by Account must be corrected or handled through approval reversal.', 409);
                }

                $actorId = (int) $actor['id'];
                $canonicalScope = procurementRequestCanonicalLocalAdvanceScopeSql();
                $delete = $conn->prepare(
                    "UPDATE procurement_requests
                     SET deleted_by = ?, deleted_at = NOW(), updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE legacy_source_id = ? AND {$canonicalScope} AND deleted_at IS NULL"
                );
                $delete->bind_param('iii', $actorId, $actorId, $id);
                $delete->execute();
                if ($delete->affected_rows !== 1) {
                    $delete->close();
                    throw new RuntimeException('Local Advance Purchase could not be deleted.', 409);
                }
                $delete->close();

                $remainingUnits = procurementLocalAdvanceAllocatedUnits($conn, (int) $record['po_id']);
                procurementLocalAdvanceRecordEvent($conn, $id, 'deleted', $actor, [
                    'reason' => $reason !== '' ? $reason : null,
                    'released_percentage' => (string) $record['po_percentage'],
                    'allocated_after' => procurementLocalAdvancePercentString($remainingUnits),
                    'available_after' => procurementLocalAdvancePercentString(
                        max(0, PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX - $remainingUnits)
                    ),
                ]);
                procurementRequestCanonicalSyncRequest(
                    $conn,
                    PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
                    $id
                );
                $conn->commit();
                $successful[] = $id;
            } catch (Throwable $error) {
                $conn->rollback();
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }

        jsonResponse([
            'status' => $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success'),
            'message' => $failed === [] ? 'Selected Local Advance Purchases were deleted.' : 'Batch deletion completed with some exceptions.',
            'data' => ['successful' => $successful, 'failed' => $failed],
        ], $successful === [] && $failed !== [] ? 409 : 200);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    localAdvancePurchaseErrorResponse($error);
}
