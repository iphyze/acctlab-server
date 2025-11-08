<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure connection uses utf8mb4 (fixes malformed characters)
$conn->set_charset("utf8mb4");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
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
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Allowed sorting fields
    $allowedSortFields = [
        "beneficiary_name", "beneficiary_bank", "reference", "amount_figure",
        "currency", "payment_status", "payment_date", "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    // Base query
    $baseQuery = "FROM fx_instruction_letter_table WHERE 1=1";
    $params = [];
    $types = "";

    if ($paymentStatus !== 'all') {
        $baseQuery .= " AND payment_status = ?";
        $params[] = $paymentStatus;
        $types .= "s";
    }

    if ($year) {
        $baseQuery .= " AND YEAR(created_at) = ?";
        $params[] = $year;
        $types .= "i";
    }

    if ($search) {
        $baseQuery .= " AND (
            beneficiary_name LIKE ? OR
            beneficiary_bank LIKE ? OR
            reference LIKE ? OR
            amount_figure LIKE ? OR
            currency LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        $params = array_merge($params, [$likeSearch, $likeSearch, $likeSearch, $likeSearch, $likeSearch]);
        $types .= "sssss";
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
    $total = $countResult->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    // Fetch paginated results (streaming, safe for large data)
    $dataQuery = "SELECT SQL_BIG_RESULT * $baseQuery ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();

    // Stream results instead of get_result()
    $meta = $dataStmt->result_metadata();
    $fields = [];
    $row = [];

    while ($field = $meta->fetch_field()) {
        $fields[] = &$row[$field->name];
    }
    call_user_func_array([$dataStmt, 'bind_result'], $fields);

    $data = [];
    while ($dataStmt->fetch()) {
        $temp = [];
        foreach ($row as $key => $val) {
            // Encode text properly to avoid bad characters breaking JSON
            $temp[$key] = is_string($val) ? utf8_encode($val) : $val;
        }
        $data[] = $temp;
    }

    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "FX instruction letters fetched successfully",
        "data" => $data,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            "payment_status" => $paymentStatus,
            "year" => $year,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search
        ]
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
