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
    $loggedInUserIntegrity = $userData['integrity'];
    $userId = $userData['id'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can view this report", 401);
    }

    $type = isset($_GET['type']) ? $_GET['type'] : 'daily';
    $groupBy = match ($type) {
        'weekly' => "CONCAT('Week ', WEEK(payment_date))",
        'monthly' => "DATE_FORMAT(payment_date, '%b')",
        'yearly' => "YEAR(payment_date)",
        default => "DATE(payment_date)"
    };

    $query = "
        SELECT $groupBy AS label, SUM(payment_amount) AS total
        FROM advance_payment_schedule_tab
        GROUP BY label
        ORDER BY payment_date ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Failed to prepare statement: " . $conn->error, 500);

    // $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_all(MYSQLI_ASSOC);

    $totalSum = array_sum(array_column($report, 'total'));

    echo json_encode([
        "status" => "Success",
        "data" => $report,
        "totalSum" => number_format($totalSum, 2)
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
