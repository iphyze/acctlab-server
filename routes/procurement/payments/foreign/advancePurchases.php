<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';

function fxAdvancePurchaseErrorResponse(Throwable $error): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement FX Advance Purchase error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process FX Advance Purchase.' : $error->getMessage(),
    ], $status);
}

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxAdvancePurchaseStorage($conn);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'payments.fx_advance.view');
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
            $record = procurementFxAdvanceFetchRecord($conn, $id);
            if (!$record) {
                throw new RuntimeException('FX Advance Purchase not found.', 404);
            }
            $response = [
                'record' => procurementFxAdvanceSerializeRecord($record),
                'replacement_links' => procurementPurchaseReplacementLinks(
                    $conn,
                    'fx_advance_purchase',
                    $id
                ),
            ];
            if (filter_var($_GET['include_events'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $response['events'] = procurementFxAdvanceEvents($conn, $id);
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
        $currency = trim((string) ($_GET['currency'] ?? ''));
        $year = (int) ($_GET['year'] ?? 0);
        $projectId = (int) ($_GET['project_id'] ?? 0);
        $supplierId = (int) ($_GET['supplier_id'] ?? 0);

        if ($approvalStatus !== '') {
            $approvalStatus = procurementLocalAdvanceValidateStatus($approvalStatus, PROCUREMENT_LOCAL_ADVANCE_APPROVAL_STATUSES, 'Approval Status');
        }
        if ($paymentStatus !== '') {
            $paymentStatus = procurementLocalAdvanceValidateStatus($paymentStatus, PROCUREMENT_LOCAL_ADVANCE_PAYMENT_STATUSES, 'Payment Status');
        }
        if ($poStatus !== '') {
            $poStatus = procurementLocalAdvanceValidateStatus($poStatus, PROCUREMENT_LOCAL_ADVANCE_PO_STATUSES, 'PO Status');
        }
        if ($handoffStatus !== '') {
            $handoffStatus = procurementLocalAdvanceValidateStatus($handoffStatus, PROCUREMENT_LOCAL_ADVANCE_HANDOFF_STATUSES, 'Account Handoff Status');
        }
        if ($currency !== '') {
            $currency = procurementFxAdvanceNormalizeCurrency($currency);
        }
        if ($year !== 0 && ($year < 2000 || $year > 2100)) {
            throw new RuntimeException('Year filter is invalid.', 400);
        }

        $allowedSortFields = [
            'created_at' => 'r.created_at', 'updated_at' => 'r.updated_at',
            'transaction_date' => 'r.transaction_date', 'date_received' => 'r.date_received',
            'request_number' => 'r.request_number', 'purchase_number' => 'r.purchase_number',
            'po_number' => 'COALESCE(pr.po_number, p.po_number)',
            'supplier_name' => 'COALESCE(pr.supplier_name, p.supplier_name)',
            'project_code' => 'COALESCE(pr.project_code, p.project_code)',
            'currency' => 'r.currency', 'po_percentage' => 'r.po_percentage',
            'expected_payment' => 'r.expected_payment', 'po_value' => 'COALESCE(pr.po_value, p.po_value)',
            'approval_status' => 'r.approval_status', 'payment_status' => 'r.payment_status',
            'po_status' => 'p.po_status', 'handoff_status' => 'r.handoff_status',
        ];
        $sortBy = (string) ($_GET['sort_by'] ?? 'created_at');
        $sortColumn = $allowedSortFields[$sortBy] ?? $allowedSortFields['created_at'];
        $sortOrder = strtoupper((string) ($_GET['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['r.deleted_at IS NULL', "p.request_scope = 'fx_advance_purchase'"];
        $params = [];
        $types = '';
        if ($search !== '') {
            $where[] = '(r.request_number LIKE ? OR r.purchase_number LIKE ? OR COALESCE(pr.po_number, p.po_number) LIKE ? OR COALESCE(pr.project_code, p.project_code) LIKE ? OR COALESCE(pr.project_name, p.project_name) LIKE ? OR COALESCE(pr.supplier_name, p.supplier_name) LIKE ? OR COALESCE(pr.supplier_ledger, p.supplier_ledger) LIKE ? OR r.contact_person LIKE ? OR r.phone_number LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 9; $i++) $params[] = $like;
            $types .= 'sssssssss';
        }
        foreach ([
            [$approvalStatus, 'r.approval_status = ?'], [$paymentStatus, 'r.payment_status = ?'],
            [$poStatus, 'p.po_status = ?'], [$handoffStatus, 'r.handoff_status = ?'], [$currency, 'r.currency = ?'],
        ] as $filter) {
            if ($filter[0] !== '') {
                $where[] = $filter[1];
                $params[] = $filter[0];
                $types .= 's';
            }
        }
        if ($year > 0) { $where[] = 'r.transaction_date >= ? AND r.transaction_date < ?'; $params[] = sprintf('%04d-01-01', $year); $params[] = sprintf('%04d-01-01', $year + 1); $types .= 'ss'; }
        if ($projectId > 0) { $where[] = 'COALESCE(pr.project_id, p.project_id) = ?'; $params[] = $projectId; $types .= 'i'; }
        if ($supplierId > 0) { $where[] = 'COALESCE(pr.supplier_id, p.supplier_id) = ?'; $params[] = $supplierId; $types .= 'i'; }
        $whereSql = implode(' AND ', $where);
        $relation = procurementRequestCanonicalFxAdvanceReadRelation();

        $count = $conn->prepare(
            "SELECT COUNT(*) AS total FROM {$relation} r
             INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
             LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
             WHERE {$whereSql}"
        );
        if ($params !== []) $count->bind_param($types, ...$params);
        $count->execute();
        $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
        $count->close();

        // Register projection: keep the grid response small; PO snapshots remain detail-only.
        $list = $conn->prepare(
            "SELECT
                    r.id,
                    r.request_number,
                    r.currency,
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
                    r.fx_fund_request_id,
                    r.previous_fx_fund_request_id,
                    r.approved_at,
                    r.retrieved_at,
                    r.retrieval_reason,
                    r.retrieval_source,
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
                    p.po_status,
                    p.version AS po_version,
                    ffr.payment_status AS account_payment_status,
                    ffr.payment_currency AS account_payment_currency,
                    ffr.payment_amount AS amount_paid,
                    ffr.exchange_rate AS account_exchange_rate,
                    ffr.fx_instruction_letter_id
             FROM {$relation} r
             INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
             LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id
             LEFT JOIN fx_fund_request_table ffr ON ffr.id = r.fx_fund_request_id
             WHERE {$whereSql}
             ORDER BY {$sortColumn} {$sortOrder}, r.id DESC LIMIT ? OFFSET ?"
        );
        $listParams = array_merge($params, [$limit, $offset]);
        $listTypes = $types . 'ii';
        $list->bind_param($listTypes, ...$listParams);
        $list->execute();
        $rows = array_map('procurementFxAdvanceSerializeRecord', $list->get_result()->fetch_all(MYSQLI_ASSOC));
        $list->close();

        jsonResponse([
            'status' => 'Success', 'data' => $rows,
            'meta' => [
                'page' => $page, 'limit' => $limit, 'total' => $total,
                'total_pages' => (int) ceil($total / $limit),
                'filters' => compact('search', 'approvalStatus', 'paymentStatus', 'poStatus', 'handoffStatus', 'currency', 'year', 'projectId', 'supplierId'),
                'sort_by' => $sortBy, 'sort_order' => $sortOrder,
            ],
        ]);
    }

    if ($method === 'POST') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.create');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
        $payload = procurementFxAdvanceBuildPayload($conn, $data);

        $conn->begin_transaction();
        try {
            $actorId = (int) $actor['id'];
            $poResult = procurementFxAdvanceCreatePo($conn, $payload, $actorId);
            $poId = (int) $poResult['id'];
            $po = procurementFxAdvanceFetchPo($conn, $poId, true);
            if (!$po) throw new RuntimeException('Unable to initialize the FX Advance PO.', 500);
            procurementFxAdvanceAssertExistingPoCompatible($po, $payload);
            if (!(bool) $poResult['created']) procurementFxAdvanceAssertPoOpen($po);
            $allocation = procurementFxAdvanceAssertAllocationAvailable($conn, $poId, (int) $payload['percentage_units']);

            $publicId = procurementFxAdvanceNextPublicId($conn);
            $requestNumber = 'FXA-' . date('Y') . '-' . str_pad((string) $publicId, 7, '0', STR_PAD_LEFT);
            $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE;
            $source = PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE;
            $variant = 'Original';
            $stmt = $conn->prepare(
                "INSERT INTO procurement_requests
                    (request_type, request_number, legacy_source_table, legacy_source_id, currency,
                     po_id, po_revision_id, po_revision_number, request_variant, po_snapshot_json,
                     purchase_number, po_percentage, expected_payment, remark, contact_person, phone_number,
                     transaction_date, payment_status, payment_status_source, payment_status_updated_at,
                     approval_status, handoff_status, handoff_revision, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), CURDATE(),
                         'Pending', 'procuredesk', NOW(), 'Unapproved', 'Not Sent', 0, ?, ?)"
            );
            $params = [
                $requestType, $requestNumber, $source, $publicId, $payload['currency'],
                $poId, $poResult['revision_id'], $poResult['revision_number'], $variant,
                $poResult['revision_snapshot_json'], $payload['purchase_number'], $payload['po_percentage'],
                $payload['expected_payment'], $payload['remark'], $payload['contact_person'], $payload['phone_number'], $actorId, $actorId,
            ];
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            $replacementOfId = (int) ($data['replacement_of_id'] ?? 0);
            $replacementReason = trim((string) ($data['replacement_reason'] ?? ''));
            if ($replacementOfId > 0) {
                $sourceRecord = procurementFxAdvanceFetchRecord($conn, $replacementOfId, true);
                if (!$sourceRecord || (string) ($sourceRecord['po_status'] ?? '') !== 'Cancelled') {
                    throw new RuntimeException('A replacement can only be linked to a cancelled FX Advance Purchase.', 409);
                }
                procurementPurchaseReplacementCreate(
                    $conn,
                    'fx_advance_purchase',
                    $replacementOfId,
                    'fx_advance_purchase',
                    $publicId,
                    $actorId,
                    $replacementReason
                );
                procurementFxAdvanceRecordEvent($conn, $replacementOfId, 'replacement_created', $actor, [
                    'replacement_purchase_id' => $publicId,
                    'reason' => $replacementReason !== '' ? $replacementReason : null,
                ]);
            }

            procurementFxAdvanceRecordEvent($conn, $publicId, 'created', $actor, [
                'request_number' => $requestNumber, 'po_number' => $payload['po_number'],
                'currency' => $payload['currency'], 'po_percentage' => $payload['po_percentage'],
                'expected_payment' => $payload['expected_payment'],
                'allocated_after' => procurementLocalAdvancePercentString((int) $allocation['total_units']),
                'replacement_of_id' => $replacementOfId > 0 ? $replacementOfId : null,
            ]);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        jsonResponse([
            'status' => 'Success', 'message' => 'FX Advance Purchase created successfully.',
            'data' => procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $publicId) ?? []),
        ], 201);
    }

    if ($method === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.update');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('A valid FX Advance Purchase ID is required.', 400);
        $payload = procurementFxAdvanceBuildPayload($conn, $data);

        $conn->begin_transaction();
        try {
            $existing = procurementFxAdvanceFetchRecord($conn, $id, true);
            if (!$existing) throw new RuntimeException('FX Advance Purchase not found.', 404);
            if ((string) $existing['approval_status'] !== 'Unapproved') throw new RuntimeException('Approved FX Advance Purchases cannot be edited.', 409);
            if ((string) $existing['payment_status'] !== 'Pending') throw new RuntimeException('Only pending FX Advance Purchases can be edited.', 409);
            if (array_key_exists('version', $data) && (int) $data['version'] !== (int) $existing['version']) {
                throw new RuntimeException('This record was updated by another user. Refresh and try again.', 409);
            }
            $actorId = (int) $actor['id'];
            $currentPoId = (int) $existing['po_id'];
            $currentPo = procurementFxAdvanceFetchPo($conn, $currentPoId, true);
            if (!$currentPo) throw new RuntimeException('The linked FX PO could not be found.', 409);

            $targetPoId = $currentPoId;
            $targetRevisionId = (int) ($existing['po_revision_id'] ?? 0);
            $targetRevisionNumber = (int) ($existing['po_revision_number'] ?? 1);
            $targetSnapshot = (string) ($existing['po_snapshot_json'] ?? '');
            if ((string) $currentPo['po_number_normalized'] === $payload['po_number_normalized']) {
                if ((string) $currentPo['po_status'] !== $payload['po_status']) {
                    throw new RuntimeException('Use the PO status action to change PO Status.', 409);
                }
                if (procurementFxAdvanceCountOtherActiveRequests($conn, $currentPoId, $id) > 0) {
                    procurementFxAdvanceAssertExistingPoCompatible($currentPo, $payload);
                } else {
                    procurementFxAdvanceUpdatePoCommercials($conn, $currentPoId, $payload, $actorId);
                    $refreshedPo = procurementFxAdvanceFetchPo($conn, $currentPoId, true) ?? $currentPo;
                    $targetRevisionId = (int) ($refreshedPo['current_revision_id'] ?? $targetRevisionId);
                    $targetRevisionNumber = (int) ($refreshedPo['current_revision_number'] ?? $targetRevisionNumber);
                    $revision = procurementFxAdvanceFetchPoRevision($conn, $targetRevisionId, true);
                    if ($revision) $targetSnapshot = (string) $revision['commercial_snapshot_json'];
                }
                $allocation = procurementFxAdvanceAssertAllocationAvailable($conn, $currentPoId, (int) $payload['percentage_units'], $id);
            } else {
                $poResult = procurementFxAdvanceCreatePo($conn, $payload, $actorId);
                $targetPoId = (int) $poResult['id'];
                $targetRevisionId = (int) $poResult['revision_id'];
                $targetRevisionNumber = (int) $poResult['revision_number'];
                $targetSnapshot = (string) $poResult['revision_snapshot_json'];
                $targetPo = procurementFxAdvanceFetchPo($conn, $targetPoId, true);
                if (!$targetPo) throw new RuntimeException('The target FX PO could not be initialized.', 500);
                procurementFxAdvanceAssertExistingPoCompatible($targetPo, $payload);
                if (!(bool) $poResult['created']) procurementFxAdvanceAssertPoOpen($targetPo);
                $allocation = procurementFxAdvanceAssertAllocationAvailable($conn, $targetPoId, (int) $payload['percentage_units']);
            }

            $scope = procurementRequestCanonicalFxAdvanceScopeSql();
            $stmt = $conn->prepare(
                "UPDATE procurement_requests SET
                    currency = ?, po_id = ?, po_revision_id = ?, po_revision_number = ?, po_snapshot_json = ?,
                    purchase_number = ?, po_percentage = ?, expected_payment = ?, remark = ?,
                    contact_person = NULLIF(?, ''), phone_number = NULLIF(?, ''),
                    updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
            );
            $params = [
                $payload['currency'], $targetPoId, $targetRevisionId, $targetRevisionNumber, $targetSnapshot,
                $payload['purchase_number'], $payload['po_percentage'], $payload['expected_payment'],
                $payload['remark'], $payload['contact_person'], $payload['phone_number'], $actorId, $id,
            ];
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            procurementFxAdvanceRecordEvent($conn, $id, 'updated', $actor, [
                'currency' => $payload['currency'], 'po_number' => $payload['po_number'],
                'po_percentage' => $payload['po_percentage'],
                'allocated_after' => procurementLocalAdvancePercentString((int) $allocation['total_units']),
            ]);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        jsonResponse([
            'status' => 'Success', 'message' => 'FX Advance Purchase updated successfully.',
            'data' => procurementFxAdvanceSerializeRecord(procurementFxAdvanceFetchRecord($conn, $id) ?? []),
        ]);
    }

    if ($method === 'DELETE') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.delete');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
        $ids = procurementFxAdvanceBatchIds($data['ids'] ?? $data['requestIds'] ?? null);
        $reason = trim((string) ($data['reason'] ?? ''));
        $successful = []; $failed = [];
        foreach ($ids as $id) {
            $conn->begin_transaction();
            try {
                $record = procurementFxAdvanceFetchRecord($conn, $id, true);
                if (!$record) throw new RuntimeException('FX Advance Purchase not found.', 404);
                if ((string) $record['approval_status'] !== 'Unapproved' || !empty($record['fx_fund_request_id'])) {
                    throw new RuntimeException('Approved FX Advance Purchases cannot be deleted.', 409);
                }
                if ((string) $record['payment_status'] !== 'Pending') throw new RuntimeException('Only pending FX Advance Purchases can be deleted.', 409);
                if ((string) ($record['handoff_status'] ?? '') === 'Retrieved') throw new RuntimeException('A record returned by Account must be corrected or handled through approval reversal.', 409);
                $scope = procurementRequestCanonicalFxAdvanceScopeSql();
                $actorId = (int) $actor['id'];
                $stmt = $conn->prepare(
                    "UPDATE procurement_requests SET deleted_by = ?, deleted_at = NOW(), updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
                );
                $stmt->bind_param('iii', $actorId, $actorId, $id);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('FX Advance Purchase could not be deleted.', 409); }
                $stmt->close();
                procurementFxAdvanceRecordEvent($conn, $id, 'deleted', $actor, ['reason' => $reason]);
                $conn->commit(); $successful[] = $id;
            } catch (Throwable $error) {
                $conn->rollback(); $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
        jsonResponse([
            'status' => $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success'),
            'message' => $failed === [] ? 'Selected FX Advance Purchases were deleted.' : 'Batch deletion completed with some exceptions.',
            'data' => ['successful' => $successful, 'failed' => $failed],
        ], $successful === [] && $failed !== [] ? 409 : 200);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    fxAdvancePurchaseErrorResponse($error);
}
