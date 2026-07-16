<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    $accountId = (int) $account['id'];

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $cashBalance = cashGetBalance($conn, $accountId);
    $pendingMutilatedCash = cashGetPendingMutilatedAmount($conn, $accountId);
    $availableBalance = cashGetUsableBalance($conn, $accountId);
    $openingBalance = cashGetUsableBalance($conn, $accountId, (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'));

    $summarySql = "SELECT
            COALESCE(SUM(CASE WHEN transaction_date = ? AND direction = 'IN' AND transaction_type = 'CASH_RECEIPT' THEN amount ELSE 0 END), 0) AS received_today,
            COALESCE(SUM(CASE WHEN transaction_date = ? AND direction = 'OUT' AND transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT') THEN amount ELSE 0 END), 0) AS disbursed_today,
            COALESCE(SUM(CASE WHEN transaction_date = ? AND direction = 'IN' AND transaction_type = 'CASH_RETURN' THEN amount ELSE 0 END), 0) AS returned_today,
            COALESCE(SUM(CASE WHEN transaction_date = ? AND direction = 'OUT' AND transaction_type = 'REIMBURSEMENT' THEN amount ELSE 0 END), 0) AS reimbursements_today,
            COUNT(CASE WHEN transaction_date = ? THEN 1 END) AS transactions_today
        FROM cash_transactions
        WHERE account_id = ?
          AND status = 'POSTED'";

    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new RuntimeException('Unable to calculate the Cash Desk summary.', 500);
    }
    $summaryStmt->bind_param('sssssi', $today, $today, $today, $today, $today, $accountId);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $iouSql = "SELECT
            COUNT(CASE WHEN status NOT IN ('CLOSED', 'REVERSED') THEN 1 END) AS open_ious,
            COALESCE(SUM(CASE WHEN status NOT IN ('CLOSED', 'REVERSED') THEN outstanding_amount ELSE 0 END), 0) AS outstanding_iou_value,
            COUNT(CASE WHEN status NOT IN ('CLOSED', 'REVERSED') AND expected_retirement_date IS NOT NULL AND expected_retirement_date < ? THEN 1 END) AS overdue_ious
        FROM cash_ious
        WHERE account_id = ?";
    $iouStmt = $conn->prepare($iouSql);
    if (!$iouStmt) {
        throw new RuntimeException('Unable to calculate the IOU summary.', 500);
    }
    $iouStmt->bind_param('si', $today, $accountId);
    $iouStmt->execute();
    $iouSummary = $iouStmt->get_result()->fetch_assoc() ?: [];
    $iouStmt->close();

    $receiptStmt = $conn->prepare("SELECT COUNT(*) AS pending_receipts
                                   FROM cash_transactions
                                   WHERE account_id = ?
                                     AND status = 'POSTED'
                                     AND direction = 'OUT'
                                     AND receipt_status IN ('PENDING', 'PARTIAL')");
    if (!$receiptStmt) {
        throw new RuntimeException('Unable to calculate the receipt summary.', 500);
    }
    $receiptStmt->bind_param('i', $accountId);
    $receiptStmt->execute();
    $pendingReceipts = (int) (($receiptStmt->get_result()->fetch_assoc()['pending_receipts'] ?? 0));
    $receiptStmt->close();

    $recentSql = "SELECT
            ct.id,
            ct.transaction_reference,
            ct.transaction_date,
            ct.transaction_type,
            ct.direction,
            ct.person_name,
            ct.amount,
            ct.reason,
            ct.receipt_status,
            ct.status,
            ct.created_at,
            cc.category_name,
            ci.id AS iou_id,
            ci.iou_reference,
            ci.status AS iou_status
        FROM cash_transactions ct
        LEFT JOIN cash_categories cc ON cc.id = ct.category_id
        LEFT JOIN cash_ious ci ON ci.source_transaction_id = ct.id
        WHERE ct.account_id = ?
        ORDER BY ct.transaction_date DESC, ct.id DESC
        LIMIT 8";
    $recentStmt = $conn->prepare($recentSql);
    if (!$recentStmt) {
        throw new RuntimeException('Unable to load recent cash activity.', 500);
    }
    $recentStmt->bind_param('i', $accountId);
    $recentStmt->execute();
    $recent = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentStmt->close();

    foreach ($recent as &$row) {
        $row['id'] = (int) $row['id'];
        $row['amount'] = round((float) $row['amount'], 2);
        $row['iou_id'] = $row['iou_id'] !== null ? (int) $row['iou_id'] : null;
        $row['affects_balance'] = cashTransactionAffectsBalance((string) $row['transaction_type']);
    }
    unset($row);

    $settings = cashGetSettings($conn, $accountId);
    $lowBalanceThreshold = round((float) ($settings['low_balance_threshold'] ?? 0), 2);

    $thirtyDaysAgo = (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
    $trendStmt = $conn->prepare("SELECT
            COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount ELSE 0 END), 0) AS cash_out_30_days,
            COUNT(DISTINCT CASE WHEN direction = 'OUT' THEN transaction_date END) AS spending_days,
            COALESCE(MAX(CASE WHEN direction = 'OUT' THEN amount ELSE 0 END), 0) AS largest_disbursement
        FROM cash_transactions
        WHERE account_id = ?
          AND transaction_date BETWEEN ? AND ?
          AND transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT')
          AND status = 'POSTED'");
    if (!$trendStmt) {
        throw new RuntimeException('Unable to calculate Cash Desk activity insights.', 500);
    }
    $trendStmt->bind_param('iss', $accountId, $thirtyDaysAgo, $today);
    $trendStmt->execute();
    $trend = $trendStmt->get_result()->fetch_assoc() ?: [];
    $trendStmt->close();
    $cashOut30Days = round((float) ($trend['cash_out_30_days'] ?? 0), 2);
    $averageDailyOutflow = round($cashOut30Days / 30, 2);
    $estimatedDaysRemaining = $averageDailyOutflow > 0 ? round($availableBalance / $averageDailyOutflow, 1) : null;
    $targetFloat = round(max($lowBalanceThreshold, $averageDailyOutflow * 7), 2);
    $suggestedTopUp = round(max(0, $targetFloat - $availableBalance), 2);

    $attentionStmt = $conn->prepare("SELECT id, iou_reference, recipient_name, outstanding_amount, expected_retirement_date, status
                                     FROM cash_ious
                                     WHERE account_id = ?
                                       AND status NOT IN ('CLOSED', 'REVERSED')
                                     ORDER BY (expected_retirement_date IS NOT NULL AND expected_retirement_date < ?) DESC,
                                              expected_retirement_date ASC, id DESC
                                     LIMIT 5");
    if (!$attentionStmt) {
        throw new RuntimeException('Unable to load IOUs requiring attention.', 500);
    }
    $attentionStmt->bind_param('is', $accountId, $today);
    $attentionStmt->execute();
    $iousRequiringAttention = $attentionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $attentionStmt->close();
    foreach ($iousRequiringAttention as &$attentionIou) {
        $attentionIou['id'] = (int) $attentionIou['id'];
        $attentionIou['outstanding_amount'] = round((float) $attentionIou['outstanding_amount'], 2);
        $attentionIou['is_overdue'] = !empty($attentionIou['expected_retirement_date'])
            && $attentionIou['expected_retirement_date'] < $today;
    }
    unset($attentionIou);

    $lastClosureStmt = $conn->prepare("SELECT closure_date, system_closing_balance, physical_cash_counted, difference_amount, status, closed_at
                                       FROM cash_daily_closures
                                       WHERE account_id = ?
                                       ORDER BY closure_date DESC
                                       LIMIT 1");
    if (!$lastClosureStmt) {
        throw new RuntimeException('Unable to load the latest daily close.', 500);
    }
    $lastClosureStmt->bind_param('i', $accountId);
    $lastClosureStmt->execute();
    $lastClosure = $lastClosureStmt->get_result()->fetch_assoc() ?: null;
    $lastClosureStmt->close();

    if ($lastClosure) {
        foreach (['system_closing_balance', 'physical_cash_counted', 'difference_amount'] as $field) {
            $lastClosure[$field] = round((float) $lastClosure[$field], 2);
        }
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk dashboard loaded successfully.',
        'data' => [
            'account' => [
                'id' => $accountId,
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'currency' => $account['currency'],
                'access_level' => $account['access_level'],
            ],
            'summary' => [
                'date' => $today,
                'opening_balance' => $openingBalance,
                'cash_received_today' => round((float) ($summary['received_today'] ?? 0), 2),
                'cash_disbursed_today' => round((float) ($summary['disbursed_today'] ?? 0), 2),
                'cash_returned_today' => round((float) ($summary['returned_today'] ?? 0), 2),
                'reimbursements_paid_today' => round((float) ($summary['reimbursements_today'] ?? 0), 2),
                'cash_balance' => $cashBalance,
                'pending_mutilated_cash' => $pendingMutilatedCash,
                'available_balance' => $availableBalance,
                'transactions_today' => (int) ($summary['transactions_today'] ?? 0),
                'open_ious' => (int) ($iouSummary['open_ious'] ?? 0),
                'outstanding_iou_value' => round((float) ($iouSummary['outstanding_iou_value'] ?? 0), 2),
                'overdue_ious' => (int) ($iouSummary['overdue_ious'] ?? 0),
                'pending_receipts' => $pendingReceipts,
                'low_balance_threshold' => $lowBalanceThreshold,
                'is_low_balance' => $availableBalance <= $lowBalanceThreshold,
            ],
            'recent_transactions' => $recent,
            'smart_insights' => [
                'average_daily_outflow_30_days' => $averageDailyOutflow,
                'cash_out_last_30_days' => $cashOut30Days,
                'spending_days_last_30_days' => (int) ($trend['spending_days'] ?? 0),
                'largest_disbursement_last_30_days' => round((float) ($trend['largest_disbursement'] ?? 0), 2),
                'estimated_days_of_cash_remaining' => $estimatedDaysRemaining,
                'target_float' => $targetFloat,
                'suggested_top_up' => $suggestedTopUp,
                'ious_requiring_attention' => $iousRequiringAttention,
            ],
            'last_daily_close' => $lastClosure,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load the Cash Desk dashboard.');
}
