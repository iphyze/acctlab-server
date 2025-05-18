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
        throw new Exception("Unauthorized: Only Admins can view summaries", 401);
    }

    // Get summary statistics
    $query = "SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN payment_status = 'Unconfirmed' THEN 1 ELSE 0 END) as unconfirmed_count,
        SUM(CASE WHEN payment_status = 'Pending' THEN amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN payment_status = 'Paid' THEN amount ELSE 0 END) as paid_amount,
        SUM(CASE WHEN payment_status = 'Unconfirmed' THEN amount ELSE 0 END) as unconfirmed_amount,
        SUM(CASE WHEN payment_status = 'Pending' THEN vat ELSE 0 END) as pending_vat,
        SUM(CASE WHEN payment_status = 'Paid' THEN vat ELSE 0 END) as paid_vat,
        SUM(CASE WHEN payment_status = 'Unconfirmed' THEN vat ELSE 0 END) as unconfirmed_vat,
        SUM(CASE WHEN payment_status = 'Pending' THEN wht ELSE 0 END) as pending_wht,
        SUM(CASE WHEN payment_status = 'Paid' THEN wht ELSE 0 END) as paid_wht,
        SUM(CASE WHEN payment_status = 'Unconfirmed' THEN wht ELSE 0 END) as unconfirmed_wht,
        SUM(amount) as total_amount, SUM(vat) as total_vat,
        SUM(wht) as total_wht FROM supplier_fund_request_table WHERE YEAR(created_at) = ?";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $accounting_period);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = $result->fetch_assoc();

    // Calculate percentages
    $total_count = $summary['total_count'] ?: 1; // Prevent division by zero
    $summary['pending_percentage'] = round(($summary['pending_count'] / $total_count) * 100, 2);
    $summary['paid_percentage'] = round(($summary['paid_count'] / $total_count) * 100, 2);
    $summary['unconfirmed_percentage'] = round(($summary['unconfirmed_count'] / $total_count) * 100, 2);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Summary generated successfully!",
        "data" => [
            "counts" => [
                "total" => (int)$summary['total_count'],
                "pending" => (int)$summary['pending_count'],
                "paid" => (int)$summary['paid_count'],
                "unconfirmed" => (int)$summary['unconfirmed_count']
            ],
            "amounts" => [
                "total" => (float)$summary['total_amount'],
                "pending" => (float)$summary['pending_amount'],
                "paid" => (float)$summary['paid_amount'],
                "unconfirmed" => (float)$summary['unconfirmed_amount']
            ],
            "vats" => [
                "total" => (float)$summary['total_vat'],
                "pending" => (float)$summary['pending_vat'],
                "paid" => (float)$summary['paid_vat'],
                "unconfirmed" => (float)$summary['unconfirmed_vat']
            ],
            "wht" => [
                "total" => (float)$summary['total_wht'],
                "pending" => (float)$summary['pending_wht'],
                "paid" => (float)$summary['paid_vat'],
                "unconfirmed" => (float)$summary['unconfirmed_wht']
            ],
            "percentages" => [
                "pending" => (float)$summary['pending_percentage'],
                "paid" => (float)$summary['paid_percentage'],
                "unconfirmed" => (float)$summary['unconfirmed_percentage']
            ]
        ]
    ]);

    $stmt->close();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>