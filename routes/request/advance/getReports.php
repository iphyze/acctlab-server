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
    $accounting_period = (int)$userData['accounting_period'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can view reports", 401);
    }

    // Get query parameters
    $reportType = isset($_GET['type']) ? $_GET['type'] : 'summary';
    $timeFrame = isset($_GET['timeFrame']) ? $_GET['timeFrame'] : 'monthly';

    $response = [];

    // Get total counts by status
    $statusQuery = "SELECT payment_status, COUNT(*) as count, SUM(advance_payment) as total_amount FROM advance_payment_request WHERE YEAR(created_at) = ? 
    GROUP BY payment_status";

    $statusStmt = $conn->prepare($statusQuery);
    $statusStmt->bind_param("i", $accounting_period);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    
    $statusSummary = [];
    while ($row = $statusResult->fetch_assoc()) {
        $statusSummary[$row['payment_status']] = [
            'count' => $row['count'],
            'total_amount' => $row['total_amount']
        ];
    }
    $response['status_summary'] = $statusSummary;

    // Get time-based data based on timeFrame
    $timeQuery = "";
    switch ($timeFrame) {
        case 'daily':
            $timeQuery = "SELECT 
                DATE(created_at) as date,
                payment_status,
                COUNT(*) as count,
                SUM(advance_payment) as total_amount
            FROM advance_payment_request
            WHERE YEAR(created_at) = ?
            GROUP BY DATE(created_at), payment_status
            ORDER BY DATE(created_at) DESC
            LIMIT 30"; // Last 30 days
            break;

        case 'weekly':
            $timeQuery = "SELECT 
                YEARWEEK(created_at) as week,
                payment_status,
                COUNT(*) as count,
                SUM(advance_payment) as total_amount
            FROM advance_payment_request
            WHERE YEAR(created_at) = ?
            GROUP BY YEARWEEK(created_at), payment_status
            ORDER BY YEARWEEK(created_at) DESC
            LIMIT 52"; // Last 52 weeks
            break;

        case 'monthly':
            $timeQuery = "SELECT 
                MONTH(created_at) as month,
                payment_status,
                COUNT(*) as count,
                SUM(advance_payment) as total_amount
            FROM advance_payment_request
            WHERE YEAR(created_at) = ?
            GROUP BY MONTH(created_at), payment_status
            ORDER BY MONTH(created_at)";
            break;

        case 'yearly':
            $timeQuery = "SELECT 
                YEAR(created_at) as year,
                payment_status,
                COUNT(*) as count,
                SUM(advance_payment) as total_amount
            FROM advance_payment_request
            GROUP BY YEAR(created_at), payment_status
            ORDER BY YEAR(created_at)";
            break;
    }

    $timeStmt = $conn->prepare($timeQuery);
    $timeStmt->bind_param("i", $accounting_period);
    $timeStmt->execute();
    $timeResult = $timeStmt->get_result();
    
    $timeData = [];
    while ($row = $timeResult->fetch_assoc()) {
        $period = isset($row['date']) ? $row['date'] : 
                 (isset($row['week']) ? $row['week'] : 
                 (isset($row['month']) ? $row['month'] : 
                 $row['year']));
                 
        if (!isset($timeData[$period])) {
            $timeData[$period] = [
                'Pending' => ['count' => 0, 'amount' => 0],
                'Paid' => ['count' => 0, 'amount' => 0],
                'Unconfirmed' => ['count' => 0, 'amount' => 0]
            ];
        }
        $timeData[$period][$row['payment_status']] = [
            'count' => $row['count'],
            'amount' => $row['total_amount']
        ];
    }
    $response['time_series'] = $timeData;

    // Calculate trends and percentage changes
    $response['trends'] = [
        'total_requests' => array_sum(array_column($statusSummary, 'count')),
        'total_amount' => array_sum(array_column($statusSummary, 'total_amount')),
        'pending_percentage' => isset($statusSummary['Pending']) ? 
            ($statusSummary['Pending']['count'] / array_sum(array_column($statusSummary, 'count'))) * 100 : 0,
        'paid_percentage' => isset($statusSummary['Paid']) ? 
            ($statusSummary['Paid']['count'] / array_sum(array_column($statusSummary, 'count'))) * 100 : 0
    ];

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Reports generated successfully!",
        "data" => $response
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