<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Check if the user is authenticated
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can access logs", 401);
    }

    // Validate required GET parameters
    if (!isset($_GET['limit']) || !isset($_GET['page']) || !isset($_GET['year'])) {
        throw new Exception("Missing required parameters: 'limit', 'page', and 'year' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    $year = (int) $_GET['year'];
    $date = isset($_GET['date']) ? $_GET['date'] : null;

    if ($limit <= 0 || $page <= 0 || $year <= 0) {
        throw new Exception("Invalid values: 'limit', 'page', and 'year' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Build the base query with filters
    $baseQuery = "FROM advance_payment_schedule_tab WHERE userId = ? AND YEAR(created_at) = ?";
    $params = [$loggedInUserId, $year];
    $types = "ii";

    if ($date) {
        $baseQuery .= " AND DATE(created_at) = ?";
        $params[] = $date;
        $types .= "s";
    }

    // Get total count for pagination
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery");
    if (!$countStmt) {
        throw new Exception("Failed to prepare count statement: " . $conn->error, 500);
    }

    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated data
    $query = "SELECT * $baseQuery ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $getStmt = $conn->prepare($query);
    if (!$getStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $getStmt->bind_param($types, ...$params);
    $getStmt->execute();
    $result = $getStmt->get_result();
    $payments = $result->fetch_all(MYSQLI_ASSOC);
    $getStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Payments fetched successfully",
        "data" => $payments,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            "year" => $year,
            "date" => $date
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
?>
