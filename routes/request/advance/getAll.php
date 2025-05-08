<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can view requests", 401);
    }

    $validStatuses = ['Pending', 'Paid', 'Unconfirmed'];
    $payment_status = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : null;

    // Validate if an invalid status is provided
    if (
        $payment_status !== null && 
        $payment_status !== '' && 
        $payment_status !== 'All' && 
        !in_array($payment_status, $validStatuses)
    ) {
        throw new Exception("Invalid payment status provided.", 400);
    }

    // Fetch all if status is null, empty, or 'All'
    if ($payment_status === null || $payment_status === '' || $payment_status === 'All') {
        $statement = "SELECT * FROM advance_payment_request ORDER BY created_at DESC";
        $get = $conn->prepare($statement);
    } else {
        $statement = "SELECT * FROM advance_payment_request WHERE payment_status = ? ORDER BY created_at DESC";
        $get = $conn->prepare($statement);
        if (!$get) {
            throw new Exception("Failed to prepare statement: " . $conn->error, 500);
        }
        $get->bind_param("s", $payment_status);
    }

    $get->execute();
    $result = $get->get_result();
    $payments = $result->fetch_all(MYSQLI_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Requests have been fetched successfully!",
        "data" => $payments
    ]);

    $get->close();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
