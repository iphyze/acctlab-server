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
        !isset($data['supplierIds']) ||
        !is_array($data['supplierIds']) ||
        count($data['supplierIds']) === 0
    ) {
        throw new Exception("Please select at least one supplier to delete.", 400);
    }

    $supplierIds = array_map('intval', $data['supplierIds']);

    // Start transaction
    $conn->begin_transaction();

    try {
        /**
         * Delete suppliers
         */
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $deleteQuery = "DELETE FROM suppliers_table WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        $deleteStmt->bind_param(
            str_repeat('i', count($supplierIds)),
            ...$supplierIds
        );

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete supplier(s): " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching suppliers found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");
        $logAction = "$loggedInUserEmail deleted supplier ledger record(s) with ID(s): " . implode(', ', $supplierIds);
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
            "message" => "Supplier ledger record(s) deleted successfully."
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
