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

    // Check if the user is authenticated
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins are authorized to delete", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['letterIds']) || !is_array($data['letterIds']) || count($data['letterIds']) === 0) {
        throw new Exception("Please select a transfer first.", 400);
    }

    $letterIds = array_map('intval', $data['letterIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // Delete from instruction_letter table
        $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
        $deleteQuery = "DELETE FROM instruction_letter WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        // Bind integer ids
        $types = str_repeat('i', count($letterIds));
        $deleteStmt->bind_param($types, ...$letterIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete transfers: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching transfers found to delete.", 404);
        }

        $deleteStmt->close();

        // Log the action
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        if (!$logStmt) {
            throw new Exception("Database error, failed to prepare log statement: " . $conn->error, 500);
        }

        $logAction = "$loggedInUserEmail deleted local transfer(s) with ID(s): " . implode(', ', $letterIds);
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log the delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Transfer(s) deleted and logged successfully."
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
?>
