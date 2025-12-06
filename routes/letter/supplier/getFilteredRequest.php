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
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    // batch is optional: null when not provided
    $batch = (isset($_GET['batch']) && $_GET['batch'] !== '' && is_numeric($_GET['batch'])) ? (int) $_GET['batch'] : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Sorting setup — whitelist to prevent SQL injection
    $allowedSortFields = ["amount", "date", "beneficiary_name", "ben_bank_name", "created_at", "payment_category", "batch"];
    // Default to created_at so newest entries show first
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields) ? $_GET['sortBy'] : "created_at";
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC" ? "ASC" : "DESC";

    // Build base query and filter parameters
    $baseQuery = "FROM local_transfer WHERE 1=1";
    $params = [];
    $types = "";

    // User filter -> (keeps your present logic)
    if ($requestedUserId !== 'all') {
        $baseQuery .= " AND payment_category = ?";
        $params[] = (int) $requestedUserId;
        $types .= "i";
    }

    // Year filter (optional) - using the `created_at` column as in your snippet
    if ($year) {
        $baseQuery .= " AND YEAR(`created_at`) = ?";
        $params[] = $year;
        $types .= "i";
    }

    // Specific date filter (optional) - using DATE(date)
    if ($date) {
        $baseQuery .= " AND DATE(`date`) = ?";
        $params[] = $date;
        $types .= "s";
    }

    // Batch filter (optional) — only add it when batch is provided
    if ($batch !== null) {
        $baseQuery .= " AND batch = ?";
        $params[] = $batch;
        $types .= "i";
    }

    // Search filter (optional) - search over many text/number columns
    if ($search) {
        $baseQuery .= " AND (beneficiary_name LIKE ? OR account_number LIKE ? OR ben_bank_name LIKE ? OR payment_account_number LIKE ? OR payment_category LIKE ? OR amount LIKE ? OR created_at LIKE ? OR `date` LIKE ?)";
        $likeSearch = "%" . $search . "%";
        // append eight times
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "ssssssss";
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
    $total = (int) $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated results
    $query = "SELECT id, beneficiary_name, account_number, ben_bank_name, payment_account_number, payment_category, amount, created_at, created_by, `date`, batch $baseQuery ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $getStmt = $conn->prepare($query);
    if (!$getStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add pagination values to parameters
    $typesWithPaging = $types . "ii";
    $paramsWithPaging = $params;
    $paramsWithPaging[] = $limit;
    $paramsWithPaging[] = $offset;

    // bind params if any
    if (!empty($paramsWithPaging)) {
        $getStmt->bind_param($typesWithPaging, ...$paramsWithPaging);
    }
    $getStmt->execute();
    $result = $getStmt->get_result();
    $transfers = $result->fetch_all(MYSQLI_ASSOC);
    $getStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Local transfers fetched successfully",
        "data" => $transfers,
        "meta" => [
            "total" => $total,
            "limit" => $limit,
            "page" => $page,
            "year" => $year,
            "date" => $date,
            "userId" => $requestedUserId,
            "batch" => $batch,
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
