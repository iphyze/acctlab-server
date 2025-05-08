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

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins are authorized to update", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['requestIds']) || !is_array($data['requestIds']) || count($data['requestIds']) === 0) {
        throw new Exception("Please select a request first.", 400);
    }

    if (!isset($data['payment_status']) || trim($data['payment_status']) === '') {
        throw new Exception("Payment status is required.", 400);
    }

    $requestIds = array_map('intval', $data['requestIds']);
    $paymentStatus = trim($data['payment_status']);


    if(count($requestIds) > 100) {
        throw new Exception("Too many request IDs provided. Maximum allowed is 100.", 400);
    }

    $validStatuses = ['Pending', 'Paid', 'Unconfirmed'];
    // $payment_status = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : null;

    // Validate if an invalid status is provided
    if (!in_array($paymentStatus, $validStatuses)) {
        throw new Exception("Invalid payment status provided.", 400);
    }


    // if($paymentStatus !== 'Paid' && $paymentStatus !== 'Pending') {
    //     throw new Exception("Invalid payment status provided. Allowed values are 'Paid' or 'Pending'.", 400);
    // }

    // Step 1: Verify that all IDs exist
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $typeString = str_repeat('i', count($requestIds));
    $checkStmt = $conn->prepare("SELECT id FROM advance_payment_request WHERE id IN ($placeholders)");
    $checkStmt->bind_param($typeString, ...$requestIds);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    $existingIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingIds[] = (int) $row['id'];
    }

    $missingIds = array_diff($requestIds, $existingIds);

    if (count($missingIds) > 0) {
        throw new Exception("The following request IDs do not exist: " . implode(', ', $missingIds), 404);
    }

    $checkStmt->close();

    // Step 2: Begin transaction
    $conn->begin_transaction();

    try {
        $updateQuery = "UPDATE advance_payment_request SET payment_status = ? WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($updateQuery);

        if (!$stmt) {
            throw new Exception("Failed to prepare update statement: " . $conn->error, 500);
        }

        $params = array_merge([$paymentStatus], $requestIds);
        $types = 's' . $typeString;
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update payment status: " . $stmt->error, 500);
        }

        $stmt->close();

        // Step 3: Log the update
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$loggedInUserEmail updated payment_status to '$paymentStatus' for request(s) with ID(s): " . implode(', ', $requestIds) . " in Advance Payment Request.";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userData['email']);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log the update action: " . $logStmt->error, 500);
        }

        $logStmt->close();
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Request status have been updated successfully."
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
