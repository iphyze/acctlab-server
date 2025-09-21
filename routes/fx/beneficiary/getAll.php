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
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this data", 401);
    }

    // Validate pagination parameters
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM bank_beneficiary_details_table");
    if (!$countStmt) {
        throw new Exception("Failed to prepare count statement: " . $conn->error, 500);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Get paginated data
    $dataStmt = $conn->prepare("SELECT * FROM bank_beneficiary_details_table ORDER BY beneficiary_name ASC LIMIT ? OFFSET ?");
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $dataStmt->bind_param("ii", $limit, $offset);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $beneficiaries = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Bank beneficiary details fetched successfully",
        "data" => $beneficiaries,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page
        ],
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
