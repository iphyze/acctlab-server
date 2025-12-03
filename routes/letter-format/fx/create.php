<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if ($userIntegrity !== 'Admin' && $userIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can create FX bank records", 401);
    }

    // ✅ Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected a JSON object.", 400);
    }

    // ✅ Required fields for fx_banks_table
    $requiredFields = [
        'bank_name',
        'account_number',
        'currency',
        'bank_code',
        'letter_format'
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || trim((string)$data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // ✅ Clean / extract data
    $bank_name       = trim($data['bank_name']);
    $account_number  = trim($data['account_number']); // keep as string (to preserve leading zeros)
    $currency        = trim($data['currency']);
    $bank_code       = trim($data['bank_code']);
    $letter_format   = trim($data['letter_format']);

    // Optional fields
    $letter_header   = isset($data['letter_header'])   ? trim($data['letter_header'])   : '';
    $salutation      = isset($data['salutation'])      ? trim($data['salutation'])      : '';
    $attention       = isset($data['attention'])       ? trim($data['attention'])       : '';
    $letter_title    = isset($data['letter_title'])    ? trim($data['letter_title'])    : '';

    // ✅ Check for duplicate (bank_name + account_number)
    $dupQuery = $conn->prepare("
        SELECT id 
        FROM fx_banks_table 
        WHERE bank_name = ? AND account_number = ?
        LIMIT 1
    ");
    if (!$dupQuery) {
        throw new Exception("Failed to prepare duplicate check query: " . $conn->error, 500);
    }

    $dupQuery->bind_param("ss", $bank_name, $account_number);
    $dupQuery->execute();
    $dupResult = $dupQuery->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate record. Bank '{$bank_name}' with account number '{$account_number}' already exists.", 400);
    }
    $dupQuery->close();

    // ✅ Insert into fx_banks_table
    $insertStmt = $conn->prepare("
        INSERT INTO fx_banks_table
        (
            bank_name,
            account_number,
            currency,
            bank_code,
            letter_header,
            salutation,
            attention,
            letter_title,
            letter_format
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$insertStmt) {
        throw new Exception("Database insert prepare failed: " . $conn->error, 500);
    }

    $insertStmt->bind_param(
        "sssssssss",
        $bank_name,
        $account_number,
        $currency,
        $bank_code,
        $letter_header,
        $salutation,
        $attention,
        $letter_title,
        $letter_format
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;
    $insertStmt->close();

    // ✅ Log action
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    if ($log_stmt) {
        $action = "$userEmail created a new FX bank record with ID $insertedId ($bank_name - $account_number)";
        $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
        $log_stmt->execute();
        $log_stmt->close();
    }

    // ✅ Response
    echo json_encode([
        "status"  => "Success",
        "message" => "FX bank record created successfully",
        "data"    => [
            "id"             => $insertedId,
            "bank_name"      => $bank_name,
            "account_number" => $account_number,
            "currency"       => $currency,
            "bank_code"      => $bank_code,
            "letter_header"  => $letter_header,
            "salutation"     => $salutation,
            "attention"      => $attention,
            "letter_title"   => $letter_title,
            "letter_format"  => $letter_format
        ]
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
