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
        throw new InvalidArgumentException('Invalid receipt pagination values.', 422);
    }
    $offset = ($page - 1) * $limit;
    $year = (int) ($user['accounting_period'] ?? date('Y'));
    $startDate = !empty($_GET['start_date']) ? cashParseIsoDate($_GET['start_date'], 'Start date', true) : sprintf('%04d-01-01', $year);
    $endDate = !empty($_GET['end_date']) ? cashParseIsoDate($_GET['end_date'], 'End date', true) : sprintf('%04d-12-31', $year);
    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Start date cannot be later than end date.', 422);
    }

    $status = strtoupper(trim((string) ($_GET['status'] ?? 'ACTIVE')));
    if (!in_array($status, ['ACTIVE', 'ARCHIVED', 'ALL'], true)) {
        throw new InvalidArgumentException('Invalid receipt status filter.', 422);
    }
    $documentType = strtoupper(trim((string) ($_GET['document_type'] ?? 'ALL')));
    if (!in_array($documentType, ['ALL', 'RECEIPT', 'INVOICE', 'VOUCHER', 'OTHER'], true)) {
        throw new InvalidArgumentException('Invalid document_type filter.', 422);
    }
    $search = trim((string) ($_GET['search'] ?? ''));

    $where = ['COALESCE(ct.account_id, ci.account_id) = ?', 'COALESCE(ct.transaction_date, source_ct.transaction_date, DATE(cr.created_at)) BETWEEN ? AND ?'];
    $params = [$accountId, $startDate, $endDate];
    $types = 'iss';
    if ($status !== 'ALL') {
        $where[] = 'cr.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($documentType !== 'ALL') {
        $where[] = 'cr.document_type = ?';
        $params[] = $documentType;
        $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(cr.original_filename LIKE ? OR ct.transaction_reference LIKE ? OR ci.iou_reference LIKE ? OR COALESCE(ct.person_name, ci.recipient_name) LIKE ?)';
        for ($i = 0; $i < 4; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $join = "FROM cash_receipts cr
             LEFT JOIN cash_transactions ct ON ct.id = cr.transaction_id
             LEFT JOIN cash_ious ci ON ci.id = cr.iou_id
             LEFT JOIN cash_transactions source_ct ON source_ct.id = ci.source_transaction_id";
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total {$join} WHERE {$whereSql}");
    if (!$countStmt) {
        throw new RuntimeException('Unable to count receipts.', 500);
    }
    $countParams = $params;
    cashBindParams($countStmt, $types, $countParams);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT
                cr.id, cr.transaction_id, cr.iou_id, cr.document_type, cr.original_filename,
                cr.mime_type, cr.file_size, cr.status, cr.uploaded_by_email, cr.created_at,
                COALESCE(ct.transaction_reference, source_ct.transaction_reference) AS transaction_reference,
                COALESCE(ct.transaction_date, source_ct.transaction_date, DATE(cr.created_at)) AS transaction_date,
                COALESCE(ct.person_name, ci.recipient_name) AS person_name,
                COALESCE(ct.amount, source_ct.amount) AS transaction_amount,
                ci.iou_reference, ci.status AS iou_status
            {$join}
            WHERE {$whereSql}
            ORDER BY transaction_date DESC, cr.id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load receipts.', 500);
    }
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    cashBindParams($stmt, $types . 'ii', $dataParams);
    $stmt->execute();
    $receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($receipts as &$receipt) {
        foreach (['id', 'transaction_id', 'iou_id', 'file_size'] as $field) {
            $receipt[$field] = $receipt[$field] !== null ? (int) $receipt[$field] : null;
        }
        $receipt['transaction_amount'] = $receipt['transaction_amount'] !== null ? round((float) $receipt['transaction_amount'], 2) : null;
        $receipt['download_path'] = '/cash/receipts/download?id=' . $receipt['id'];
    }
    unset($receipt);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Receipt register loaded successfully.',
        'data' => $receipts,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'total_pages' => (int) max(1, ceil($total / $limit)),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
            'document_type' => $documentType,
            'search' => $search,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load the receipt register.');
}
