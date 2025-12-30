<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page'", 400);
    }

    $limit  = (int) $_GET['limit'];
    $page   = (int) $_GET['page'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid pagination values", 400);
    }

    $offset = ($page - 1) * $limit;

    // Sorting
    $allowedSortFields = ['bank_name', 'sort_code', 'code_name', 'id'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : 'bank_name';

    $sortOrder = (isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === 'ASC')
        ? 'ASC'
        : 'DESC';

    $baseQuery = "FROM bank_sortcode_tab WHERE 1=1";
    $params = [];
    $types = "";

    if ($search) {
        $baseQuery .= " AND (bank_name LIKE ? OR sort_code LIKE ? OR code_name LIKE ?)";
        $like = "%{$search}%";
        $params = [$like, $like, $like];
        $types = "sss";
    }

    // Count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery");
    if ($params) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    // Data
    $dataStmt = $conn->prepare("
        SELECT id, bank_name, sort_code, code_name
        $baseQuery
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ");

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $data = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Bank sort codes fetched successfully",
        "data" => $data,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search
        ]
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
