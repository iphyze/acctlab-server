<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

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
        throw new Exception("Unauthorized: Only Admins can create local transfers", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data) || empty($data)) {
        throw new Exception("Invalid data format. Expected a non-empty array of objects.", 400);
    }

    function validateRow($item, $index)
    {
        $requiredFields = [
            'beneficiary_name' => 'Beneficiary Name',
            'account_number' => 'Account Number',
            'ben_bank_name' => 'Beneficiary Bank Name',
            'payment_account_number' => 'Payment Account Number',
            'payment_category' => 'Payment Category',
            'batch' => 'Batch',
            'amount' => 'Amount',
            'date' => 'Date'
        ];

        foreach ($requiredFields as $key => $label) {
            if (!isset($item[$key]) || $item[$key] === '' || $item[$key] === null) {
                // ensure correct row number (human-friendly)
                return "Row " . ($index + 1) . ": " . $label . " is required.";
            }
        }

        // Additional simple validations
        if (!is_numeric($item['batch'])) {
            return "Row " . ($index + 1) . ": Batch must be a numeric value.";
        }
        if (!is_numeric($item['amount'])) {
            return "Row " . ($index + 1) . ": Amount must be a numeric value.";
        }
        // Optionally validate date format (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['date'])) {
            return "Row " . ($index + 1) . ": Date must be in YYYY-MM-DD format.";
        }

        return null;
    }

    // Validate all rows
    foreach ($data as $index => $item) {
        $error = validateRow($item, $index);
        if ($error) {
            http_response_code(400);
            echo json_encode([
                "status" => "Failed",
                "message" => $error
            ]);
            exit;
        }
    }

    // Prepare insert statement
    // created_at set to NOW(), created_by is the authenticated user id
    $stmt = $conn->prepare("
        INSERT INTO local_transfer
        (beneficiary_name, account_number, ben_bank_name, payment_account_number, payment_category, batch, amount, created_at, created_by, `date`)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ");
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement - " . $conn->error, 500);
    }

    foreach ($data as $item) {
        $beneficiary_name = trim($item['beneficiary_name']);
        $account_number = trim($item['account_number']);
        $ben_bank_name = trim($item['ben_bank_name']);
        $payment_account_number = trim($item['payment_account_number']);
        $payment_category = trim($item['payment_category']);
        $batch = (int) $item['batch'];
        $amount = (float) $item['amount'];
        $date = trim($item['date']);

        $stmt->bind_param(
            "sssssidis", // note: whitespace only for readability; PHP will accept the string as "sssssidis"
            $beneficiary_name,
            $account_number,
            $ben_bank_name,
            $payment_account_number,
            $payment_category,
            $batch,
            $amount,
            $loggedInUserId,
            $date
        );

        $types = "sssssidis";
        $stmt->bind_param($types,
            $beneficiary_name,
            $account_number,
            $ben_bank_name,
            $payment_account_number,
            $payment_category,
            $batch,
            $amount,
            $loggedInUserId,
            $date
        );

        if (!$stmt->execute()) {
            throw new Exception("Database insert failed: " . $stmt->error, 500);
        }

        $id = $stmt->insert_id;

        // Log action
        $log_stmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        if ($log_stmt) {
            $action = "$userEmail created new local transfer with ID $id";
            // userId (int), action (string), created_by (string/email)
            $log_stmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    $stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Local transfers have been created successfully"
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
