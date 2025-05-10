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

    if (!$search || $search === "") {
        throw new Exception("Please enter an account name, number, or bank name", 400);
    }

    $query = "
        SELECT 
            suppliers_account_details.id, 
            suppliers_account_details.account_name, 
            suppliers_account_details.account_number, 
            suppliers_account_details.bank_name, 
            bank_sortcode_tab.sort_code
        FROM suppliers_account_details
        LEFT JOIN bank_sortcode_tab
        ON suppliers_account_details.bank_name = bank_sortcode_tab.bank_name
        WHERE 
            suppliers_account_details.account_name LIKE CONCAT('%', ?, '%')
        ORDER BY suppliers_account_details.account_name ASC LIMIT 100
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $search);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("No supplier account details found for '$search'", 404);
    }

    $suppliersAccounts = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        "status" => "Success",
        "data" => $suppliersAccounts
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
