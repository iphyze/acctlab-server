<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $integrity = (string) $userData['integrity'];
    $accountingPeriod = (int) $userData['accounting_period'];
    if (!in_array($integrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Unauthorized: Only Admins can view summaries', 401);
    }

    accountSupplierEnsurePaymentStorage($conn);
    $query = "SELECT
        COUNT(*) AS total_count,
        SUM(payment_status = 'Pending') AS pending_count,
        SUM(payment_status IN ('Processing', 'Unconfirmed')) AS processing_count,
        SUM(payment_status = 'Paid') AS paid_count,
        SUM(payment_status = 'Failed') AS failed_count,
        SUM(payment_status = 'Cancelled') AS cancelled_count,
        SUM(payment_confirmation_status = 'Due') AS confirmation_due_count,
        SUM(CASE WHEN payment_status = 'Pending' THEN CAST(amount AS DECIMAL(18,2)) ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN payment_status IN ('Processing', 'Unconfirmed') THEN CAST(amount AS DECIMAL(18,2)) ELSE 0 END) AS processing_amount,
        SUM(CASE WHEN payment_status = 'Paid' THEN CAST(amount AS DECIMAL(18,2)) ELSE 0 END) AS paid_payable_amount,
        SUM(CASE WHEN payment_status = 'Paid' THEN amount_paid ELSE 0 END) AS amount_eventually_paid,
        SUM(CASE WHEN payment_status = 'Failed' THEN CAST(amount AS DECIMAL(18,2)) ELSE 0 END) AS failed_amount,
        SUM(CASE WHEN payment_status = 'Cancelled' THEN CAST(amount AS DECIMAL(18,2)) ELSE 0 END) AS cancelled_amount,
        SUM(CAST(amount AS DECIMAL(18,2))) AS total_amount,
        SUM(CAST(vat AS DECIMAL(18,2))) AS total_vat,
        SUM(CAST(wht AS DECIMAL(18,2))) AS total_wht
      FROM supplier_fund_request_table WHERE YEAR(created_at) = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $accountingPeriod);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $total = max(1, (int) ($summary['total_count'] ?? 0));
    $processingCount = (int) ($summary['processing_count'] ?? 0);
    $processingAmount = (float) ($summary['processing_amount'] ?? 0);

    echo json_encode([
        'status' => 'Success',
        'message' => 'Summary generated successfully!',
        'data' => [
            'counts' => [
                'total' => (int) ($summary['total_count'] ?? 0),
                'pending' => (int) ($summary['pending_count'] ?? 0),
                'processing' => $processingCount,
                'paid' => (int) ($summary['paid_count'] ?? 0),
                'failed' => (int) ($summary['failed_count'] ?? 0),
                'cancelled' => (int) ($summary['cancelled_count'] ?? 0),
                'confirmation_due' => (int) ($summary['confirmation_due_count'] ?? 0),
                // Kept for existing Account frontend compatibility.
                'unconfirmed' => $processingCount,
            ],
            'amounts' => [
                'total' => (float) ($summary['total_amount'] ?? 0),
                'pending' => (float) ($summary['pending_amount'] ?? 0),
                'processing' => $processingAmount,
                'paid_payable' => (float) ($summary['paid_payable_amount'] ?? 0),
                'paid' => (float) ($summary['amount_eventually_paid'] ?? 0),
                'failed' => (float) ($summary['failed_amount'] ?? 0),
                'cancelled' => (float) ($summary['cancelled_amount'] ?? 0),
                'unconfirmed' => $processingAmount,
            ],
            'vats' => ['total' => (float) ($summary['total_vat'] ?? 0)],
            'wht' => ['total' => (float) ($summary['total_wht'] ?? 0)],
            'percentages' => [
                'pending' => round(((int) ($summary['pending_count'] ?? 0) / $total) * 100, 2),
                'processing' => round(($processingCount / $total) * 100, 2),
                'paid' => round(((int) ($summary['paid_count'] ?? 0) / $total) * 100, 2),
                'unconfirmed' => round(($processingCount / $total) * 100, 2),
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Supplier summary error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
