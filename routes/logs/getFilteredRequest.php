<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    /**
     * Authenticate user
     */
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    // Only Super_Admin allowed
    if ($loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Super Admin can access logs", 401);
    }

    /**
     * Validate pagination
     */
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit  = (int) $_GET['limit'];
    $page   = (int) $_GET['page'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    /**
     * Sorting setup
     */
    $allowedSortFields = ['id', 'userId', 'created_by', 'created_at'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'id';

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC'
        ? 'ASC'
        : 'DESC';

    /**
     * Base query
     */
    $baseQuery = "FROM logs WHERE 1=1";
    $params = [];
    $types  = "";

    /**
     * Search filter
     */
    if ($search) {
        $baseQuery .= " AND (
            action LIKE ?
            OR created_by LIKE ?
            OR userId LIKE ?
        )";

        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types   .= "sss";
    }

    /**
     * Count total records
     */
    $countQuery = "SELECT COUNT(*) AS total $baseQuery";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    /**
     * Fetch paginated logs
     */
    $dataQuery = "
        SELECT id, userId, action, created_by, created_at
        $baseQuery
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $types  .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $logs = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    /**
     * Response
     */
    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Logs fetched successfully",
        "data"    => $logs,
        "meta"    => [
            "total"     => $total,
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
