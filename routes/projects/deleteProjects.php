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

    if (!isset($data['projectIds']) || !is_array($data['projectIds']) || count($data['projectIds']) === 0) {
        throw new Exception("Please select a project first.", 400);
    }

    $projectIds = array_map('intval', $data['projectIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        // Delete from payments table
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $deleteQuery = "DELETE FROM location_table WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($projectIds)), ...$projectIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete projects: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching projects found to delete.", 404);
        }

        $deleteStmt->close();

        // Log the action
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$loggedInUserEmail deleted project(s) with ID(s): " . implode(', ', $projectIds) . " from Projects.";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userData['email']);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log the delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Project(s) deleted successfully."
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
