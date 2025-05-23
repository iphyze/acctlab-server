<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

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

    // Check if the entry exists
    $check = $conn->prepare("SELECT id FROM advance_payment_request WHERE id = ?");
    $check->bind_param("i", $requestId);
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Advance payment request with ID $requestId not found", 404);
    }
    $check->close();

    // Check for duplicate (excluding current ID)
    $dup = $conn->prepare("SELECT id FROM advance_payment_request WHERE suppliers_name = ? AND percentage = ? AND po_number = ? AND date_received = ? AND id != ?");
    $dup->bind_param("sdssi", $supplier_name, $percentage, $po_number, $date_received, $requestId);
    $dup->execute();
    $dupResult = $dup->get_result();
    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate entry detected for same supplier, percentage, PO number, and date.", 400);
    }

    // Validate total percentage (excluding current entry)
    $percQuery = $conn->prepare("SELECT percentage FROM advance_payment_request WHERE po_number = ? AND id != ?");
    $percQuery->bind_param("si", $po_number, $id);
    $percQuery->execute();
    $percResult = $percQuery->get_result();

    $existing_percentage = 0;
    while ($row = $percResult->fetch_assoc()) {
        $existing_percentage += (float) $row['percentage'];
    }

    $total_percentage = $existing_percentage + $percentage;
    if ($total_percentage > 100) {
        throw new Exception("Total percentage for PO '$po_number' exceeds 100%.", 400);
    }

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
