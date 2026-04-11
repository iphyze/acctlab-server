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
     * The frontend might send ?search=london
     */
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    /**
     * Prepare Base Query
     * Uses WHERE 1=1 as a base to cleanly append AND conditions dynamically
     */
    $sql = "
        SELECT 
            id, 
            location, 
            code 
        FROM location_table 
        WHERE 1=1
    ";

    $params = [];
    $types = "";

    /**
     * Search Filter Logic
     * Search by location name or code
     */
    if (!empty($search)) {
        $sql .= " AND (location LIKE ? OR code LIKE ?)";
        
        // Fuzzy match for location name
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        
        // Prefix match for code (better UX for alphanumeric/numeric identifiers)
        $params[] = $search . "%";
        
        $types .= "ss";
    }

    /**
     * Sorting and Limiting
     * Always sort by location ASC and limit to 100 results for performance.
     */
    $sql .= " ORDER BY location ASC LIMIT 100";

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