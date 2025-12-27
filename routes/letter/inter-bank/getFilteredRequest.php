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
    $page  = (int) $_GET['page'];

    // Optional filters
    $year         = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $paymentDate  = isset($_GET['payment_date']) ? $_GET['payment_date'] : null; // <-- renamed
    $search       = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Sorting setup — whitelist to prevent SQL injection
    $allowedSortFields = [
        "payment_amount",
        "payment_date",
        "letter_heading",
        "payment_to",
        "payment_bank_name",
        "tax_beneficiary",
        "tax_type",
        "created_at"
    ];

    // Default to created_at so newest entries show first
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields)
        ? $_GET['sortBy']
        : "created_at";

    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC"
        ? "ASC"
        : "DESC";

    // Build base query and filter parameters
    $baseQuery = "FROM instruction_letter WHERE 1=1";
    $params    = [];
    $types     = "";

    // Year filter (optional) - using payment_date
    if ($year) {
        $baseQuery .= " AND YEAR(payment_date) = ?";
        $params[] = $year;
        $types   .= "i";
    }

    // Specific payment_date filter (optional)
    if ($paymentDate) {
        $baseQuery .= " AND DATE(payment_date) = ?";
        $params[] = $paymentDate;
        $types   .= "s";
    }

    // Search filter (optional)
    if ($search) {
        $baseQuery .= " AND (
            letter_heading        LIKE ?
            OR instruction_type   LIKE ?
            OR payment_to         LIKE ?
            OR payment_bank_name  LIKE ?
            OR tax_beneficiary    LIKE ?
            OR tax_type           LIKE ?
            OR tax_tin            LIKE ?
            OR tax_date_from      LIKE ?
            OR tax_date_to        LIKE ?
            OR payment_amount     LIKE ?
            OR words              LIKE ?
            OR letter_body        LIKE ?
            OR payment_account_number LIKE ?
            OR bank_code          LIKE ?
            OR payment_date       LIKE ?
            OR created_at         LIKE ?
        )";

        $likeSearch = "%" . $search . "%";

        // 16 columns above → 16 params
        for ($i = 0; $i < 16; $i++) {
            $params[] = $likeSearch;
        }

        $types .= str_repeat("s", 16);
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
    $total       = (int) $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated results
    $query = "
        SELECT
            id,
            letter_heading,
            instruction_type,
            payment_to,
            payment_bank_name,
            tax_beneficiary,
            tax_type,
            tax_tin,
            tax_date_from,
            tax_date_to,
            payment_amount,
            words,
            letter_body,
            payment_account_number,
            bank_code,
            payment_date,
            created_at
        $baseQuery
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $getStmt = $conn->prepare($query);
    if (!$getStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add pagination values to parameters
    $typesWithPaging    = $types . "ii";
    $paramsWithPaging   = $params;
    $paramsWithPaging[] = $limit;
    $paramsWithPaging[] = $offset;

    $getStmt->bind_param($typesWithPaging, ...$paramsWithPaging);

    $getStmt->execute();
    $result   = $getStmt->get_result();
    $letters  = $result->fetch_all(MYSQLI_ASSOC);
    $getStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Instruction letters fetched successfully",
        "data"    => $letters,
        "meta"    => [
            "total"         => $total,
            "limit"         => $limit,
            "page"          => $page,
            "year"          => $year,
            "payment_date"  => $paymentDate,
            "sortBy"        => $sortBy,
            "sortOrder"     => $sortOrder,
            "search"        => $search
        ],
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
