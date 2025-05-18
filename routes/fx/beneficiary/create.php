<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can create bank beneficiary records", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected a JSON object.", 400);
    }

    // Required fields
    $requiredFields = [
        'beneficiary_name',
        'beneficiary_address',
        'beneficiary_bank',
        'beneficiary_bank_address',
        'swift_code',
        'beneficiary_account_number'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Individual trim as in reference
    $beneficiary_name = trim($data['beneficiary_name']);
    $beneficiary_address = trim($data['beneficiary_address']);
    $beneficiary_bank = trim($data['beneficiary_bank']);
    $beneficiary_bank_address = trim($data['beneficiary_bank_address']);
    $swift_code = trim($data['swift_code']);
    $beneficiary_account_number = trim($data['beneficiary_account_number']);

    $bank_code = isset($data['bank_code']) ? trim($data['bank_code']) : null;
    $account = isset($data['account']) ? trim($data['account']) : null;
    $sort_code = isset($data['sort_code']) ? trim($data['sort_code']) : null;
    $intermediary_bank = isset($data['intermediary_bank']) ? trim($data['intermediary_bank']) : null;
    $intermediary_bank_swift_code = isset($data['intermediary_bank_swift_code']) ? trim($data['intermediary_bank_swift_code']) : null;
    $intermediary_bank_iban = isset($data['intermediary_bank_iban']) ? trim($data['intermediary_bank_iban']) : null;
    $domiciliation = isset($data['domiciliation']) ? trim($data['domiciliation']) : null;
    $code_guichet = isset($data['code_guichet']) ? trim($data['code_guichet']) : null;
    $compte_no = isset($data['compte_no']) ? trim($data['compte_no']) : null;
    $cle_rib = isset($data['cle_rib']) ? trim($data['cle_rib']) : null;

    // Check for duplicate
    $checkStmt = $conn->prepare("SELECT id FROM bank_beneficiary_details_table WHERE beneficiary_name = ? AND beneficiary_account_number = ?");
    $checkStmt->bind_param("ss", $beneficiary_name, $beneficiary_account_number);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        throw new Exception("Duplicate entry: Beneficiary with $beneficiary_name and $beneficiary_account_number already exists.", 400);
    }

    // Insert new record
    $insertStmt = $conn->prepare("
        INSERT INTO bank_beneficiary_details_table (
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
            cle_rib
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertStmt->bind_param(
        "ssssssssssssssss",
        $beneficiary_name,
        $beneficiary_address,
        $beneficiary_bank,
        $beneficiary_bank_address,
        $swift_code,
        $beneficiary_account_number,
        $bank_code,
        $account,
        $sort_code,
        $intermediary_bank,
        $intermediary_bank_swift_code,
        $intermediary_bank_iban,
        $domiciliation,
        $code_guichet,
        $compte_no,
        $cle_rib
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;

    // Log the creation
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $logAction = "$userEmail created a new FX bank beneficiary record with ID $insertedId";
    $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
    $logStmt->execute();

    echo json_encode([
        "status" => "Success",
        "message" => "Bank beneficiary details created successfully",
        "data" => [
            "id" => $insertedId,
            "beneficiary_name" => $beneficiary_name,
            "beneficiary_address" => $beneficiary_address,
            "beneficiary_bank" => $beneficiary_bank,
            "beneficiary_bank_address" => $beneficiary_bank_address,
            "swift_code" => $swift_code,
            "beneficiary_account_number" => $beneficiary_account_number,
            "bank_code" => $bank_code,
            "account" => $account,
            "sort_code" => $sort_code,
            "intermediary_bank" => $intermediary_bank,
            "intermediary_bank_swift_code" => $intermediary_bank_swift_code,
            "intermediary_bank_iban" => $intermediary_bank_iban,
            "domiciliation" => $domiciliation,
            "code_guichet" => $code_guichet,
            "compte_no" => $compte_no,
            "cle_rib" => $cle_rib
        ]
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
