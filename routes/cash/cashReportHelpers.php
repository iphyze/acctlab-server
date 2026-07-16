<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

function cashReportRange(array $user): array
{
    $year = (int) ($user['accounting_period'] ?? date('Y'));
    $defaultDate = (int) date('Y') === $year
        ? date('Y-m-d')
        : sprintf('%04d-01-01', $year);
    $startDate = !empty($_GET['start_date'])
        ? cashParseIsoDate($_GET['start_date'], 'Start date', true)
        : $defaultDate;
    $endDate = !empty($_GET['end_date'])
        ? cashParseIsoDate($_GET['end_date'], 'End date', true)
        : $defaultDate;
    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Start date cannot be later than end date.', 422);
    }
    cashAssertAccountingPeriod($user, $startDate);
    cashAssertAccountingPeriod($user, $endDate);

    return [$startDate, $endDate];
}

function cashBuildReportData(mysqli $conn, array $user, array $account, string $startDate, string $endDate, int $detailLimit = 500): array
{
    $accountId = (int) $account['id'];
    $dayBeforeStart = (new DateTimeImmutable($startDate))->modify('-1 day')->format('Y-m-d');
    $openingBalance = cashGetBalance($conn, $accountId, $dayBeforeStart);
    $openingUsableBalance = cashGetUsableBalance($conn, $accountId, $dayBeforeStart);
    $closingBalance = cashGetBalance($conn, $accountId, $endDate);

    $summarySql = "SELECT
            COUNT(*) AS transaction_count,
            COALESCE(SUM(CASE WHEN direction = 'IN' AND transaction_type NOT IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN amount ELSE 0 END), 0) AS total_cash_in,
            COALESCE(SUM(CASE WHEN direction = 'OUT' AND transaction_type NOT IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN amount ELSE 0 END), 0) AS total_cash_out,
            COALESCE(SUM(CASE WHEN transaction_type = 'CASH_RECEIPT' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS cash_received,
            COALESCE(SUM(CASE WHEN transaction_type = 'CASH_RETURN' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS cash_returned,
            COALESCE(SUM(CASE WHEN transaction_type = 'DIRECT_DISBURSEMENT' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS direct_disbursements,
            COALESCE(SUM(CASE WHEN transaction_type = 'IOU_DISBURSEMENT' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS iou_advances,
            COALESCE(SUM(CASE WHEN transaction_type = 'REIMBURSEMENT' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS reimbursements_paid,
            COALESCE(SUM(CASE WHEN status = 'POSTED' AND transaction_type = 'MUTILATED_CASH_SET_ASIDE' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS mutilated_cash_set_aside,
            COALESCE(SUM(CASE WHEN status = 'POSTED' AND transaction_type = 'MUTILATED_CASH_REPLACEMENT' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS mutilated_cash_replaced,
            COALESCE(SUM(CASE WHEN status = 'POSTED' AND transaction_type = 'MUTILATED_CASH_BANK_RETURN' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS mutilated_cash_returned_to_bank,
            COALESCE(SUM(CASE WHEN transaction_type = 'REVERSAL' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS reversals_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'REVERSAL' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS reversals_out
        FROM cash_transactions
        WHERE account_id = ?
          AND transaction_date BETWEEN ? AND ?
          AND status IN ('POSTED', 'REVERSED')";
    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new RuntimeException('Unable to calculate the cash report summary.', 500);
    }
    $summaryStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    foreach (['total_cash_in', 'total_cash_out', 'cash_received', 'cash_returned', 'direct_disbursements', 'iou_advances', 'reimbursements_paid', 'mutilated_cash_set_aside', 'mutilated_cash_replaced', 'mutilated_cash_returned_to_bank', 'reversals_in', 'reversals_out'] as $field) {
        $summary[$field] = round((float) ($summary[$field] ?? 0), 2);
    }
    $summary['transaction_count'] = (int) ($summary['transaction_count'] ?? 0);
    $summary['opening_balance'] = $openingBalance;
    $summary['closing_balance'] = $closingBalance;
    $summary['net_movement'] = round((float) $summary['total_cash_in'] - (float) $summary['total_cash_out'], 2);

    $dailyStmt = $conn->prepare("SELECT
            transaction_date,
            COUNT(*) AS transaction_count,
            COALESCE(SUM(CASE WHEN direction = 'IN' AND transaction_type NOT IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN amount ELSE 0 END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN direction = 'OUT' AND transaction_type NOT IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN amount ELSE 0 END), 0) AS cash_out,
            COALESCE(SUM(CASE WHEN status = 'POSTED' AND transaction_type = 'MUTILATED_CASH_SET_ASIDE' THEN amount ELSE 0 END), 0) AS mutilated_classified,
            COALESCE(SUM(CASE WHEN status = 'POSTED' AND transaction_type = 'MUTILATED_CASH_REPLACEMENT' THEN amount ELSE 0 END), 0) AS mutilated_replaced,
            COALESCE(SUM(CASE WHEN status = 'POSTED' AND transaction_type = 'MUTILATED_CASH_BANK_RETURN' THEN amount ELSE 0 END), 0) AS mutilated_bank_return
        FROM cash_transactions
        WHERE account_id = ? AND transaction_date BETWEEN ? AND ? AND status IN ('POSTED', 'REVERSED')
        GROUP BY transaction_date
        ORDER BY transaction_date ASC");
    if (!$dailyStmt) {
        throw new RuntimeException('Unable to calculate daily cash activity.', 500);
    }
    $dailyStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $dailyStmt->execute();
    $daily = $dailyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dailyStmt->close();
    $running = $openingBalance;
    foreach ($daily as &$row) {
        $row['transaction_count'] = (int) $row['transaction_count'];
        $row['cash_in'] = round((float) $row['cash_in'], 2);
        $row['cash_out'] = round((float) $row['cash_out'], 2);
        $row['mutilated_classified'] = round((float) $row['mutilated_classified'], 2);
        $row['mutilated_replaced'] = round((float) $row['mutilated_replaced'], 2);
        $row['mutilated_bank_return'] = round((float) $row['mutilated_bank_return'], 2);
        $row['net_movement'] = round($row['cash_in'] - $row['cash_out'], 2);
        $row['usable_in'] = round($row['cash_in'] + $row['mutilated_replaced'], 2);
        $row['usable_out'] = round(
            $row['cash_out'] - $row['mutilated_bank_return'] + $row['mutilated_classified'],
            2
        );
        $row['net_usable_movement'] = round($row['usable_in'] - $row['usable_out'], 2);
        $running = round($running + $row['net_movement'], 2);
        $row['closing_balance'] = $running;
        $row['closing_usable_balance'] = cashGetUsableBalance($conn, $accountId, (string) $row['transaction_date']);
    }
    unset($row);

    $categoryStmt = $conn->prepare("SELECT
            COALESCE(cc.category_name, 'Uncategorised') AS category_name,
            COUNT(*) AS transaction_count,
            COALESCE(SUM(ct.amount), 0) AS amount
        FROM cash_transactions ct
        LEFT JOIN cash_categories cc ON cc.id = ct.category_id
        WHERE ct.account_id = ?
          AND ct.transaction_date BETWEEN ? AND ?
          AND ct.direction = 'OUT'
          AND ct.transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT')
          AND ct.status = 'POSTED'
        GROUP BY COALESCE(cc.category_name, 'Uncategorised')
        ORDER BY amount DESC, category_name ASC");
    if (!$categoryStmt) {
        throw new RuntimeException('Unable to calculate the expense-category report.', 500);
    }
    $categoryStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $categoryStmt->execute();
    $categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoryStmt->close();
    foreach ($categories as &$row) {
        $row['transaction_count'] = (int) $row['transaction_count'];
        $row['amount'] = round((float) $row['amount'], 2);
    }
    unset($row);

    $recipientStmt = $conn->prepare("SELECT person_name, COUNT(*) AS transaction_count, COALESCE(SUM(amount), 0) AS amount
        FROM cash_transactions
        WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
          AND direction = 'OUT'
          AND transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT')
          AND status = 'POSTED'
        GROUP BY person_name
        ORDER BY amount DESC, person_name ASC
        LIMIT 100");
    if (!$recipientStmt) {
        throw new RuntimeException('Unable to calculate recipient activity.', 500);
    }
    $recipientStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $recipientStmt->execute();
    $recipients = $recipientStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recipientStmt->close();
    foreach ($recipients as &$row) {
        $row['transaction_count'] = (int) $row['transaction_count'];
        $row['amount'] = round((float) $row['amount'], 2);
    }
    unset($row);

    $iouStmt = $conn->prepare("SELECT
            ci.id, ci.iou_reference, ci.recipient_name, ci.amount_advanced, ci.actual_amount_spent,
            ci.amount_returned, ci.reimbursement_paid, ci.outstanding_amount, ci.expected_retirement_date,
            ci.status, ci.receipt_status, ct.transaction_date, ct.reason, cc.category_name
        FROM cash_ious ci
        INNER JOIN cash_transactions ct ON ct.id = ci.source_transaction_id
        LEFT JOIN cash_categories cc ON cc.id = ct.category_id
        WHERE ci.account_id = ?
          AND ct.transaction_date <= ?
          AND (ct.transaction_date >= ? OR ci.status NOT IN ('CLOSED', 'REVERSED'))
        ORDER BY (ci.status NOT IN ('CLOSED', 'REVERSED')) DESC, ci.expected_retirement_date ASC, ci.id DESC");
    if (!$iouStmt) {
        throw new RuntimeException('Unable to load the IOU report.', 500);
    }
    $iouStmt->bind_param('iss', $accountId, $endDate, $startDate);
    $iouStmt->execute();
    $ious = $iouStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iouStmt->close();
    $iouSummary = [
        'open_count' => 0,
        'overdue_count' => 0,
        'closed_count' => 0,
        'outstanding_value' => 0.0,
        'cash_return_due' => 0.0,
        'reimbursement_due' => 0.0,
    ];
    foreach ($ious as &$iou) {
        $iou = cashNormalizeIouRow($iou);
        if (in_array($iou['status'], ['CLOSED', 'REVERSED'], true)) {
            $iouSummary['closed_count']++;
        } else {
            $iouSummary['open_count']++;
            $iouSummary['outstanding_value'] += (float) $iou['outstanding_amount'];
            $iouSummary['cash_return_due'] += (float) $iou['cash_return_due'];
            $iouSummary['reimbursement_due'] += (float) $iou['reimbursement_due'];
            if ($iou['display_status'] === 'OVERDUE') {
                $iouSummary['overdue_count']++;
            }
        }
    }
    unset($iou);
    foreach (['outstanding_value', 'cash_return_due', 'reimbursement_due'] as $field) {
        $iouSummary[$field] = round((float) $iouSummary[$field], 2);
    }

    $receiptStmt = $conn->prepare("SELECT
            cr.id, cr.document_type, cr.original_filename, cr.mime_type, cr.file_size,
            cr.uploaded_by_email, cr.created_at, cr.status,
            COALESCE(ct.transaction_reference, source_ct.transaction_reference) AS transaction_reference,
            COALESCE(ct.transaction_date, source_ct.transaction_date) AS transaction_date,
            COALESCE(ct.person_name, ci.recipient_name) AS person_name,
            ci.iou_reference
        FROM cash_receipts cr
        LEFT JOIN cash_transactions ct ON ct.id = cr.transaction_id
        LEFT JOIN cash_ious ci ON ci.id = cr.iou_id
        LEFT JOIN cash_transactions source_ct ON source_ct.id = ci.source_transaction_id
        WHERE COALESCE(ct.account_id, ci.account_id) = ?
          AND COALESCE(ct.transaction_date, source_ct.transaction_date, DATE(cr.created_at)) BETWEEN ? AND ?
          AND cr.status = 'ACTIVE'
        ORDER BY transaction_date ASC, cr.id ASC");
    if (!$receiptStmt) {
        throw new RuntimeException('Unable to load the receipt register report.', 500);
    }
    $receiptStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $receiptStmt->execute();
    $receipts = $receiptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $receiptStmt->close();
    foreach ($receipts as &$receipt) {
        $receipt['id'] = (int) $receipt['id'];
        $receipt['file_size'] = (int) $receipt['file_size'];
        $receipt['download_path'] = '/cash/receipts/download?id=' . $receipt['id'];
    }
    unset($receipt);

    $pendingReceiptStmt = $conn->prepare("SELECT COUNT(*) AS total
        FROM cash_transactions
        WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
          AND direction = 'OUT' AND receipt_status IN ('PENDING', 'PARTIAL')
          AND status = 'POSTED'");
    if (!$pendingReceiptStmt) {
        throw new RuntimeException('Unable to calculate pending receipts.', 500);
    }
    $pendingReceiptStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $pendingReceiptStmt->execute();
    $pendingReceiptCount = (int) ($pendingReceiptStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $pendingReceiptStmt->close();

    $closureStmt = $conn->prepare("SELECT * FROM cash_daily_closures
                                   WHERE account_id = ? AND closure_date BETWEEN ? AND ?
                                   ORDER BY closure_date ASC");
    if (!$closureStmt) {
        throw new RuntimeException('Unable to load daily-close reports.', 500);
    }
    $closureStmt->bind_param('iss', $accountId, $startDate, $endDate);
    $closureStmt->execute();
    $closures = $closureStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $closureStmt->close();
    foreach ($closures as &$closure) {
        foreach (['id', 'account_id', 'closed_by_user_id', 'reopened_by_user_id'] as $field) {
            $closure[$field] = $closure[$field] !== null ? (int) $closure[$field] : null;
        }
        foreach (['opening_balance', 'cash_received', 'cash_returned', 'cash_disbursed', 'reimbursements_paid', 'system_closing_balance', 'physical_cash_counted', 'difference_amount'] as $field) {
            $closure[$field] = round((float) $closure[$field], 2);
        }
    }
    unset($closure);

    $mutilatedStmt = $conn->prepare(cashMutilatedCashSelectSql() . "
        WHERE cmc.account_id = ?
          AND cmc.discovered_date <= ?
          AND (
              cmc.discovered_date >= ?
              OR (cmc.return_date IS NULL OR cmc.return_date > ?)
          )
        ORDER BY (cmc.status = 'PENDING_RETURN') DESC, cmc.discovered_date DESC, cmc.id DESC");
    if (!$mutilatedStmt) {
        throw new RuntimeException('Unable to load the mutilated cash register.', 500);
    }
    $mutilatedStmt->bind_param('isss', $accountId, $endDate, $startDate, $endDate);
    $mutilatedStmt->execute();
    $mutilatedCash = $mutilatedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mutilatedStmt->close();

    $mutilatedSummary = [
        'period_set_aside_count' => 0,
        'period_set_aside_amount' => 0.0,
        'period_returned_count' => 0,
        'period_returned_amount' => 0.0,
        'period_replaced_count' => 0,
        'period_replaced_amount' => 0.0,
        'pending_count' => 0,
        'pending_amount' => 0.0,
    ];
    foreach ($mutilatedCash as &$record) {
        $record = cashNormalizeMutilatedCashRow($record);
        if ($record['discovered_date'] >= $startDate && $record['discovered_date'] <= $endDate) {
            $mutilatedSummary['period_set_aside_count']++;
            $mutilatedSummary['period_set_aside_amount'] += (float) $record['amount'];
        }
        if (!empty($record['return_date']) && $record['return_date'] >= $startDate && $record['return_date'] <= $endDate) {
            if ($record['resolution_type'] === 'REPLACED') {
                $mutilatedSummary['period_replaced_count']++;
                $mutilatedSummary['period_replaced_amount'] += (float) $record['amount'];
            } elseif ($record['resolution_type'] === 'RETURNED') {
                $mutilatedSummary['period_returned_count']++;
                $mutilatedSummary['period_returned_amount'] += (float) $record['amount'];
            }
        }
        $wasPendingAtPeriodEnd = $record['discovered_date'] <= $endDate
            && (empty($record['return_date']) || $record['return_date'] > $endDate);
        if ($wasPendingAtPeriodEnd) {
            $mutilatedSummary['pending_count']++;
            $mutilatedSummary['pending_amount'] += (float) $record['amount'];
        }
    }
    unset($record);
    foreach (['period_set_aside_amount', 'period_returned_amount', 'period_replaced_amount', 'pending_amount'] as $field) {
        $mutilatedSummary[$field] = round((float) $mutilatedSummary[$field], 2);
    }

    $usableInflows = round(
        (float) $summary['cash_received']
        + (float) $summary['cash_returned']
        + (float) $summary['mutilated_cash_replaced']
        + (float) $summary['reversals_in'],
        2
    );
    $usableReductions = round(
        (float) $summary['direct_disbursements']
        + (float) $summary['iou_advances']
        + (float) $summary['reimbursements_paid']
        + (float) $summary['mutilated_cash_set_aside']
        + (float) $summary['reversals_out'],
        2
    );
    $cashPosition = [
        'opening_balance' => $openingUsableBalance,
        'additional_cash' => (float) $summary['cash_received'],
        'iou_retirement_cash' => (float) $summary['cash_returned'],
        'mutilated_cash_replacements' => (float) $summary['mutilated_cash_replaced'],
        'mutilated_cash_bank_returns' => (float) $summary['mutilated_cash_returned_to_bank'],
        'reversals_in' => (float) $summary['reversals_in'],
        'total_usable_inflows' => $usableInflows,
        'cash_available_before_outflows' => round($openingUsableBalance + $usableInflows, 2),
        'direct_disbursements' => (float) $summary['direct_disbursements'],
        'iou_advances' => (float) $summary['iou_advances'],
        'reimbursements_paid' => (float) $summary['reimbursements_paid'],
        'mutilated_cash_set_aside' => (float) $summary['mutilated_cash_set_aside'],
        'reversals_out' => (float) $summary['reversals_out'],
        'total_outflows' => $usableReductions,
        'closing_cash_balance' => $closingBalance,
        'pending_mutilated_cash' => (float) $mutilatedSummary['pending_amount'],
        'closing_usable_balance' => round($closingBalance - (float) $mutilatedSummary['pending_amount'], 2),
        'total_accounted_cash' => $closingBalance,
    ];

    $transactions = [];
    if ($detailLimit > 0) {
        $detailLimit = min(max($detailLimit, 1), 50000);
        $detailSql = "SELECT
                ct.id, ct.transaction_reference, ct.transaction_date, ct.transaction_type, ct.direction,
                ct.person_name, ct.amount, ct.reason, ct.description, ct.external_reference,
                ct.receipt_status, ct.status, ct.created_by_email, ct.created_at,
                cc.category_name, ci.iou_reference, ci.status AS iou_status,
                (SELECT COALESCE(SUM(CASE
                        WHEN prior.transaction_type IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN 0
                        WHEN prior.direction = 'IN' THEN prior.amount
                        ELSE -prior.amount
                    END), 0)
                 FROM cash_transactions prior
                 WHERE prior.account_id = ct.account_id
                   AND prior.status IN ('POSTED', 'REVERSED')
                   AND (prior.transaction_date < ct.transaction_date OR (prior.transaction_date = ct.transaction_date AND prior.id <= ct.id))) AS running_balance
            FROM cash_transactions ct
            LEFT JOIN cash_categories cc ON cc.id = ct.category_id
            LEFT JOIN cash_ious ci ON ci.source_transaction_id = ct.id
            WHERE ct.account_id = ? AND ct.transaction_date BETWEEN ? AND ? AND ct.status IN ('POSTED', 'REVERSED')
            ORDER BY ct.transaction_date ASC, ct.id ASC
            LIMIT ?";
        $detailStmt = $conn->prepare($detailSql);
        if (!$detailStmt) {
            throw new RuntimeException('Unable to load report transactions.', 500);
        }
        $detailStmt->bind_param('issi', $accountId, $startDate, $endDate, $detailLimit);
        $detailStmt->execute();
        $transactions = $detailStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $detailStmt->close();
        $runningPendingMutilated = cashGetPendingMutilatedAmount($conn, $accountId, $dayBeforeStart);
        foreach ($transactions as &$transaction) {
            $transaction['id'] = (int) $transaction['id'];
            $transaction['amount'] = round((float) $transaction['amount'], 2);
            $transaction['running_balance'] = round((float) $transaction['running_balance'], 2);
            $transaction['affects_balance'] = cashTransactionAffectsBalance((string) $transaction['transaction_type']);
            $type = strtoupper((string) $transaction['transaction_type']);
            $status = strtoupper((string) $transaction['status']);
            if ($status === 'POSTED' && $type === 'MUTILATED_CASH_SET_ASIDE') {
                $runningPendingMutilated = round($runningPendingMutilated + (float) $transaction['amount'], 2);
            } elseif ($status === 'POSTED' && in_array($type, ['MUTILATED_CASH_REPLACEMENT', 'MUTILATED_CASH_BANK_RETURN'], true)) {
                $runningPendingMutilated = round(max(0, $runningPendingMutilated - (float) $transaction['amount']), 2);
            }
            $transaction['running_usable_balance'] = round(
                (float) $transaction['running_balance'] - $runningPendingMutilated,
                2
            );
        }
        unset($transaction);
    }

    return [
        'account' => [
            'id' => $accountId,
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'currency' => $account['currency'],
        ],
        'period' => ['start_date' => $startDate, 'end_date' => $endDate],
        'summary' => $summary,
        'daily_activity' => $daily,
        'category_summary' => $categories,
        'recipient_summary' => $recipients,
        'iou_summary' => $iouSummary,
        'ious' => $ious,
        'receipt_summary' => [
            'uploaded_count' => count($receipts),
            'pending_count' => $pendingReceiptCount,
        ],
        'receipts' => $receipts,
        'daily_closures' => $closures,
        'cash_position' => $cashPosition,
        'mutilated_summary' => $mutilatedSummary,
        'mutilated_cash' => $mutilatedCash,
        'transactions' => $transactions,
        'generated_at' => date(DATE_ATOM),
        'generated_by' => $user['email'],
    ];
}
