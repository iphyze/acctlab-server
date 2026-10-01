<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalFinalPurchaseService.php';
require_once 'includes/accountNotificationService.php';

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

    if (!isset($data['requestIds']) || !is_array($data['requestIds']) || count($data['requestIds']) === 0) {
        throw new Exception("Please select a request first.", 400);
    }

    $requestIds = array_map('intval', $data['requestIds']);

    // Approved ProcureDesk handoffs must be reversed from ProcureDesk, not deleted in AcctLab.
    procurementAssertSupplierFundRequestsCanBeDeleted($conn, $requestIds);

    // Start transaction
    $conn->begin_transaction();

    try {
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $snapshotStmt = $conn->prepare(
            "SELECT id, suppliers_name, purchase_number, po_number, invoice_number, payment_status
             FROM supplier_fund_request_table WHERE id IN ($placeholders) FOR UPDATE"
        );
        $snapshotStmt->bind_param(str_repeat('i', count($requestIds)), ...$requestIds);
        $snapshotStmt->execute();
        $deletedRecords = $snapshotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $snapshotStmt->close();

        // Delete from payments table
        $deleteQuery = "DELETE FROM supplier_fund_request_table WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($requestIds)), ...$requestIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete payments: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching payments found to delete.", 404);
        }

        $deleteStmt->close();

        // Log the action
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$loggedInUserEmail deleted request(s) with ID(s): " . implode(', ', $requestIds) . " from Supplier's Payment Request.";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userData['email']);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log the delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        $deletedIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $deletedRecords);
        accountNotificationPublishSupplierAction(
            $conn,
            'supplier_request_deleted',
            'Supplier fund request deleted',
            $loggedInUserEmail . ' deleted ' . count($deletedRecords) . ' Supplier Fund Request(s).',
            $deletedIds,
            ['id' => (int) $loggedInUserId, 'email' => (string) $loggedInUserEmail],
            '/payments/fund-request/supplier',
            'warning',
            [
                'deleted_records' => $deletedRecords,
                'deleted_count' => count($deletedRecords),
            ]
        );

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Request(s) deleted successfully."
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Throwable $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
