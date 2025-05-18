<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can update bank beneficiaries", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format", 400);
    }

    $requiredFields = ['beneficiaryId', 'beneficiary_name', 'beneficiary_address', 'beneficiary_bank', 'beneficiary_bank_address', 'swift_code', 'beneficiary_account_number'];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $beneficiaryId = intval($data['beneficiaryId']);
    if (!$beneficiaryId) throw new Exception("Invalid beneficiary ID", 400);

    // Sanitize inputs individually
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

    // Check if record exists
    $check = $conn->prepare("SELECT id FROM bank_beneficiary_details_table WHERE id = ?");
    $check->bind_param("i", $beneficiaryId);
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Beneficiary with ID $id not found", 404);
    }
    $check->close();


    $dup = $conn->prepare("SELECT id FROM bank_beneficiary_details_table WHERE beneficiary_name = ? AND beneficiary_account_number = ? AND id != ?");
    $dup->bind_param("ssi", $beneficiary_name, $beneficiary_account_number, $beneficiaryId);
    $dup->execute();
    $dupResult = $dup->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate entry: Beneficiary with $beneficiary_name and $beneficiary_account_number already exists.", 400);
    }

    // Update the record
    $update = $conn->prepare("
        UPDATE bank_beneficiary_details_table SET 
            beneficiary_name = ?, 
            beneficiary_address = ?, 
            beneficiary_bank = ?, 
            beneficiary_bank_address = ?, 
            swift_code = ?, 
            beneficiary_account_number = ?, 
            bank_code = ?, 
            account = ?, 
            sort_code = ?, 
            intermediary_bank = ?, 
            intermediary_bank_swift_code = ?, 
            intermediary_bank_iban = ?, 
            domiciliation = ?, 
            code_guichet = ?, 
            compte_no = ?, 
            cle_rib = ?
        WHERE id = ?
    ");

    $update->bind_param(
        "ssssssssssssssssi",
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
        $cle_rib,
        $beneficiaryId
    );

    if (!$update->execute()) {
        throw new Exception("Update failed: " . $update->error, 500);
    }

    // Log update
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail updated fx bank beneficiary with ID $beneficiaryId";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    // Return updated data
    $fetch = $conn->prepare("SELECT * FROM bank_beneficiary_details_table WHERE id = ?");
    $fetch->bind_param("i", $beneficiaryId);
    $fetch->execute();
    $fetchResult = $fetch->get_result();
    $updatedData = $fetchResult->fetch_assoc();
    $fetch->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Bank beneficiary details updated successfully",
        "data" => $updatedData
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
