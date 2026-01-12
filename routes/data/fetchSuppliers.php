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
        throw new Exception("Unauthorized", 401);
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';


    if(!$search && $search === ""){
        throw new Exception("Please enter a supplier's name", 400);
    }


    $query = "
        SELECT id, supplier_name, supplier_number, wht_status 
        FROM suppliers_table 
        WHERE supplier_number BETWEEN 40000000 AND 70000000
        AND (supplier_name LIKE CONCAT('%', ?, '%') OR supplier_number LIKE CONCAT('%', ?, '%'))
        ORDER BY supplier_name ASC LIMIT 100
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    // if($result->num_rows === 0){
    //     throw new Exception("No suppliers with the name $search " .  $conn->error, 404);
    // }

    $suppliers = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        "status" => "Success",
        "data" => $suppliers
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
