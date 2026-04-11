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
    
    // Batch handling: defaults to 'all', converts to int only if a specific number is passed
    $batch = isset($_GET['batch']) ? $_GET['batch'] : 'all';
    $batchParam = ($batch !== 'all' && is_numeric($batch)) ? (int) $batch : null;
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Sorting setup (updated to match new table schema)
    $allowedSortFields = ["payment_amount", "payment_date", "suppliers_name", "invoice_number", "po_number", "created_at", "batch"];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields) ? $_GET['sortBy'] : "payment_date";
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC" ? "ASC" : "DESC";

    // Build base query and filter parameters
    $baseQuery = "FROM payment_schedule_tab WHERE 1=1";
    $params = [];
    $types = "";

    // User filter (updated column name)
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

    // Batch filter (CONDITIONAL: only apply if a specific batch number is requested)
    if ($batchParam !== null) {
        $baseQuery .= " AND batch = ?";
        $params[] = $batchParam;
        $types .= "i";
    }

    // Search filter (updated to cover new table columns)
    if ($search) {
        $baseQuery .= " AND (
            suppliers_name LIKE ? 
            OR payment_amount LIKE ? 
            OR payment_date LIKE ? 
            OR invoice_number LIKE ? 
            OR po_number LIKE ?
            OR narration LIKE ?
            OR bank_name LIKE ?
            OR account_name LIKE ?
            OR account_number LIKE ?
            OR sort_code LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "ssssssssss";
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
    $query = "SELECT * $baseQuery ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
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
            "userId" => $requestedUserId,
            "batch" => $batch, // Returns 'all' or the requested batch number
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search
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