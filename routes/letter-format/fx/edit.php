<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can update FX bank records", 401);
    }

    // ✅ Decode JSON body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // ✅ Required fields
    $requiredFields = [
        'bankId',
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

    $bankId = intval($data['bankId']);
    if (!$bankId) {
        throw new Exception("Invalid Bank ID provided.", 400);
    }

    // ✅ Clean / assign values
    $normalizeMultiline = static function ($value): string {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)$value));
    };

    $bank_name       = trim((string)$data['bank_name']);
    $account_number  = trim((string)$data['account_number']); // keep as string (to preserve leading zeros)
    $currency        = strtoupper(trim((string)$data['currency']));
    $bank_code       = strtoupper(trim((string)$data['bank_code']));
    $letter_format   = strtoupper(trim((string)$data['letter_format']));

    if (!preg_match('/^[A-Z]{3}$/', $bank_code)) {
        throw new Exception("Bank code must be exactly 3 uppercase letters.", 400);
    }

    $allowedLetterFormats = ['ZBN', 'REGULAR', 'REGULAR LEM', 'PVB'];
    if (!in_array($letter_format, $allowedLetterFormats, true)) {
        throw new Exception("Invalid FX letter format selected.", 400);
    }

    // Optional fields. Keep user-entered line breaks, but store one canonical newline style.
    $letter_header   = isset($data['letter_header']) ? $normalizeMultiline($data['letter_header']) : '';
    $salutation      = isset($data['salutation']) ? trim((string)$data['salutation']) : '';
    $attention       = isset($data['attention']) ? trim((string)$data['attention']) : '';
    $letter_title    = isset($data['letter_title']) ? $normalizeMultiline($data['letter_title']) : '';

    // ✅ Check if record exists
    $check = $conn->prepare("SELECT id FROM fx_banks_table WHERE id = ?");
    if (!$check) {
        throw new Exception("Failed to prepare existence check query: " . $conn->error, 500);
    }
    $check->bind_param("i", $bankId);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("FX bank record with ID $bankId not found", 404);
    }
    $check->close();

    // ✅ Check for duplicate (bank_name + account_number), excluding this ID
    $dup = $conn->prepare("
        SELECT id 
        FROM fx_banks_table 
        WHERE bank_name = ? AND account_number = ? AND id != ?
        LIMIT 1
    ");
    if (!$dup) {
        throw new Exception("Failed to prepare duplicate check query: " . $conn->error, 500);
    }

    $dup->bind_param("ssi", $bank_name, $account_number, $bankId);
    $dup->execute();
    $dupResult = $dup->get_result();

    if ($dupResult->num_rows > 0) {
        throw new Exception("Duplicate record. Bank '$bank_name' with account number '$account_number' already exists.", 400);
    }
    $dup->close();

    // ✅ Update record in fx_banks_table
    $update = $conn->prepare("
        UPDATE fx_banks_table 
        SET 
            bank_name = ?,
            account_number = ?,
            currency = ?,
            bank_code = ?,
            letter_header = ?,
            salutation = ?,
            attention = ?,
            letter_title = ?,
            letter_format = ?
        WHERE id = ?
    ");

    if (!$update) {
        throw new Exception("Failed to prepare update query: " . $conn->error, 500);
    }

    // Bind all as strings except id (you can bind id as string too; MySQL will cast)
    $update->bind_param(
        "sssssssssi",
        $bank_name,
        $account_number,
        $currency,
        $bank_code,
        $letter_header,
        $salutation,
        $attention,
        $letter_title,
        $letter_format,
        $bankId
    );

    if (!$update->execute()) {
        throw new Exception("Update failed: " . $update->error, 500);
    }
    $update->close();

    // ✅ Log update
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    if ($log_stmt) {
        $action = "$userEmail updated FX bank record with ID $bankId ($bank_name - $account_number)";
        $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
        $log_stmt->execute();
        $log_stmt->close();
    }

    // ✅ Fetch updated record
    $fetchData = $conn->prepare("SELECT * FROM fx_banks_table WHERE id = ?");
    if (!$fetchData) {
        throw new Exception("Failed to prepare fetch updated record query: " . $conn->error, 500);
    }

    $fetchData->bind_param("i", $bankId);
    $fetchData->execute();
    $dataResult  = $fetchData->get_result();
    $fetchedData = $dataResult->fetch_assoc();
    $fetchData->close();

    echo json_encode([
        "status"  => "Success",
        "message" => "FX bank record updated successfully",
        "data"    => $fetchedData
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
