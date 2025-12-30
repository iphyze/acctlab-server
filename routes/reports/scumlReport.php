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

    // Period handling
    if (isset($_GET['period_from']) && isset($_GET['period_to'])) {

        // Use provided dates
        $periodFrom = $_GET['period_from'];
        $periodTo   = $_GET['period_to'];

        $fromDate = new DateTime($periodFrom);
        $toDate   = new DateTime($periodTo);

        if ($fromDate > $toDate) {
            throw new Exception("'period_from' cannot be later than 'period_to'", 400);
        }

        // Ensure max 31 days
        $dateDiff = $fromDate->diff($toDate)->days;
        if ($dateDiff > 31) {
            throw new Exception("Date range cannot exceed one month (31 days)", 400);
        }

    } else {
        /**
         * Default: Last 7 days (including today)
         */
        $toDate = new DateTime(); // today
        $fromDate = (clone $toDate)->modify('-6 days'); // last 7 days total

        $periodFrom = $fromDate->format('Y-m-d');
        $periodTo   = $toDate->format('Y-m-d');
    }


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
            payment_bank_name as beneficiary_bank_name,
            payment_to as beneficiary_account_number,
            payment_account_number as paid_from,
            bank_code,
            payment_date,
            payment_amount
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
            account_name as beneficiary_account_name,
            account_number as beneficiary_bank_number,
            bank_name as beneficiary_bank_name,
            payment_date,
            payment_amount,
            batch
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
            beneficiary_name as beneficiary_account_name,
            beneficiary_account_number as beneficiary_account_number,
            payment_account_number as paid_from,
            payment_bank as bank_code,
            currency,
            payment_date,
            amount_figure as payment_amount
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
            beneficiary_name as beneficiary_account_name,
            account_number as beneficiary_account_number,
            ben_bank_name as beneficiary_bank_name,
            payment_account_number as paid_from,
            amount as payment_amount,
            date as payment_date,
            batch
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
            bank_name as beneficiary_bank_name,
            account_number as beneficiary_account_number,
            account_name as beneficiary_account_name,
            payment_date,
            payment_amount,
            batch
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
            bank_name as beneficiary_bank_name,
            account_number as beneficiary_account_number,
            account_name as beneficiary_account_name,
            payment_date,
            payment_amount,
            batch
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
