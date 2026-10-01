<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxFinalPurchaseService.php';

function fxFinalPurchaseErrorResponse(Throwable $error): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement FX Final Purchase error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process FX Final Purchase.' : $error->getMessage(),
    ], $status);
}

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxFinalPurchaseStorage($conn);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'payments.fx_final.view');
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
            $record = procurementFxFinalFetchRecord($conn, $id);
            if (!$record) {
                throw new RuntimeException('FX Final Purchase not found.', 404);
            }
            $response = [
                'record' => procurementFxFinalSerializeRecord($record),
                'replacement_links' => procurementPurchaseReplacementLinks(
                    $conn,
                    'fx_final_purchase',
                    $id
                ),
            ];
            if (filter_var($_GET['include_events'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $response['events'] = procurementFxFinalEvents($conn, $id);
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
            $approvalStatus = procurementLocalFinalValidateStatus($approvalStatus, PROCUREMENT_LOCAL_FINAL_APPROVAL_STATUSES, 'Approval Status');
        }
        if ($paymentStatus !== '') {
            $paymentStatus = procurementLocalFinalValidateStatus($paymentStatus, PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES, 'Payment Status');
        }
        if ($poStatus !== '') {
            $poStatus = procurementLocalFinalValidateStatus($poStatus, PROCUREMENT_LOCAL_FINAL_PO_STATUSES, 'PO Status');
        }
        if ($handoffStatus !== '') {
            $handoffStatus = procurementLocalFinalValidateStatus($handoffStatus, PROCUREMENT_LOCAL_FINAL_HANDOFF_STATUSES, 'Account Handoff Status');
        }
        if ($currency !== '') {
            $currency = procurementFxFinalNormalizeCurrency($currency);
        }
        if ($year !== 0 && ($year < 2000 || $year > 2100)) {
            throw new RuntimeException('Year filter is invalid.', 400);
        }

        $allowedSortFields = [
            'created_at' => 'p.created_at', 'updated_at' => 'p.updated_at',
            'purchase_date' => 'p.purchase_date', 'invoice_date' => 'p.invoice_date',
            'purchase_number' => 'p.purchase_number', 'po_number' => 'p.po_number',
            'supplier_name' => 'p.supplier_name', 'project_code' => 'p.project_code',
            'currency' => 'p.currency', 'purchase_value' => 'p.purchase_value',
            'po_value' => 'p.po_value', 'approval_status' => 'p.approval_status',
            'payment_status' => 'p.payment_status', 'po_status' => 'p.po_status',
            'handoff_status' => 'p.handoff_status',
        ];
        $sortBy = (string) ($_GET['sort_by'] ?? 'created_at');
        $sortColumn = $allowedSortFields[$sortBy] ?? $allowedSortFields['created_at'];
        $sortOrder = strtoupper((string) ($_GET['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $types = '';
        if ($search !== '') {
            $where[] = '(p.purchase_number LIKE ? OR p.po_number LIKE ? OR p.grn_ref LIKE ? OR p.material_type LIKE ? OR p.invoice_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ? OR p.supplier_ledger LIKE ? OR p.contact_person LIKE ? OR p.phone_number LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 11; $i++) $params[] = $like;
            $types .= 'sssssssssss';
        }
        foreach ([
            ['value' => $approvalStatus, 'sql' => 'p.approval_status = ?'],
            ['value' => $paymentStatus, 'sql' => 'p.payment_status = ?'],
            ['value' => $poStatus, 'sql' => 'p.po_status = ?'],
            ['value' => $handoffStatus, 'sql' => 'p.handoff_status = ?'],
            ['value' => $currency, 'sql' => 'p.currency = ?'],
        ] as $filter) {
            if ($filter['value'] !== '') {
                $where[] = $filter['sql'];
                $params[] = $filter['value'];
                $types .= 's';
            }
        }
        if ($year > 0) { $where[] = 'p.purchase_date >= ? AND p.purchase_date < ?'; $params[] = sprintf('%04d-01-01', $year); $params[] = sprintf('%04d-01-01', $year + 1); $types .= 'ss'; }
        if ($projectId > 0) { $where[] = 'p.project_id = ?'; $params[] = $projectId; $types .= 'i'; }
        if ($supplierId > 0) { $where[] = 'p.supplier_id = ?'; $params[] = $supplierId; $types .= 'i'; }

        $whereSql = implode(' AND ', $where);
        $relation = procurementRequestCanonicalFxFinalReadRelation();
        $count = $conn->prepare("SELECT COUNT(*) AS total FROM {$relation} p WHERE {$whereSql}");
        if ($params !== []) $count->bind_param($types, ...$params);
        $count->execute();
        $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
        $count->close();

        // Register projection: omit commercial detail fields that the table never renders.
        $list = $conn->prepare(
            "SELECT
                    p.id,
                    p.currency,
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
                    p.fx_fund_request_id,
                    p.previous_fx_fund_request_id,
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
                    ffr.payment_status AS account_payment_status,
                    COALESCE(ffr.payable_amount, p.account_payable_amount, GREATEST(p.purchase_value - p.wht_amount, 0.00)) AS account_payable_amount,
                    COALESCE(ffr.payment_amount, p.account_amount_paid, 0.00) AS account_amount_paid,
                    ffr.payment_currency AS account_payment_currency,
                    ffr.exchange_rate AS account_exchange_rate,
                    ffr.fx_instruction_letter_id,
                    CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                    CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
             FROM {$relation} p
             LEFT JOIN fx_fund_request_table ffr ON ffr.id = p.fx_fund_request_id
             LEFT JOIN user_table au ON au.id = p.approved_by
             LEFT JOIN user_table rtu ON rtu.id = p.retrieved_by
             WHERE {$whereSql}
             ORDER BY {$sortColumn} {$sortOrder}, p.id DESC
             LIMIT ? OFFSET ?"
        );
        $listParams = array_merge($params, [$limit, $offset]);
        $listTypes = $types . 'ii';
        $list->bind_param($listTypes, ...$listParams);
        $list->execute();
        $rows = array_map('procurementFxFinalSerializeRecord', $list->get_result()->fetch_all(MYSQLI_ASSOC));
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
        $actor = procurementRequirePermission($conn, 'payments.fx_final.create');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
        $payload = procurementFxFinalBuildPayload($conn, $data);
        procurementFxFinalAssertPurchaseNumberAvailable($conn, $payload['purchase_number_normalized']);

        $conn->begin_transaction();
        try {
            $publicId = procurementFxFinalNextPublicId($conn);
            $actorId = (int) $actor['id'];
            $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE;
            $source = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE;
            $requestNumber = $payload['purchase_number'];
            $stmt = $conn->prepare(
                "INSERT INTO procurement_requests
                    (request_type, request_number, legacy_source_table, legacy_source_id, currency,
                     po_number, purchase_number, purchase_number_normalized, grn_ref, material_type,
                     project_id, project_code, project_name, supplier_id, supplier_name, supplier_ledger,
                     contact_person, phone_number, wht_status, wht_rate, wht_amount, invoice_number, invoice_date, purchase_date,
                     transaction_date, purchase_subtotal, purchase_discount, purchase_other_charges,
                     purchase_vat_status, purchase_vat_rate, purchase_vat_amount, purchase_value,
                     po_subtotal, po_discount, po_other_charges, po_vat_status, po_vat_rate,
                     po_vat_amount, po_value, remark, po_status, payment_status, payment_status_source,
                     payment_status_updated_at, approval_status, handoff_status, handoff_revision,
                     created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         'Pending', 'procuredesk', NOW(), 'Unapproved', 'Not Sent', 0, ?, ?)"
            );
            $params = [
                $requestType, $requestNumber, $source, $publicId, $payload['currency'],
                $payload['po_number'], $payload['purchase_number'], $payload['purchase_number_normalized'], $payload['grn_ref'], $payload['material_type'],
                $payload['project_id'], $payload['project_code'], $payload['project_name'], $payload['supplier_id'], $payload['supplier_name'], $payload['supplier_ledger'],
                $payload['contact_person'], $payload['phone_number'], $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'], $payload['invoice_number'], $payload['invoice_date'], $payload['purchase_date'],
                $payload['purchase_date'], $payload['purchase_subtotal'], $payload['purchase_discount'], $payload['purchase_other_charges'],
                $payload['purchase_vat_status'], $payload['purchase_vat_rate'], $payload['purchase_vat_amount'], $payload['purchase_value'],
                $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'], $payload['po_vat_status'], $payload['po_vat_rate'],
                $payload['po_vat_amount'], $payload['po_value'], $payload['remark'], $payload['po_status'], $actorId, $actorId,
            ];
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            $replacementOfId = (int) ($data['replacement_of_id'] ?? 0);
            $replacementReason = trim((string) ($data['replacement_reason'] ?? ''));
            if ($replacementOfId > 0) {
                $sourceRecord = procurementFxFinalFetchRecord($conn, $replacementOfId, true);
                if (!$sourceRecord || (string) ($sourceRecord['po_status'] ?? '') !== 'Cancelled') {
                    throw new RuntimeException('A replacement can only be linked to a cancelled FX Final Purchase.', 409);
                }
                procurementPurchaseReplacementCreate(
                    $conn,
                    'fx_final_purchase',
                    $replacementOfId,
                    'fx_final_purchase',
                    $publicId,
                    $actorId,
                    $replacementReason
                );
                procurementFxFinalRecordEvent($conn, $replacementOfId, 'replacement_created', $actor, [
                    'replacement_purchase_id' => $publicId,
                    'reason' => $replacementReason !== '' ? $replacementReason : null,
                ]);
            }

            procurementFxFinalRecordEvent($conn, $publicId, 'created', $actor, [
                'purchase_number' => $payload['purchase_number'], 'currency' => $payload['currency'],
                'purchase_value' => $payload['purchase_value'], 'po_value' => $payload['po_value'],
                'replacement_of_id' => $replacementOfId > 0 ? $replacementOfId : null,
            ]);
            $conn->commit();
        } catch (mysqli_sql_exception $error) {
            $conn->rollback();
            if ((int) $error->getCode() === 1062) throw new RuntimeException('Purchase Number already exists for an FX Final Purchase.', 409);
            throw $error;
        } catch (Throwable $error) {
            $conn->rollback(); throw $error;
        }
        jsonResponse([
            'status' => 'Success', 'message' => 'FX Final Purchase created successfully.',
            'data' => procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $publicId) ?? []),
        ], 201);
    }

    if ($method === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.fx_final.update');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('A valid FX Final Purchase ID is required.', 400);
        $payload = procurementFxFinalBuildPayload($conn, $data);

        $conn->begin_transaction();
        try {
            $existing = procurementFxFinalFetchRecord($conn, $id, true);
            if (!$existing) throw new RuntimeException('FX Final Purchase not found.', 404);
            if ((string) $existing['approval_status'] !== 'Unapproved') throw new RuntimeException('Approved FX Final Purchases cannot be edited.', 409);
            if ((string) $existing['payment_status'] !== 'Pending') throw new RuntimeException('Only pending FX Final Purchases can be edited.', 409);
            if (array_key_exists('version', $data) && (int) $data['version'] !== (int) $existing['version']) {
                throw new RuntimeException('This record was updated by another user. Refresh and try again.', 409);
            }
            procurementFxFinalAssertPurchaseNumberAvailable($conn, $payload['purchase_number_normalized'], $id);
            $scope = procurementRequestCanonicalFxFinalScopeSql();
            $stmt = $conn->prepare(
                "UPDATE procurement_requests SET
                    currency = ?, po_number = ?, purchase_number = ?, purchase_number_normalized = ?, grn_ref = ?, material_type = ?,
                    project_id = ?, project_code = ?, project_name = ?, supplier_id = ?, supplier_name = ?, supplier_ledger = ?,
                    contact_person = NULLIF(?, ''), phone_number = NULLIF(?, ''),
                    wht_status = ?, wht_rate = ?, wht_amount = ?, invoice_number = ?, invoice_date = ?, purchase_date = ?,
                    transaction_date = ?, purchase_subtotal = ?, purchase_discount = ?, purchase_other_charges = ?,
                    purchase_vat_status = ?, purchase_vat_rate = ?, purchase_vat_amount = ?, purchase_value = ?,
                    po_subtotal = ?, po_discount = ?, po_other_charges = ?, po_vat_status = ?, po_vat_rate = ?, po_vat_amount = ?, po_value = ?,
                    remark = ?, po_status = ?, updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
            );
            $actorId = (int) $actor['id'];
            $params = [
                $payload['currency'], $payload['po_number'], $payload['purchase_number'], $payload['purchase_number_normalized'], $payload['grn_ref'], $payload['material_type'],
                $payload['project_id'], $payload['project_code'], $payload['project_name'], $payload['supplier_id'], $payload['supplier_name'], $payload['supplier_ledger'],
                $payload['contact_person'], $payload['phone_number'], $payload['wht_status'], $payload['wht_rate'], $payload['wht_amount'], $payload['invoice_number'], $payload['invoice_date'], $payload['purchase_date'],
                $payload['purchase_date'], $payload['purchase_subtotal'], $payload['purchase_discount'], $payload['purchase_other_charges'],
                $payload['purchase_vat_status'], $payload['purchase_vat_rate'], $payload['purchase_vat_amount'], $payload['purchase_value'],
                $payload['po_subtotal'], $payload['po_discount'], $payload['po_other_charges'], $payload['po_vat_status'], $payload['po_vat_rate'], $payload['po_vat_amount'], $payload['po_value'],
                $payload['remark'], $payload['po_status'], $actorId, $id,
            ];
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            procurementFxFinalRecordEvent($conn, $id, 'updated', $actor, [
                'previous_version' => (int) $existing['version'], 'currency' => $payload['currency'],
            ]);
            $conn->commit();
        } catch (mysqli_sql_exception $error) {
            $conn->rollback();
            if ((int) $error->getCode() === 1062) throw new RuntimeException('Purchase Number already exists for an FX Final Purchase.', 409);
            throw $error;
        } catch (Throwable $error) { $conn->rollback(); throw $error; }

        jsonResponse([
            'status' => 'Success', 'message' => 'FX Final Purchase updated successfully.',
            'data' => procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $id) ?? []),
        ]);
    }

    if ($method === 'DELETE') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.fx_final.delete');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
        $ids = procurementFxFinalBatchIds($data['ids'] ?? $data['requestIds'] ?? null);
        $reason = trim((string) ($data['reason'] ?? ''));
        $successful = []; $failed = [];
        foreach ($ids as $id) {
            $conn->begin_transaction();
            try {
                $record = procurementFxFinalFetchRecord($conn, $id, true);
                if (!$record) throw new RuntimeException('FX Final Purchase not found.', 404);
                if ((string) $record['approval_status'] !== 'Unapproved' || !empty($record['fx_fund_request_id'])) {
                    throw new RuntimeException('Approved FX Final Purchases cannot be deleted.', 409);
                }
                if ((string) $record['payment_status'] !== 'Pending') throw new RuntimeException('Only pending FX Final Purchases can be deleted.', 409);
                $actorId = (int) $actor['id'];
                $scope = procurementRequestCanonicalFxFinalScopeSql();
                $stmt = $conn->prepare(
                    "UPDATE procurement_requests SET deleted_by = ?, deleted_at = NOW(), updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
                );
                $stmt->bind_param('iii', $actorId, $actorId, $id);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('FX Final Purchase could not be deleted.', 409); }
                $stmt->close();
                procurementFxFinalRecordEvent($conn, $id, 'deleted', $actor, ['reason' => $reason]);
                $conn->commit(); $successful[] = $id;
            } catch (Throwable $error) { $conn->rollback(); $failed[] = ['id' => $id, 'reason' => $error->getMessage()]; }
        }
        jsonResponse([
            'status' => $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success'),
            'message' => $failed === [] ? 'Selected FX Final Purchases were deleted.' : 'Batch deletion completed with some exceptions.',
            'data' => ['successful' => $successful, 'failed' => $failed],
        ], $successful === [] && $failed !== [] ? 409 : 200);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    fxFinalPurchaseErrorResponse($error);
}
