<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // ✅ Authenticate
    $userData = authenticateUser();
    $integrity = $userData['integrity'];

    if (!in_array($integrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can view this report", 401);
    }

    // ✅ Year is mandatory
    if (!isset($_GET['year'])) {
        throw new Exception("Year parameter is required", 400);
    }

    $year = (int) $_GET['year'];

    $query = "
        SELECT 
            DATE_FORMAT(payment_date, '%b') AS label,
            SUM(payment_amount) AS value
        FROM payment_schedule_tab
        WHERE YEAR(payment_date) = ?
        GROUP BY MONTH(payment_date)
        ORDER BY MONTH(payment_date) ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $year);
    $stmt->execute();

    $result = $stmt->get_result();
    $report = $result->fetch_all(MYSQLI_ASSOC);

    $totalSum = array_sum(array_column($report, 'value'));

    echo json_encode([
        "status"   => "Success",
        "year"     => $year,
        "data"     => $report,
        "totalSum" => number_format($totalSum, 2)
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
