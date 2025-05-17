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
    $requiredFields = ['suppliers_name', 'supplier_id', 'invoice_number', 'purchase_number',
    'po_number', 'invoice_date', 'purchase_date', 'date_received', 'project_code', 'description', 'amount', 'vat_policy', 'discount', 
    'other_charges', 'payment_status'];
    

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || ($data[$field] === '' && $data[$field] !== 0 && $data[$field] !== '0.00%')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean datas
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


    // Check for duplicate request
    $dupQuery = $conn->prepare("SELECT id FROM supplier_fund_request_table WHERE purchase_number = ?");
    $dupQuery->bind_param("s", $purchase_number);
    $dupQuery->execute();
    $dupResult = $dupQuery->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate request. Purchase No.: $purchase_number already exists!", 400);
    }



    // Insert into supplier_fund_request_table
    $insertStmt = $conn->prepare("
        INSERT INTO supplier_fund_request_table 
        (
        suppliers_name,
        supplier_id,
        invoice_number,
        purchase_number,
        po_number,
        invoice_date,
        purchase_date,
        date_received,
        invoice_month,
        purchase_month,
        project_code,
        description,
        vat_policy,
        net_value,
        discount,
        other_charges,
        amount,
        note,
        payment_status,
        vat,
        wht
    )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->bind_param(
        "sisisssssssssddddssdd",
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
        $wht
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;

    // Log action
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail created a new supplier payment request with ID $insertedId";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Supplier's payment request created successfully",
        "data" => [
            "suppliers_name" => $suppliers_name,
            "supplier_id" => $supplier_id,
            "invoice_number" => $invoice_number,
            "purchase_number" => $purchase_number,
            "po_number" => $po_number,
            "invoice_date" => $invoice_date,
            "purchase_date" => $purchase_date,
            "date_received" => $date_received,
            "invoice_month" => $invoice_month,
            "purchase_month" => $purchase_month,
            "project_code" => $project_code,
            "description" => $description,
            "vat_policy" => $vat_policy,
            "vat" => $vat,
            "wht" => $wht,
            "net_value" => $amount,
            "discount" => $discount,
            "other_charges" => $other_charges,
            "amount" => $total_amount_payament,
            "note" => $note,
            "payment_status" => $payment_status
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
