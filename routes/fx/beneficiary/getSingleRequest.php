<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure UTF-8 encoding
$conn->set_charset("utf8mb4");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    // ✅ Validate beneficiaryId
    if (!isset($_GET['beneficiaryId']) || !is_numeric($_GET['beneficiaryId'])) {
        throw new Exception("Missing or invalid parameter: 'beneficiaryId' is required and must be numeric.", 400);
    }

    $beneficiaryId = (int) $_GET['beneficiaryId'];

    // ✅ Fetch beneficiary details
    $stmt = $conn->prepare("
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
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $beneficiaryId);
    $stmt->execute();

    $result = $stmt->get_result();
    $record = $result->fetch_assoc();

    $stmt->close();

    // ❌ No record found
    if (!$record) {
        throw new Exception("No bank beneficiary found for ID: $beneficiaryId", 404);
    }

    // Ensure proper encoding
    foreach ($record as $key => $value) {
        if (is_string($value)) {
            $record[$key] = utf8_encode($value);
        }
    }

    // ✅ Return response
    echo json_encode([
        "status"  => "Success",
        "message" => "Bank beneficiary details fetched successfully",
        "data"    => $record
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
