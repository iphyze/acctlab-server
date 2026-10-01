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

    $rawId = $_GET['params'] ?? null;
    if (filter_var($rawId, FILTER_VALIDATE_INT) === false || (int) $rawId <= 0) {
        throw new Exception('requestId must be a positive integer.', 400);
    }
    $requestId = (int) $rawId;

    $stmt = $conn->prepare(
        "SELECT ffr.*,
                pr.legacy_source_id AS procurement_purchase_id,
                pr.request_type AS procurement_request_type,
                pr.request_number AS procurement_request_number,
                pr.approval_status AS procurement_approval_status,
                pr.handoff_status AS procurement_handoff_status,
                pr.payment_status AS procurement_payment_status
         FROM fx_fund_request_table ffr
         LEFT JOIN procurement_requests pr
           ON (pr.request_type = 'fx_final_purchase' OR pr.request_type = 'fx_advance_purchase')
          AND pr.account_request_id = ffr.id
          AND pr.approval_status = 'Approved'
          AND pr.handoff_status = 'In Account'
          AND pr.deleted_at IS NULL
         WHERE ffr.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('FX Fund Request not found.', 404);
    }

    $formatted = fxFundRequestFormatRow($row);
    $supplierId = (int) ($row['suppliers_id'] ?? 0);
    $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
    $formatted['available_supplier_credit'] = ($supplierId > 0 && $currency !== '')
        ? procurementSupplierAvailableCreditForSupplier($conn, $supplierId, $currency)
        : '0.00';

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX Fund Request fetched successfully.',
        'data' => $formatted,
        'meta' => ['data_source' => $GLOBALS['databaseReadSource'] ?? 'active'],
    ]);
} catch (Throwable $e) {
    error_log('FX Fund Request getSingle error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
