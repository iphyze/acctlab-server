<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $userEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can create payment schedules", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid data format. Expected an array of objects.", 400);
    }

    function validateRow($item, $index)
    {
        $requiredFields = [
            'supplier_name' => 'Supplier Name',
            'payment_amount' => 'Payment Amount',
            'payment_date' => 'Payment Date',
            'batch' => 'Batch',
            // 'remark' => 'Description',
            'invoice_numbers' => 'Invoice Numbers',
            'po_numbers' => 'PO Numbers',
            'bank_name' => 'Bank Name',
            'account_number' => 'Account Number',
            'account_name' => 'Account Name',
            'sort_code' => 'Sort Code',
            'suppliers_id' => 'Suppliers ID',
        ];

        foreach ($requiredFields as $key => $label) {
            if (empty($item[$key])) {
                return "Row " . $index + 1 . ": " . $label . " is required.";
            }
        }

        return null;
    }

    // Validate all rows
    foreach ($data as $index => $item) {
        $error = validateRow($item, $index);
        if ($error) {
            http_response_code(400);
            echo json_encode([
                "status" => "Failed",
                "message" => $error
            ]);
            exit;
        }
    }

    // Prepare insert statement
    $stmt = $conn->prepare("
        INSERT INTO payment_schedule_tab 
        (payment_amount, payment_date, batch, invoice_numbers, po_numbers, remark, suppliers_name, supplier_id, account_number, sort_code, account_name, bank_name, userId)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
    }

    foreach ($data as $item) {
        $suppliers_name = trim($item['supplier_name']);
        $payment_amount = trim($item['payment_amount']);
        $payment_date = trim($item['payment_date']);
        $batch = trim($item['batch']);
        $invoice_numbers = trim($item['invoice_numbers']);
        $po_numbers = trim($item['po_numbers']);
        // $remark = trim($item['remark']);
        $remark = "Payment against Inv No $invoice_numbers, Po No $po_numbers";
        $bank_name = trim($item['bank_name']);
        $account_number = trim($item['account_number']);
        $account_name = trim($item['account_name']);
        $sort_code = trim($item['sort_code']);
        $supplier_id = trim($item['suppliers_id']);

        $stmt->bind_param(
            "sssssssssssss",
            $payment_amount,
            $payment_date,
            $batch,
            $invoice_numbers,
            $po_numbers,
            $remark,
            $suppliers_name,
            $supplier_id,
            $account_number,
            $sort_code,
            $account_name,
            $bank_name,
            $loggedInUserId
        );

        if (!$stmt->execute()) {
            throw new Exception("Database insert failed: " . $stmt->error, 500);
        }

        $id = $stmt->insert_id;

        // Log action
        $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        if ($log_stmt) {
            $action = "$userEmail created new suppliers payment schedule with ID $id";
            $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    $stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Schedule has been created successfully"
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
