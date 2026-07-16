<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    $accountId = (int) $account['id'];

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if (!in_array($limit, [10, 25, 50, 100], true) || $page <= 0) {
        throw new InvalidArgumentException('Invalid daily-close pagination values.', 422);
    }
    $offset = ($page - 1) * $limit;
    $year = (int) ($user['accounting_period'] ?? date('Y'));
    $startDate = !empty($_GET['start_date']) ? cashParseIsoDate($_GET['start_date'], 'Start date', true) : sprintf('%04d-01-01', $year);
    $endDate = !empty($_GET['end_date']) ? cashParseIsoDate($_GET['end_date'], 'End date', true) : sprintf('%04d-12-31', $year);
    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Start date cannot be later than end date.', 422);
    }
    $status = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
    if (!in_array($status, ['ALL', 'CLOSED', 'REOPENED'], true)) {
        throw new InvalidArgumentException('Invalid daily-close status filter.', 422);
    }

    $where = ['account_id = ?', 'closure_date BETWEEN ? AND ?'];
    $params = [$accountId, $startDate, $endDate];
    $types = 'iss';
    if ($status !== 'ALL') {
        $where[] = 'status = ?';
        $params[] = $status;
        $types .= 's';
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM cash_daily_closures WHERE {$whereSql}");
    if (!$countStmt) {
        throw new RuntimeException('Unable to count daily cash closures.', 500);
    }
    $countParams = $params;
    cashBindParams($countStmt, $types, $countParams);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT * FROM cash_daily_closures
            WHERE {$whereSql}
            ORDER BY closure_date DESC, id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load daily cash closures.', 500);
    }
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    cashBindParams($stmt, $types . 'ii', $dataParams);
    $stmt->execute();
    $closures = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($closures as &$closure) {
        foreach (['id', 'account_id', 'closed_by_user_id', 'reopened_by_user_id'] as $field) {
            $closure[$field] = $closure[$field] !== null ? (int) $closure[$field] : null;
        }
        foreach (['opening_balance', 'cash_received', 'cash_returned', 'cash_disbursed', 'reimbursements_paid', 'system_closing_balance', 'physical_cash_counted', 'difference_amount'] as $field) {
            $closure[$field] = round((float) $closure[$field], 2);
        }
        $closure['movements'] = cashGetDayMovements($conn, $accountId, (string) $closure['closure_date']);
    }
    unset($closure);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Daily cash closures loaded successfully.',
        'data' => $closures,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'total_pages' => (int) max(1, ceil($total / $limit)),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load daily cash closures.');
}
