<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Set charset to handle special/unicode characters
    $conn->set_charset("utf8mb4");

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins or Super Admins can access this resource", 401);
    }

    /**
     * Get search query (optional)
     * The frontend might send ?search=john
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * Uses WHERE 1=1 as a base to cleanly append AND conditions dynamically
     */
    $sql = "
        SELECT 
            id,
            beneficiary_name,
            beneficiary_address,
            beneficiary_bank,
            beneficiary_bank_address,
            swift_code,
            beneficiary_account_number,
            bank_code,
            account,
            sort_code,
            intermediary_bank,
            intermediary_bank_swift_code,
            intermediary_bank_iban,
            domiciliation,
            code_guichet,
            compte_no,
            cle_rib,
            created_at
        FROM bank_beneficiary_details_table
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * Search by beneficiary name, account number, or bank name
     */
    if (!empty($search)) {
        $sql .= " AND (
            beneficiary_name LIKE ? 
            OR beneficiary_account_number LIKE ? 
            OR beneficiary_bank LIKE ?
        )";
        
        // Fuzzy match for text fields
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        
        // Prefix match for account number (better UX for numeric/alphanumeric identifiers)
        $params[] = $search . "%";
        
        $params[] = $likeSearch;
        
        $types .= "sss";
    }

    /**
     * Sorting and Limiting
     * Always sort by beneficiary name ASC and limit to 100 results for performance.
     */
    $sql .= " ORDER BY beneficiary_name ASC LIMIT 100";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database prepare error: " . $conn->error, 500);
    }

    // Bind parameters if search exists
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception("Execute error: " . $stmt->error, 500);
    }

    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Failed to get result: " . $stmt->error, 500);
    }

    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Sanitize data to ensure all strings are valid UTF-8
    array_walk_recursive($data, function (&$value) {
        if (is_string($value)) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
    });

    $encoded = json_encode([
        "status" => "Success",
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Catch silent json_encode failures
    if ($encoded === false) {
        throw new Exception("JSON encoding error: " . json_last_error_msg(), 500);
    }

    http_response_code(200);
    echo $encoded;

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

}

?>