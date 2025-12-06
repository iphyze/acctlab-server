<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

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
        throw new Exception("Unauthorized: Only Admins can edit local transfers", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format", 400);
    }

    // Basic validation
    $required = [
        'id' => 'ID',
        'beneficiary_name' => 'Beneficiary Name',
        'account_number' => 'Account Number',
        'ben_bank_name' => 'Beneficiary Bank Name',
        'payment_account_number' => 'Payment Account Number',
        'payment_category' => 'Payment Category',
        'batch' => 'Batch',
        'amount' => 'Amount',
        'date' => 'Date'
    ];

    foreach ($required as $key => $label) {
        if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
            throw new Exception("$label is required", 400);
        }
    }

    $id = intval($data['id']);
    if ($id <= 0) {
        throw new Exception("ID must be a valid number", 400);
    }

    // Additional validations
    if (!is_numeric($data['batch'])) {
        throw new Exception("Batch must be numeric", 400);
    }
    if (!is_numeric($data['amount'])) {
        throw new Exception("Amount must be numeric", 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) {
        throw new Exception("Date must be in YYYY-MM-DD format", 400);
    }

    // Check if the record exists
    $checkStmt = $conn->prepare("SELECT id FROM local_transfer WHERE id = ?");
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows === 0) {
        $checkStmt->close();
        throw new Exception("Local transfer with ID $id not found", 404);
    }
    $checkStmt->close();

    // Prepare update values
    $beneficiary_name = trim($data['beneficiary_name']);
    $account_number = trim($data['account_number']);
    $ben_bank_name = trim($data['ben_bank_name']);
    $payment_account_number = trim($data['payment_account_number']);
    $payment_category = trim($data['payment_category']);
    $batch = (int)$data['batch'];
    $amount = (float)$data['amount'];
    $date = trim($data['date']);

    // Update statement - set created_by to the user performing the update (keeps your pattern)
    $stmt = $conn->prepare("
        UPDATE local_transfer
        SET beneficiary_name = ?,
            account_number = ?,
            ben_bank_name = ?,
            payment_account_number = ?,
            payment_category = ?,
            batch = ?,
            amount = ?,
            `date` = ?,
            created_by = ?
        WHERE id = ?
    ");
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement - " . $conn->error, 500);
    }

    // bind_param types:
    // s - beneficiary_name
    // s - account_number
    // s - ben_bank_name
    // s - payment_account_number
    // s - payment_category
    // i - batch
    // d - amount
    // s - date  <-- FIXED: bind as string
    // i - created_by (user id)
    // i - id
    $stmt->bind_param(
        "sssssidsii",
        $beneficiary_name,
        $account_number,
        $ben_bank_name,
        $payment_account_number,
        $payment_category,
        $batch,
        $amount,
        $date,
        $loggedInUserId,
        $id
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Failed to update local transfer: " . $stmt->error, 500);
    }

    $stmt->close();

    // Insert log entry
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    if ($logStmt) {
        $action = "$userEmail updated local transfer with ID $id";
        $created_by = $userEmail;
        $logStmt->bind_param("iss", $loggedInUserId, $action, $created_by);
        $logStmt->execute();
        $logStmt->close();
    }

    // Return updated data
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Local transfer updated successfully",
        "data" => [
            "id" => $id,
            "beneficiary_name" => $beneficiary_name,
            "account_number" => $account_number,
            "ben_bank_name" => $ben_bank_name,
            "payment_account_number" => $payment_account_number,
            "payment_category" => $payment_category,
            "batch" => $batch,
            "amount" => $amount,
            "date" => $date,
            "created_by" => $loggedInUserId
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
