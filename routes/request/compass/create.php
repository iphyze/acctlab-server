<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

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
    $requiredFields = ['suppliers_name', 'supplier_id', 'invoice_number', 'invoice_date', 'date_received', 'project_code', 'description', 'classification', 
    'percentage', 'net_value', 'vat_policy', 'discount', 'other_charges', 'payment_status'];
    

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || ($data[$field] === '' && $data[$field] !== 0 && $data[$field] !== '0.00%')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean datas
    $suppliers_name = trim($data['suppliers_name']);
    $supplier_id = trim($data['supplier_id']);
    $invoice_number = trim($data['invoice_number']);
    $invoice_date = trim($data['invoice_date']);
    $date_received = trim($data['date_received']);
    $project_code = trim($data['project_code']);
    $description = trim($data['description']);
    $classification = trim($data['classification']);
    $percentage = (float) $data['percentage'];
    $net_value = isset($data['net_value']) ? number_format(round((float) $data['net_value'], 2), 2, '.', '') : '0.00';
    $discount = isset($data['discount']) ? number_format(round((float) $data['discount'], 2), 2, '.', '') : '0.00';
    $other_charges = isset($data['other_charges']) ? number_format(round((float) $data['other_charges'], 2), 2, '.', '') : '0.00';
    $vat_policy = trim($data['vat_policy']) ?: "0.00%";
    $payment_status = isset($data['payment_status']) ? trim($data['payment_status']) : '';
    $note = isset($data['note']) ? trim($data['note']) : '';

    $net_amount = round($net_value - $discount, 2);

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

    $gross_amount = round($amount_payable + $other_charges, 2);
    $amount = round($gross_amount * ($percentage / 100), 2);


    // Check for duplicate request
    $dupQuery = $conn->prepare("SELECT id FROM compass_fund_request_table WHERE invoice_number = ? AND suppliers_name = ?");
    $dupQuery->bind_param("ss", $invoice_number, $suppliers_name);
    $dupQuery->execute();
    $dupResult = $dupQuery->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate request. Invoice No.: $invoice_number already exists under $suppliers_name", 400);
    }


    // Insert into compass_fund_request_table
    $insertStmt = $conn->prepare("
        INSERT INTO compass_fund_request_table 
        (
        suppliers_name,
        supplier_id,
        invoice_number,
        invoice_date,
        date_received,
        project_code,
        description,
        classification,
        vat_policy,
        net_value,
        discount,
        other_charges,
        amount,
        note,
        payment_status,
        vat,
        wht,
        percentage
    )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->bind_param(
        "sisssssssddddssddd",
        $suppliers_name,
        $supplier_id,
        $invoice_number,
        $invoice_date,
        $date_received,
        $project_code,
        $description,
        $classification,
        $vat_policy,
        $net_value,
        $discount,
        $other_charges,
        $amount,
        $note,
        $payment_status,
        $vat,
        $wht,
        $percentage
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;

    // Log action
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail created a new expense payment request with ID $insertedId";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Expense payment request created successfully",
        "data" => [
            "id" => $insertedId,
            "suppliers_name" => $suppliers_name,
            "supplier_id" => $supplier_id,
            "invoice_number" => $invoice_number,
            "invoice_date" => $invoice_date,
            "date_received" => $date_received,
            "project_code" => $project_code,
            "description" => $description,
            "classification" => $classification,
            "vat_policy" => $vat_policy,
            "vat" => $vat,
            "wht" => $wht,
            "net_value" => $net_value,
            "discount" => $discount,
            "other_charges" => $other_charges,
            "amount" => $amount,
            "note" => $note,
            "payment_status" => $payment_status,
            "percentage" => $percentage
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
