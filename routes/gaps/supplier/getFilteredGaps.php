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
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can access logs", 401);
    }

    // Validate required pagination parameters
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $date = isset($_GET['date']) ? $_GET['date'] : null;
    $requestedUserId = isset($_GET['userId']) ? $_GET['userId'] : $loggedInUserId;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Build base query and filter parameters
    $baseQuery = "FROM payment_schedule_tab WHERE 1=1";
    $params = [];
    $types = "";

    // User filter
    if ($requestedUserId !== 'all') {
        $baseQuery .= " AND userId = ?";
        $params[] = (int) $requestedUserId;
        $types .= "i";
    }

    // Year filter (optional)
    if ($year) {
        $baseQuery .= " AND YEAR(payment_date) = ?";
        $params[] = $year;
        $types .= "i";
    }

    // Specific date filter (optional)
    if ($date) {
        $baseQuery .= " AND DATE(payment_date) = ?";
        $params[] = $date;
        $types .= "s";
    }

    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery");
    if (!$countStmt) {
        throw new Exception("Failed to prepare count statement: " . $conn->error, 500);
    }
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated results
    $query = "SELECT * $baseQuery ORDER BY payment_date DESC LIMIT ? OFFSET ?";
    $getStmt = $conn->prepare($query);
    if (!$getStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add pagination values to parameters
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
            "date" => $date,
            "userId" => $requestedUserId
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
