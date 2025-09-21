<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $integrity = $userData['integrity'];

    if (!in_array($integrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can view this report", 401);
    }

    // Allowed tables + their amount columns
    $tableAmountMap = [
        "supplier_fund_request_table" => "amount",
        "advance_payment_request"     => "amount_payable",   // ✅ uses amount_payable instead of amount
        "expense_fund_request_table"  => "amount",
        "compass_fund_request_table"  => "amount"
    ];

    $table = $_GET['table'] ?? "supplier_fund_request_table";
    $year  = $_GET['year'] ?? date('Y');

    if (!array_key_exists($table, $tableAmountMap)) {
        throw new Exception("Invalid table selected", 400);
    }

    $amountColumn = $tableAmountMap[$table];

    // Build query
    $query = "
        SELECT payment_status, SUM($amountColumn) as total
        FROM $table
        WHERE YEAR(created_at) = ?
        GROUP BY payment_status
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Failed to prepare statement: " . $conn->error, 500);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    // Ensure consistent output
    $data = ['Pending' => 0, 'Paid' => 0, 'Unconfirmed' => 0];
    foreach ($rows as $row) {
        $status = $row['payment_status'];
        $data[$status] = (float)$row['total'];
    }

    echo json_encode([
        "status" => "Success",
        "year"   => (int)$year,
        "table"  => $table,
        "data"   => $data
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
