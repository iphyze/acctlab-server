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

    /**
     * Authenticate user
     */
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    // Only Super_Admin allowed
    if ($loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Super Admins can delete logs", 401);
    }

    /**
     * Decode request body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (
        !isset($data['logIds']) ||
        !is_array($data['logIds']) ||
        count($data['logIds']) === 0
    ) {
        throw new Exception("Please select at least one log to delete.", 400);
    }

    $logIds = array_map('intval', $data['logIds']);

    /**
     * Start transaction
     */
    $conn->begin_transaction();

    try {
        /**
         * Delete logs
         */
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        $deleteQuery = "DELETE FROM logs WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($logIds)), ...$logIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete logs: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching logs found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Commit transaction
         */
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "Log record(s) deleted successfully."
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
