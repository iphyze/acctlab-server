<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    cashRequireIouActionsSchema($conn);
    $accountId = (int) $account['id'];

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if (!in_array($limit, [10, 25, 50, 100], true)) {
        throw new InvalidArgumentException('limit must be one of 10, 25, 50 or 100.', 422);
    }
    if ($page <= 0) {
        throw new InvalidArgumentException('page must be a positive integer.', 422);
    }
    $offset = ($page - 1) * $limit;

    $accountingYear = (int) ($user['accounting_period'] ?? date('Y'));
    $startDate = !empty($_GET['start_date'])
        ? cashParseIsoDate($_GET['start_date'], 'Start date', true)
        : sprintf('%04d-01-01', $accountingYear);
    $endDate = !empty($_GET['end_date'])
        ? cashParseIsoDate($_GET['end_date'], 'End date', true)
        : sprintf('%04d-12-31', $accountingYear);
    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Start date cannot be later than end date.', 422);
    }

    $status = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
    $receiptStatus = strtoupper(trim((string) ($_GET['receipt_status'] ?? 'ALL')));
    $search = trim((string) ($_GET['search'] ?? ''));
    $today = date('Y-m-d');

    $allowedStatuses = [
        'ALL',
        'OPEN',
        'PARTIALLY_RETIRED',
        'PENDING_CASH_RETURN',
        'PENDING_REIMBURSEMENT',
        'CLOSED',
        'OVERDUE',
    ];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new InvalidArgumentException('Invalid IOU status filter.', 422);
    }
    if (!in_array($receiptStatus, ['ALL', 'PENDING', 'PARTIAL', 'RECEIVED'], true)) {
        throw new InvalidArgumentException('Invalid receipt_status filter.', 422);
    }

    $allowedSortFields = [
        'transaction_date' => 'ct.transaction_date',
        'recipient_name' => 'ci.recipient_name',
        'amount_advanced' => 'ci.amount_advanced',
        'outstanding_amount' => 'ci.outstanding_amount',
        'expected_retirement_date' => 'ci.expected_retirement_date',
        'created_at' => 'ci.created_at',
    ];
    $sortByKey = (string) ($_GET['sortBy'] ?? 'transaction_date');
    $sortBy = $allowedSortFields[$sortByKey] ?? $allowedSortFields['transaction_date'];
    $sortOrder = strtoupper((string) ($_GET['sortOrder'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = [
        'ci.account_id = ?',
        'ct.transaction_date BETWEEN ? AND ?',
        "ci.status <> 'REVERSED'",
    ];
    $params = [$accountId, $startDate, $endDate];
    $types = 'iss';

    if ($status === 'OVERDUE') {
        $where[] = "ci.status IN ('OPEN', 'PARTIALLY_RETIRED')";
        $where[] = 'ci.expected_retirement_date IS NOT NULL';
        $where[] = 'ci.expected_retirement_date < ?';
        $params[] = $today;
        $types .= 's';
    } elseif ($status !== 'ALL') {
        $where[] = 'ci.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($receiptStatus !== 'ALL') {
        $where[] = 'ci.receipt_status = ?';
        $params[] = $receiptStatus;
        $types .= 's';
    }

    if ($search !== '') {
        $where[] = "(
            ci.iou_reference LIKE ?
            OR ci.recipient_name LIKE ?
            OR ci.reason LIKE ?
            OR ci.description LIKE ?
            OR ct.transaction_reference LIKE ?
            OR ct.external_reference LIKE ?
            OR cc.category_name LIKE ?
        )";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $joinSql = "FROM cash_ious ci
                INNER JOIN cash_transactions ct ON ct.id = ci.source_transaction_id
                LEFT JOIN cash_categories cc ON cc.id = ct.category_id";
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total {$joinSql} WHERE {$whereSql}");
    if (!$countStmt) {
        throw new RuntimeException('Unable to count cash IOUs.', 500);
    }
    $countParams = $params;
    cashBindParams($countStmt, $types, $countParams);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $dataSql = "SELECT
            ci.*,
            ct.transaction_reference AS source_transaction_reference,
            ct.transaction_date AS source_transaction_date,
            ct.amount AS source_transaction_amount,
            ct.category_id,
            ct.external_reference,
            ct.created_at AS disbursed_at,
            cc.category_name,
            (
                SELECT COUNT(*)
                FROM cash_receipts cr
                WHERE cr.iou_id = ci.id AND cr.status = 'ACTIVE'
            ) AS receipt_count
        {$joinSql}
        WHERE {$whereSql}
        ORDER BY {$sortBy} {$sortOrder}, ci.id {$sortOrder}
        LIMIT ? OFFSET ?";
    $dataStmt = $conn->prepare($dataSql);
    if (!$dataStmt) {
        throw new RuntimeException('Unable to load cash IOUs.', 500);
    }
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';
    cashBindParams($dataStmt, $dataTypes, $dataParams);
    $dataStmt->execute();
    $ious = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    foreach ($ious as &$iou) {
        $iou = cashNormalizeIouRow($iou);
    }
    unset($iou);

    $summarySql = "SELECT
            COUNT(*) AS total_ious,
            SUM(CASE WHEN ci.status IN ('OPEN', 'PARTIALLY_RETIRED') THEN 1 ELSE 0 END) AS open_ious,
            SUM(CASE WHEN ci.status IN ('OPEN', 'PARTIALLY_RETIRED') AND ci.expected_retirement_date IS NOT NULL AND ci.expected_retirement_date < ? THEN 1 ELSE 0 END) AS overdue_ious,
            SUM(CASE WHEN ci.status = 'PENDING_CASH_RETURN' THEN 1 ELSE 0 END) AS pending_cash_return,
            SUM(CASE WHEN ci.status = 'PENDING_REIMBURSEMENT' THEN 1 ELSE 0 END) AS pending_reimbursement,
            SUM(CASE WHEN ci.status = 'CLOSED' THEN 1 ELSE 0 END) AS closed_ious,
            COALESCE(SUM(CASE WHEN ci.status NOT IN ('CLOSED', 'REVERSED') THEN ci.outstanding_amount ELSE 0 END), 0) AS outstanding_value,
            COALESCE(SUM(CASE WHEN ci.status = 'PENDING_CASH_RETURN' THEN ci.outstanding_amount ELSE 0 END), 0) AS cash_return_due,
            COALESCE(SUM(CASE WHEN ci.status = 'PENDING_REIMBURSEMENT' THEN ci.outstanding_amount ELSE 0 END), 0) AS reimbursement_due
        {$joinSql}
        WHERE ci.account_id = ?
          AND ct.transaction_date BETWEEN ? AND ?
          AND ci.status <> 'REVERSED'";
    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new RuntimeException('Unable to calculate the IOU summary.', 500);
    }
    $summaryStmt->bind_param('siss', $today, $accountId, $startDate, $endDate);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    foreach (['total_ious', 'open_ious', 'overdue_ious', 'pending_cash_return', 'pending_reimbursement', 'closed_ious'] as $field) {
        $summary[$field] = (int) ($summary[$field] ?? 0);
    }
    foreach (['outstanding_value', 'cash_return_due', 'reimbursement_due'] as $field) {
        $summary[$field] = round((float) ($summary[$field] ?? 0), 2);
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash IOUs fetched successfully.',
        'data' => $ious,
        'summary' => $summary,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'total_pages' => (int) max(1, ceil($total / $limit)),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status_filter' => $status,
            'receipt_status' => $receiptStatus,
            'search' => $search,
            'sortBy' => $sortByKey,
            'sortOrder' => $sortOrder,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load cash IOUs.');
}
