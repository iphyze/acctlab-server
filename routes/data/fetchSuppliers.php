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
     * The frontend might send ?search=john
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * Includes the static range filter right in the WHERE clause
     */
    $sql = "
        SELECT 
            id, 
            supplier_name, 
            supplier_number, 
            wht_status 
        FROM suppliers_table 
        WHERE supplier_number BETWEEN 40000000 AND 70000000
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * Appended with AND because the WHERE clause already exists above.
     */
    if (!empty($search)) {
        $sql .= " AND (supplier_name LIKE ? OR supplier_number LIKE ?)";
        
        // Fuzzy match for name (e.g., "john" finds "Elton John")
        $params[] = "%" . $search . "%";
        
        // Prefix match for number (e.g., "45" finds "45001234" but avoids matching "12345000")
        $params[] = $search . "%";
        
        $types .= "ss";
    }

    /**
     * Sorting and Limiting
     * Always sort by name ASC and limit to 100 results for performance.
     */
    $sql .= " ORDER BY supplier_name ASC LIMIT 100";

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