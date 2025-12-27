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
 *
 * This mirrors:
 *  - $TotNet = $payment_amount;
 *  - $USDollar = number_format($TotNet, 2,'.',',');
 *  - $printTotNet = numtowords($TotNet);
 *  - $explode = explode('.', $USDollar);
 *  - numtowords($explode[1]) for kobo
 */
function buildAmountInWords($amount)
{
    $curr   = "Naira";
    $points = "Kobo";

    // Ensure decimal format with exactly 2 decimal places
    $TotNet   = $amount;
    $USDollar = number_format($TotNet, 2, '.', ','); // same style as old code

    $printTotNet = numToWords($TotNet);              // naira part in words

    $explode = explode('.', $USDollar);             // separate the Kobo part (string)
    $koboStr = isset($explode[1]) ? $explode[1] : "0";

    // Kobo in words (PHP will coerce string "05" etc. to int)
    $koboWords = numToWords($koboStr);

    $printDolKobo = $printTotNet . ' ' . $curr . ' & ' . $koboWords . ' ' . $points;

    return $printDolKobo . " Only";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];
    $userEmail = $userData['email'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can create instruction letters", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    // Single entry expected (JSON object, not an array of objects)
    if (!is_array($data) || isset($data[0])) {
        throw new Exception("Invalid data format. Expected a single instruction letter object.", 400);
    }

    /**
     * Validate a single row (instruction letter payload)
     */
    function validateRow($item)
    {
        // Always required
        $baseRequired = [
            'letter_heading'   => 'Letter Heading',
            'instruction_type' => 'Instruction Type',
        ];

        foreach ($baseRequired as $key => $label) {
            if (!isset($item[$key]) || $item[$key] === '' || $item[$key] === null) {
                return $label . " is required.";
            }
        }

        // Normalize instruction_type for flexible matching
        $normalizedType = strtolower(str_replace(' ', '_', $item['instruction_type']));

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
            return "Invalid instruction type.";
        }

        foreach ($required as $key => $label) {
            if (!isset($item[$key]) || $item[$key] === '' || $item[$key] === null) {
                return $label . " is required.";
            }
        }

        // Numeric validations (where relevant)
        if (isset($item['payment_amount']) && $item['payment_amount'] !== '') {
            if (!is_numeric($item['payment_amount'])) {
                return "Payment Amount must be numeric.";
            }
        }

        // Payment date format (if present / required)
        if (isset($item['payment_date']) && $item['payment_date'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['payment_date'])) {
                return "Payment Date must be in YYYY-MM-DD format.";
            }
        }

        // Tax date formats if present
        if (!empty($item['tax_date_from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['tax_date_from'])) {
            return "Tax Date From must be in YYYY-MM-DD format.";
        }
        if (!empty($item['tax_date_to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['tax_date_to'])) {
            return "Tax Date To must be in YYYY-MM-DD format.";
        }

        return null;
    }

    // Validate single row
    $error = validateRow($data);
    if ($error) {
        http_response_code(400);
        echo json_encode([
            "status"  => "Failed",
            "message" => $error
        ]);
        exit;
    }

    // Map input
    $letter_heading   = trim($data['letter_heading']);
    $instruction_type = trim($data['instruction_type']);

    // Normalize type for logic (but store original value)
    $normalizedType = strtolower(str_replace(' ', '_', $instruction_type));

    $payment_to       = isset($data['payment_to']) ? trim($data['payment_to']) : "";
    $tax_beneficiary  = isset($data['tax_beneficiary']) ? trim($data['tax_beneficiary']) : "";
    $tax_type         = isset($data['tax_type']) ? trim($data['tax_type']) : "";
    $tax_tin          = isset($data['tax_tin']) ? trim($data['tax_tin']) : "";
    $tax_date_from    = "";
    $tax_date_to      = "";

    if ($normalizedType === 'tax_payment') {
        $tax_date_from = trim($data['tax_date_from']); // Expect YYYY-MM-DD already
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

    // Build words from amount (Naira & Kobo Only), matching old behavior as closely as possible
    $words = $payment_amount != 0 ? buildAmountInWords($payment_amount) : "";

    // Prepare insert statement into instruction_letter
    $stmt = $conn->prepare("
        INSERT INTO instruction_letter
        (
            letter_heading,
            letter_body,
            instruction_type,
            payment_to,
            tax_beneficiary,
            tax_type,
            tax_tin,
            tax_date_from,
            tax_date_to,
            payment_bank_name,
            payment_amount,
            words,
            payment_account_number,
            bank_code,
            payment_date,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare insert statement - " . $conn->error, 500);
    }

    // Types: 10x s, 1x d, 4x s => "ssssssssss" + "d" + "ssss" = "ssssssssssdssss"
    $types = "ssssssssssdssss";

    $stmt->bind_param(
        $types,
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
        $payment_date
    );

    if (!$stmt->execute()) {
        throw new Exception("Database insert failed: " . $stmt->error, 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    // Log action
    $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    if ($log_stmt) {
        $action = "$userEmail created new instruction letter with ID $id";
        $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
        $log_stmt->execute();
        $log_stmt->close();
    }

    echo json_encode([
        "status"  => "Success",
        "message" => "Instruction letter has been created successfully",
        "data"    => [
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
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
