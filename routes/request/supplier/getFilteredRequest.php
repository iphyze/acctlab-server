<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountPaymentReminderService.php';
require_once 'includes/procurementSupplierFinancialAdjustmentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate the user
    $userData = authenticateUser();
    // Keep this paginated list read-only and fast. Schema/payment-storage readiness is
    // migration-managed; the expensive Supplier payment storage repair/verification
    // routine must never run on every register fetch. Reminder summaries are enriched
    // only after the current page has been selected.
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can access this resource", 401);
    }

    // Validate pagination
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    $paymentStatus = isset($_GET['payment_status']) ? $_GET['payment_status'] : 'all';
    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;


    // Sorting setup
    $allowedSortFields = ["suppliers_name", "invoice_number", "purchase_number", "po_number", "payment_status", "amount", "net_value", "created_at"];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields) ? $_GET['sortBy'] : "created_at";
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC" ? "ASC" : "DESC";


    // Base query
    $baseQuery = "FROM supplier_fund_request_table WHERE 1=1";
    $params = [];
    $types = "";

    // Apply payment status filter
    if ($paymentStatus !== 'all') {
        $baseQuery .= " AND payment_status = ?";
        $params[] = $paymentStatus;
        $types .= "s";
    }

    // Apply year filter
    if ($year) {
        $baseQuery .= " AND YEAR(created_at) = ?";
        $params[] = $year;
        $types .= "i";
    }


    // Search filter (optional)
    if ($search) {
        $baseQuery .= " AND (suppliers_name LIKE ? OR purchase_number LIKE ? OR invoice_number LIKE ? OR po_number LIKE ?)";
        $likeSearch = "%" . $search . "%";
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $types .= "ssss";
    }

    // Count total
    $countQuery = "SELECT COUNT(*) AS total $baseQuery";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count query: " . $conn->error, 500);
    }
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated results
    $dataQuery = "SELECT * $baseQuery ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add limit and offset to params
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();
    $data = accountPaymentReminderAttachSummaries($conn, 'Supplier', $data);
    $offsetRequestIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $data
    )));
    $offsetSummaries = procurementSupplierPaymentOffsetSummariesForRequests(
        $conn,
        'local_final_purchase',
        $offsetRequestIds
    );

    foreach ($data as &$row) {
        $row['supplier_offset_available'] = '0.00';
        $row['supplier_offset_reserved'] = '0.00';
        $row['supplier_offset_applied'] = '0.00';
        $row['supplier_offset_effective'] = '0.00';
        $row['supplier_offset_basis'] = '';
        $row['supplier_offset_reason'] = '';
        $row['supplier_offset_references'] = [];

        $requestId = (int) ($row['id'] ?? 0);
        $grossCents = procurementSupplierAdjustmentMoneyToCents(
            $row['amount'] ?? 0,
            'Gross Payable',
            true
        );
        $summary = $offsetSummaries[$requestId] ?? [
            'reserved_amount' => '0.00',
            'applied_amount' => '0.00',
            'effective_offset_amount' => '0.00',
            'basis' => '',
            'reason' => '',
            'references' => [],
        ];
        $reservedCents = min(
            $grossCents,
            procurementSupplierAdjustmentMoneyToCents($summary['reserved_amount'] ?? 0, 'Reserved Offset', true)
        );
        $appliedCents = min(
            $grossCents,
            procurementSupplierAdjustmentMoneyToCents($summary['applied_amount'] ?? 0, 'Applied Offset', true)
        );
        $effectiveCents = min($grossCents, $reservedCents + $appliedCents);

        $row['supplier_offset_reserved'] = procurementSupplierAdjustmentCents($reservedCents);
        $row['supplier_offset_applied'] = procurementSupplierAdjustmentCents($appliedCents);
        $row['supplier_offset_effective'] = procurementSupplierAdjustmentCents($effectiveCents);
        $row['supplier_offset_cash_required'] = procurementSupplierAdjustmentCents(max(0, $grossCents - $effectiveCents));
        $row['supplier_net_payable'] = $row['supplier_offset_cash_required'];
        $row['supplier_offset_basis'] = (string) ($summary['basis'] ?? '');
        $row['supplier_offset_reason'] = (string) ($summary['reason'] ?? '');
        $row['supplier_offset_references'] = is_array($summary['references'] ?? null)
            ? $summary['references']
            : [];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Supplier payments fetched successfully",
        "data" => $data,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            "payment_status" => $paymentStatus,
            "year" => $year,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search
        ]
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
