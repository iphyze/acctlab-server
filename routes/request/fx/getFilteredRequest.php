<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestService.php';
require_once 'includes/procurementSupplierFinancialAdjustmentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    requireAdmin();

    if (!isset($_GET['limit'], $_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    if ($limit <= 0 || $limit > 500 || $page <= 0) {
        throw new Exception("'limit' must be between 1 and 500 and 'page' must be positive.", 400);
    }
    $offset = ($page - 1) * $limit;

    $requestType = isset($_GET['request_type']) ? trim((string) $_GET['request_type']) : 'all';
    if ($requestType !== 'all' && !in_array($requestType, ['Final', 'Advance'], true)) {
        throw new Exception('Invalid request_type filter.', 400);
    }


    $currencyInput = isset($_GET['currency']) ? trim((string) $_GET['currency']) : 'all';
    $currency = ($currencyInput === '' || strtolower($currencyInput) === 'all')
        ? 'all'
        : strtoupper($currencyInput);
    if ($currency !== 'all' && !in_array($currency, fxFundRequestAllowedCurrencies(), true)) {
        throw new Exception('Invalid currency filter.', 400);
    }

    $paymentStatus = isset($_GET['payment_status']) ? trim((string) $_GET['payment_status']) : 'all';
    $validPaymentStatuses = ['Pending', 'Processing', 'Paid', 'Failed', 'Cancelled', 'Unconfirmed'];
    if ($paymentStatus !== 'all' && !in_array($paymentStatus, $validPaymentStatuses, true)) {
        throw new Exception('Invalid payment_status filter.', 400);
    }

    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
    if ($year !== null && ($year < 2000 || $year > 2100)) {
        throw new Exception('Invalid year filter.', 400);
    }
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

    $allowedSortFields = [
        'request_type', 'suppliers_name', 'invoice_number', 'purchase_number', 'po_number',
        'date_received', 'project_code', 'currency', 'percentage', 'payable_amount', 'payment_status', 'created_at',
    ];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields, true)
        ? $_GET['sortBy']
        : 'created_at';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper((string) $_GET['sortOrder']) === 'ASC' ? 'ASC' : 'DESC';

    $baseQuery = "FROM fx_fund_request_table ffr
        LEFT JOIN procurement_requests pr
          ON (pr.request_type = 'fx_final_purchase' OR pr.request_type = 'fx_advance_purchase')
         AND pr.account_request_id = ffr.id
         AND pr.approval_status = 'Approved'
         AND pr.handoff_status = 'In Account'
         AND pr.deleted_at IS NULL
        WHERE 1=1";
    $params = [];
    $types = '';

    if ($requestType !== 'all') {
        $baseQuery .= ' AND ffr.request_type = ?';
        $params[] = $requestType;
        $types .= 's';
    }
    if ($currency !== 'all') {
        $baseQuery .= ' AND ffr.currency = ?';
        $params[] = $currency;
        $types .= 's';
    }
    if ($paymentStatus !== 'all') {
        $baseQuery .= ' AND ffr.payment_status = ?';
        $params[] = $paymentStatus;
        $types .= 's';
    }
    if ($year !== null) {
        $baseQuery .= ' AND YEAR(ffr.created_at) = ?';
        $params[] = $year;
        $types .= 'i';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $baseQuery .= ' AND (
            ffr.suppliers_name LIKE ? OR ffr.contact_person LIKE ? OR ffr.phone_number LIKE ? OR
            ffr.invoice_number LIKE ? OR ffr.purchase_number LIKE ? OR ffr.po_number LIKE ? OR ffr.project_code LIKE ?
        )';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
        $types .= 'sssssss';
    }

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total ' . $baseQuery);
    if ($params) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $dataParams = $params;
    $dataTypes = $types . 'ii';
    $dataParams[] = $limit;
    $dataParams[] = $offset;

    $dataStmt = $conn->prepare(
        'SELECT ffr.*,
                pr.legacy_source_id AS procurement_purchase_id,
                pr.request_type AS procurement_request_type,
                pr.request_number AS procurement_request_number,
                pr.approval_status AS procurement_approval_status,
                pr.handoff_status AS procurement_handoff_status,
                pr.payment_status AS procurement_payment_status ' . $baseQuery
        . " ORDER BY ffr.{$sortBy} {$sortOrder}, ffr.id DESC LIMIT ? OFFSET ?"
    );
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();
    $creditCache = [];
    $rows = array_map(static function (array $row) use ($conn, &$creditCache): array {
        $formatted = fxFundRequestFormatRow($row);
        $supplierId = (int) ($row['suppliers_id'] ?? 0);
        $rowCurrency = strtoupper(trim((string) ($row['currency'] ?? '')));
        $key = $supplierId . ':' . $rowCurrency;
        if ($supplierId > 0 && $rowCurrency !== '') {
            if (!array_key_exists($key, $creditCache)) {
                $creditCache[$key] = procurementSupplierAvailableCreditForSupplier($conn, $supplierId, $rowCurrency);
            }
            $formatted['available_supplier_credit'] = $creditCache[$key];
        } else {
            $formatted['available_supplier_credit'] = '0.00';
        }
        return $formatted;
    }, $rows);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX Fund Requests fetched successfully.',
        'data' => $rows,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'request_type' => $requestType,
            'currency' => $currency,
            'payment_status' => $paymentStatus,
            'year' => $year,
            'search' => $search,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'data_source' => $GLOBALS['databaseReadSource'] ?? 'active',
        ],
    ]);
} catch (Throwable $e) {
    error_log('FX Fund Request list error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
