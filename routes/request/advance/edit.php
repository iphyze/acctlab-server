<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';
require_once 'includes/accountAdvancePaymentService.php';

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

    $requiredFields = ['requestId', 'supplier_name', 'supplier_id', 'site', 'po_number', 'date_received', 'percentage', 'amount', 'discount', 'vat_status', 'payment_status'];

    // foreach ($requiredFields as $field) {
    //     if (empty($data[$field]) && $data[$field] !== 0 && $data[$field] !== '0.00%') {
    //         throw new Exception("Field '{$field}' is required.", 400);
    //     }
    // }

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || ($data[$field] === '' && $data[$field] !== 0 && $data[$field] !== '0.00%')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }
    
    
    $requestId = intval($data['requestId']);
    if (!$requestId) throw new Exception("Invalid Request ID provided.", 400);

    // Clean and assign values
    $supplier_name = trim($data['supplier_name']);
    $supplier_id = trim($data['supplier_id']);
    $site = trim($data['site']);
    $po_number = trim($data['po_number']);
    $payment_status = trim($data['payment_status']);
    $date_received = trim($data['date_received']);
    $percentage = (float) $data['percentage'];
    $amount = isset($data['amount']) ? number_format(round((float) $data['amount'], 2), 2, '.', '') : '0.00';
    $discount = isset($data['discount']) ? number_format(round((float) $data['discount'], 2), 2, '.', '') : '0.00';
    $vat_status = trim($data['vat_status']) ?: "0.00%";
    $other_charges = isset($data['other_charges']) ? number_format(round((float) $data['other_charges'], 2), 2, '.', '') : '0.00';
    $note = isset($data['note']) ? trim($data['note']) : '';

    // VAT Calculation
    $net_amount = round($amount - $discount, 2);

    switch ($vat_status) {
        case "0.00%":
            $vat = 0.00;
            $amount_payable = $net_amount;
            break;
        case "7.50%":
            $vat = round($net_amount * 0.075, 2);
            $amount_payable = round($net_amount + $vat, 2);
            break;
        case "2.00%":
            $vat = round($net_amount * 0.075, 2); // Label is 2%, but calculation uses 7.5%
            $amount_payable = round($net_amount * 1.055, 2);
            break;
        case "5.00%":
            $vat = round($net_amount * 0.075, 2); // Label is 5%, but calculation uses 7.5%
            $amount_payable = round($net_amount * 1.025, 2);
            break;
        default:
            throw new Exception("Invalid VAT status.", 400);
    }

    $gross_amount = round($amount_payable + $other_charges, 2);
    $advance_payment = round($gross_amount * ($percentage / 100), 2);

    procurementLocalAdvanceEnsureStorage($conn);

    // Check if the entry exists
    $check = $conn->prepare("SELECT * FROM advance_payment_request WHERE id = ?");
    $check->bind_param("i", $requestId);
    $check->execute();
    $currentRequest = $check->get_result()->fetch_assoc();
    if (!$currentRequest) {
        throw new Exception("Advance payment request with ID $requestId not found", 404);
    }
    $check->close();

    $commercialChanged =
            trim((string) ($currentRequest['suppliers_name'] ?? '')) !== $supplier_name
            || (int) ($currentRequest['supplier_id'] ?? 0) !== (int) $supplier_id
            || trim((string) ($currentRequest['site'] ?? '')) !== $site
            || trim((string) ($currentRequest['po_number'] ?? '')) !== $po_number
            || trim((string) ($currentRequest['date_received'] ?? '')) !== $date_received
            || abs((float) ($currentRequest['percentage'] ?? 0) - $percentage) > 0.000001
            || abs((float) ($currentRequest['amount'] ?? 0) - (float) $amount) > 0.009
            || abs((float) ($currentRequest['discount'] ?? 0) - (float) $discount) > 0.009
            || abs((float) ($currentRequest['other_charges'] ?? 0) - (float) $other_charges) > 0.009
            || trim((string) ($currentRequest['vat_status'] ?? '')) !== $vat_status;
    $statusChanged = trim((string) ($currentRequest['payment_status'] ?? '')) !== $payment_status;

    $linkedPurchase = procurementLocalAdvanceLinkedRequest($conn, $requestId);
    if ($linkedPurchase !== null) {
        if ($commercialChanged || $statusChanged) {
            throw new Exception(
                'ProcureDesk-linked Advance Fund Request commercial details are locked in Account. Return the request to Procurement for correction.',
                409
            );
        }

        $accountRemarks = trim((string) ($data['account_remarks'] ?? $note));
        $remarksUpdate = $conn->prepare(
            'UPDATE advance_payment_request
             SET note = ?, account_remarks = ?, payment_updated_by = ?, payment_updated_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $remarksUpdate->bind_param('ssii', $note, $accountRemarks, $loggedInUserId, $requestId);
        $remarksUpdate->execute();
        $remarksUpdate->close();

        $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $action = "$userEmail updated the note for ProcureDesk-linked advance payment request with ID $requestId";
        $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
        $log_stmt->execute();
        $log_stmt->close();

        $fetchData = $conn->prepare("SELECT * FROM advance_payment_request WHERE id = ?");
        $fetchData->bind_param("i", $requestId);
        $fetchData->execute();
        $fetchedData = $fetchData->get_result()->fetch_assoc();
        $fetchData->close();

        echo json_encode([
            "status" => "Success",
            "message" => "Advance payment request note updated successfully",
            "data" => $fetchedData
        ]);
        exit;
    }

    if ((string) ($currentRequest['payment_status'] ?? 'Pending') !== 'Pending') {
        throw new Exception(
            'Only Pending Advance Fund Requests can be commercially edited. Use the payment-status or processing workflow for payment changes.',
            409
        );
    }
    if ($statusChanged) {
        throw new Exception(
            'Payment status cannot be changed from the edit form. Use the payment-status action instead.',
            409
        );
    }
    accountAdvanceAssertNoActivePaymentOperation($conn, [$requestId], 'edited');

    // Check for duplicate (excluding current ID)
    $dup = $conn->prepare("SELECT id FROM advance_payment_request WHERE suppliers_name = ? AND percentage = ? AND po_number = ? AND date_received = ? AND id != ?");
    $dup->bind_param("sdssi", $supplier_name, $percentage, $po_number, $date_received, $requestId);
    $dup->execute();
    $dupResult = $dup->get_result();
    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate entry detected for same supplier, percentage, PO number, and date.", 400);
    }

    // Validate against both manual Account requests and active ProcureDesk reservations.
    procurementLocalAdvanceAssertAccountAllocationAvailable(
        $conn,
        $po_number,
        $percentage,
        $requestId
    );

    // Update the record
    $update = $conn->prepare("
    UPDATE advance_payment_request SET
    suppliers_name = ?, supplier_id = ?, site = ?, po_number = ?, date_received = ?, 
    percentage = ?, amount = ?, discount = ?, net_amount = ?, vat = ?, 
    amount_payable = ?, other_charges = ?, advance_payment = ?, note = ?, updated_at = NOW(), payment_status = ?, vat_status = ?
    WHERE id = ?
    ");
    $update->bind_param(
        "sisssdddddddssssi",
        $supplier_name,
        $supplier_id,
        $site,
        $po_number,
        $date_received,
        $percentage,
        $amount,
        $discount,
        $net_amount,
        $vat,
        $amount_payable,
        $other_charges,
        $advance_payment,
        $note,
        $payment_status,
        $vat_status,
        $requestId
    );

    if (!$update->execute()) {
        throw new Exception("Update failed: " . $update->error, 500);
    }

    // Log update
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail updated advance payment request with ID $requestId";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    $fetchData = $conn->prepare("SELECT * FROM advance_payment_request WHERE id = ?");
    $fetchData->bind_param("i", $requestId);
    $fetchData->execute();
    $dataResult = $fetchData->get_result();
    $fetchedData = $dataResult->fetch_assoc();
    $fetchData->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Advance payment request updated successfully",
        "data" => $fetchedData
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
