<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';
require_once 'includes/accountAdvancePaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

function validatePaymentInstructionRow(array $item, int $index): void
{
    $requiredFields = [
        'beneficiary_name' => 'Beneficiary Name',
        'account_number' => 'Account Number',
        'ben_bank_name' => 'Beneficiary Bank Name',
        'payment_account_number' => 'Payment Account Number',
        'payment_category' => 'Payment Category',
        'batch' => 'Batch',
        'amount' => 'Amount',
        'date' => 'Date',
    ];

    foreach ($requiredFields as $key => $label) {
        if (!array_key_exists($key, $item) || $item[$key] === '' || $item[$key] === null) {
            throw new RuntimeException('Row ' . ($index + 1) . ": $label is required.", 400);
        }
    }
    if (!is_numeric($item['batch']) || (int) $item['batch'] < 1) {
        throw new RuntimeException('Row ' . ($index + 1) . ': Batch must be a valid numeric value.', 400);
    }
    if (!is_numeric($item['amount']) || (float) $item['amount'] <= 0) {
        throw new RuntimeException('Row ' . ($index + 1) . ': Amount must be greater than zero.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $item['date']))) {
        throw new RuntimeException('Row ' . ($index + 1) . ': Date must be in YYYY-MM-DD format.', 400);
    }
}

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserIntegrity = (string) ($userData['integrity'] ?? '');
    $userEmail = trim((string) ($userData['email'] ?? 'account-user')) ?: 'account-user';

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Unauthorized: Only Admins can create local transfers.', 401);
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || $payload === []) {
        throw new RuntimeException('Invalid data format. Expected instruction payment rows.', 400);
    }

    $managedCompletion = isset($payload['transfers'], $payload['payment_completion'])
        && is_array($payload['transfers'])
        && is_array($payload['payment_completion']);
    $transfers = $managedCompletion ? $payload['transfers'] : $payload;
    $completion = $managedCompletion ? $payload['payment_completion'] : null;

    if (!is_array($transfers) || $transfers === []) {
        throw new RuntimeException('At least one instruction payment row is required.', 400);
    }
    foreach ($transfers as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Row ' . ($index + 1) . ' is invalid.', 400);
        }
        validatePaymentInstructionRow($item, $index);
    }

    $managedSource = '';
    $requestIds = [];
    $completionMode = null;
    $businessDays = 0;
    $accountRemarks = null;

    if ($managedCompletion) {
        $managedSource = strtolower(trim((string) ($completion['source'] ?? 'supplier')));
        if (!in_array($managedSource, ['supplier', 'advance'], true)) {
            throw new RuntimeException(
                'Managed Prepare Instruction completion is available only for Supplier or Advance Fund Requests.',
                400
            );
        }

        if ($managedSource === 'advance') {
            $requestIds = accountAdvanceBatchIds($completion['request_ids'] ?? null);
            $completionMode = accountAdvanceValidateChoice(
                $completion['completion_mode'] ?? '',
                ACCOUNT_ADVANCE_COMPLETION_MODES,
                'Completion Mode'
            );
            $accountRemarks = accountAdvanceOptionalText($completion, 'account_remarks');
            accountAdvanceEnsurePaymentStorage($conn);
        } else {
            $requestIds = accountSupplierBatchIds($completion['request_ids'] ?? null);
            $completionMode = accountSupplierValidateChoice(
                $completion['completion_mode'] ?? '',
                ACCOUNT_SUPPLIER_COMPLETION_MODES,
                'Completion Mode'
            );
            $accountRemarks = accountSupplierOptionalText($completion, 'account_remarks');
            accountSupplierEnsurePaymentStorage($conn);
        }

        $businessDays = (int) ($completion['processing_business_days'] ?? 0);
        if ($completionMode !== 'Immediate' && ($businessDays < 1 || $businessDays > 5)) {
            throw new RuntimeException('Select between 1 and 5 business days for this completion mode.', 400);
        }
        if ($completionMode === 'Immediate') {
            $businessDays = 0;
        }
    }

    $conn->begin_transaction();
    $transactionStarted = true;

    if ($managedCompletion) {
        if ($managedSource === 'advance') {
            $creditPlans = accountAdvancePrepareCreditOffsets($conn, $requestIds, $loggedInUserId);
            $transfers = accountAdvanceNormalizeAllocationAmounts(
                $transfers,
                $creditPlans,
                'advance_request_ids',
                'amount'
            );
            accountAdvanceAssertFullPaymentAllocations($conn, $requestIds, $transfers);
        } else {
            $creditPlans = accountSupplierPrepareCreditOffsets($conn, $requestIds, $loggedInUserId);
            $transfers = accountSupplierNormalizeAllocationAmounts(
                $transfers,
                $creditPlans,
                'supplier_request_ids',
                'amount'
            );
            accountSupplierAssertFullPaymentAllocations($conn, $requestIds, $transfers);
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO local_transfer
            (beneficiary_name, account_number, ben_bank_name, payment_account_number,
             payment_category, batch, amount, created_at, created_by, `date`)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Database error: Failed to prepare instruction statement.', 500);
    }

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $transferIds = [];
    foreach ($transfers as $item) {
        $beneficiaryName = trim((string) $item['beneficiary_name']);
        $accountNumber = trim((string) $item['account_number']);
        $beneficiaryBankName = trim((string) $item['ben_bank_name']);
        $paymentAccountNumber = trim((string) $item['payment_account_number']);
        $paymentCategory = trim((string) $item['payment_category']);
        $batch = (int) $item['batch'];
        $amount = $managedSource === 'advance'
            ? procurementLocalAdvanceCents(procurementLocalAdvanceMoneyToCents($item['amount'], 'Instruction Row Amount', false))
            : procurementLocalFinalCents(procurementLocalFinalMoneyToCents($item['amount'], 'Instruction Row Amount', false));
        $date = trim((string) $item['date']);

        $stmt->bind_param(
            'sssssidis',
            $beneficiaryName,
            $accountNumber,
            $beneficiaryBankName,
            $paymentAccountNumber,
            $paymentCategory,
            $batch,
            $amount,
            $loggedInUserId,
            $date
        );
        $stmt->execute();
        $transferId = (int) $stmt->insert_id;
        $transferIds[] = $transferId;

        if ($logStmt) {
            $sourceLabel = $managedSource === 'advance' ? 'Advance' : ($managedSource === 'supplier' ? 'Supplier' : 'Local');
            $action = "$userEmail created $sourceLabel payment instruction transfer ID $transferId";
            $logStmt->bind_param('iss', $loggedInUserId, $action, $userEmail);
            $logStmt->execute();
        }
    }
    $stmt->close();
    if ($logStmt) {
        $logStmt->close();
    }

    $paymentOperation = null;
    $instructionRoute = '/payments/letters/suppliers?transfer_ids=' . implode(',', $transferIds);
    if ($managedCompletion) {
        $firstRow = $transfers[0];
        $instructionBatch = (int) $firstRow['batch'];
        $instructionDate = trim((string) $firstRow['date']);
        foreach ($transfers as $item) {
            if ((int) $item['batch'] !== $instructionBatch || trim((string) $item['date']) !== $instructionDate) {
                throw new RuntimeException(
                    'All rows in a managed Prepare Instruction operation must use the same batch and payment date.',
                    409
                );
            }
        }

        $firstTransferId = min($transferIds);
        $lastTransferId = max($transferIds);
        $transferReference = $firstTransferId === $lastTransferId
            ? "LT-$firstTransferId"
            : "LT-$firstTransferId-$lastTransferId";
        $processingReference = "Instruction $instructionDate / Batch $instructionBatch / $transferReference";
        $actor = ['id' => $loggedInUserId, 'email' => $userEmail];

        if ($managedSource === 'advance') {
            $paymentOperation = accountAdvanceCreatePaymentBatch(
                $conn,
                [
                    'request_ids' => $requestIds,
                    'processing_method' => 'Bank Instruction',
                    'processing_reference' => $processingReference,
                    'processing_business_days' => $businessDays,
                    'completion_mode' => $completionMode,
                    'account_remarks' => $accountRemarks,
                ],
                $actor,
                false
            );

            $artifactRequestMap = [];
            foreach ($transferIds as $index => $transferId) {
                $rawIds = $transfers[$index]['advance_request_ids']
                    ?? $transfers[$index]['source_request_ids']
                    ?? $transfers[$index]['request_ids']
                    ?? [];
                $artifactRequestMap[$transferId] = array_values(array_unique(array_filter(
                    array_map('intval', is_array($rawIds) ? $rawIds : []),
                    static fn(int $id): bool => $id > 0
                )));
            }
            accountAdvanceLinkPaymentArtifacts(
                $conn,
                (int) ($paymentOperation['id'] ?? 0),
                'Bank Instruction',
                $transferIds,
                $actor,
                $instructionRoute,
                $artifactRequestMap
            );
            $paymentOperation = accountAdvanceGetBatchFromLegacyStorage($conn, (int) $paymentOperation['id']);
        } else {
            $paymentOperation = accountSupplierCreatePaymentBatch(
                $conn,
                [
                    'request_ids' => $requestIds,
                    'processing_method' => 'Bank Instruction',
                    'processing_reference' => $processingReference,
                    'processing_business_days' => $businessDays,
                    'completion_mode' => $completionMode,
                    'account_remarks' => $accountRemarks,
                ],
                $actor,
                false
            );

            $artifactRequestMap = [];
            foreach ($transferIds as $index => $transferId) {
                $rawIds = $transfers[$index]['supplier_request_ids']
                    ?? $transfers[$index]['source_request_ids']
                    ?? $transfers[$index]['request_ids']
                    ?? [];
                $artifactRequestMap[$transferId] = array_values(array_unique(array_filter(
                    array_map('intval', is_array($rawIds) ? $rawIds : []),
                    static fn(int $id): bool => $id > 0
                )));
            }
            accountSupplierLinkPaymentArtifacts(
                $conn,
                (int) ($paymentOperation['id'] ?? 0),
                'Bank Instruction',
                $transferIds,
                $actor,
                $instructionRoute,
                $artifactRequestMap
            );
            $paymentOperation = accountSupplierGetBatchFromLegacyStorage($conn, (int) $paymentOperation['id']);
        }
    }

    $conn->commit();
    $transactionStarted = false;

    http_response_code($managedCompletion ? 201 : 200);
    echo json_encode([
        'status' => 'Success',
        'message' => $managedCompletion
            ? (($managedSource === 'advance' ? 'Advance' : 'Supplier') . ' payment instruction and completion controls were saved successfully.')
            : 'Local transfers have been created successfully.',
        'data' => [
            'transfer_ids' => $transferIds,
            'instruction_route' => $instructionRoute,
            'payment_operation' => $paymentOperation,
        ],
    ]);
} catch (Throwable $error) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Payment instruction creation error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to create the payment instruction.' : $error->getMessage(),
    ]);
}
