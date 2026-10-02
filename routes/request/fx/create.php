<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestService.php';

header('Content-Type: application/json');

$transactionStarted = false;
$poLockName = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Route not found', 400);
    }

    $user = requireAdmin();
    $userId = (int) $user['id'];
    $userEmail = (string) $user['email'];
    $writeConn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    $request = fxFundRequestNormalizePayload($data);
    fxFundRequestAssertSupplierAndProject($writeConn, $request);

    if ($request['request_type'] === 'Advance') {
        $poLockName = fxFundRequestAcquireAdvancePoLock($writeConn, $request['po_number']);
    }

    $writeConn->begin_transaction();
    $transactionStarted = true;

    $advanceAllocation = null;
    if ($request['request_type'] === 'Final') {
        fxFundRequestAssertFinalPurchaseAvailable($writeConn, (string) $request['purchase_number']);
    } else {
        $advanceAllocation = fxFundRequestAssertAdvancePercentageAvailable(
            $writeConn,
            $request['po_number'],
            (float) $request['percentage'],
            null,
            $request['currency']
        );
    }

    $requestType = $request['request_type'];
    $supplierName = $request['suppliers_name'];
    $supplierId = (int) $request['suppliers_id'];
    $invoiceNumber = $request['invoice_number'];
    $purchaseNumber = $request['purchase_number'];
    $poNumber = $request['po_number'];
    $invoiceDate = $request['invoice_date'];
    $purchaseDate = $request['purchase_date'];
    $dateReceived = $request['date_received'];
    $projectCode = $request['project_code'];
    $currency = $request['currency'];
    $subTotal = (float) $request['sub_total'];
    $discount = (float) $request['discount'];
    $otherCharges = (float) $request['other_charges'];
    $vatRate = (float) $request['vat_rate'];
    $vatAmount = (float) $request['vat_amount'];
    $whtRate = (float) $request['wht_rate'];
    $whtAmount = (float) $request['wht_amount'];
    $percentage = $request['percentage'] === null ? null : (float) $request['percentage'];
    $payableAmount = (float) $request['payable_amount'];

    $stmt = $writeConn->prepare(
        'INSERT INTO fx_fund_request_table (
            request_type, suppliers_name, suppliers_id, invoice_number, purchase_number,
            po_number, invoice_date, purchase_date, date_received, project_code, currency,
            sub_total, discount, other_charges, vat_rate, vat_amount, wht_rate, wht_amount,
            percentage, payable_amount, payment_status, created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', ?, ?)'
    );

    $stmt->bind_param(
        'ssissssssssdddddddddii',
        $requestType,
        $supplierName,
        $supplierId,
        $invoiceNumber,
        $purchaseNumber,
        $poNumber,
        $invoiceDate,
        $purchaseDate,
        $dateReceived,
        $projectCode,
        $currency,
        $subTotal,
        $discount,
        $otherCharges,
        $vatRate,
        $vatAmount,
        $whtRate,
        $whtAmount,
        $percentage,
        $payableAmount,
        $userId,
        $userId
    );
    $stmt->execute();
    $requestId = (int) $stmt->insert_id;
    $stmt->close();

    fxFundRequestInsertLog(
        $writeConn,
        $userId,
        $userEmail,
        $userEmail . ' created FX ' . $request['request_type'] . ' Fund Request #' . $requestId
            . ($request['po_number'] ? ' for PO ' . $request['po_number'] : '')
            . ' in ' . $request['currency'] . '.'
    );

    $fetch = $writeConn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ?');
    $fetch->bind_param('i', $requestId);
    $fetch->execute();
    $created = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    $writeConn->commit();
    $transactionStarted = false;
    fxFundRequestReleaseAdvancePoLock($writeConn, $poLockName);
    $poLockName = null;

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX Fund Request created successfully.',
        'data' => fxFundRequestFormatRow($created ?: []),
        'calculation' => $request['calculation'],
        'advance_percentage' => $advanceAllocation,
    ]);
} catch (mysqli_sql_exception $e) {
    if ($transactionStarted) {
        $writeConn->rollback();
    }
    if (isset($writeConn) && $writeConn instanceof mysqli) {
        fxFundRequestReleaseAdvancePoLock($writeConn, $poLockName);
    }

    $status = $e->getCode() === 1062 ? 409 : 500;
    $message = $e->getCode() === 1062
        ? 'Duplicate FX Fund Request detected.'
        : 'Database error while creating FX Fund Request.';
    error_log('FX Fund Request create DB error: ' . $e->getMessage());
    http_response_code($status);
    echo json_encode(['status' => 'Failed', 'message' => $message]);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }
    if (isset($writeConn) && $writeConn instanceof mysqli) {
        fxFundRequestReleaseAdvancePoLock($writeConn, $poLockName);
    }

    error_log('FX Fund Request create error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
