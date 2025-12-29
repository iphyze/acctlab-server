<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can update supplier account details", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['id', 'account_name', 'account_number', 'bank_name'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $id = (int) $data['id'];
    if ($id <= 0) {
        throw new Exception("Invalid supplier account ID provided.", 400);
    }

    // Clean inputs
    $account_name   = trim($data['account_name']);
    $account_number = trim($data['account_number']);
    $bank_name      = trim($data['bank_name']);

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT id 
        FROM suppliers_account_details 
        WHERE id = ?
    ");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Supplier account with ID {$id} not found.", 404);
    }
    $checkStmt->close();

    /**
     * Duplicate check (exclude current record)
     * Same account_number + same bank_name is NOT allowed
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM suppliers_account_details
        WHERE account_number = ? AND bank_name = ? AND id != ?
        LIMIT 1
    ");
    $dupStmt->bind_param("ssi", $account_number, $bank_name, $id);
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
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE suppliers_account_details
        SET account_name = ?, account_number = ?, bank_name = ?
        WHERE id = ?
    ");
    $updateStmt->bind_param("sssi", $account_name, $account_number, $bank_name, $id);

    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }
    $updateStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("
        INSERT INTO logs (userId, action, created_by)
        VALUES (?, ?, ?)
    ");
    $action = "$userEmail updated supplier account details ({$account_name} - {$bank_name}) [ID {$id}]";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT id, account_name, account_number, bank_name, created_at
        FROM suppliers_account_details
        WHERE id = ?
    ");
    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Supplier account details updated successfully",
        "data" => $updatedData
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
