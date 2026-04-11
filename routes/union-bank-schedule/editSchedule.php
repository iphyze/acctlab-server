<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Check if the user is authenticated
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $userEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can update logs", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid input format", 400);
    }

    function validateRow($data)
    {
        $requiredFields = [
            'id' => 'ID',
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
            if (empty($data[$key])) {
                throw new Exception($label . " is required", 400);
            }
        }
    }

    validateRow($data);

    $id = intval($data['id']);
    if (!$id) {
        throw new Exception("ID must be a number", 400);
    }

    // Check if ID exists in the new table
    $check = $conn->prepare("SELECT id FROM union_payment_schedule WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows === 0) {
        throw new Exception("Schedule with ID $id not found", 404);
    }
    $check->close();

    // Map to new schema
    $supplier_name = trim($data['supplier_name']);
    $payment_amount = trim($data['payment_amount']);
    $payment_date = trim($data['payment_date']);
    $batch = trim($data['batch']);
    $bank_name = trim($data['bank_name']);
    $account_number = trim($data['account_number']);
    $account_name = trim($data['account_name']);
    $sort_code = trim($data['sort_code']);
    $supplier_id = (int) $data['supplier_id'];

    // Safely fetch and trim the optional fields (default to empty string if missing)
    $invoice_number = isset($data['invoice_number']) ? trim($data['invoice_number']) : '';
    $po_number = isset($data['po_number']) ? trim($data['po_number']) : '';

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

    $stmt = $conn->prepare("
        UPDATE union_payment_schedule 
        SET payment_amount = ?, payment_date = ?, batch = ?, narration = ?, supplier_name = ?, supplier_id = ?, 
            account_number = ?, sort_code = ?, account_name = ?, bank_name = ?, user_id = ?, 
            invoice_number = ?, po_number = ? 
        WHERE id = ?
    ");

    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
    }

    // "sssisssssissi" matches: string, string, string, string, int, string, string, string, string, int, string, string, int
    $stmt->bind_param(
        "ssssssssssissi",
        $payment_amount,
        $payment_date,
        $batch,
        $narration,
        $supplier_name,
        $supplier_id,
        $account_number,
        $sort_code,
        $account_name,
        $bank_name,
        $loggedInUserId,
        $invoice_number,
        $po_number,
        $id
    );

    if ($stmt->execute()) {
        $stmt->close();

        // Log action
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        if ($logStmt) {
            $action = $userEmail . " updated payment schedule with ID $id";
            $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
            $logStmt->execute(); // Added missing execute() call from original code
            $logStmt->close();
        }

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "The schedule has been updated successfully!",
            "data" => [
                "id" => $id,
                "payment_amount" => $payment_amount,
                "payment_date" => $payment_date,
                "batch" => $batch,
                "invoice_number" => $invoice_number,
                "po_number" => $po_number,
                "narration" => $narration,
                "supplier_name" => $supplier_name,
                "supplier_id" => $supplier_id,
                "account_number" => $account_number,
                "sort_code" => $sort_code,
                "account_name" => $account_name,
                "bank_name" => $bank_name
            ]
        ]);
    } else {
        throw new Exception("Failed to update schedule: " . $stmt->error, 500);
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>