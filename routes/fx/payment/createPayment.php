<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

// ✅ Reliable helper: Convert number to words
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can create FX instruction records", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ✅ Required Fields
    $required = [
        'beneficiary_name', 'beneficiary_address', 'beneficiary_bank', 'beneficiary_account_number', 'reference', 'payment_bank',
        'amount_figure', 'currency', 'payment_account_number', 'currency_table'
    ];

    foreach ($required as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '$field' is required.", 400);
        }
    }

    // ✅ Assign Values
    $beneficiary_name = trim($data['beneficiary_name']);
    $beneficiary_address = trim($data['beneficiary_address']);
    $beneficiary_bank = trim($data['beneficiary_bank']);
    $beneficiary_bank_address = trim($data['beneficiary_bank_address']);
    $swift_code = trim($data['swift_code']);
    $beneficiary_account_number = trim($data['beneficiary_account_number']);
    $payment_bank = $data['payment_bank'] ?? null;
    $bank_code = $data['bank_code'] ?? null;
    $account = $data['account'] ?? null;
    $sort_code = $data['sort_code'] ?? null;
    $intermediary_bank = $data['intermediary_bank'] ?? null;
    $intermediary_bank_swift_code = $data['intermediary_bank_swift_code'] ?? null;
    $intermediary_bank_iban = $data['intermediary_bank_iban'] ?? null;
    $domiciliation = $data['domiciliation'] ?? null;
    $code_guichet = $data['code_guichet'] ?? null;
    $compte_no = $data['compte_no'] ?? null;
    $cle_rib = $data['cle_rib'] ?? null;

    $reference = trim($data['reference']);
    $payment_purpose = trim($data['payment_purpose']);
    $amount_figure = floatval(trim($data['amount_figure']));
    $payment_account_number = trim($data['payment_account_number']);
    $currency_table = trim($data['currency_table']);
    $currency = trim($data['currency']);
    $payment_date = date('Y-m-d H:i:s');

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

    // ✅ Default Payment Status
    $defaultStatus = "Pending";

    // ✅ Insert new FX instruction
    $insertStmt = $conn->prepare("
        INSERT INTO fx_instruction_letter_table (
            beneficiary_name, beneficiary_address, beneficiary_bank, beneficiary_bank_address,
            swift_code, beneficiary_account_number, reference, payment_purpose, amount_figure,
            amount_words, payment_account_number, payment_bank, currency, currency_table,
            payment_date, bank_code, sort_code, account, intermediary_bank,
            intermediary_bank_swift_code, intermediary_bank_iban, domiciliation, code_guichet,
            compte_no, cle_rib, payment_status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertStmt->bind_param(
        "ssssssssdsssssssssssssssss",
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
        $payment_date,
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
        $defaultStatus
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Database insert failed: " . $insertStmt->error, 500);
    }

    $insertedId = $insertStmt->insert_id;
    $insertStmt->close();

    // ✅ Log creation
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $logAction = "$userEmail created a new FX instruction with ID $insertedId (Ref: $reference)";
    $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "FX Instruction created successfully",
        "data" => [
            "id" => $insertedId,
            "reference" => $reference,
            "beneficiary_name" => $beneficiary_name,
            "amount_figure" => $amount_figure,
            "amount_words" => $amount_words,
            "currency" => $currency,
            "payment_bank" => $payment_bank,
            "payment_account_number" => $payment_account_number,
            "payment_status" => $defaultStatus
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

?>
