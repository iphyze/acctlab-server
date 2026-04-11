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
     * The frontend might send ?search=barclays
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * Uses WHERE 1=1 as a base to cleanly append AND conditions dynamically
     */
    $sql = "
        SELECT 
            suppliers_account_details.id, 
            suppliers_account_details.account_name, 
            suppliers_account_details.account_number, 
            suppliers_account_details.bank_name, 
            bank_sortcode_tab.sort_code
        FROM suppliers_account_details
        LEFT JOIN bank_sortcode_tab
        ON suppliers_account_details.bank_name = bank_sortcode_tab.bank_name
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * Search by account name, number, or bank name
     */
    if (!empty($search)) {
        $sql .= " AND (
            suppliers_account_details.account_name LIKE ? 
            OR suppliers_account_details.account_number LIKE ? 
            OR suppliers_account_details.bank_name LIKE ?
        )";
        
        // Fuzzy match for text fields
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        
        // Prefix match for account number (better UX for numeric inputs)
        $params[] = $search . "%";
        
        $params[] = $likeSearch;
        
        $types .= "sss";
    }

    /**
     * Sorting and Limiting
     * Always sort by account name ASC and limit to 100 results for performance.
     */
    $sql .= " ORDER BY suppliers_account_details.account_name ASC LIMIT 100";

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