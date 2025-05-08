<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $userEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can update payment schedules", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid input format", 400);
    }

    function validateRow($item)
    {
        $requiredFields = [
            'id' => 'ID',
            'supplier_name' => 'Supplier Name',
            'payment_amount' => 'Payment Amount',
            'payment_date' => 'Payment Date',
            'po_numbers' => 'PO Numbers',
            'bank_name' => 'Bank Name',
            'account_number' => 'Account Number',
            'account_name' => 'Account Name',
            'sort_code' => 'Sort Code',
            'suppliers_id' => 'Suppliers ID',
            'percentages' => 'Percentages',
        ];

        foreach ($requiredFields as $key => $label) {
            if (empty($item[$key])) {
                throw new Exception($label . " is required", 400);
            }
        }
    }

    validateRow($data);

    $id = intval($data['id']);
    if (!$id) {
        throw new Exception("ID must be a number", 400);
    }

    // Check if ID exists
    $check = $conn->prepare("SELECT id FROM advance_payment_schedule_tab WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Advance payment schedule with ID $id not found", 404);
    }
    $check->close();

    $suppliers_name = trim($data['supplier_name']);
    $payment_amount = trim($data['payment_amount']);
    $payment_date = trim($data['payment_date']);
    $po_numbers = trim($data['po_numbers']);
    $percentages = trim($data['percentages']);
    $remark = $percentages . " Advance Payment against Po No.: " . $po_numbers;
    $bank_name = trim($data['bank_name']);
    $account_number = trim($data['account_number']);
    $account_name = trim($data['account_name']);
    $sort_code = trim($data['sort_code']);
    $suppliers_id = trim($data['suppliers_id']);

    $stmt = $conn->prepare("UPDATE advance_payment_schedule_tab 
        SET payment_amount = ?, payment_date = ?, po_numbers = ?, 
        remark = ?, suppliers_name = ?, suppliers_id = ?, 
        account_number = ?, sort_code = ?, account_name = ?, 
        bank_name = ?, percentages = ?, userId = ?
        WHERE id = ?");

    $stmt->bind_param(
        "sssssssisssii",
        $payment_amount,
        $payment_date,
        $po_numbers,
        $remark,
        $suppliers_name,
        $suppliers_id,
        $account_number,
        $sort_code,
        $account_name,
        $bank_name,
        $percentages,
        $loggedInUserId,
        $id
    );

    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
    }

    if ($stmt->execute()) {

        $get = "INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($get);
        if (!$stmt) {
            throw new Exception("Database query preparation failed: " . $conn->error, 500);
        }

        $action = $userEmail . " updated payment schedule with ID $id";
        $created_by = $userEmail;

        $stmt->bind_param("iss", $loggedInUserId, $action, $created_by);

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Advance payment schedule updated successfully",
            "data" => [
                "id" => $id,
                "payment_amount" => $payment_amount,
                "payment_date" => $payment_date,
                "po_numbers" => $po_numbers,
                "remark" => $remark,
                "suppliers_name" => $suppliers_name,
                "suppliers_id" => $suppliers_id,
                "account_number" => $account_number,
                "sort_code" => $sort_code,
                "account_name" => $account_name,
                "bank_name" => $bank_name,
                "percentages" => $percentages
            ]
        ]);
    } else {
        throw new Exception("Failed to update schedule: " . $stmt->error, 500);
    }

    $stmt->close();

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
