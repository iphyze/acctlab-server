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
        throw new Exception("Please enter a project name", 400);
    }


    $query = "
        SELECT id, location, code FROM location_table WHERE (location LIKE CONCAT('%', ?, '%') OR code LIKE CONCAT('%', ?, '%'))
        ORDER BY location ASC LIMIT 50
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    $projects = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        "status" => "Success",
        "data" => $projects
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
