<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if ($userIntegrity !== 'Admin' && $userIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can create payment requests", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected a JSON object.", 400);
    }

    // Extract and validate data
    $requiredFields = ['supplier_name', 'supplier_id', 'site', 'po_number', 'date_received', 'percentage', 'amount', 'discount', 'vat_status', 'payment_status'];
    

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || ($data[$field] === '' && $data[$field] !== 0 && $data[$field] !== '0.00%')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean datas
    $supplier_name = trim($data['supplier_name']);
    $supplier_id = trim($data['supplier_id']);
    $site = trim($data['site']);
    $po_number = trim($data['po_number']);
    $date_received = trim($data['date_received']);
    $percentage = (float) $data['percentage'];
    $amount = isset($data['amount']) ? number_format(round((float) $data['amount'], 2), 2, '.', '') : '0.00';
    $discount = isset($data['discount']) ? number_format(round((float) $data['discount'], 2), 2, '.', '') : '0.00';
    $vat_status = trim($data['vat_status']) ?: "0.00%";
    $other_charges = isset($data['other_charges']) ? number_format(round((float) $data['other_charges'], 2), 2, '.', '') : '0.00';
    $payment_status = isset($data['payment_status']) ? trim($data['payment_status']) : '';
    $note = isset($data['note']) ? trim($data['note']) : '';

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

    // Check for duplicate request
    $dupQuery = $conn->prepare("SELECT id FROM advance_payment_request WHERE suppliers_name = ? AND percentage = ? AND po_number = ? AND date_received = ?");
    $dupQuery->bind_param("sdss", $supplier_name, $percentage, $po_number, $date_received);
    $dupQuery->execute();
    $dupResult = $dupQuery->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate request. This advance request already exists.", 400);
    }

    // Validate against both manual Account requests and active ProcureDesk reservations.
    procurementLocalAdvanceAssertAccountAllocationAvailable($conn, $po_number, $percentage);

    // Insert into advance_payment_request
    $insertStmt = $conn->prepare("
        INSERT INTO advance_payment_request 
        (
        suppliers_name, 
        supplier_id, 
        site, 
        po_number, 
        date_received, 
        percentage, 
        amount, 
        discount, 
        net_amount, 
        vat,
        amount_payable, 
        other_charges, 
        advance_payment, 
        payment_status, 
        vat_status, 
        note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->bind_param(
        "sisssdddddddssss",
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
        $payment_status,
        $vat_status,
        $note
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;

    // Log action
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail created a new advance payment request with ID $insertedId";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Advance payment request created successfully",
        "data" => [
            "id" => $insertedId,
            "supplier_name" => $supplier_name,
            "site" => $site,
            "po_number" => $po_number,
            "date_received" => $date_received,
            "percentage" => $percentage,
            "amount" => $amount,
            "discount" => $discount,
            "net_amount" => $net_amount,
            "vat" => $vat,
            "amount_payable" => $amount_payable,
            "other_charges" => $other_charges,
            "advance_payment" => $advance_payment,
            "payment" => $payment_status,
            "note" => $note
        ]
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
