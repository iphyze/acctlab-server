<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can fetch payments", 401);
    }

    // Validate and get the payment_date param
    if (!isset($_GET['payment_date']) || empty(trim($_GET['payment_date']))) {
        throw new Exception("Missing payment_date parameter", 400);
    }

    $paymentDate = trim($_GET['payment_date']);

    // Validate format
    $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate) {
        throw new Exception("Invalid payment_date format. Use YYYY-MM-DD", 400);
    }

    // Query
    $get = $conn->prepare("SELECT * FROM advance_payment_schedule_tab WHERE DATE(payment_date) = ? ORDER BY created_at DESC");

    if (!$get) {
        throw new Exception("Failed to prepare statement: " . $conn->error, 500);
    }

    $get->bind_param("s", $paymentDate);
    $get->execute();
    $result = $get->get_result();
    $payments = $result->fetch_all(MYSQLI_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Payments fetched successfully by payment date!",
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
