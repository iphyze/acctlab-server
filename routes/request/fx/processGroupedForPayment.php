<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestService.php';
require_once 'includes/fxFundRequestPaymentService.php';
require_once 'includes/procurementFxFinalPurchaseService.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';
require_once 'includes/accountPaymentProcessingService.php';

header('Content-Type: application/json');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    $processing = fxFundRequestNormalizeGroupedProcessingPayload($data);

    $writeConn->begin_transaction();
    $transactionStarted = true;

    // Lock in deterministic ID order so concurrent grouped/single processing
    // cannot attach the same Fund Request to two different payments.
    $requests = fxFundRequestLockManyForProcessing($writeConn, $processing['request_items']);
    $supplier = fxFundRequestAssertGroupedSupplier($requests);

    $creditPlans = [];
    foreach ($requests as $request) {
        $requestId = (int) $request['id'];
        $creditPlans[$requestId] = fxFundRequestReserveSupplierCredit($writeConn, $request, $userId);
    }
    $groupedConversion = fxFundRequestResolveGroupedConversions($requests, $processing, $creditPlans);
    $creditOnly = (float) $groupedConversion['payment_amount'] <= 0.009;
    $effectiveProcessing = $processing;
    if ($creditOnly) {
        $effectiveProcessing['completion_mode'] = 'Immediate';
        $effectiveProcessing['processing_business_days'] = 0;
        $effectiveProcessing['expected_completion_at'] = date('Y-m-d H:i:s');
        $effectiveProcessing['is_immediate'] = true;
    }

    $instructionId = 0;
    $instructionReference = trim((string) $processing['reference']);
    $createdInstruction = null;
    if (!$creditOnly) {
        $beneficiary = fxFundRequestLoadBeneficiaryForProcessing(
            $writeConn,
            $processing['beneficiary_details_id']
        );
        $paymentBank = fxFundRequestLoadPaymentBankForProcessing(
            $writeConn,
            $processing['payment_bank_id']
        );
        $instruction = fxFundRequestBuildInstructionForProcessing(
            $requests[0],
            $beneficiary,
            $paymentBank,
            $effectiveProcessing,
            [
                'payment_amount' => $groupedConversion['payment_amount'],
                'payment_currency' => $groupedConversion['payment_currency'],
            ]
        );
        $instructionId = fxFundRequestInsertInstructionForProcessing($writeConn, $instruction);
        $instructionReference = (string) $instruction['reference'];
    }

    $fundRequestStatus = !empty($effectiveProcessing['is_immediate']) ? 'Paid' : 'Processing';
    $update = $writeConn->prepare(
        "UPDATE fx_fund_request_table
         SET fx_instruction_letter_id = NULLIF(?, 0), payment_status = ?,
             payment_currency = ?, payment_amount = ?, exchange_rate = ?,
             processed_by = ?, processed_at = NOW(), updated_by = ?, updated_at = NOW()
         WHERE id = ? AND fx_instruction_letter_id IS NULL AND processed_at IS NULL"
    );

    $updatedRequestIds = [];
    foreach ($groupedConversion['lines'] as $line) {
        $requestId = (int) $line['request_id'];
        $paymentCurrency = (string) $line['payment_currency'];
        $paymentAmount = (float) $line['payment_amount'];
        $exchangeRate = (float) $line['exchange_rate'];
        $update->bind_param(
            'issddiii',
            $instructionId,
            $fundRequestStatus,
            $paymentCurrency,
            $paymentAmount,
            $exchangeRate,
            $userId,
            $userId,
            $requestId
        );
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new Exception('FX Fund Request #' . $requestId . ' could not be linked to the grouped payment operation.', 409);
        }
        procurementFxFinalSyncFundRequestToProcurement(
            $writeConn,
            $requestId,
            $userId,
            $userEmail,
            !empty($effectiveProcessing['is_immediate']) ? 'account_payment_completed_immediately' : 'account_processing_started'
        );
        procurementFxAdvanceSyncFundRequestToProcurement(
            $writeConn,
            $requestId,
            $userId,
            $userEmail,
            !empty($effectiveProcessing['is_immediate']) ? 'account_payment_completed_immediately' : 'account_processing_started'
        );
        $updatedRequestIds[] = $requestId;
    }
    $update->close();

    $processingBatches = accountPaymentProcessingCreateFxBatches(
        $writeConn,
        $requests,
        $groupedConversion['lines'],
        $instructionId,
        $instructionReference !== '' ? $instructionReference : 'Supplier credit offset',
        $userId,
        $effectiveProcessing
    );
    $batchByType = [];
    foreach ($processingBatches as $batch) {
        $batchByType[(string) ($batch['request_type'] ?? '')] = (int) ($batch['id'] ?? 0);
    }
    foreach ($requests as $request) {
        $requestId = (int) $request['id'];
        $canonicalType = fxFundRequestSupplierCreditRequestType($request);
        $canonicalBatchId = (int) ($batchByType[$canonicalType] ?? 0);
        if ($canonicalBatchId > 0) {
            procurementSupplierAttachCreditReservationsToBatch($writeConn, $canonicalType, $requestId, $canonicalBatchId);
        }
        if (!empty($effectiveProcessing['is_immediate'])) {
            procurementSupplierFinalizeCreditReservations(
                $writeConn,
                $canonicalType,
                $requestId,
                $userId,
                null,
                $instructionReference !== '' ? $instructionReference : 'FX supplier credit offset'
            );
        }
    }

    $creditTotal = array_sum(array_map(
        static fn(array $line): float => (float) ($line['supplier_credit_applied'] ?? 0),
        $groupedConversion['lines']
    ));
    fxFundRequestInsertLog(
        $writeConn,
        $userId,
        $userEmail,
        $userEmail . ' grouped FX Fund Request ID(s) ' . implode(', ', $updatedRequestIds)
            . ' for supplier ' . $supplier['suppliers_name']
            . ($instructionId > 0 ? ' into FX Instruction #' . $instructionId : ' through supplier credit only')
            . ' (Ref: ' . $instructionReference . ', supplier credit ' . number_format($creditTotal, 2)
            . ', cash ' . $groupedConversion['payment_currency'] . ' '
            . number_format((float) $groupedConversion['payment_amount'], 2)
            . ', completion ' . $effectiveProcessing['completion_mode'] . ').'
    );

    $placeholders = implode(',', array_fill(0, count($updatedRequestIds), '?'));
    $types = str_repeat('i', count($updatedRequestIds));
    $fetch = $writeConn->prepare(
        "SELECT * FROM fx_fund_request_table WHERE id IN ($placeholders) ORDER BY id"
    );
    $fetch->bind_param($types, ...$updatedRequestIds);
    $fetch->execute();
    $result = $fetch->get_result();
    $updatedRequests = [];
    while ($row = $result->fetch_assoc()) {
        $updatedRequests[] = fxFundRequestFormatRow($row);
    }
    $fetch->close();

    if ($instructionId > 0) {
        $fetchInstruction = $writeConn->prepare('SELECT * FROM fx_instruction_letter_table WHERE id = ? LIMIT 1');
        $fetchInstruction->bind_param('i', $instructionId);
        $fetchInstruction->execute();
        $createdInstruction = $fetchInstruction->get_result()->fetch_assoc() ?: null;
        $fetchInstruction->close();
    }

    $writeConn->commit();
    $transactionStarted = false;

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => !empty($effectiveProcessing['is_immediate'])
            ? count($updatedRequestIds) . ' FX Fund Request(s) grouped and marked Paid successfully.'
            : count($updatedRequestIds) . ' FX Fund Request(s) grouped into one payment successfully.',
        'data' => [
            'fund_requests' => $updatedRequests,
            'fx_instruction' => $createdInstruction,
            'line_conversions' => $groupedConversion['lines'],
            'payment_total' => [
                'currency' => $groupedConversion['payment_currency'],
                'amount' => number_format((float) $groupedConversion['payment_amount'], 2, '.', ''),
            ],
            'processing_batches' => $processingBatches,
            'completion' => [
                'mode' => $effectiveProcessing['completion_mode'],
                'processing_business_days' => $effectiveProcessing['processing_business_days'],
                'expected_completion_at' => $effectiveProcessing['expected_completion_at'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (mysqli_sql_exception $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX Fund Request grouped process DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'Failed',
        'message' => 'Database error while grouping FX Fund Requests for payment.',
    ]);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX Fund Request grouped process error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
