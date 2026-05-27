<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php'; // should provide $conn = new mysqli(...)
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Dashboard analytics are restricted to operational administrator roles.
    $userData = authenticateUser();
    $integrity = $userData['integrity'] ?? '';
    if (!in_array($integrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception("Forbidden: Only administrators can view dashboard analytics", 403);
    }

    $table = $_GET['table'] ?? 'supplier_fund_request_table';
    $year = trim((string) ($_GET['year'] ?? date("Y")));
    if (!preg_match('/^\d{4}$/', $year)) {
        throw new Exception("Invalid accounting year", 400);
    }
    $year = (int) $year;

    // Only allow known request tables and their correct financial amount column.
    $tableAmountMap = [
        'supplier_fund_request_table' => 'amount',
        'advance_payment_request' => 'amount_payable',
        'expense_fund_request_table' => 'amount',
        'compass_fund_request_table' => 'amount'
    ];
    if (!array_key_exists($table, $tableAmountMap)) {
        throw new Exception("Invalid table", 400);
    }

    $dateCol = "created_at";
    $statusCol = "payment_status";
    $amountColumn = $tableAmountMap[$table];
    $amountExpression = "CAST(REPLACE(REPLACE(COALESCE($amountColumn, '0'), ',', ''), '₦', '') AS DECIMAL(18,2))";

    $sql = "
        SELECT 
            MONTH($dateCol) AS month,
            $statusCol AS status,
            SUM($amountExpression) AS total
        FROM $table
        WHERE YEAR($dateCol) = ?
        GROUP BY MONTH($dateCol), $statusCol
        ORDER BY month ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare SQL: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    // Build monthly array
    $months = array_fill(1, 12, ['Pending' => 0, 'Paid' => 0, 'Unconfirmed' => 0]);

    foreach ($rows as $row) {
        $month = (int)$row['month'];
        $status = ucfirst(strtolower(trim($row['status']))); // normalize
        $amount = (float)$row['total'];

        if (isset($months[$month][$status])) {
            $months[$month][$status] = $amount;
        }
    }

    // Format result
    $resultData = [];
    foreach ($months as $m => $values) {
        $resultData[] = [
            'month' => date("M", mktime(0, 0, 0, $m, 1)),
            'pending' => $values['Pending'],
            'paid' => $values['Paid'],
            'unconfirmed' => $values['Unconfirmed']
        ];
    }

    echo json_encode([
        "success" => true,
        "data" => $resultData
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
