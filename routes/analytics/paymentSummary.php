<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found.', 405);
    }

    requireAdmin();

    $source = trim((string) ($_GET['source'] ?? ''));
    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $search = trim((string) ($_GET['search'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));
    $userId = trim((string) ($_GET['userId'] ?? ''));
    $batch = trim((string) ($_GET['batch'] ?? ''));

    $sources = [
        'request-supplier' => [
            'kind' => 'request', 'table' => 'supplier_fund_request_table', 'amount' => 'amount', 'date' => 'created_at',
            'search' => ['suppliers_name', 'purchase_number', 'invoice_number', 'po_number', 'project_code', 'description'],
        ],
        'request-advance' => [
            'kind' => 'request', 'table' => 'advance_payment_request', 'amount' => 'amount_payable', 'date' => 'created_at',
            'search' => ['suppliers_name', 'po_number', 'site', 'note'],
        ],
        'request-expense' => [
            'kind' => 'request', 'table' => 'expense_fund_request_table', 'amount' => 'amount', 'date' => 'created_at',
            'search' => ['suppliers_name', 'invoice_number', 'project_code', 'description'],
        ],
        'request-compass' => [
            'kind' => 'request', 'table' => 'compass_fund_request_table', 'amount' => 'amount', 'date' => 'created_at',
            'search' => ['suppliers_name', 'invoice_number', 'project_code', 'description'],
        ],
        'gaps-supplier' => [
            'kind' => 'schedule', 'table' => 'payment_schedule_tab', 'amount' => 'payment_amount', 'date' => 'payment_date',
            'search' => ['suppliers_name', 'payment_amount', 'payment_date', 'invoice_number', 'po_number', 'narration', 'bank_name', 'account_name', 'account_number', 'sort_code'],
            'allow_user' => true, 'allow_batch' => true, 'allow_date' => true,
        ],
        'gaps-advance' => [
            'kind' => 'schedule', 'table' => 'advance_payment_schedule_tab', 'amount' => 'payment_amount', 'date' => 'payment_date',
            'search' => ['suppliers_name', 'payment_amount', 'payment_date'],
            'allow_user' => true, 'allow_batch' => true, 'allow_date' => true,
        ],
        'gaps-expense' => [
            'kind' => 'schedule', 'table' => 'other_payment_schedule', 'amount' => 'payment_amount', 'date' => 'payment_date',
            'search' => ['suppliers_name', 'payment_amount', 'payment_date'],
            'allow_user' => true, 'allow_batch' => true, 'allow_date' => true,
        ],
    ];

    if (!array_key_exists($source, $sources)) {
        throw new Exception('Unsupported summary source.', 400);
    }

    $definition = $sources[$source];
    $table = $definition['table'];
    $amount = "CAST(REPLACE(COALESCE({$definition['amount']}, '0'), ',', '') AS DECIMAL(20,2))";
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($year !== null) {
        $where[] = "YEAR({$definition['date']}) = ?";
        $params[] = $year;
        $types .= 'i';
    }

    if (!empty($definition['allow_date']) && $date !== '') {
        $where[] = "DATE({$definition['date']}) = ?";
        $params[] = $date;
        $types .= 's';
    }

    if (!empty($definition['allow_user']) && $userId !== '' && $userId !== 'all') {
        $where[] = 'userId = ?';
        $params[] = (int) $userId;
        $types .= 'i';
    }

    if (!empty($definition['allow_batch']) && $batch !== '' && $batch !== 'all' && is_numeric($batch)) {
        $where[] = 'batch = ?';
        $params[] = (int) $batch;
        $types .= 'i';
    }

    if ($search !== '') {
        $clauses = [];
        $like = '%' . $search . '%';
        foreach ($definition['search'] as $column) {
            $clauses[] = "$column LIKE ?";
            $params[] = $like;
            $types .= 's';
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    $whereSql = implode(' AND ', $where);
    if ($definition['kind'] === 'request') {
        $sql = "SELECT
            COUNT(*) AS record_count,
            COALESCE(SUM($amount), 0) AS overall_total,
            COALESCE(SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN $amount ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN LOWER(payment_status) = 'pending' THEN $amount ELSE 0 END), 0) AS pending_total,
            COALESCE(SUM(CASE WHEN LOWER(payment_status) = 'unconfirmed' THEN $amount ELSE 0 END), 0) AS unconfirmed_total,
            SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN LOWER(payment_status) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(payment_status) = 'unconfirmed' THEN 1 ELSE 0 END) AS unconfirmed_count
            FROM $table WHERE $whereSql";
    } else {
        $sql = "SELECT
            COUNT(*) AS record_count,
            COALESCE(SUM($amount), 0) AS overall_total,
            COALESCE(AVG($amount), 0) AS average_amount,
            COALESCE(MAX($amount), 0) AS highest_amount
            FROM $table WHERE $whereSql";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare summary analytics.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($summary as $key => $value) {
        $summary[$key] = strpos($key, 'count') !== false ? (int) $value : (float) $value;
    }
    $summary['kind'] = $definition['kind'];
    $summary['year'] = $year;
    $summary['search'] = $search;

    echo json_encode(['status' => 'Success', 'data' => $summary]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
