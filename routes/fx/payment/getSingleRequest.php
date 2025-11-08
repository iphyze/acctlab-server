<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure UTF-8 encoding
$conn->set_charset("utf8mb4");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    // ✅ Check if paymentId is provided
    if (!isset($_GET['paymentId']) || !is_numeric($_GET['paymentId'])) {
        throw new Exception("Missing or invalid parameter: 'paymentId' is required and must be numeric.", 400);
    }

    $paymentId = (int) $_GET['paymentId'];

    // ✅ Fetch single payment record
    $stmt = $conn->prepare("
        SELECT * 
        FROM fx_instruction_letter_table 
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result->fetch_assoc();
    $stmt->close();

    // ✅ If no record found
    if (!$record) {
        throw new Exception("No FX instruction found for payment ID: $paymentId", 404);
    }

    // ✅ Now fetch corresponding FX bank details
    $bankStmt = $conn->prepare("
        SELECT letter_header, salutation, attention, letter_title, letter_format
        FROM fx_banks_table
        WHERE account_number = ?
        LIMIT 1
    ");
    if (!$bankStmt) {
        throw new Exception("Failed to prepare bank query: " . $conn->error, 500);
    }

    $bankStmt->bind_param("s", $record['payment_account_number']);
    $bankStmt->execute();
    $bankResult = $bankStmt->get_result();
    $bankDetails = $bankResult->fetch_assoc();
    $bankStmt->close();

    // ✅ Merge FX bank details if found
    if ($bankDetails) {
        $record = array_merge($record, $bankDetails);
    } else {
        // If no match found, add them as null keys for consistency
        $record['letter_header'] = null;
        $record['salutation'] = null;
        $record['attention'] = null;
        $record['letter_title'] = null;
        $record['letter_format'] = null;
    }

    // ✅ Return combined data
    echo json_encode([
        "status" => "Success",
        "message" => "FX instruction fetched successfully",
        "data" => $record
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
