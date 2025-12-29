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

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can create supplier account details", 401);
    }

    // Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['account_name', 'account_number', 'bank_name'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean inputs
    $account_name   = trim($data['account_name']);
    $account_number = trim($data['account_number']);
    $bank_name      = trim($data['bank_name']);

    /**
     * Duplicate check:
     * Same account_number + same bank_name is NOT allowed
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM suppliers_account_details
        WHERE account_number = ? AND bank_name = ?
        LIMIT 1
    ");
    $dupStmt->bind_param("ss", $account_number, $bank_name);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Duplicate account detected: This account number already exists for the selected bank.",
            400
        );
    }
    $dupStmt->close();

    /**
     * Insert supplier account details
     */
    $insertStmt = $conn->prepare("
        INSERT INTO suppliers_account_details (account_name, account_number, bank_name)
        VALUES (?, ?, ?)
    ");
    $insertStmt->bind_param("sss", $account_name, $account_number, $bank_name);

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;
    $insertStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("
        INSERT INTO logs (userId, action, created_by)
        VALUES (?, ?, ?)
    ");
    $action = "$userEmail created supplier account details ({$account_name} - {$bank_name})";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    http_response_code(201);
    echo json_encode([
        "status" => "Success",
        "message" => "Supplier account details created successfully",
        "data" => [
            "id" => $insertedId,
            "account_name" => $account_name,
            "account_number" => $account_number,
            "bank_name" => $bank_name
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
