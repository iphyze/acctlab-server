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
    $page  = (int) $_GET['page'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Sorting setup for local_banks
    $allowedSortFields = [
        "id",
        "name",
        "bank_name",
        "account_number",
        "currency",
        "bank_code",
        "letter_title",
        "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    // Base query (use local_banks)
    $baseQuery = "FROM local_banks WHERE 1=1";
    $params = [];
    $types  = "";

    // Search filter (optional) - search across relevant columns
    if ($search) {
        $baseQuery .= " AND (
            name LIKE ? OR
            bank_name LIKE ? OR
            account_number LIKE ? OR
            currency LIKE ? OR
            bank_code LIKE ? OR
            letter_title LIKE ? OR
            letter_header LIKE ? OR
            salutation LIKE ? OR
            attention LIKE ?
        )";

        $likeSearch = "%" . $search . "%";
        // order of params must match the question marks above
        $params[] = $likeSearch; // name
        $params[] = $likeSearch; // bank_name
        $params[] = $likeSearch; // account_number
        $params[] = $likeSearch; // currency
        $params[] = $likeSearch; // bank_code
        $params[] = $likeSearch; // letter_title
        $params[] = $likeSearch; // letter_header
        $params[] = $likeSearch; // salutation
        $params[] = $likeSearch; // attention
        $types   .= str_repeat("s", 9);
    }

    // Count total
    $countQuery = "SELECT COUNT(*) AS total $baseQuery";
    $countStmt  = $conn->prepare($countQuery);

    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    // Fetch paginated results
    $dataQuery = "SELECT id, name, bank_name, account_number, currency, bank_code, letter_header, salutation, attention, letter_title, created_at $baseQuery ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $dataStmt  = $conn->prepare($dataQuery);

    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add limit and offset to params for the data query
    $types_with_limit = $types . "ii";
    $params_with_limit = $params;
    $params_with_limit[] = $limit;
    $params_with_limit[] = $offset;

    // bind params dynamically
    $dataStmt->bind_param($types_with_limit, ...$params_with_limit);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $data   = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Local banks fetched successfully",
        "data"    => $data,
        "meta"    => [
            "total"     => (int) $total,
            "limit"     => $limit,
            "page"      => $page,
            "sortBy"    => $sortBy,
            "sortOrder" => $sortOrder,
            "search"    => $search
        ]
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
