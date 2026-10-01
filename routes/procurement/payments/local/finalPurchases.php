<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementLocalFinalPurchaseService.php';
require_once 'includes/procurementRequestCanonicalReadService.php';

function localFinalPurchaseErrorResponse(Throwable $error): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement Local Final Purchase error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process Local Final Purchase.' : $error->getMessage(),
    ], $status);
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    procurementEnsureAuthenticationTables($conn);
    if ($method === 'GET') {
        procurementAssertLocalFinalPurchaseReadStorage($conn);
    } else {
        procurementEnsureLocalFinalPurchaseStorage($conn);
        procurementNotificationEnsureStorage($conn);
        accountNotificationEnsureStorage($conn);
    }

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'payments.local_final.view');
        $canonicalResponse = procurementRequestCanonicalLocalFinalGetResponse($conn, $_GET);
        if ($canonicalResponse !== null) {
            jsonResponse($canonicalResponse);
        }
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
            $record = procurementLocalFinalFetchRecord($conn, $id);
            if (!$record) {
                throw new RuntimeException('Local Final Purchase not found.', 404);
            }

            $response = [
                'record' => procurementLocalFinalSerializeRecord($record),
                'replacement_links' => procurementPurchaseReplacementLinks(
                    $conn,
                    'local_final_purchase',
                    $id
                ),
            ];
            if (filter_var($_GET['include_events'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $response['events'] = procurementRequestCanonicalLocalFinalEvents($conn, $id);
                $response['handoffs'] = procurementRequestCanonicalLocalFinalHandoffs($conn, $id);
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
            $approvalStatus = procurementLocalFinalValidateStatus(
                $approvalStatus,
                PROCUREMENT_LOCAL_FINAL_APPROVAL_STATUSES,
                'Approval Status'
            );
        }
        if ($paymentStatus !== '') {
            $paymentStatus = procurementLocalFinalValidateStatus(
                $paymentStatus,
                PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES,
                'Payment Status'
            );
        }
        if ($poStatus !== '') {
            $poStatus = procurementLocalFinalValidateStatus(
                $poStatus,
                PROCUREMENT_LOCAL_FINAL_PO_STATUSES,
                'PO Status'
            );
        }
        if ($handoffStatus !== '') {
            $handoffStatus = procurementLocalFinalValidateStatus(
                $handoffStatus,
                PROCUREMENT_LOCAL_FINAL_HANDOFF_STATUSES,
                'Account Handoff Status'
            );
        }
        if ($year !== 0 && ($year < 2000 || $year > 2100)) {
            throw new RuntimeException('Year filter is invalid.', 400);
        }

        $allowedSortFields = [
            'created_at' => 'p.created_at',
            'updated_at' => 'p.updated_at',
            'purchase_date' => 'p.purchase_date',
            'invoice_date' => 'p.invoice_date',
            'purchase_number' => 'p.purchase_number',
            'po_number' => 'p.po_number',
            'supplier_name' => 'p.supplier_name',
            'project_code' => 'p.project_code',
            'purchase_value' => 'p.purchase_value',
            'po_value' => 'p.po_value',
            'approval_status' => 'p.approval_status',
            'payment_status' => 'p.payment_status',
            'po_status' => 'p.po_status',
            'handoff_status' => 'p.handoff_status',
            'approved_at' => 'p.approved_at',
            'retrieved_at' => 'p.retrieved_at',
        ];
        $sortBy = (string) ($_GET['sort_by'] ?? 'created_at');
        $sortColumn = $allowedSortFields[$sortBy] ?? $allowedSortFields['created_at'];
        $sortOrder = strtoupper((string) ($_GET['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $types = '';
        if ($search !== '') {
            $where[] = '(p.purchase_number LIKE ? OR p.po_number LIKE ? OR p.grn_ref LIKE ? OR p.material_type LIKE ? OR p.invoice_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ? OR p.supplier_ledger LIKE ?)';
            $like = '%' . $search . '%';
            for ($index = 0; $index < 9; $index++) {
                $params[] = $like;
            }
            $types .= 'sssssssss';
        }
        if ($approvalStatus !== '') {
            $where[] = 'p.approval_status = ?';
            $params[] = $approvalStatus;
            $types .= 's';
        }
        if ($paymentStatus !== '') {
            $where[] = 'p.payment_status = ?';
            $params[] = $paymentStatus;
            $types .= 's';
        }
        if ($poStatus !== '') {
            $where[] = 'p.po_status = ?';
            $params[] = $poStatus;
            $types .= 's';
        }
        if ($handoffStatus !== '') {
            $where[] = 'p.handoff_status = ?';
            $params[] = $handoffStatus;
            $types .= 's';
        }
        if ($year > 0) {
            $where[] = 'p.purchase_date >= ? AND p.purchase_date < ?';
            $params[] = sprintf('%04d-01-01', $year);
            $params[] = sprintf('%04d-01-01', $year + 1);
            $types .= 'ss';
        }
        if ($projectId > 0) {
            $where[] = 'p.project_id = ?';
            $params[] = $projectId;
            $types .= 'i';
        }
        if ($supplierId > 0) {
            $where[] = 'p.supplier_id = ?';
            $params[] = $supplierId;
            $types .= 'i';
        }
        $whereSql = implode(' AND ', $where);
        $localFinalRelation = procurementRequestCanonicalLocalFinalReadRelation();

        $count = $conn->prepare(
            "SELECT COUNT(*) AS total FROM {$localFinalRelation} p WHERE $whereSql"
        );
        if ($params !== []) {
            $count->bind_param($types, ...$params);
        }
        $count->execute();
        $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
        $count->close();

        // Register projection: keep list reads lean. Full commercial/audit payloads remain on GET ?id=.
        $list = $conn->prepare(
            "SELECT
                    p.id,
                    p.po_number,
                    p.purchase_number,
                    p.grn_ref,
                    p.material_type,
                    p.project_id,
                    p.project_code,
                    p.project_name,
                    p.supplier_id,
                    p.supplier_name,
                    p.supplier_ledger,
                    p.wht_status,
                    p.wht_amount,
                    p.account_wht_override_status,
                    p.account_wht_override_amount,
                    p.invoice_number,
                    p.invoice_date,
                    p.purchase_date,
                    p.purchase_vat_status,
                    p.purchase_value,
                    p.po_value,
                    p.po_status,
                    p.payment_status,
                    p.approval_status,
                    p.handoff_status,
                    p.handoff_revision,
                    p.account_request_type,
                    p.supplier_fund_request_id,
                    p.previous_supplier_fund_request_id,
                    p.approved_by,
                    p.approved_at,
                    p.retrieved_by,
                    p.retrieved_at,
                    p.retrieval_reason,
                    p.retrieval_source,
                    p.version,
                    p.account_processing_method,
                    p.account_processing_reference,
                    p.account_expected_completion_at,
                    p.account_payment_reference,
                    p.account_payment_batch_id,
                    COALESCE(p.account_payable_amount, sfr.amount, cfr.amount, GREATEST(p.purchase_value - p.wht_amount, 0.00)) AS account_payable_amount,
                    COALESCE(sfr.payment_status, cfr.payment_status) AS account_payment_status,
                    COALESCE(sfr.amount_paid, cfr.amount_paid, p.account_amount_paid, 0.00) AS account_amount_paid,
                    CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                    CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
             FROM {$localFinalRelation} p
             LEFT JOIN supplier_fund_request_table sfr
               ON sfr.id = p.supplier_fund_request_id
              AND (p.account_request_type IS NULL OR p.account_request_type = 'supplier_fund_request')
             LEFT JOIN compass_fund_request_table cfr
               ON cfr.id = p.supplier_fund_request_id
              AND p.account_request_type = 'compass_fund_request'
             LEFT JOIN user_table au ON au.id = p.approved_by
             LEFT JOIN user_table rtu ON rtu.id = p.retrieved_by
             WHERE $whereSql
             ORDER BY $sortColumn $sortOrder, p.id DESC
             LIMIT ? OFFSET ?"
        );
        $listParams = array_merge($params, [$limit, $offset]);
        $listTypes = $types . 'ii';
        $list->bind_param($listTypes, ...$listParams);
        $list->execute();
        $rows = $list->get_result()->fetch_all(MYSQLI_ASSOC);
        $list->close();
        $rows = array_map('procurementLocalFinalSerializeRecord', $rows);

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
        $actor = procurementRequirePermission($conn, 'payments.local_final.create');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $payload = procurementLocalFinalBuildPayload($conn, $data);
        procurementLocalFinalAssertPurchaseNumberAvailable($conn, $payload['purchase_number_normalized']);

        $conn->begin_transaction();
        try {
            $actorId = (int) $actor['id'];
            $id = procurementRequestCanonicalNextLegacyId(
                $conn,
                PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL
            );
            $requestType = PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE;
            $sourceTable = PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_SOURCE;
            $requestNumber = $payload['purchase_number'];
            $stmt = $conn->prepare(
                "INSERT INTO procurement_requests
                    (request_type, request_number, legacy_source_table, legacy_source_id,
                     po_number, purchase_number, purchase_number_normalized, grn_ref, material_type,
                     project_id, project_code, project_name, supplier_id, supplier_name,
                     supplier_ledger, wht_status, wht_rate, wht_amount, invoice_number,
                     invoice_date, purchase_date, transaction_date, purchase_subtotal, purchase_discount,
                     purchase_other_charges, purchase_vat_status, purchase_vat_rate,
                     purchase_vat_amount, purchase_value, po_subtotal, po_discount,
                     po_other_charges, po_vat_status, po_vat_rate, po_vat_amount, po_value,
                     remark, po_status, payment_status, payment_status_source, payment_status_updated_at,
                     approval_status, handoff_status, handoff_revision, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         'Pending', 'procuredesk', NOW(), 'Unapproved', 'Not Sent', 0, ?, ?)"
            );
            $params = [
                $requestType, $requestNumber, $sourceTable, $id,
                $payload['po_number'], $payload['purchase_number'], $payload['purchase_number_normalized'], $payload['grn_ref'], $payload['material_type'],
                $payload['project_id'], $payload['project_code'], $payload['project_name'], $payload['supplier_id'], $payload['supplier_name'],
                $payload['supplier_ledger'], $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'], $payload['invoice_number'],
                $payload['invoice_date'], $payload['purchase_date'], $payload['purchase_date'], $payload['purchase_subtotal'], $payload['purchase_discount'],
                $payload['purchase_other_charges'], $payload['purchase_vat_status'], $payload['purchase_vat_rate'],
                $payload['purchase_vat_amount'], $payload['purchase_value'], $payload['po_subtotal'], $payload['po_discount'],
                $payload['po_other_charges'], $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'], $payload['po_value'],
                $payload['remark'], $payload['po_status'], $actorId, $actorId,
            ];
            $types = 'sssisssssississsssssssssssssssssssssssii';
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            $replacementOfId = (int) ($data['replacement_of_id'] ?? 0);
            $replacementReason = trim((string) ($data['replacement_reason'] ?? ''));
            if ($replacementOfId > 0) {
                $source = procurementLocalFinalFetchRecord($conn, $replacementOfId, true);
                if (!$source || (string) ($source['po_status'] ?? '') !== 'Cancelled') {
                    throw new RuntimeException('A replacement can only be linked to a cancelled Local Final Purchase.', 409);
                }
                procurementPurchaseReplacementCreate(
                    $conn,
                    'local_final_purchase',
                    $replacementOfId,
                    'local_final_purchase',
                    $id,
                    $actorId,
                    $replacementReason
                );
                procurementLocalFinalRecordEvent($conn, $replacementOfId, 'replacement_created', $actor, [
                    'replacement_purchase_id' => $id,
                    'reason' => $replacementReason !== '' ? $replacementReason : null,
                ]);
            }

            procurementLocalFinalRecordEvent($conn, $id, 'created', $actor, [
                'purchase_number' => $payload['purchase_number'],
                'material_type' => $payload['material_type'],
                'purchase_value' => $payload['purchase_value'],
                'po_value' => $payload['po_value'],
                'replacement_of_id' => $replacementOfId > 0 ? $replacementOfId : null,
            ]);
            procurementRequestCanonicalSyncRequest(
                $conn,
                PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
                $id
            );
            $conn->commit();
        } catch (mysqli_sql_exception $error) {
            $conn->rollback();
            if ((int) $error->getCode() === 1062) {
                throw new RuntimeException('Purchase Number already exists.', 409);
            }
            throw $error;
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        jsonResponse([
            'status' => 'Success',
            'message' => 'Local Final Purchase created successfully.',
            'data' => procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []),
        ], 201);
    }

    if ($method === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequireAnyPermission($conn, [
            'payments.local_final.update',
            'payments.local_final.amend_paid_purchase',
        ]);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('A valid Local Final Purchase ID is required.', 400);
        }
        $payload = procurementLocalFinalBuildPayload($conn, $data);

        $current = procurementLocalFinalFetchRecord($conn, $id);
        if (!$current) {
            throw new RuntimeException('Local Final Purchase not found.', 404);
        }
        $isPaidRevision = (string) ($current['approval_status'] ?? '') === 'Approved'
            && (string) ($current['payment_status'] ?? '') === 'Paid';
        if ($isPaidRevision) {
            $record = procurementLocalFinalApplyPaidRevision(
                $conn,
                $id,
                $payload,
                $actor,
                trim((string) ($data['revision_reason'] ?? $data['reason'] ?? '')),
                array_key_exists('version', $data) ? (int) $data['version'] : null
            );
            jsonResponse([
                'status' => 'Success',
                'message' => 'Paid Local Final Purchase revised successfully.',
                'data' => $record,
            ]);
        }
        if (!procurementLocalFinalActorHasPermission($actor, 'payments.local_final.update')) {
            throw new RuntimeException('You are not permitted to update Local Final Purchases.', 403);
        }

        $conn->begin_transaction();
        try {
            $existing = procurementLocalFinalFetchRecord($conn, $id, true);
            if (!$existing) {
                throw new RuntimeException('Local Final Purchase not found.', 404);
            }
            if ((string) $existing['approval_status'] !== 'Unapproved') {
                throw new RuntimeException('Approved Local Final Purchases are closed and cannot be edited.', 409);
            }
            if ((string) $existing['payment_status'] !== 'Pending') {
                throw new RuntimeException('Only pending Local Final Purchases can be edited.', 409);
            }
            if ((string) ($existing['handoff_status'] ?? '') === 'Retrieved'
                && (string) $actor['role'] !== 'super_admin'
                && !in_array('payments.local_final.edit_retrieved', $actor['permissions'], true)) {
                throw new RuntimeException('You are not permitted to edit purchases returned by Account.', 403);
            }
            if (array_key_exists('version', $data) && (int) $data['version'] !== (int) $existing['version']) {
                throw new RuntimeException('This record was updated by another user. Refresh and try again.', 409);
            }
            procurementLocalFinalAssertPurchaseNumberAvailable($conn, $payload['purchase_number_normalized'], $id);

            $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
            $stmt = $conn->prepare(
                "UPDATE procurement_requests SET
                    po_number = ?, purchase_number = ?, purchase_number_normalized = ?, grn_ref = ?, material_type = ?,
                    project_id = ?, project_code = ?, project_name = ?, supplier_id = ?, supplier_name = ?,
                    supplier_ledger = ?, wht_status = ?, wht_rate = ?, wht_amount = ?, invoice_number = ?,
                    invoice_date = ?, purchase_date = ?, purchase_subtotal = ?, purchase_discount = ?,
                    purchase_other_charges = ?, purchase_vat_status = ?, purchase_vat_rate = ?,
                    purchase_vat_amount = ?, purchase_value = ?, po_subtotal = ?, po_discount = ?,
                    po_other_charges = ?, po_vat_status = ?, po_vat_rate = ?, po_vat_amount = ?, po_value = ?,
                    remark = ?, po_status = ?,
                    account_wht_override_status = NULL, account_wht_override_rate = NULL,
                    account_wht_override_amount = NULL, account_payable_amount = NULL,
                    account_wht_adjustment_reason = NULL, account_wht_adjusted_by = NULL,
                    account_wht_adjusted_at = NULL,
                    updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$canonicalScope}"
            );
            $actorId = (int) $actor['id'];
            $params = [
                $payload['po_number'], $payload['purchase_number'], $payload['purchase_number_normalized'], $payload['grn_ref'], $payload['material_type'],
                $payload['project_id'], $payload['project_code'], $payload['project_name'], $payload['supplier_id'], $payload['supplier_name'],
                $payload['supplier_ledger'], $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'], $payload['invoice_number'],
                $payload['invoice_date'], $payload['purchase_date'], $payload['purchase_subtotal'], $payload['purchase_discount'],
                $payload['purchase_other_charges'], $payload['purchase_vat_status'], $payload['purchase_vat_rate'],
                $payload['purchase_vat_amount'], $payload['purchase_value'], $payload['po_subtotal'], $payload['po_discount'],
                $payload['po_other_charges'], $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'], $payload['po_value'],
                $payload['remark'], $payload['po_status'], $actorId, $id,
            ];
            $types = 'sssssississssssssssssssssssssssssii';
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            procurementLocalFinalRecordEvent($conn, $id, 'updated', $actor, [
                'previous_version' => (int) $existing['version'],
                'purchase_number' => $payload['purchase_number'],
                'material_type' => $payload['material_type'],
            ]);
            procurementRequestCanonicalSyncRequest(
                $conn,
                PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
                $id
            );
            $conn->commit();
        } catch (mysqli_sql_exception $error) {
            $conn->rollback();
            if ((int) $error->getCode() === 1062) {
                throw new RuntimeException('Purchase Number already exists.', 409);
            }
            throw $error;
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        jsonResponse([
            'status' => 'Success',
            'message' => 'Local Final Purchase updated successfully.',
            'data' => procurementLocalFinalSerializeRecord(procurementLocalFinalFetchRecord($conn, $id) ?? []),
        ]);
    }

    if ($method === 'DELETE') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.local_final.delete');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $ids = procurementLocalFinalBatchIds($data['ids'] ?? $data['requestIds'] ?? null);
        $reason = trim((string) ($data['reason'] ?? ''));
        $successful = [];
        $failed = [];

        foreach ($ids as $id) {
            $conn->begin_transaction();
            try {
                $record = procurementLocalFinalFetchRecord($conn, $id, true);
                if (!$record) {
                    throw new RuntimeException('Local Final Purchase not found.', 404);
                }
                if ((string) $record['approval_status'] !== 'Unapproved' || !empty($record['supplier_fund_request_id'])) {
                    throw new RuntimeException('Approved Local Final Purchases cannot be deleted.', 409);
                }
                if ((string) $record['payment_status'] !== 'Pending') {
                    throw new RuntimeException('Only pending Local Final Purchases can be deleted.', 409);
                }

                $actorId = (int) $actor['id'];
                $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
                $delete = $conn->prepare(
                    "UPDATE procurement_requests
                     SET deleted_by = ?, deleted_at = NOW(), updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE legacy_source_id = ? AND {$canonicalScope} AND deleted_at IS NULL"
                );
                $delete->bind_param('iii', $actorId, $actorId, $id);
                $delete->execute();
                if ($delete->affected_rows !== 1) {
                    $delete->close();
                    throw new RuntimeException('Local Final Purchase could not be deleted.', 409);
                }
                $delete->close();
                procurementLocalFinalRecordEvent($conn, $id, 'deleted', $actor, ['reason' => $reason]);
                procurementRequestCanonicalSyncRequest(
                    $conn,
                    PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
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
            'message' => $failed === [] ? 'Selected Local Final Purchases were deleted.' : 'Batch deletion completed with some exceptions.',
            'data' => ['successful' => $successful, 'failed' => $failed],
        ], $successful === [] && $failed !== [] ? 409 : 200);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    localFinalPurchaseErrorResponse($error);
}
