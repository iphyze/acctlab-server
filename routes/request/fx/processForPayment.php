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

    $processing = fxFundRequestNormalizeProcessingPayload($data);

    $writeConn->begin_transaction();
    $transactionStarted = true;

    // Lock the canonical ACTIVE request row so two users cannot process it at the same time.
    $request = fxFundRequestLockForProcessing($writeConn, $processing['request_id']);
    $requestId = (int) $request['id'];
    $canonicalRequestType = fxFundRequestSupplierCreditRequestType($request);
    $creditPlan = fxFundRequestReserveSupplierCredit($writeConn, $request, $userId);
    $conversion = fxFundRequestResolvePaymentConversion($request, $processing, $creditPlan);
    $creditOnly = (float) $conversion['cash_request_amount'] <= 0.009;

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
        // Payment-specific bank details are resolved only when cash is actually required.
        $beneficiary = fxFundRequestLoadBeneficiaryForProcessing(
            $writeConn,
            $processing['beneficiary_details_id']
        );
        $paymentBank = fxFundRequestLoadPaymentBankForProcessing(
            $writeConn,
            $processing['payment_bank_id']
        );
        $instruction = fxFundRequestBuildInstructionForProcessing(
            $request,
            $beneficiary,
            $paymentBank,
            $effectiveProcessing,
            $conversion
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
    $paymentCurrency = (string) $conversion['payment_currency'];
    $paymentAmount = (float) $conversion['payment_amount'];
    $exchangeRate = (float) $conversion['exchange_rate'];
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
    $affected = $update->affected_rows;
    $update->close();
    if ($affected !== 1) {
        throw new Exception('FX Fund Request could not be linked to the payment operation.', 409);
    }

    $processingBatches = accountPaymentProcessingCreateFxBatches(
        $writeConn,
        [$request],
        [[
            'request_id' => $requestId,
            'payment_amount' => (float) $conversion['payment_amount'],
        ]],
        $instructionId,
        $instructionReference !== '' ? $instructionReference : 'Supplier credit offset',
        $userId,
        $effectiveProcessing
    );
    foreach ($processingBatches as $batch) {
        if ((string) ($batch['request_type'] ?? '') !== $canonicalRequestType) continue;
        $canonicalBatchId = (int) ($batch['id'] ?? 0);
        if ($canonicalBatchId > 0) {
            procurementSupplierAttachCreditReservationsToBatch($writeConn, $canonicalRequestType, $requestId, $canonicalBatchId);
        }
    }
    if (!empty($effectiveProcessing['is_immediate'])) {
        procurementSupplierFinalizeCreditReservations(
            $writeConn,
            $canonicalRequestType,
            $requestId,
            $userId,
            null,
            $instructionReference !== '' ? $instructionReference : 'FX supplier credit offset'
        );
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

    fxFundRequestInsertLog(
        $writeConn,
        $userId,
        $userEmail,
        $userEmail . ' processed FX Fund Request #' . $requestId
            . ($instructionId > 0 ? ' as FX Instruction #' . $instructionId : ' through supplier credit only')
            . ' (Ref: ' . $instructionReference . ', gross '
            . $conversion['request_currency'] . ' ' . number_format((float) $conversion['request_amount'], 2)
            . ', supplier credit ' . number_format((float) $conversion['supplier_credit_applied'], 2)
            . ', cash ' . $conversion['payment_currency'] . ' ' . number_format((float) $conversion['payment_amount'], 2)
            . ', completion ' . $effectiveProcessing['completion_mode'] . ').'
    );

    $fetchRequest = $writeConn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1');
    $fetchRequest->bind_param('i', $requestId);
    $fetchRequest->execute();
    $updatedRequest = $fetchRequest->get_result()->fetch_assoc();
    $fetchRequest->close();

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
            ? 'FX Fund Request processed and marked Paid successfully.'
            : 'FX Fund Request processed for payment successfully.',
        'data' => [
            'fund_request' => fxFundRequestFormatRow($updatedRequest ?: []),
            'fx_instruction' => $createdInstruction,
            'conversion' => $conversion,
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

    error_log('FX Fund Request process-for-payment DB error: ' . $e->getMessage());
    http_response_code($e->getCode() === 1062 ? 409 : 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getCode() === 1062
            ? 'FX Fund Request has already been linked to a payment instruction.'
            : 'Database error while processing FX Fund Request for payment.',
    ]);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX Fund Request process-for-payment error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
