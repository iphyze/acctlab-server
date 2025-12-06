<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized", 401);
    }

    // ✅ Get and validate search
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    if ($search === '') {
        throw new Exception("Please enter a bank name, account number, or currency", 400);
    }

    // ✅ Prepare query for local_banks
    $query = "
        SELECT 
            id,
            bank_name,
            account_number,
            currency,
            bank_code,
            letter_header,
            salutation,
            attention,
            letter_title,
            created_at
        FROM local_banks
        WHERE 
            bank_name LIKE CONCAT('%', ?, '%')
            OR account_number LIKE CONCAT('%', ?, '%')
            OR currency LIKE CONCAT('%', ?, '%')
            OR bank_code LIKE CONCAT('%', ?, '%')
        ORDER BY bank_name ASC
        LIMIT 100
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    // ✅ Bind search term
    $stmt->bind_param("ssss", $search, $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    // ✅ Fetch results
    $banks = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        "status" => "Success",
        "data" => $banks
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
