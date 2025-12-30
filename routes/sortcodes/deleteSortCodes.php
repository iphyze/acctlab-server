<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins are authorized to delete", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (
        !isset($data['sortCodeIds']) ||
        !is_array($data['sortCodeIds']) ||
        count($data['sortCodeIds']) === 0
    ) {
        throw new Exception("Please select at least one bank sort code to delete.", 400);
    }

    $sortCodeIds = array_map('intval', $data['sortCodeIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        /**
         * Delete bank sort codes
         */
        $placeholders = implode(',', array_fill(0, count($sortCodeIds), '?'));
        $deleteQuery = "DELETE FROM bank_sortcode_tab WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($sortCodeIds)), ...$sortCodeIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete bank sort code(s): " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching bank sort codes found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");
        $logAction = "$loggedInUserEmail deleted bank sort code(s) with ID(s): " . implode(', ', $sortCodeIds);
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Bank sort code(s) deleted successfully."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
