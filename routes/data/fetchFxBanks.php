<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins or Super Admins can access this resource", 401);
    }

    /**
     * Get search query (optional)
     * The frontend might send ?search=chase
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * Uses WHERE 1=1 as a base to cleanly append AND conditions dynamically
     */
    $sql = "
        SELECT 
            id, 
            bank_name, 
            account_number, 
            currency, 
            bank_code, 
            created_at
        FROM fx_banks_table
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * Search by bank name, account number, currency, or bank code
     */
    if (!empty($search)) {
        $sql .= " AND (
            bank_name LIKE ? 
            OR account_number LIKE ? 
            OR currency LIKE ? 
            OR bank_code LIKE ?
        )";
        
        // Fuzzy match for text fields
        $likeSearch = "%" . $search . "%";
        
        $params[] = $likeSearch;
        
        // Prefix match for account number (better UX for numeric/alphanumeric identifiers)
        $params[] = $search . "%";
        
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        
        $types .= "ssss";
    }

    /**
     * Sorting and Limiting
     * Always sort by bank name ASC and limit to 100 results for performance.
     */
    $sql .= " ORDER BY bank_name ASC LIMIT 100";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    // Bind parameters if search exists
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "data" => $data
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