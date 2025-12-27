<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    // Validate period filters
    if (!isset($_GET['period_from']) || !isset($_GET['period_to'])) {
        throw new Exception("Missing required parameters: 'period_from' and 'period_to'", 400);
    }

    $periodFrom = $_GET['period_from'];
    $periodTo   = $_GET['period_to'];

    // Validate date format & range
    $fromDate = new DateTime($periodFrom);
    $toDate   = new DateTime($periodTo);

    if ($fromDate > $toDate) {
        throw new Exception("'period_from' cannot be later than 'period_to'", 400);
    }

    // Calculate difference in days
    $dateDiff = $fromDate->diff($toDate)->days;

    if ($dateDiff > 31) {
        throw new Exception("Date range cannot exceed one month (31 days)", 400);
    }


    $responseData = [];

    /**
     * 1. Instruction Letter Table
     */
    $stmt = $conn->prepare("
        SELECT 
            payment_to,
            payment_bank_name,
            payment_account_number,
            bank_code,
            payment_date,
            payment_amount,
            instruction_type
        FROM instruction_letter
        WHERE payment_date BETWEEN ? AND ?
        ORDER BY payment_date DESC
    ");
    $stmt->bind_param("ss", $periodFrom, $periodTo);
    $stmt->execute();
    $responseData['instruction_letter'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * 2. Advance Payment Schedule
     */
    $stmt = $conn->prepare("
        SELECT 
            payment_amount,
            payment_date,
            remark,
            suppliers_name,
            bank_name,
            account_name,
            account_number
        FROM advance_payment_schedule_tab
        WHERE payment_date BETWEEN ? AND ?
        ORDER BY payment_date DESC
    ");
    $stmt->bind_param("ss", $periodFrom, $periodTo);
    $stmt->execute();
    $responseData['advance_payment_schedule'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * 3. FX Instruction Letter
     */
    $stmt = $conn->prepare("
        SELECT 
            beneficiary_name,
            beneficiary_address,
            beneficiary_bank,
            beneficiary_bank_address,
            swift_code,
            beneficiary_account_number,
            reference,
            payment_purpose,
            amount_figure,
            payment_account_number,
            payment_bank,
            currency,
            payment_date,
            bank_code
        FROM fx_instruction_letter_table
        WHERE payment_date BETWEEN ? AND ?
        ORDER BY payment_date DESC
    ");
    $stmt->bind_param("ss", $periodFrom, $periodTo);
    $stmt->execute();
    $responseData['fx_instruction_letter'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * 4. Local Transfer
     * (Uses `date` column for matching)
     */
    $stmt = $conn->prepare("
        SELECT 
            beneficiary_name,
            account_number,
            ben_bank_name,
            payment_account_number,
            created_at,
            date
        FROM local_transfer
        WHERE date BETWEEN ? AND ?
        ORDER BY date DESC
    ");
    $stmt->bind_param("ss", $periodFrom, $periodTo);
    $stmt->execute();
    $responseData['local_transfer'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * 5. Other Payment Schedule
     */
    $stmt = $conn->prepare("
        SELECT 
            payment_amount,
            payment_date,
            suppliers_name,
            bank_name,
            account_name,
            account_number
        FROM other_payment_schedule
        WHERE payment_date BETWEEN ? AND ?
        ORDER BY payment_date DESC
    ");
    $stmt->bind_param("ss", $periodFrom, $periodTo);
    $stmt->execute();
    $responseData['other_payment_schedule'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * 6. Payment Schedule
     */
    $stmt = $conn->prepare("
        SELECT 
            payment_amount,
            payment_date,
            suppliers_name,
            bank_name,
            account_name,
            account_number
        FROM payment_schedule_tab
        WHERE payment_date BETWEEN ? AND ?
        ORDER BY payment_date DESC
    ");
    $stmt->bind_param("ss", $periodFrom, $periodTo);
    $stmt->execute();
    $responseData['payment_schedule'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Payments fetched successfully by period",
        "meta" => [
            "period_from" => $periodFrom,
            "period_to" => $periodTo
        ],
        "data" => $responseData
    ]);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
