<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $integrity = $userData['integrity'] ?? '';
    if (!in_array($integrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Forbidden: Only administrators can view dashboard analytics', 403);
    }

    $year = trim((string) ($_GET['year'] ?? date('Y')));
    if (!preg_match('/^\d{4}$/', $year)) {
        throw new Exception('Invalid accounting year.', 400);
    }
    $year = (int) $year;

    $sources = [
        [
            'key' => 'supplier',
            'label' => 'Supplier Requests',
            'table' => 'supplier_fund_request_table',
            'amount' => 'amount',
        ],
        [
            'key' => 'advance',
            'label' => 'Advance Requests',
            'table' => 'advance_payment_request',
            'amount' => 'amount_payable',
        ],
        [
            'key' => 'expense',
            'label' => 'Expense Requests',
            'table' => 'expense_fund_request_table',
            'amount' => 'amount',
        ],
        [
            'key' => 'compass',
            'label' => 'Compass Requests',
            'table' => 'compass_fund_request_table',
            'amount' => 'amount',
        ],
    ];

    $overview = [
        'request_count' => 0,
        'total_amount' => 0.0,
        'paid_amount' => 0.0,
        'pending_amount' => 0.0,
        'unconfirmed_amount' => 0.0,
        'paid_count' => 0,
        'pending_count' => 0,
        'unconfirmed_count' => 0,
    ];
    $categories = [];
    $recentSqlParts = [];

    foreach ($sources as $source) {
        $table = $source['table'];
        $amountColumn = $source['amount'];
        $amountExpression = "CAST(REPLACE(REPLACE(COALESCE($amountColumn, '0'), ',', ''), '₦', '') AS DECIMAL(18,2))";

        $sql = "
            SELECT
                COUNT(*) AS request_count,
                COALESCE(SUM($amountExpression), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'paid' THEN $amountExpression ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'pending' THEN $amountExpression ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'unconfirmed' THEN $amountExpression ELSE 0 END), 0) AS unconfirmed_amount,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'unconfirmed' THEN 1 ELSE 0 END) AS unconfirmed_count
            FROM $table
            WHERE YEAR(created_at) = ?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Unable to prepare dashboard analytics.', 500);
        }
        $stmt->bind_param('i', $year);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $category = [
            'key' => $source['key'],
            'label' => $source['label'],
            'request_count' => (int) ($row['request_count'] ?? 0),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'paid_amount' => (float) ($row['paid_amount'] ?? 0),
            'pending_amount' => (float) ($row['pending_amount'] ?? 0),
            'unconfirmed_amount' => (float) ($row['unconfirmed_amount'] ?? 0),
            'paid_count' => (int) ($row['paid_count'] ?? 0),
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'unconfirmed_count' => (int) ($row['unconfirmed_count'] ?? 0),
        ];
        $category['completion_rate'] = $category['total_amount'] > 0
            ? round(($category['paid_amount'] / $category['total_amount']) * 100, 1)
            : 0;
        $categories[] = $category;

        foreach ($overview as $key => $value) {
            $overview[$key] += $category[$key];
        }

        $recentSqlParts[] = "
            SELECT
                '{$source['key']}' AS category,
                '{$source['label']}' AS category_label,
                id,
                suppliers_name AS reference,
                payment_status,
                $amountExpression AS amount,
                created_at
            FROM $table
            WHERE YEAR(created_at) = ?
        ";
    }

    $overview['completion_rate'] = $overview['total_amount'] > 0
        ? round(($overview['paid_amount'] / $overview['total_amount']) * 100, 1)
        : 0;

    $recentSql = implode(' UNION ALL ', $recentSqlParts) . ' ORDER BY created_at DESC LIMIT 8';
    $recentStmt = $conn->prepare($recentSql);
    if (!$recentStmt) {
        throw new Exception('Unable to prepare recent activity analytics.', 500);
    }
    $recentStmt->bind_param('iiii', $year, $year, $year, $year);
    $recentStmt->execute();
    $recentActivity = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentStmt->close();

    foreach ($recentActivity as &$item) {
        $item['id'] = (int) $item['id'];
        $item['amount'] = (float) $item['amount'];
    }
    unset($item);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'year' => $year,
        'data' => [
            'overview' => $overview,
            'categories' => $categories,
            'recent_activity' => $recentActivity,
        ],
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
