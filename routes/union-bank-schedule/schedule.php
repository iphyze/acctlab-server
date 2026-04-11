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
            'bank_name' => 'Bank Name',
            'account_number' => 'Account Number',
            'account_name' => 'Account Name',
            'sort_code' => 'Sort Code',
            'supplier_id' => 'Supplier ID',
        ];

        foreach ($requiredFields as $key => $label) {
            if (empty($item[$key])) {
                return "Row " . ($index + 1) . ": " . $label . " is required.";
            }
        }

        // Custom validation: At least one must be filled
        $invoice = isset($item['invoice_number']) ? trim($item['invoice_number']) : '';
        $po = isset($item['po_number']) ? trim($item['po_number']) : '';

        if (empty($invoice) && empty($po)) {
            return "Row " . ($index + 1) . ": Either Invoice Number or PO Number must be provided.";
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
        INSERT INTO union_payment_schedule 
        (payment_amount, payment_date, batch, narration, supplier_name, supplier_id, bank_name, account_name, account_number, sort_code, user_id, invoice_number, po_number)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
    }

    foreach ($data as $item) {
        $supplier_name = trim($item['supplier_name']);
        $payment_amount = trim($item['payment_amount']);
        $payment_date = trim($item['payment_date']);
        $batch = trim($item['batch']);
        $bank_name = trim($item['bank_name']);
        $account_number = trim($item['account_number']);
        $account_name = trim($item['account_name']);
        $sort_code = trim($item['sort_code']);
        $supplier_id = trim($item['supplier_id']);
        
        // Safely fetch and trim the optional fields (default to empty string if missing)
        $invoice_number = isset($item['invoice_number']) ? trim($item['invoice_number']) : '';
        $po_number = isset($item['po_number']) ? trim($item['po_number']) : '';

        // Dynamic Narration Logic
        $narrationParts = [];
        if (!empty($invoice_number)) {
            $narrationParts[] = "Inv No $invoice_number";
        }
        if (!empty($po_number)) {
            $narrationParts[] = "Po No $po_number";
        }
        
        // Join the parts with a comma if both exist, otherwise just use the single part
        $narration = "Payment against " . implode(", ", $narrationParts);

        $stmt->bind_param(
            "sssssssssssss",
            $payment_amount,
            $payment_date,
            $batch,
            $narration,
            $supplier_name,
            $supplier_id,
            $bank_name,
            $account_name,
            $account_number,
            $sort_code,
            $loggedInUserId,
            $invoice_number,
            $po_number
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