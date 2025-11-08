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
        throw new Exception("Unauthorized: Only Admins can update FX payments", 401);
    }

    // ✅ Read JSON input
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    // ✅ Required fields
    $required = [
        'paymentId', 'beneficiary_name', 'beneficiary_address', 'beneficiary_bank', 'beneficiary_account_number', 'reference', 'payment_bank',
        'amount_figure', 'currency', 'payment_account_number', 'currency_table', 'payment_status'
    ];

    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $paymentId = intval($data['paymentId']);
    if (!$paymentId) throw new Exception("Invalid payment ID", 400);

    // ✅ Sanitize & Assign fields
    $beneficiary_name = trim($data['beneficiary_name']);
    $beneficiary_address = trim($data['beneficiary_address']);
    $beneficiary_bank = trim($data['beneficiary_bank']);
    $beneficiary_bank_address = trim($data['beneficiary_bank_address']);
    $swift_code = trim($data['swift_code']);
    $beneficiary_account_number = trim($data['beneficiary_account_number']);
    $reference = trim($data['reference']);
    $payment_purpose = trim($data['payment_purpose']);
    $amount_figure = floatval($data['amount_figure']);
    $payment_account_number = trim($data['payment_account_number']);
    $payment_bank = trim($data['payment_bank']);
    $currency = trim($data['currency']);
    $currency_table = trim($data['currency_table']);
    $payment_status = trim($data['payment_status']);

    // Optional fields
    $bank_code = $data['bank_code'] ?? null;
    $sort_code = $data['sort_code'] ?? null;
    $account = $data['account'] ?? null;
    $intermediary_bank = $data['intermediary_bank'] ?? null;
    $intermediary_bank_swift_code = $data['intermediary_bank_swift_code'] ?? null;
    $intermediary_bank_iban = $data['intermediary_bank_iban'] ?? null;
    $domiciliation = $data['domiciliation'] ?? null;
    $code_guichet = $data['code_guichet'] ?? null;
    $compte_no = $data['compte_no'] ?? null;
    $cle_rib = $data['cle_rib'] ?? null;

    // ✅ Check if payment exists
    $check = $conn->prepare("SELECT id FROM fx_instruction_letter_table WHERE id = ?");
    $check->bind_param("i", $paymentId);
    $check->execute();
    $checkResult = $check->get_result();
    if ($checkResult->num_rows === 0) {
        throw new Exception("FX payment with ID $paymentId not found", 404);
    }
    $check->close();

    // ✅ Prevent duplicate references
    // $dup = $conn->prepare("SELECT id FROM fx_instruction_letter_table WHERE reference = ? AND id != ?");
    // $dup->bind_param("si", $reference, $paymentId);
    // $dup->execute();
    // $dupRes = $dup->get_result();
    // if ($dupRes->num_rows > 0) {
    //     throw new Exception("Duplicate entry: Reference '$reference' already exists in another record.", 400);
    // }
    // $dup->close();

    // ✅ Convert amount to words again (for updated value)
    function numToWords($number)
{
    $number = floatval($number);

    if (($number < 0) || ($number > 999999999999)) {
        return "$number";
    }

    $Bn = floor($number / 1000000000); // Billions
    $number -= $Bn * 1000000000;
    $Mn = floor($number / 1000000);    // Millions
    $number -= $Mn * 1000000;
    $kn = floor($number / 1000);       // Thousands
    $number -= $kn * 1000;
    $Hn = floor($number / 100);        // Hundreds
    $number -= $Hn * 100;
    $Dn = floor($number / 10);         // Tens
    $n = $number % 10;                 // Ones

    $res = "";

    if ($Bn) $res .= numToWords($Bn) . " Billion";
    if ($Mn) $res .= (empty($res) ? "" : " ") . numToWords($Mn) . " Million";
    if ($kn) $res .= (empty($res) ? "" : " ") . numToWords($kn) . " Thousand";
    if ($Hn) $res .= (empty($res) ? "" : " ") . numToWords($Hn) . " Hundred";

    $ones = [
        "", "One", "Two", "Three", "Four", "Five", "Six",
        "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen",
        "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"
    ];

    $tens = [
        "", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty",
        "Seventy", "Eighty", "Ninety"
    ];

    if ($Dn || $n) {
        if (!empty($res)) $res .= " ";
        if ($Dn < 2) {
            $res .= $ones[$Dn * 10 + $n];
        } else {
            $res .= $tens[$Dn];
            if ($n) $res .= " " . $ones[$n];
        }
    }

    return $res ?: "Zero";
}

    // ✅ Currency Labels
    $mainCurrency = [
        "USD" => "USD",
        "EUR" => "Euros",
        "GBP" => "Pounds",
        "ZAR" => "Rands",
        "AED" => "UAE Dirhams"
    ][$currency_table] ?? $currency_table;

    $subCurrency = [
        "USD" => "Cents",
        "EUR" => "Cents",
        "GBP" => "Cents",
        "ZAR" => "Cents",
        "AED" => "Fils"
    ][$currency_table] ?? "Cents";

    // ✅ Amount to words
    $formattedAmount = number_format($amount_figure, 2, '.', ',');
    $parts = explode('.', $formattedAmount);
    $whole = isset($parts[0]) ? str_replace(',', '', $parts[0]) : 0;
    $decimal = isset($parts[1]) ? intval($parts[1]) : 0;

    $amount_words = numToWords($whole) . " " . $mainCurrency . " & " . numToWords($decimal) . " " . $subCurrency . " Only";

    // ✅ Update record
    $update = $conn->prepare("
        UPDATE fx_instruction_letter_table SET
            beneficiary_name = ?, beneficiary_address = ?, beneficiary_bank = ?, beneficiary_bank_address = ?,
            swift_code = ?, beneficiary_account_number = ?, reference = ?, payment_purpose = ?, amount_figure = ?,
            amount_words = ?, payment_account_number = ?, payment_bank = ?, currency = ?, currency_table = ?,
            bank_code = ?, sort_code = ?, account = ?, intermediary_bank = ?, intermediary_bank_swift_code = ?,
            intermediary_bank_iban = ?, domiciliation = ?, code_guichet = ?, compte_no = ?, cle_rib = ?,
            payment_status = ?
        WHERE id = ?
    ");

    $update->bind_param(
        "ssssssssdssssssssssssssssi",
        $beneficiary_name,
        $beneficiary_address,
        $beneficiary_bank,
        $beneficiary_bank_address,
        $swift_code,
        $beneficiary_account_number,
        $reference,
        $payment_purpose,
        $amount_figure,
        $amount_words,
        $payment_account_number,
        $payment_bank,
        $currency,
        $currency_table,
        $bank_code,
        $sort_code,
        $account,
        $intermediary_bank,
        $intermediary_bank_swift_code,
        $intermediary_bank_iban,
        $domiciliation,
        $code_guichet,
        $compte_no,
        $cle_rib,
        $payment_status,
        $paymentId
    );

    if (!$update->execute()) {
        throw new Exception("Update failed: " . $update->error, 500);
    }

    // ✅ Log update
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail updated FX payment with ID $paymentId (Ref: $reference)";
    $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log_stmt->execute();
    $log_stmt->close();

    // ✅ Fetch updated data
    $fetch = $conn->prepare("SELECT * FROM fx_instruction_letter_table WHERE id = ?");
    $fetch->bind_param("i", $paymentId);
    $fetch->execute();
    $res = $fetch->get_result();
    $updatedPayment = $res->fetch_assoc();
    $fetch->close();

    echo json_encode([
        "status" => "Success",
        "message" => "FX payment updated successfully",
        "data" => $updatedPayment
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
