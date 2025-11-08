<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate the user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized", 401);
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if ($search === '') {
        throw new Exception("Please enter a beneficiary name, account number, or bank name", 400);
    }

    // Query from the new table
    $query = "
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
        WHERE 
            beneficiary_name LIKE CONCAT('%', ?, '%')
            OR beneficiary_account_number LIKE CONCAT('%', ?, '%')
            OR beneficiary_bank LIKE CONCAT('%', ?, '%')
        ORDER BY beneficiary_name ASC
        LIMIT 100
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    // Bind parameters for all three search conditions
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();

    $beneficiaries = $result->fetch_all(MYSQLI_ASSOC);

    // Optionally, you can throw an exception if nothing is found:
    // if (empty($beneficiaries)) {
    //     throw new Exception("No matching beneficiary records found for '$search'", 404);
    // }

    echo json_encode([
        "status" => "Success",
        "data" => $beneficiaries
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
