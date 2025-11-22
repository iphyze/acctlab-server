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

    // Optional filters
    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Allowed sorting fields for bank_beneficiary_details_table
    $allowedSortFields = [
        "id",
        "beneficiary_name",
        "beneficiary_bank",
        "beneficiary_address",
        "beneficiary_bank_address",
        "swift_code",
        "beneficiary_account_number",
        "bank_code",
        "account",
        "sort_code",
        "intermediary_bank",
        "intermediary_bank_swift_code",
        "intermediary_bank_iban",
        "domiciliation",
        "code_guichet",
        "compte_no",
        "cle_rib",
        "created_at"
    ];

    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    // Base query for bank_beneficiary_details_table
    $baseQuery = "FROM bank_beneficiary_details_table WHERE 1=1";
    $params = [];
    $types = "";

    // Filter by year (created_at)
    if ($year) {
        $baseQuery .= " AND YEAR(created_at) = ?";
        $params[] = $year;
        $types .= "i";
    }

    // Search filter
    if ($search) {
        $baseQuery .= " AND (
            beneficiary_name LIKE ? OR
            beneficiary_address LIKE ? OR
            beneficiary_bank LIKE ? OR
            beneficiary_bank_address LIKE ? OR
            swift_code LIKE ? OR
            beneficiary_account_number LIKE ? OR
            bank_code LIKE ? OR
            account LIKE ? OR
            sort_code LIKE ? OR
            intermediary_bank LIKE ? OR
            intermediary_bank_swift_code LIKE ? OR
            intermediary_bank_iban LIKE ? OR
            domiciliation LIKE ? OR
            code_guichet LIKE ? OR
            compte_no LIKE ? OR
            cle_rib LIKE ?
        )";

        $likeSearch = "%" . $search . "%";

        // Add 16 bound values for the 16 LIKE conditions
        $params = array_merge($params, array_fill(0, 16, $likeSearch));
        $types .= str_repeat("s", 16);
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

    // Add limit and offset to params
    $typesForData = $types . "ii";
    $paramsForData = $params;
    $paramsForData[] = $limit;
    $paramsForData[] = $offset;

    $dataStmt->bind_param($typesForData, ...$paramsForData);
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
            $temp[$key] = is_string($val) ? utf8_encode($val) : $val;
        }
        $data[] = $temp;
    }

    $dataStmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Bank beneficiary details fetched successfully",
        "data" => $data,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
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
