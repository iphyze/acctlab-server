<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * Convert integer number to words (up to hundreds of billions).
 * Mirrors the old numtowords() logic.
 */
function numToWords($number)
{
    $number = (int)$number;

    if ($number < 0 || $number > 999999999999) {
        return (string)$number;
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
    $n  = $number % 10;                // Ones

    $res = "";

    if ($Bn) {
        $res .= numToWords($Bn) . " Billion";
    }
    if ($Mn) {
        $res .= (empty($res) ? "" : " ") . numToWords($Mn) . " Million";
    }
    if ($kn) {
        $res .= (empty($res) ? "" : " ") . numToWords($kn) . " Thousand";
    }
    if ($Hn) {
        $res .= (empty($res) ? "" : " ") . numToWords($Hn) . " Hundred";
    }

    $ones = [
        "", "One", "Two", "Three", "Four", "Five", "Six",
        "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve",
        "Thirteen", "Fourteen", "Fifteen", "Sixteen",
        "Seventeen", "Eighteen", "Nineteen"
    ];
    $tens = [
        "", "", "Twenty", "Thirty", "Forty", "Fifty",
        "Sixty", "Seventy", "Eighty", "Ninety"
    ];

    if ($Dn || $n) {
        if (!empty($res)) {
            $res .= " ";
        }

        if ($Dn < 2) {
            $res .= $ones[$Dn * 10 + $n];
        } else {
            $res .= $tens[$Dn];
            if ($n) {
                $res .= " " . $ones[$n];
            }
        }
    }

    if (empty($res)) {
        $res = "Zero";
    }

    return $res;
}

/**
 * Build the "words" string like the old implementation:
 * e.g. "Ten Thousand Naira & Fifty Kobo Only"
 */
function buildAmountInWords($amount)
{
    $curr   = "Naira";
    $points = "Kobo";

    $TotNet   = $amount;
    $USDollar = number_format($TotNet, 2, '.', ','); // same style as old code

    $printTotNet = numToWords($TotNet);              // naira part in words

    $explode = explode('.', $USDollar);              // separate the Kobo part (string)
    $koboStr = isset($explode[1]) ? $explode[1] : "0";

    // Kobo in words (PHP will coerce string "05" etc. to int)
    $koboWords = numToWords($koboStr);

    $printDolKobo = $printTotNet . ' ' . $curr . ' & ' . $koboWords . ' ' . $points;

    return $printDolKobo . " Only";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $userEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can edit instruction letters", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format", 400);
    }

    // --- VALIDATION ---

    // Always required
    $alwaysRequired = [
        'id'              => 'ID',
        'letter_heading'  => 'Letter Heading',
        'instruction_type'=> 'Instruction Type',
    ];

    foreach ($alwaysRequired as $key => $label) {
        if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
            throw new Exception("$label is required", 400);
        }
    }

    $id = intval($data['id']);
    if ($id <= 0) {
        throw new Exception("ID must be a valid number", 400);
    }

    // Normalize instruction_type for flexible matching
    $normalizedType = strtolower(str_replace(' ', '_', $data['instruction_type']));

    // Type-specific required fields
    if ($normalizedType === 'inter_bank_transfer') {
        $required = [
            'payment_to'             => 'Payment To',
            'payment_bank_name'      => 'Payment Bank Name',
            'payment_account_number' => 'Payment Account Number',
            'payment_amount'         => 'Payment Amount',
            'payment_date'           => 'Payment Date',
            'bank_code'              => 'Bank Code',
        ];
    } elseif ($normalizedType === 'tax_payment') {
        $required = [
            'tax_beneficiary'        => 'Tax Beneficiary',
            'tax_type'               => 'Tax Type',
            'tax_tin'                => 'Tax TIN',
            'tax_date_from'          => 'Tax Date From',
            'tax_date_to'            => 'Tax Date To',
            'payment_amount'         => 'Payment Amount',
            'payment_account_number' => 'Payment Account Number',
            'bank_code'              => 'Bank Code',
            'payment_date'           => 'Payment Date',
        ];
    } elseif ($normalizedType === 'other_transfers') {
        $required = [
            'payment_date'           => 'Payment Date',
            'payment_account_number' => 'Payment Account Number',
            'bank_code'              => 'Bank Code',
            'letter_body'            => 'Letter Body',
        ];
    } else {
        throw new Exception("Invalid instruction type", 400);
    }

    foreach ($required as $key => $label) {
        if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
            throw new Exception("$label is required", 400);
        }
    }

    // Numeric validations
    if (isset($data['payment_amount']) && $data['payment_amount'] !== '') {
        if (!is_numeric($data['payment_amount'])) {
            throw new Exception("Payment Amount must be numeric", 400);
        }
    }

    // Payment date format
    if (isset($data['payment_date']) && $data['payment_date'] !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['payment_date'])) {
            throw new Exception("Payment Date must be in YYYY-MM-DD format", 400);
        }
    }

    // Tax date formats if present/required
    if ($normalizedType === 'tax_payment') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['tax_date_from'])) {
            throw new Exception("Tax Date From must be in YYYY-MM-DD format", 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['tax_date_to'])) {
            throw new Exception("Tax Date To must be in YYYY-MM-DD format", 400);
        }
    } else {
        // If not tax payment but dates are provided, still validate if non-empty
        if (!empty($data['tax_date_from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['tax_date_from'])) {
            throw new Exception("Tax Date From must be in YYYY-MM-DD format", 400);
        }
        if (!empty($data['tax_date_to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['tax_date_to'])) {
            throw new Exception("Tax Date To must be in YYYY-MM-DD format", 400);
        }
    }

    // Check if the record exists
    $checkStmt = $conn->prepare("SELECT id FROM instruction_letter WHERE id = ?");
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        $checkStmt->close();
        throw new Exception("Instruction letter with ID $id not found", 404);
    }
    $checkStmt->close();

    // --- MAPPING VALUES ---

    $letter_heading   = trim($data['letter_heading']);
    $instruction_type = trim($data['instruction_type']); // store as-is

    $payment_to       = isset($data['payment_to']) ? trim($data['payment_to']) : "";
    $tax_beneficiary  = isset($data['tax_beneficiary']) ? trim($data['tax_beneficiary']) : "";
    $tax_type         = isset($data['tax_type']) ? trim($data['tax_type']) : "";
    $tax_tin          = isset($data['tax_tin']) ? trim($data['tax_tin']) : "";
    $tax_date_from    = "";
    $tax_date_to      = "";

    if ($normalizedType === 'tax_payment') {
        $tax_date_from = trim($data['tax_date_from']);
        $tax_date_to   = trim($data['tax_date_to']);
    }

    $payment_amount         = isset($data['payment_amount']) && $data['payment_amount'] !== ''
        ? (float)$data['payment_amount']
        : 0.0;

    $letter_body            = isset($data['letter_body']) ? trim($data['letter_body']) : "";
    $payment_account_number = trim($data['payment_account_number']);
    $payment_date           = isset($data['payment_date']) ? trim($data['payment_date']) : "";
    $payment_bank_name      = isset($data['payment_bank_name']) ? trim($data['payment_bank_name']) : "";
    $bank_code              = trim($data['bank_code']);

    // Rebuild words from amount (must match create behavior)
    $words = $payment_amount != 0 ? buildAmountInWords($payment_amount) : "";

    // --- UPDATE STATEMENT ---

    $stmt = $conn->prepare("
        UPDATE instruction_letter
        SET
            letter_heading        = ?,
            letter_body           = ?,
            instruction_type      = ?,
            payment_to            = ?,
            tax_beneficiary       = ?,
            tax_type              = ?,
            tax_tin               = ?,
            tax_date_from         = ?,
            tax_date_to           = ?,
            payment_bank_name     = ?,
            payment_amount        = ?,
            words                 = ?,
            payment_account_number= ?,
            bank_code             = ?,
            payment_date          = ?
        WHERE id = ?
    ");
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement - " . $conn->error, 500);
    }

    // Types: 10x s, 1x d, 4x s, 1x i => "ssssssssss" + "d" + "ssss" + "i" = "ssssssssssdssssi"
    $stmt->bind_param(
        "ssssssssssdssssi",
        $letter_heading,
        $letter_body,
        $instruction_type,
        $payment_to,
        $tax_beneficiary,
        $tax_type,
        $tax_tin,
        $tax_date_from,
        $tax_date_to,
        $payment_bank_name,
        $payment_amount,
        $words,
        $payment_account_number,
        $bank_code,
        $payment_date,
        $id
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Failed to update instruction letter: " . $stmt->error, 500);
    }

    $stmt->close();

    // Insert log entry
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    if ($logStmt) {
        $action = "$userEmail updated instruction letter with ID $id";
        $created_by = $userEmail;
        $logStmt->bind_param("iss", $loggedInUserId, $action, $created_by);
        $logStmt->execute();
        $logStmt->close();
    }

    // Return updated data
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Instruction letter updated successfully",
        "data" => [
            "id"                     => $id,
            "letter_heading"         => $letter_heading,
            "instruction_type"       => $instruction_type,
            "payment_to"             => $payment_to,
            "tax_beneficiary"        => $tax_beneficiary,
            "tax_type"               => $tax_type,
            "tax_tin"                => $tax_tin,
            "tax_date_from"          => $tax_date_from,
            "tax_date_to"            => $tax_date_to,
            "payment_bank_name"      => $payment_bank_name,
            "payment_amount"         => $payment_amount,
            "words"                  => $words,
            "payment_account_number" => $payment_account_number,
            "bank_code"              => $bank_code,
            "payment_date"           => $payment_date
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
