<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail = $userData['email'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can update FX payments", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['paymentIds']) || !is_array($data['paymentIds']) || count($data['paymentIds']) === 0) {
        throw new Exception("Please select at least one FX payment to update.", 400);
    }

    if (!isset($data['payment_status']) || trim($data['payment_status']) === '') {
        throw new Exception("Payment status is required.", 400);
    }

    $paymentIds = array_map('intval', $data['paymentIds']);
    $paymentStatus = trim($data['payment_status']);

    if (count($paymentIds) > 100) {
        throw new Exception("Too many IDs provided. Maximum allowed is 100.", 400);
    }

    $validStatuses = ['Pending', 'Paid', 'Unconfirmed'];
    if (!in_array($paymentStatus, $validStatuses)) {
        throw new Exception("Invalid payment status provided. Allowed: Pending, Paid, Unconfirmed.", 400);
    }

    // ✅ Step 1: Verify that all IDs exist
    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
    $typeString = str_repeat('i', count($paymentIds));

    $checkStmt = $conn->prepare("SELECT id FROM fx_instruction_letter_table WHERE id IN ($placeholders)");
    $checkStmt->bind_param($typeString, ...$paymentIds);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    $existingIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingIds[] = (int) $row['id'];
    }

    $missingIds = array_diff($paymentIds, $existingIds);

    if (count($missingIds) > 0) {
        throw new Exception("The following FX payment IDs do not exist: " . implode(', ', $missingIds), 404);
    }

    $checkStmt->close();

    // ✅ Step 2: Begin transaction
    $conn->begin_transaction();

    try {
        // ✅ Step 3: Update payment status
        $updateQuery = "UPDATE fx_instruction_letter_table SET payment_status = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($updateQuery);

        if (!$stmt) {
            throw new Exception("Failed to prepare update statement: " . $conn->error, 500);
        }

        $params = array_merge([$paymentStatus], $paymentIds);
        $types = 's' . $typeString;
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update FX payment status: " . $stmt->error, 500);
        }

        $stmt->close();

        // ✅ Step 4: Log the update
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$loggedInUserEmail updated FX payment_status to '$paymentStatus' for record(s) with ID(s): " . implode(', ', $paymentIds) . " in fx_instruction_letter_table.";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log the update action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // ✅ Step 5: Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "FX payment status updated successfully."
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
