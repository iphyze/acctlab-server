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
    if (!in_array($limit, [10, 25, 50, 100], true)) {
        throw new InvalidArgumentException('limit must be one of 10, 25, 50 or 100.', 422);
    }
    if ($page <= 0) {
        throw new InvalidArgumentException('page must be a positive integer.', 422);
    }
    $offset = ($page - 1) * $limit;

    $accountingYear = (int) ($user['accounting_period'] ?? date('Y'));
    $startDate = isset($_GET['start_date']) && trim((string) $_GET['start_date']) !== ''
        ? cashParseIsoDate($_GET['start_date'], 'Start date', true)
        : sprintf('%04d-01-01', $accountingYear);
    $endDate = isset($_GET['end_date']) && trim((string) $_GET['end_date']) !== ''
        ? cashParseIsoDate($_GET['end_date'], 'End date', true)
        : sprintf('%04d-12-31', $accountingYear);

    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Start date cannot be later than end date.', 422);
    }

    $transactionType = strtoupper(trim((string) ($_GET['transaction_type'] ?? 'ALL')));
    $direction = strtoupper(trim((string) ($_GET['direction'] ?? 'ALL')));
    $receiptStatus = strtoupper(trim((string) ($_GET['receipt_status'] ?? 'ALL')));
    $iouStatus = strtoupper(trim((string) ($_GET['iou_status'] ?? 'ALL')));
    $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : 0;
    $search = trim((string) ($_GET['search'] ?? ''));

    $allowedTypes = ['ALL', 'CASH_RECEIPT', 'DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'CASH_RETURN', 'REIMBURSEMENT', 'MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT', 'MUTILATED_CASH_BANK_RETURN', 'OPENING_BALANCE', 'REVERSAL'];
    $allowedDirections = ['ALL', 'IN', 'OUT'];
    $allowedReceiptStatuses = ['ALL', 'PENDING', 'RECEIVED', 'PARTIAL', 'NOT_REQUIRED'];
    $allowedIouStatuses = ['ALL', 'OPEN', 'PARTIALLY_RETIRED', 'PENDING_CASH_RETURN', 'PENDING_REIMBURSEMENT', 'CLOSED', 'OVERDUE', 'REVERSED'];

    if (!in_array($transactionType, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid transaction_type filter.', 422);
    }
    if (!in_array($direction, $allowedDirections, true)) {
        throw new InvalidArgumentException('Invalid direction filter.', 422);
    }
    if (!in_array($receiptStatus, $allowedReceiptStatuses, true)) {
        throw new InvalidArgumentException('Invalid receipt_status filter.', 422);
    }
    if (!in_array($iouStatus, $allowedIouStatuses, true)) {
        throw new InvalidArgumentException('Invalid iou_status filter.', 422);
    }

    $allowedSortFields = [
        'transaction_date' => 'ct.transaction_date',
        'created_at' => 'ct.created_at',
        'amount' => 'ct.amount',
        'person_name' => 'ct.person_name',
        'transaction_type' => 'ct.transaction_type',
        'transaction_reference' => 'ct.transaction_reference',
    ];
    $sortByKey = (string) ($_GET['sortBy'] ?? 'transaction_date');
    $sortBy = $allowedSortFields[$sortByKey] ?? $allowedSortFields['transaction_date'];
    $sortOrder = strtoupper((string) ($_GET['sortOrder'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $where = [
        'ct.account_id = ?',
        "ct.status IN ('POSTED', 'REVERSED')",
        'ct.transaction_date BETWEEN ? AND ?',
    ];
    $params = [$accountId, $startDate, $endDate];
    $types = 'iss';

    if ($transactionType !== 'ALL') {
        $where[] = 'ct.transaction_type = ?';
        $params[] = $transactionType;
        $types .= 's';
    }
    if ($direction !== 'ALL') {
        $where[] = 'ct.direction = ?';
        $params[] = $direction;
        $types .= 's';
    }
    if ($receiptStatus !== 'ALL') {
        $where[] = 'ct.receipt_status = ?';
        $params[] = $receiptStatus;
        $types .= 's';
    }
    if ($iouStatus === 'OVERDUE') {
        $where[] = "ci.status NOT IN ('CLOSED', 'REVERSED') AND ci.expected_retirement_date IS NOT NULL AND ci.expected_retirement_date < CURDATE()";
    } elseif ($iouStatus !== 'ALL') {
        $where[] = 'ci.status = ?';
        $params[] = $iouStatus;
        $types .= 's';
    }
    if ($categoryId > 0) {
        $where[] = 'ct.category_id = ?';
        $params[] = $categoryId;
        $types .= 'i';
    }
    if ($search !== '') {
        $where[] = "(
            ct.transaction_reference LIKE ?
            OR ct.person_name LIKE ?
            OR ct.reason LIKE ?
            OR ct.description LIKE ?
            OR ct.external_reference LIKE ?
            OR cc.category_name LIKE ?
            OR ci.iou_reference LIKE ?
        )";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = "FROM cash_transactions ct
                LEFT JOIN cash_categories cc ON cc.id = ct.category_id
                LEFT JOIN cash_ious ci ON ci.source_transaction_id = ct.id";

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total {$joinSql} WHERE {$whereSql}");
    if (!$countStmt) {
        throw new RuntimeException('Unable to count cash transactions.', 500);
    }
    $countParams = $params;
    cashBindParams($countStmt, $types, $countParams);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalsSql = "SELECT
            COALESCE(SUM(CASE WHEN ct.direction = 'IN' AND ct.transaction_type NOT IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN ct.amount ELSE 0 END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN ct.direction = 'OUT' AND ct.transaction_type NOT IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN ct.amount ELSE 0 END), 0) AS cash_out
        {$joinSql}
        WHERE {$whereSql}";
    $totalsStmt = $conn->prepare($totalsSql);
    if (!$totalsStmt) {
        throw new RuntimeException('Unable to calculate transaction totals.', 500);
    }
    $totalsParams = $params;
    cashBindParams($totalsStmt, $types, $totalsParams);
    $totalsStmt->execute();
    $periodTotals = $totalsStmt->get_result()->fetch_assoc() ?: [];
    $totalsStmt->close();

    $dataSql = "SELECT
            ct.id,
            ct.account_id,
            ct.transaction_reference,
            ct.transaction_date,
            ct.transaction_type,
            ct.direction,
            ct.person_name,
            ct.amount,
            ct.reason,
            ct.description,
            ct.category_id,
            cc.category_name,
            ct.external_reference,
            ct.disbursement_type,
            ct.receipt_status,
            ct.status,
            ct.accounting_year,
            ct.created_by_user_id,
            ct.created_by_email,
            ct.created_at,
            ci.id AS iou_id,
            ci.iou_reference,
            ci.status AS iou_status,
            ci.outstanding_amount AS iou_outstanding_amount,
            (
                SELECT COUNT(*)
                FROM cash_receipts cr
                WHERE cr.status = 'ACTIVE'
                  AND (cr.transaction_id = ct.id OR (ci.id IS NOT NULL AND cr.iou_id = ci.id))
            ) AS receipt_count,
            (
                SELECT COALESCE(SUM(CASE
                    WHEN prior.transaction_type IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN 0
                    WHEN prior.direction = 'IN' THEN prior.amount
                    ELSE -prior.amount
                END), 0)
                FROM cash_transactions prior
                WHERE prior.account_id = ct.account_id
                  AND prior.status IN ('POSTED', 'REVERSED')
                  AND (
                    prior.transaction_date < ct.transaction_date
                    OR (prior.transaction_date = ct.transaction_date AND prior.id <= ct.id)
                  )
            ) AS running_balance
        {$joinSql}
        WHERE {$whereSql}
        ORDER BY {$sortBy} {$sortOrder}, ct.id {$sortOrder}
        LIMIT ? OFFSET ?";

    $dataStmt = $conn->prepare($dataSql);
    if (!$dataStmt) {
        throw new RuntimeException('Unable to load cash transactions.', 500);
    }
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';
    cashBindParams($dataStmt, $dataTypes, $dataParams);
    $dataStmt->execute();
    $transactions = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    foreach ($transactions as &$transaction) {
        foreach (['id', 'account_id', 'accounting_year', 'created_by_user_id'] as $field) {
            $transaction[$field] = (int) $transaction[$field];
        }
        $transaction['category_id'] = $transaction['category_id'] !== null ? (int) $transaction['category_id'] : null;
        $transaction['iou_id'] = $transaction['iou_id'] !== null ? (int) $transaction['iou_id'] : null;
        $transaction['receipt_count'] = (int) ($transaction['receipt_count'] ?? 0);
        $transaction['affects_balance'] = cashTransactionAffectsBalance((string) $transaction['transaction_type']);
        foreach (['amount', 'iou_outstanding_amount', 'running_balance'] as $field) {
            if ($transaction[$field] !== null) {
                $transaction[$field] = round((float) $transaction[$field], 2);
            }
        }
    }
    unset($transaction);

    $dayBeforeStart = (new DateTimeImmutable($startDate))->modify('-1 day')->format('Y-m-d');
    $openingBalance = cashGetBalance($conn, $accountId, $dayBeforeStart);
    $cashIn = round((float) ($periodTotals['cash_in'] ?? 0), 2);
    $cashOut = round((float) ($periodTotals['cash_out'] ?? 0), 2);
    $periodClosingBalance = cashGetBalance($conn, $accountId, $endDate);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash transactions fetched successfully.',
        'data' => $transactions,
        'summary' => [
            'opening_balance' => $openingBalance,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'filtered_net_movement' => round($cashIn - $cashOut, 2),
            'closing_balance' => $periodClosingBalance,
            'current_cash_balance' => cashGetBalance($conn, $accountId),
            'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, $accountId),
            'current_available_balance' => cashGetUsableBalance($conn, $accountId),
        ],
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'total_pages' => (int) max(1, ceil($total / $limit)),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'transaction_type' => $transactionType,
            'direction' => $direction,
            'receipt_status' => $receiptStatus,
            'iou_status' => $iouStatus,
            'category_id' => $categoryId ?: null,
            'search' => $search,
            'sortBy' => $sortByKey,
            'sortOrder' => $sortOrder,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load cash transactions.');
}
