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
        throw new Exception("Unauthorized: Only Admins can create locations", 401);
    }

    // Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['location', 'code'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean inputs
    $location = trim($data['location']);
    $code     = strtoupper(trim($data['code']));

    /**
     * Check for duplicates
     */
    $dupStmt = $conn->prepare("
        SELECT id 
        FROM location_table 
        WHERE location = ? OR code = ?
        LIMIT 1
    ");
    $dupStmt->bind_param("ss", $location, $code);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Location or code already exists.", 400);
    }
    $dupStmt->close();

    /**
     * Insert location
     */
    $insertStmt = $conn->prepare("
        INSERT INTO location_table (location, code)
        VALUES (?, ?)
    ");
    $insertStmt->bind_param("ss", $location, $code);

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
    $action = "$userEmail created a new location ({$location}) with code {$code}";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Project created successfully",
        "data" => [
            "id" => $insertedId,
            "location" => $location,
            "code" => $code
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
