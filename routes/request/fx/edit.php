<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestService.php';
require_once 'includes/procurementFxFinalPurchaseService.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';

header('Content-Type: application/json');

$transactionStarted = false;
$poLockName = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Route not found', 400);
    }

    $user = requireAdmin();
    $userId = (int) $user['id'];
    $userEmail = (string) $user['email'];
    $writeConn = databaseActiveConnection($conn);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    $requestId = fxFundRequestPositiveId($data, 'requestId');
    $request = fxFundRequestNormalizePayload($data);
    fxFundRequestAssertSupplierAndProject($writeConn, $request);

    if ($request['request_type'] === 'Advance') {
        $poLockName = fxFundRequestAcquireAdvancePoLock($writeConn, $request['po_number']);
    }

    $writeConn->begin_transaction();
    $transactionStarted = true;

    $currentStmt = $writeConn->prepare(
        'SELECT * FROM fx_fund_request_table WHERE id = ? FOR UPDATE'
    );
    $currentStmt->bind_param('i', $requestId);
    $currentStmt->execute();
    $current = $currentStmt->get_result()->fetch_assoc();
    $currentStmt->close();

    if (!$current) {
        throw new Exception('FX Fund Request not found in the active database.', 404);
    }
    $linkedProcurement = procurementFxFinalLinkedPurchaseForFundRequest($writeConn, $requestId, true);
    $linkedAdvanceProcurement = procurementFxAdvanceLinkedPurchaseForFundRequest($writeConn, $requestId, true);
    $isProcureDeskLinkedFinal = $linkedProcurement !== null;
    $isProcureDeskLinkedAdvance = $linkedAdvanceProcurement !== null;
    $isProcessed = $current['fx_instruction_letter_id'] !== null;

    if ($isProcureDeskLinkedAdvance) {
        throw new Exception(
            'ProcureDesk-linked FX Advance Requests must be corrected from the ProcureDesk Advance workflow. Return the request to Procurement while it is still Pending.',
            409
        );
    }

    if ($isProcureDeskLinkedFinal && $isProcessed) {
        throw new Exception('Processed ProcureDesk-linked FX Final Requests cannot be edited in AcctLab.', 409);
    }

    if (!$isProcureDeskLinkedFinal && !$isProcureDeskLinkedAdvance && $isProcessed) {
        // Direct AcctLab requests remain editable after payment preparation for
        // reference/date/project corrections. Payment-defining commercial
        // values are locked until the request is reopened to Pending so the
        // payment allocation cannot silently drift from its source request.
        $lockedText = [
            'request_type' => (string) $request['request_type'],
            'suppliers_name' => trim((string) $request['suppliers_name']),
            'suppliers_id' => (string) $request['suppliers_id'],
            'currency' => (string) $request['currency'],
        ];
        foreach ($lockedText as $field => $submittedValue) {
            if (trim((string) ($current[$field] ?? '')) !== trim($submittedValue)) {
                throw new Exception(
                    'Set this direct AcctLab FX Fund Request back to Pending before changing supplier, request type or currency.',
                    409
                );
            }
        }

        $lockedMoney = [
            'sub_total' => (float) $request['sub_total'],
            'discount' => (float) $request['discount'],
            'other_charges' => (float) $request['other_charges'],
            'vat_rate' => (float) $request['vat_rate'],
            'vat_amount' => (float) $request['vat_amount'],
            'wht_rate' => (float) $request['wht_rate'],
            'wht_amount' => (float) $request['wht_amount'],
            'payable_amount' => (float) $request['payable_amount'],
        ];
        if ($request['request_type'] === 'Advance') {
            $lockedMoney['percentage'] = (float) ($request['percentage'] ?? 0);
        }
        foreach ($lockedMoney as $field => $submittedValue) {
            if (abs((float) ($current[$field] ?? 0) - $submittedValue) > 0.0000001) {
                throw new Exception(
                    'Set this direct AcctLab FX Fund Request back to Pending before changing payment values, tax rates or advance percentage.',
                    409
                );
            }
        }
    }

    if ($isProcureDeskLinkedFinal) {
        if ((string) ($current['request_type'] ?? '') !== 'Final' || $request['request_type'] !== 'Final') {
            throw new Exception('ProcureDesk-linked FX Final Purchases must remain Final requests.', 409);
        }
        if ((string) ($current['payment_status'] ?? '') !== 'Pending'
            || (string) ($linkedProcurement['payment_status'] ?? '') !== 'Pending') {
            throw new Exception(
                'Commercial details can only be updated before Account starts processing the payment.',
                409
            );
        }
        if ((string) ($linkedProcurement['approval_status'] ?? '') !== 'Approved'
            || (string) ($linkedProcurement['handoff_status'] ?? '') !== 'In Account') {
            throw new Exception('The linked ProcureDesk FX Final Purchase is no longer an active Account handoff.', 409);
        }
    }

    $advanceAllocation = null;
    if ($request['request_type'] === 'Final') {
        fxFundRequestAssertFinalPurchaseAvailable($writeConn, (string) $request['purchase_number'], $requestId);
    } else {
        $advanceAllocation = fxFundRequestAssertAdvancePercentageAvailable(
            $writeConn,
            $request['po_number'],
            (float) $request['percentage'],
            $requestId,
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
        'UPDATE fx_fund_request_table SET
            request_type = ?, suppliers_name = ?, suppliers_id = ?, invoice_number = ?, purchase_number = ?,
            po_number = ?, invoice_date = ?, purchase_date = ?, date_received = ?, project_code = ?, currency = ?,
            sub_total = ?, discount = ?, other_charges = ?, vat_rate = ?, vat_amount = ?,
            wht_rate = ?, wht_amount = ?, percentage = ?, payable_amount = ?, updated_by = ?, updated_at = NOW()
         WHERE id = ?'
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
        $requestId
    );
    $stmt->execute();
    $stmt->close();

    if ($isProcureDeskLinkedFinal) {
        procurementSyncFxFinalPurchaseFromFundRequest(
            $writeConn,
            $requestId,
            ['id' => $userId, 'email' => $userEmail]
        );
    }

    fxFundRequestInsertLog(
        $writeConn,
        $userId,
        $userEmail,
        $userEmail . ' updated FX Fund Request #' . $requestId . ' as '
            . $request['request_type']
            . ($request['po_number'] ? ' for PO ' . $request['po_number'] : '')
            . ' in ' . $request['currency']
            . ($isProcureDeskLinkedFinal ? ' and synchronized ProcureDesk.' : '.')
    );

    $fetch = $writeConn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ?');
    $fetch->bind_param('i', $requestId);
    $fetch->execute();
    $updated = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    $writeConn->commit();
    $transactionStarted = false;
    fxFundRequestReleaseAdvancePoLock($writeConn, $poLockName);
    $poLockName = null;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX Fund Request updated successfully.',
        'data' => fxFundRequestFormatRow($updated ?: []),
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
        : 'Database error while updating FX Fund Request.';
    error_log('FX Fund Request edit DB error: ' . $e->getMessage());
    http_response_code($status);
    echo json_encode(['status' => 'Failed', 'message' => $message]);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }
    if (isset($writeConn) && $writeConn instanceof mysqli) {
        fxFundRequestReleaseAdvancePoLock($writeConn, $poLockName);
    }

    error_log('FX Fund Request edit error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
