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
        throw new Exception("Unauthorized", 401);
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if ($search === '') {
        throw new Exception("Please enter a bank name, sort code, or code name", 400);
    }

    $query = "
        SELECT 
            id,
            bank_name,
            sort_code,
            code_name
        FROM bank_sortcode_tab
        WHERE 
            bank_name LIKE CONCAT('%', ?, '%')
            OR sort_code LIKE CONCAT('%', ?, '%')
            OR code_name LIKE CONCAT('%', ?, '%')
        ORDER BY bank_name ASC
        LIMIT 100
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    // Bind same search value to all LIKE conditions
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    $bankSortCodes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode([
        "status" => "Success",
        "data" => $bankSortCodes
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
