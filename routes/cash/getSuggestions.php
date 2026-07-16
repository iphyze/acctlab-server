<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    $accountId = (int) $account['id'];

    $name = cashNullableText($_GET['name'] ?? null, 160) ?? '';
    $reason = cashNullableText($_GET['reason'] ?? null, 255) ?? '';
    $description = cashNullableText($_GET['description'] ?? null, 1000) ?? '';
    $searchContext = trim($reason . ' ' . $description);

    $categorySuggestion = null;
    if ($name !== '' || $searchContext !== '') {
        $like = '%' . $searchContext . '%';
        $stmt = $conn->prepare("SELECT
                cc.id, cc.category_code, cc.category_name,
                SUM(
                    CASE WHEN ? <> '' AND LOWER(TRIM(ct.person_name)) = LOWER(TRIM(?)) THEN 4 ELSE 0 END
                    + CASE WHEN ? <> '' AND (ct.reason LIKE ? OR ct.description LIKE ?) THEN 1 ELSE 0 END
                ) AS relevance_score,
                COUNT(*) AS previous_uses,
                MAX(ct.transaction_date) AS last_used_on
            FROM cash_transactions ct
            INNER JOIN cash_categories cc ON cc.id = ct.category_id AND cc.is_active = 1
            WHERE ct.account_id = ?
              AND ct.status = 'POSTED'
              AND ct.direction = 'OUT'
              AND ct.transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT')
              AND (
                    (? <> '' AND LOWER(TRIM(ct.person_name)) = LOWER(TRIM(?)))
                    OR (? <> '' AND (ct.reason LIKE ? OR ct.description LIKE ?))
              )
            GROUP BY cc.id, cc.category_code, cc.category_name
            HAVING relevance_score > 0
            ORDER BY relevance_score DESC, previous_uses DESC, last_used_on DESC
            LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Unable to calculate the category suggestion.', 500);
        }
        $stmt->bind_param(
            'sssssisssss',
            $name,
            $name,
            $searchContext,
            $like,
            $like,
            $accountId,
            $name,
            $name,
            $searchContext,
            $like,
            $like
        );
        $stmt->execute();
        $categorySuggestion = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($categorySuggestion) {
            $categorySuggestion['id'] = (int) $categorySuggestion['id'];
            $categorySuggestion['relevance_score'] = (int) $categorySuggestion['relevance_score'];
            $categorySuggestion['previous_uses'] = (int) $categorySuggestion['previous_uses'];
        }
    }

    $recipientStats = null;
    if ($name !== '') {
        $statsStmt = $conn->prepare("SELECT
                COUNT(*) AS previous_disbursements,
                COALESCE(AVG(amount), 0) AS average_amount,
                COALESCE(MAX(amount), 0) AS largest_amount,
                MAX(transaction_date) AS last_disbursement_date
            FROM cash_transactions
            WHERE account_id = ?
              AND status = 'POSTED'
              AND direction = 'OUT'
              AND transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT', 'REIMBURSEMENT')
              AND LOWER(TRIM(person_name)) = LOWER(TRIM(?))");
        if (!$statsStmt) {
            throw new RuntimeException('Unable to calculate recipient activity.', 500);
        }
        $statsStmt->bind_param('is', $accountId, $name);
        $statsStmt->execute();
        $recipientStats = $statsStmt->get_result()->fetch_assoc() ?: null;
        $statsStmt->close();
        if ($recipientStats) {
            $recipientStats['previous_disbursements'] = (int) $recipientStats['previous_disbursements'];
            $recipientStats['average_amount'] = round((float) $recipientStats['average_amount'], 2);
            $recipientStats['largest_amount'] = round((float) $recipientStats['largest_amount'], 2);
        }
    }

    $amount = null;
    $isUnusualAmount = false;
    if (isset($_GET['amount']) && $_GET['amount'] !== '') {
        $amount = cashParseAmount($_GET['amount'], 'Amount');
        if ($recipientStats && $recipientStats['previous_disbursements'] >= 3 && $recipientStats['average_amount'] > 0) {
            $isUnusualAmount = $amount > max($recipientStats['average_amount'] * 2.5, $recipientStats['largest_amount'] * 1.25);
        }
    }

    $possibleDuplicate = null;
    if ($name !== '' && $amount !== null && !empty($_GET['date'])) {
        $date = cashParseIsoDate($_GET['date'], 'Date', true);
        $possibleDuplicate = cashFindPossibleDuplicate($conn, $accountId, $date, $name, $amount, 'OUT');
    }

    $settings = cashGetSettings($conn, $accountId);
    $balance = cashGetUsableBalance($conn, $accountId);
    $threshold = round((float) ($settings['low_balance_threshold'] ?? 0), 2);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk suggestions generated successfully.',
        'data' => [
            'suggested_category' => $categorySuggestion,
            'recipient_history' => $recipientStats,
            'possible_duplicate' => $possibleDuplicate,
            'amount_warning' => [
                'is_unusual' => $isUnusualAmount,
                'message' => $isUnusualAmount
                    ? 'This amount is significantly higher than the recipient’s previous cash disbursements.'
                    : null,
            ],
            'balance_preview' => [
                'available_balance' => $balance,
                'entered_amount' => $amount,
                'balance_after_disbursement' => $amount !== null ? round($balance - $amount, 2) : null,
                'low_balance_threshold' => $threshold,
                'will_fall_below_threshold' => $amount !== null && ($balance - $amount) <= $threshold,
                'has_sufficient_cash' => $amount === null || (int) $account['allow_negative_balance'] === 1 || $amount <= $balance,
            ],
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to generate Cash Desk suggestions.');
}
