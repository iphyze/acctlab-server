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
        throw new Exception("Unauthorized: Only Admins can create suppliers", 401);
    }

    // Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['supplier_name', 'supplier_number', 'wht_status'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean inputs
    $supplier_name   = trim($data['supplier_name']);
    $supplier_number = trim($data['supplier_number']);
    $wht_status      = trim($data['wht_status']); // e.g. Yes / No / Exempt

    /**
     * Duplicate check
     * supplier_name + supplier_number must be unique
     */
    $dupStmt = $conn->prepare("
        SELECT id
        FROM suppliers_table
        WHERE supplier_name = ? AND supplier_number = ?
        LIMIT 1
    ");
    $dupStmt->bind_param("ss", $supplier_name, $supplier_number);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception(
            "Duplicate supplier detected: Supplier name and number already exist.",
            400
        );
    }
    $dupStmt->close();

    /**
     * Insert supplier (ledger)
     */
    $insertStmt = $conn->prepare("
        INSERT INTO suppliers_table (supplier_name, supplier_number, wht_status)
        VALUES (?, ?, ?)
    ");
    $insertStmt->bind_param("sss", $supplier_name, $supplier_number, $wht_status);

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
    $action = "$userEmail created a new supplier ledger ({$supplier_name} - {$supplier_number})";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    http_response_code(201);
    echo json_encode([
        "status" => "Success",
        "message" => "Supplier ledger created successfully",
        "data" => [
            "id" => $insertedId,
            "supplier_name" => $supplier_name,
            "supplier_number" => $supplier_number,
            "wht_status" => $wht_status
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
