<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate the user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    // Validate pagination
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    $paymentStatus = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';
    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Base query
    $baseQuery = "FROM supplier_fund_request_table WHERE 1=1";
    $params = [];
    $types = "";

    // Apply payment status filter
    if ($paymentStatus !== 'all') {
        $baseQuery .= " AND payment_status = ?";
        $params[] = $paymentStatus;
        $types .= "s";
    }

    // Apply year filter
    if ($year) {
        $baseQuery .= " AND YEAR(created_at) = ?";
        $params[] = $year;
        $types .= "i";
    }

    // Count total
    $countQuery = "SELECT COUNT(*) AS total $baseQuery";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated results
    $dataQuery = "SELECT * $baseQuery ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add limit and offset to params
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Supplier payments fetched successfully",
        "data" => $data,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            "payment_status" => $paymentStatus,
            "year" => $year
        ]
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
