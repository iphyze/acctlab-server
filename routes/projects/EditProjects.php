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
        throw new Exception("Unauthorized: Only Admins can update locations", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['id', 'location', 'code'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $id = (int) $data['id'];
    if ($id <= 0) {
        throw new Exception("Invalid location ID provided.", 400);
    }

    // Clean inputs
    $location = trim($data['location']);
    $code     = strtoupper(trim($data['code']));

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("SELECT id FROM location_table WHERE id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Location with ID {$id} not found.", 404);
    }
    $checkStmt->close();

    /**
     * Check for duplicate (exclude current record)
     */
    $dupStmt = $conn->prepare("
        SELECT id 
        FROM location_table 
        WHERE (location = ? OR code = ?) AND id != ?
        LIMIT 1
    ");
    $dupStmt->bind_param("ssi", $location, $code, $id);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Location or code already exists.", 400);
    }
    $dupStmt->close();

    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE location_table 
        SET location = ?, code = ?
        WHERE id = ?
    ");
    $updateStmt->bind_param("ssi", $location, $code, $id);

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
    $action = "$userEmail updated location '{$location}' with code {$code} (ID {$id})";
    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("SELECT id, location, code FROM location_table WHERE id = ?");
    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();
    $updatedData = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Project updated successfully",
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
