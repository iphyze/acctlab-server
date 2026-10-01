<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

function fxAnalyticsQueryOne(mysqli $conn, string $sql, int $year): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare FX analytics query: ' . $conn->error, 500);
    }
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function fxAnalyticsQueryRows(mysqli $conn, string $sql, int $year): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare FX analytics query: ' . $conn->error, 500);
    }
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fxAnalyticsNumeric(mixed $value): float
{
    return round((float) ($value ?? 0), 2);
}

function fxAnalyticsInt(mixed $value): int
{
    return (int) ($value ?? 0);
}

function fxAnalyticsNormalizeCurrency(mixed $value): string
{
    $currency = strtoupper(trim((string) $value));
    return $currency !== '' ? $currency : 'UNK';
}

function fxAnalyticsGroupCounterpartyRows(array $rows, string $nameKey): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row[$nameKey] ?? ''));
        if ($name === '') {
            $name = 'Unspecified';
        }
        if (!isset($grouped[$name])) {
            $grouped[$name] = [
                'name' => $name,
                'request_count' => 0,
                'currencies' => [],
            ];
        }
        $currency = fxAnalyticsNormalizeCurrency($row['currency'] ?? '');
        $grouped[$name]['request_count'] += fxAnalyticsInt($row['request_count'] ?? 0);
        $grouped[$name]['currencies'][$currency] = [
            'currency' => $currency,
            'request_count' => fxAnalyticsInt($row['request_count'] ?? 0),
            'value' => fxAnalyticsNumeric($row['request_value'] ?? 0),
        ];
    }

    $items = array_values(array_map(static function (array $item): array {
        $item['currencies'] = array_values($item['currencies']);
        usort($item['currencies'], static fn(array $a, array $b): int => $b['request_count'] <=> $a['request_count']);
        return $item;
    }, $grouped));

    usort($items, static function (array $a, array $b): int {
        $countCompare = $b['request_count'] <=> $a['request_count'];
        if ($countCompare !== 0) {
            return $countCompare;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return array_slice($items, 0, 5);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    requireAdmin();

    $year = isset($_GET['year']) && $_GET['year'] !== ''
        ? (int) $_GET['year']
        : (int) date('Y');
    if ($year < 2000 || $year > 2100) {
        throw new Exception('Invalid analytics year.', 400);
    }

    $requestOverview = fxAnalyticsQueryOne(
        $conn,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN request_type = 'Final' THEN 1 ELSE 0 END) AS final_count,
            SUM(CASE WHEN request_type = 'Advance' THEN 1 ELSE 0 END) AS advance_count,
            SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN payment_status = 'Processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN payment_status = 'Unconfirmed' THEN 1 ELSE 0 END) AS unconfirmed_count,
            SUM(CASE WHEN payment_status = 'Failed' THEN 1 ELSE 0 END) AS failed_count,
            SUM(CASE WHEN payment_status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN fx_instruction_letter_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_to_payment_count
         FROM fx_fund_request_table
         WHERE YEAR(created_at) = ?",
        $year
    );

    $paymentOverview = fxAnalyticsQueryOne(
        $conn,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN LOWER(payment_status) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(payment_status) = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN LOWER(payment_status) = 'unconfirmed' THEN 1 ELSE 0 END) AS unconfirmed_count,
            SUM(CASE WHEN LOWER(payment_status) = 'failed' THEN 1 ELSE 0 END) AS failed_count,
            SUM(CASE WHEN LOWER(payment_status) = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN LOWER(payment_status) = 'reversed' THEN 1 ELSE 0 END) AS reversed_count
         FROM fx_instruction_letter_table
         WHERE YEAR(created_at) = ?",
        $year
    );

    $requestCurrencies = fxAnalyticsQueryRows(
        $conn,
        "SELECT
            COALESCE(NULLIF(UPPER(TRIM(currency)), ''), 'UNK') AS currency,
            COUNT(*) AS request_count,
            SUM(payable_amount) AS request_value,
            SUM(CASE WHEN payment_status = 'Paid' THEN payable_amount ELSE 0 END) AS paid_request_value,
            SUM(CASE WHEN payment_status IN ('Pending', 'Processing', 'Unconfirmed') THEN payable_amount ELSE 0 END) AS open_request_value
         FROM fx_fund_request_table
         WHERE YEAR(created_at) = ?
         GROUP BY COALESCE(NULLIF(UPPER(TRIM(currency)), ''), 'UNK')
         ORDER BY request_count DESC, currency ASC",
        $year
    );

    $paymentCurrencies = fxAnalyticsQueryRows(
        $conn,
        "SELECT
            COALESCE(NULLIF(UPPER(TRIM(currency_table)), ''), NULLIF(UPPER(TRIM(currency)), ''), 'UNK') AS currency,
            COUNT(*) AS payment_count,
            SUM(CAST(REPLACE(amount_figure, ',', '') AS DECIMAL(24,2))) AS payment_value,
            SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN CAST(REPLACE(amount_figure, ',', '') AS DECIMAL(24,2)) ELSE 0 END) AS paid_payment_value,
            SUM(CASE WHEN LOWER(payment_status) IN ('pending', 'processing', 'unconfirmed') THEN CAST(REPLACE(amount_figure, ',', '') AS DECIMAL(24,2)) ELSE 0 END) AS open_payment_value,
            SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN 1 ELSE 0 END) AS paid_payment_count
         FROM fx_instruction_letter_table
         WHERE YEAR(created_at) = ?
         GROUP BY COALESCE(NULLIF(UPPER(TRIM(currency_table)), ''), NULLIF(UPPER(TRIM(currency)), ''), 'UNK')
         ORDER BY payment_count DESC, currency ASC",
        $year
    );

    $currencyMap = [];
    foreach ($requestCurrencies as $row) {
        $currency = fxAnalyticsNormalizeCurrency($row['currency'] ?? '');
        $currencyMap[$currency] = [
            'currency' => $currency,
            'request_count' => fxAnalyticsInt($row['request_count'] ?? 0),
            'request_value' => fxAnalyticsNumeric($row['request_value'] ?? 0),
            'paid_request_value' => fxAnalyticsNumeric($row['paid_request_value'] ?? 0),
            'open_request_value' => fxAnalyticsNumeric($row['open_request_value'] ?? 0),
            'payment_count' => 0,
            'payment_value' => 0.0,
            'paid_payment_value' => 0.0,
            'open_payment_value' => 0.0,
            'paid_payment_count' => 0,
            'payment_settlement_rate' => 0.0,
        ];
    }
    foreach ($paymentCurrencies as $row) {
        $currency = fxAnalyticsNormalizeCurrency($row['currency'] ?? '');
        if (!isset($currencyMap[$currency])) {
            $currencyMap[$currency] = [
                'currency' => $currency,
                'request_count' => 0,
                'request_value' => 0.0,
                'paid_request_value' => 0.0,
                'open_request_value' => 0.0,
                'payment_count' => 0,
                'payment_value' => 0.0,
                'paid_payment_value' => 0.0,
                'open_payment_value' => 0.0,
                'paid_payment_count' => 0,
                'payment_settlement_rate' => 0.0,
            ];
        }
        $paymentCount = fxAnalyticsInt($row['payment_count'] ?? 0);
        $paidPaymentCount = fxAnalyticsInt($row['paid_payment_count'] ?? 0);
        $currencyMap[$currency]['payment_count'] = $paymentCount;
        $currencyMap[$currency]['payment_value'] = fxAnalyticsNumeric($row['payment_value'] ?? 0);
        $currencyMap[$currency]['paid_payment_value'] = fxAnalyticsNumeric($row['paid_payment_value'] ?? 0);
        $currencyMap[$currency]['open_payment_value'] = fxAnalyticsNumeric($row['open_payment_value'] ?? 0);
        $currencyMap[$currency]['paid_payment_count'] = $paidPaymentCount;
        $currencyMap[$currency]['payment_settlement_rate'] = $paymentCount > 0
            ? round(($paidPaymentCount / $paymentCount) * 100, 1)
            : 0.0;
    }

    $currencyExposure = array_values($currencyMap);
    usort($currencyExposure, static function (array $a, array $b): int {
        $activityA = $a['request_count'] + $a['payment_count'];
        $activityB = $b['request_count'] + $b['payment_count'];
        $activityCompare = $activityB <=> $activityA;
        return $activityCompare !== 0 ? $activityCompare : strcmp($a['currency'], $b['currency']);
    });

    $requestMonthly = fxAnalyticsQueryRows(
        $conn,
        "SELECT MONTH(created_at) AS month_number, COUNT(*) AS request_count
         FROM fx_fund_request_table
         WHERE YEAR(created_at) = ?
         GROUP BY MONTH(created_at)",
        $year
    );
    $paymentMonthly = fxAnalyticsQueryRows(
        $conn,
        "SELECT MONTH(created_at) AS month_number, COUNT(*) AS payment_count
         FROM fx_instruction_letter_table
         WHERE YEAR(created_at) = ?
         GROUP BY MONTH(created_at)",
        $year
    );

    $monthlyMap = [];
    for ($month = 1; $month <= 12; $month++) {
        $monthlyMap[$month] = [
            'month' => $month,
            'label' => date('M', mktime(0, 0, 0, $month, 1, $year)),
            'request_count' => 0,
            'payment_count' => 0,
        ];
    }
    foreach ($requestMonthly as $row) {
        $month = fxAnalyticsInt($row['month_number'] ?? 0);
        if (isset($monthlyMap[$month])) {
            $monthlyMap[$month]['request_count'] = fxAnalyticsInt($row['request_count'] ?? 0);
        }
    }
    foreach ($paymentMonthly as $row) {
        $month = fxAnalyticsInt($row['month_number'] ?? 0);
        if (isset($monthlyMap[$month])) {
            $monthlyMap[$month]['payment_count'] = fxAnalyticsInt($row['payment_count'] ?? 0);
        }
    }

    $supplierRows = fxAnalyticsQueryRows(
        $conn,
        "SELECT suppliers_name, COALESCE(NULLIF(UPPER(TRIM(currency)), ''), 'UNK') AS currency,
                COUNT(*) AS request_count, SUM(payable_amount) AS request_value
         FROM fx_fund_request_table
         WHERE YEAR(created_at) = ?
         GROUP BY suppliers_name, COALESCE(NULLIF(UPPER(TRIM(currency)), ''), 'UNK')
         ORDER BY request_count DESC, suppliers_name ASC",
        $year
    );
    $projectRows = fxAnalyticsQueryRows(
        $conn,
        "SELECT project_code, COALESCE(NULLIF(UPPER(TRIM(currency)), ''), 'UNK') AS currency,
                COUNT(*) AS request_count, SUM(payable_amount) AS request_value
         FROM fx_fund_request_table
         WHERE YEAR(created_at) = ?
         GROUP BY project_code, COALESCE(NULLIF(UPPER(TRIM(currency)), ''), 'UNK')
         ORDER BY request_count DESC, project_code ASC",
        $year
    );

    $requestTotal = fxAnalyticsInt($requestOverview['total'] ?? 0);
    $paymentTotal = fxAnalyticsInt($paymentOverview['total'] ?? 0);
    $paymentPaid = fxAnalyticsInt($paymentOverview['paid_count'] ?? 0);
    $linkedRequests = fxAnalyticsInt($requestOverview['linked_to_payment_count'] ?? 0);

    echo json_encode([
        'status' => 'Success',
        'message' => 'FX analytics fetched successfully.',
        'data' => [
            'year' => $year,
            'requests' => [
                'total' => $requestTotal,
                'final' => fxAnalyticsInt($requestOverview['final_count'] ?? 0),
                'advance' => fxAnalyticsInt($requestOverview['advance_count'] ?? 0),
                'linked_to_payment' => $linkedRequests,
                'conversion_rate' => $requestTotal > 0 ? round(($linkedRequests / $requestTotal) * 100, 1) : 0.0,
                'statuses' => [
                    'Pending' => fxAnalyticsInt($requestOverview['pending_count'] ?? 0),
                    'Processing' => fxAnalyticsInt($requestOverview['processing_count'] ?? 0),
                    'Paid' => fxAnalyticsInt($requestOverview['paid_count'] ?? 0),
                    'Unconfirmed' => fxAnalyticsInt($requestOverview['unconfirmed_count'] ?? 0),
                    'Failed' => fxAnalyticsInt($requestOverview['failed_count'] ?? 0),
                    'Cancelled' => fxAnalyticsInt($requestOverview['cancelled_count'] ?? 0),
                ],
            ],
            'payments' => [
                'total' => $paymentTotal,
                'settlement_rate' => $paymentTotal > 0 ? round(($paymentPaid / $paymentTotal) * 100, 1) : 0.0,
                'statuses' => [
                    'Pending' => fxAnalyticsInt($paymentOverview['pending_count'] ?? 0),
                    'Processing' => fxAnalyticsInt($paymentOverview['processing_count'] ?? 0),
                    'Paid' => $paymentPaid,
                    'Unconfirmed' => fxAnalyticsInt($paymentOverview['unconfirmed_count'] ?? 0),
                    'Failed' => fxAnalyticsInt($paymentOverview['failed_count'] ?? 0),
                    'Cancelled' => fxAnalyticsInt($paymentOverview['cancelled_count'] ?? 0),
                    'Reversed' => fxAnalyticsInt($paymentOverview['reversed_count'] ?? 0),
                ],
            ],
            'active_currencies' => array_values(array_map(static fn(array $row): string => $row['currency'], $currencyExposure)),
            'currency_exposure' => $currencyExposure,
            'monthly_activity' => array_values($monthlyMap),
            'top_suppliers' => fxAnalyticsGroupCounterpartyRows($supplierRows, 'suppliers_name'),
            'top_projects' => fxAnalyticsGroupCounterpartyRows($projectRows, 'project_code'),
        ],
        'meta' => [
            'year' => $year,
            'data_source' => $GLOBALS['databaseReadSource'] ?? 'active',
            'currency_policy' => 'Amounts are grouped by currency and are never cross-summed.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('FX analytics overview error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
