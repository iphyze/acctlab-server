<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function dashboardTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

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
    $recentActivityByCategory = [];
    $recentActivityPool = [];

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

        /*
         * Keep a small recent list for every request category.
         * Filtering the dashboard must not depend on whichever category happens
         * to dominate the latest eight requests across the whole application.
         */
        $recentStmt = $conn->prepare("
            SELECT
                ? AS category,
                ? AS category_label,
                id,
                suppliers_name AS reference,
                payment_status,
                $amountExpression AS amount,
                created_at
            FROM $table
            WHERE YEAR(created_at) = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 8
        ");
        if (!$recentStmt) {
            throw new Exception('Unable to prepare category recent activity analytics.', 500);
        }
        $categoryKey = (string) $source['key'];
        $categoryLabel = (string) $source['label'];
        $recentStmt->bind_param('ssi', $categoryKey, $categoryLabel, $year);
        $recentStmt->execute();
        $categoryRecent = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $recentStmt->close();

        foreach ($categoryRecent as &$recentItem) {
            $recentItem['id'] = (int) $recentItem['id'];
            $recentItem['amount'] = (float) $recentItem['amount'];
        }
        unset($recentItem);

        $recentActivityByCategory[$categoryKey] = $categoryRecent;
        foreach ($categoryRecent as $recentItem) {
            $recentActivityPool[] = $recentItem;
        }
    }

    $overview['completion_rate'] = $overview['total_amount'] > 0
        ? round(($overview['paid_amount'] / $overview['total_amount']) * 100, 1)
        : 0;

    usort($recentActivityPool, static function (array $left, array $right): int {
        $dateCompare = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
    });
    $recentActivity = array_slice($recentActivityPool, 0, 8);

    /*
     * FX exposure is intentionally returned per original request currency.
     * Values from different currencies are never converted or aggregated together.
     */
    $fxExposure = [];
    $fxSummary = [
        'currency_count' => 0,
        'request_count' => 0,
    ];

    if (dashboardTableExists($conn, 'fx_fund_request_table')) {
        $fxStmt = $conn->prepare(
            "SELECT
                UPPER(COALESCE(NULLIF(TRIM(currency), ''), 'UNKNOWN')) AS currency,
                COUNT(*) AS request_count,
                COALESCE(SUM(payable_amount), 0) AS requested_amount,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'paid' THEN payable_amount ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(payment_status)) IN ('pending', 'processing', 'unconfirmed') THEN payable_amount ELSE 0 END), 0) AS open_amount,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN LOWER(TRIM(payment_status)) = 'unconfirmed' THEN 1 ELSE 0 END) AS unconfirmed_count
             FROM fx_fund_request_table
             WHERE YEAR(created_at) = ?
             GROUP BY UPPER(COALESCE(NULLIF(TRIM(currency), ''), 'UNKNOWN'))
             ORDER BY currency ASC"
        );
        if (!$fxStmt) {
            throw new Exception('Unable to prepare FX exposure analytics.', 500);
        }
        $fxStmt->bind_param('i', $year);
        $fxStmt->execute();
        $fxRows = $fxStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fxStmt->close();

        foreach ($fxRows as $row) {
            $requestedAmount = (float) ($row['requested_amount'] ?? 0);
            $paidAmount = (float) ($row['paid_amount'] ?? 0);
            $requestCountForCurrency = (int) ($row['request_count'] ?? 0);

            $fxExposure[] = [
                'currency' => (string) ($row['currency'] ?? 'UNKNOWN'),
                'request_count' => $requestCountForCurrency,
                'requested_amount' => $requestedAmount,
                'paid_amount' => $paidAmount,
                'open_amount' => (float) ($row['open_amount'] ?? 0),
                'paid_count' => (int) ($row['paid_count'] ?? 0),
                'pending_count' => (int) ($row['pending_count'] ?? 0),
                'processing_count' => (int) ($row['processing_count'] ?? 0),
                'unconfirmed_count' => (int) ($row['unconfirmed_count'] ?? 0),
                'settlement_rate' => $requestedAmount > 0
                    ? round(($paidAmount / $requestedAmount) * 100, 1)
                    : 0,
            ];
            $fxSummary['request_count'] += $requestCountForCurrency;
        }
        $fxSummary['currency_count'] = count($fxExposure);
    }

    /* Live exception controls are current operational items, not currency/value totals. */
    $exceptions = [
        'active_payment_reminders' => 0,
        'overdue_payment_reminders' => 0,
        'escalated_payment_reminders' => 0,
        'failed_delivery_reminders' => 0,
        'oldest_overdue_at' => null,
        'items' => [],
    ];

    if (dashboardTableExists($conn, 'account_payment_reminders')) {
        $exceptionSummaryStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS active_payment_reminders,
                SUM(CASE WHEN next_reminder_at < NOW() THEN 1 ELSE 0 END) AS overdue_payment_reminders,
                SUM(CASE WHEN escalation_level > 0 THEN 1 ELSE 0 END) AS escalated_payment_reminders,
                SUM(CASE WHEN failure_count > 0 OR LOWER(TRIM(delivery_status)) = 'failed' THEN 1 ELSE 0 END) AS failed_delivery_reminders,
                MIN(CASE WHEN next_reminder_at < NOW() THEN next_reminder_at ELSE NULL END) AS oldest_overdue_at
             FROM account_payment_reminders
             WHERE lifecycle_status NOT IN ('Completed', 'Cancelled')"
        );
        if (!$exceptionSummaryStmt) {
            throw new Exception('Unable to prepare dashboard exception analytics.', 500);
        }
        $exceptionSummaryStmt->execute();
        $exceptionSummary = $exceptionSummaryStmt->get_result()->fetch_assoc() ?: [];
        $exceptionSummaryStmt->close();

        $exceptions['active_payment_reminders'] = (int) ($exceptionSummary['active_payment_reminders'] ?? 0);
        $exceptions['overdue_payment_reminders'] = (int) ($exceptionSummary['overdue_payment_reminders'] ?? 0);
        $exceptions['escalated_payment_reminders'] = (int) ($exceptionSummary['escalated_payment_reminders'] ?? 0);
        $exceptions['failed_delivery_reminders'] = (int) ($exceptionSummary['failed_delivery_reminders'] ?? 0);
        $exceptions['oldest_overdue_at'] = $exceptionSummary['oldest_overdue_at'] ?: null;

        $exceptionItemsStmt = $conn->prepare(
            "SELECT
                id,
                reminder_reference,
                request_type,
                request_id,
                next_reminder_at,
                escalation_level,
                failure_count,
                delivery_count,
                delivery_status,
                TIMESTAMPDIFF(MINUTE, next_reminder_at, NOW()) AS overdue_minutes
             FROM account_payment_reminders
             WHERE lifecycle_status NOT IN ('Completed', 'Cancelled')
               AND next_reminder_at < NOW()
             ORDER BY escalation_level DESC, next_reminder_at ASC, id ASC
             LIMIT 5"
        );
        if (!$exceptionItemsStmt) {
            throw new Exception('Unable to prepare overdue payment reminder list.', 500);
        }
        $exceptionItemsStmt->execute();
        $exceptionRows = $exceptionItemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $exceptionItemsStmt->close();

        foreach ($exceptionRows as $row) {
            $exceptions['items'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'reference' => (string) ($row['reminder_reference'] ?? ''),
                'request_type' => (string) ($row['request_type'] ?? ''),
                'request_id' => (int) ($row['request_id'] ?? 0),
                'next_reminder_at' => $row['next_reminder_at'] ?? null,
                'escalation_level' => (int) ($row['escalation_level'] ?? 0),
                'failure_count' => (int) ($row['failure_count'] ?? 0),
                'delivery_count' => (int) ($row['delivery_count'] ?? 0),
                'delivery_status' => (string) ($row['delivery_status'] ?? ''),
                'overdue_minutes' => max(0, (int) ($row['overdue_minutes'] ?? 0)),
            ];
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'year' => $year,
        'data' => [
            'overview' => $overview,
            'categories' => $categories,
            'recent_activity' => $recentActivity,
            'recent_activity_by_category' => $recentActivityByCategory,
            'fx_exposure' => [
                'summary' => $fxSummary,
                'currencies' => $fxExposure,
            ],
            'exceptions' => $exceptions,
        ],
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
