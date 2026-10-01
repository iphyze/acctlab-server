<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalFinalPurchaseService.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can update payment requests", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format", 400);
    }

    $requiredFields = ['requestId', 'suppliers_name', 'supplier_id', 'invoice_number', 'purchase_number',
    'po_number', 'invoice_date', 'purchase_date', 'date_received', 'project_code', 'description', 'amount', 'vat_policy', 'discount', 
    'other_charges', 'payment_status'];



    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || ($data[$field] === '' && $data[$field] !== 0 && $data[$field] !== '0.00%')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }
    
    
    $requestId = intval($data['requestId']);
    if (!$requestId) throw new Exception("Invalid Request ID provided.", 400);

    // Clean and assign values
    $suppliers_name = trim($data['suppliers_name']);
    $supplier_id = trim($data['supplier_id']);
    $invoice_number = trim($data['invoice_number']);
    $purchase_number = trim($data['purchase_number']);
    $po_number = trim($data['po_number']);
    $invoice_date = trim($data['invoice_date']);
    $purchase_date = trim($data['purchase_date']);
    $invoice_month = date('M-Y', strtotime($invoice_date));    
    $purchase_month = date('M-Y', strtotime($purchase_date));
    $date_received = trim($data['date_received']);
    $project_code = trim($data['project_code']);
    $description = trim($data['description']);
    $amount = isset($data['amount']) ? number_format(round((float) $data['amount'], 2), 2, '.', '') : '0.00';
    $discount = isset($data['discount']) ? number_format(round((float) $data['discount'], 2), 2, '.', '') : '0.00';
    $other_charges = isset($data['other_charges']) ? number_format(round((float) $data['other_charges'], 2), 2, '.', '') : '0.00';
    $vat_policy = trim($data['vat_policy']) ?: "0.00%";
    $payment_status = isset($data['payment_status']) ? trim($data['payment_status']) : '';
    $note = isset($data['note']) ? trim($data['note']) : '';

    $validPaymentStatuses = ['Pending', 'Processing', 'Paid', 'Failed', 'Cancelled', 'Unconfirmed'];
    if (!in_array($payment_status, $validPaymentStatuses, true)) {
        throw new Exception('Invalid payment status provided.', 400);
    }

    // VAT Calculation
    $net_amount = round($amount - $discount, 2);

    switch ($vat_policy) {
        case "0.00%":
            $vat = 0.00;
            $wht = 0.00;
            $amount_payable = $net_amount;
            break;
        case "7.50%":
            $vat = round($net_amount * 0.075, 2);
            $wht = 0.00;
            $amount_payable = round($net_amount + $vat, 2);
            break;
        case "2.00%":
            $vat = round($net_amount * 0.075, 2);
            $wht = round($net_amount * 0.020, 2);
            $amount_payable = round($net_amount * 1.055, 2);
            break;
        case "5.00%":
            $vat = round($net_amount * 0.075, 2);
            $wht = round($net_amount * 0.050, 2);
            $amount_payable = round($net_amount * 1.025, 2);
            break;
        default:
            throw new Exception("Invalid VAT status.", 400);
    }

    $total_amount_payament = round($amount_payable + $other_charges, 2);

    // Check if the entry exists and preserve its current handoff values.
    $check = $conn->prepare("SELECT * FROM supplier_fund_request_table WHERE id = ?");
    $check->bind_param("i", $requestId);
    $check->execute();
    $currentRequest = $check->get_result()->fetch_assoc();
    if (!$currentRequest) {
        throw new Exception("Supplier payment request with ID $requestId not found", 404);
    }
    $check->close();

    $procurementConflict = procurementLocalFinalManualSupplierRequestConflict($conn, $purchase_number);
    if ($procurementConflict !== null
        && (int) ($procurementConflict['supplier_fund_request_id'] ?? 0) !== $requestId) {
        throw new Exception(
            "Purchase No.: $purchase_number belongs to a different ProcureDesk Local Final Purchase.",
            409
        );
    }

    $isLinkedProcurementRequest = false;
    procurementRequestCanonicalLocalFinalAssertReady($conn);
    $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql();
    $linkedStmt = $conn->prepare(
        "SELECT legacy_source_id FROM procurement_requests
         WHERE account_request_id = ? AND approval_status = 'Approved' AND deleted_at IS NULL
           AND {$localFinalScope}
         LIMIT 1"
    );
    $linkedStmt->bind_param('i', $requestId);
    $linkedStmt->execute();
    $isLinkedProcurementRequest = $linkedStmt->get_result()->num_rows > 0;
    $linkedStmt->close();

    if ($isLinkedProcurementRequest) {
        accountSupplierEnsurePaymentStorage($conn);

        $textFields = [
            'suppliers_name' => $suppliers_name,
            'supplier_id' => (string) $supplier_id,
            'invoice_number' => $invoice_number,
            'purchase_number' => $purchase_number,
            'po_number' => $po_number,
            'invoice_date' => $invoice_date,
            'purchase_date' => $purchase_date,
            'date_received' => $date_received,
            'project_code' => $project_code,
            'description' => $description,
            'vat_policy' => $vat_policy,
        ];
        $moneyFields = [
            'net_value' => $amount,
            'discount' => $discount,
            'other_charges' => $other_charges,
        ];
        $commercialChanged = false;
        foreach ($textFields as $field => $submittedValue) {
            if (trim((string) ($currentRequest[$field] ?? '')) !== trim((string) $submittedValue)) {
                $commercialChanged = true;
                break;
            }
        }
        if (!$commercialChanged) {
            foreach ($moneyFields as $field => $submittedValue) {
                if (abs((float) ($currentRequest[$field] ?? 0) - (float) $submittedValue) > 0.009) {
                    $commercialChanged = true;
                    break;
                }
            }
        }

        if ($commercialChanged && (string) $currentRequest['payment_status'] !== 'Pending') {
            throw new Exception(
                'Commercial details can only be updated before Account starts processing the payment.',
                409
            );
        }

        if ($commercialChanged) {
            [, $normalizedPurchaseNumber] = procurementLocalFinalNormalizePurchaseNumber($purchase_number);
            $duplicateLinked = $conn->prepare(
                "SELECT id FROM supplier_fund_request_table
                 WHERE id <> ? AND UPPER(REPLACE(TRIM(purchase_number), ' ', '')) = ? LIMIT 1"
            );
            $duplicateLinked->bind_param('is', $requestId, $normalizedPurchaseNumber);
            $duplicateLinked->execute();
            $hasDuplicateLinked = $duplicateLinked->get_result()->num_rows > 0;
            $duplicateLinked->close();
            if ($hasDuplicateLinked) {
                throw new Exception("Duplicate request. Purchase No.: $purchase_number already exists!", 409);
            }
        }

        $actor = ['id' => (int) $loggedInUserId, 'email' => (string) $userEmail];
        $conn->begin_transaction();
        try {
            if ($commercialChanged) {
                $updateLinked = $conn->prepare(
                    "UPDATE supplier_fund_request_table SET
                        suppliers_name = ?, supplier_id = ?, invoice_number = ?, purchase_number = ?, po_number = ?,
                        invoice_date = ?, purchase_date = ?, date_received = ?, invoice_month = ?, purchase_month = ?,
                        project_code = ?, description = ?, vat_policy = ?, net_value = ?, discount = ?,
                        other_charges = ?, amount = ?, note = ?, vat = ?, wht = ?, account_remarks = ?,
                        payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Pending'"
                );
                $accountRemarks = trim((string) ($data['account_remarks'] ?? $note));
                $updateLinked->bind_param(
                    'sisssssssssssddddsddsii',
                    $suppliers_name,
                    $supplier_id,
                    $invoice_number,
                    $purchase_number,
                    $po_number,
                    $invoice_date,
                    $purchase_date,
                    $date_received,
                    $invoice_month,
                    $purchase_month,
                    $project_code,
                    $description,
                    $vat_policy,
                    $amount,
                    $discount,
                    $other_charges,
                    $total_amount_payament,
                    $note,
                    $vat,
                    $wht,
                    $accountRemarks,
                    $loggedInUserId,
                    $requestId
                );
                $updateLinked->execute();
                if ($updateLinked->affected_rows !== 1) {
                    throw new Exception('The payment request changed before the update could be saved.', 409);
                }
                $updateLinked->close();

                procurementSyncLocalFinalPurchaseFromSupplierRequest($conn, $requestId, $actor);
                accountSupplierRecordEvent(
                    $conn,
                    $requestId,
                    isset($currentRequest['payment_batch_id']) ? (int) $currentRequest['payment_batch_id'] : null,
                    'sent_purchase_updated',
                    $actor,
                    [
                        'purchase_number' => $purchase_number,
                        'supplier_name' => $suppliers_name,
                        'project_code' => $project_code,
                        'description' => $description,
                        'account_payable_amount' => number_format((float) $total_amount_payament, 2, '.', ''),
                    ]
                );
            } else {
                $accountRemarks = trim((string) ($data['account_remarks'] ?? $note));
                $remarksUpdate = $conn->prepare(
                    'UPDATE supplier_fund_request_table
                     SET note = ?, account_remarks = ?, payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ?'
                );
                $remarksUpdate->bind_param('ssii', $note, $accountRemarks, $loggedInUserId, $requestId);
                $remarksUpdate->execute();
                $remarksUpdate->close();
            }

            $normalizedStatus = $payment_status === 'Unconfirmed' ? 'Processing' : $payment_status;
            if ($normalizedStatus !== (string) $currentRequest['payment_status']) {
                accountSupplierApplyDirectStatus(
                    $conn,
                    [$requestId],
                    $normalizedStatus,
                    $actor,
                    [
                        'processing_method' => $data['processing_method'] ?? null,
                        'processing_reference' => $data['processing_reference'] ?? null,
                        'payment_reference' => $data['payment_reference'] ?? null,
                        'reason' => $data['reason'] ?? $data['account_remarks'] ?? null,
                        'suppress_notification' => true,
                    ]
                );
            } else {
                procurementSyncLocalFinalPurchasePaymentDetails(
                    $conn,
                    [$requestId],
                    (int) $loggedInUserId,
                    'account_updated_sent_purchase'
                );
            }

            $log_stmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
            $action = "$userEmail updated ProcureDesk-linked Supplier Fund Request ID $requestId and synchronized Procurement.";
            $log_stmt->bind_param('iss', $loggedInUserId, $action, $userEmail);
            $log_stmt->execute();
            $log_stmt->close();

            accountNotificationPublishSupplierAction(
                $conn,
                'supplier_request_updated',
                'Supplier fund request updated',
                $userEmail . " updated Supplier Fund Request #$requestId for $suppliers_name and synchronized ProcureDesk.",
                [$requestId],
                $actor,
                '/payments/fund-request/supplier?search=' . rawurlencode($purchase_number),
                'info',
                [
                    'supplier_name' => $suppliers_name,
                    'purchase_number' => $purchase_number,
                    'po_number' => $po_number,
                    'invoice_number' => $invoice_number,
                    'project_code' => $project_code,
                    'payment_status' => $normalizedStatus,
                    'commercial_details_changed' => $commercialChanged,
                    'procuredesk_linked' => true,
                ]
            );
            $conn->commit();
        } catch (Throwable $transactionError) {
            $conn->rollback();
            throw $transactionError;
        }

        $fetchLinked = $conn->prepare('SELECT * FROM supplier_fund_request_table WHERE id = ?');
        $fetchLinked->bind_param('i', $requestId);
        $fetchLinked->execute();
        $linkedData = $fetchLinked->get_result()->fetch_assoc();
        $fetchLinked->close();

        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => "Supplier's payment request and Procurement record updated successfully",
            'data' => $linkedData,
        ]);
        exit;
    }

    // Check for duplicate (excluding current ID)
    $dup = $conn->prepare("SELECT id FROM supplier_fund_request_table WHERE purchase_number = ? AND id != ?");
    $dup->bind_param("si", $purchase_number, $requestId);
    $dup->execute();
    $dupResult = $dup->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate request. Purchase No.: $purchase_number already exists!", 400);
    }

    // Update the linked request and ProcureDesk status atomically.
    $conn->begin_transaction();
    try {
    // Update the record
    $update = $conn->prepare("
    UPDATE supplier_fund_request_table SET suppliers_name = ?, supplier_id = ?, invoice_number = ?, purchase_number = ?, po_number = ?, 
    invoice_date = ?, purchase_date = ?, date_received = ?, invoice_month = ?, purchase_month = ?, project_code = ?, description = ?, vat_policy = ?,
    net_value = ?, discount = ?, other_charges = ?, amount = ?, note = ?, payment_status = ?, vat = ?, wht = ? WHERE id = ?
    ");
    $update->bind_param(
        "sisisssssssssddddssidd",
        $suppliers_name,
        $supplier_id,
        $invoice_number,
        $purchase_number,
        $po_number,
        $invoice_date,
        $purchase_date,
        $date_received,
        $invoice_month,
        $purchase_month,
        $project_code,
        $description,
        $vat_policy,
        $amount,
        $discount,
        $other_charges,
        $total_amount_payament,
        $note,
        $payment_status,
        $vat,
        $wht,
        $requestId
    );

    if (!$update->execute()) {
        throw new Exception("Update failed: " . $update->error, 500);
    }

    procurementSyncLocalFinalPurchasePaymentStatus(
        $conn,
        [$requestId],
        $payment_status,
        (int) $loggedInUserId
    );

    // Log update
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail updated suppliers payment request with ID $requestId";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    accountNotificationPublishSupplierAction(
        $conn,
        'supplier_request_updated',
        'Supplier fund request updated',
        $userEmail . " updated Supplier Fund Request #$requestId for $suppliers_name.",
        [$requestId],
        ['id' => (int) $loggedInUserId, 'email' => (string) $userEmail],
        '/payments/fund-request/supplier?search=' . rawurlencode($purchase_number),
        'info',
        [
            'supplier_name' => $suppliers_name,
            'purchase_number' => $purchase_number,
            'po_number' => $po_number,
            'invoice_number' => $invoice_number,
            'project_code' => $project_code,
            'previous_payment_status' => (string) ($currentRequest['payment_status'] ?? ''),
            'payment_status' => $payment_status,
            'procuredesk_linked' => false,
        ]
    );
    $conn->commit();
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    $fetchData = $conn->prepare("SELECT * FROM supplier_fund_request_table WHERE id = ?");
    $fetchData->bind_param("i", $requestId);
    $fetchData->execute();
    $dataResult = $fetchData->get_result();
    $fetchedData = $dataResult->fetch_assoc();
    $fetchData->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Supplier's payment request updated successfully",
        "data" => $fetchedData
    ]);

} catch (Throwable $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
