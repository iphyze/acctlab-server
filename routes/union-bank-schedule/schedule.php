<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';
require_once 'includes/accountAdvancePaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

function validateUnionScheduleRow(array $item, int $index): void
{
    $requiredFields = [
        'supplier_name' => 'Supplier Name',
        'payment_amount' => 'Payment Amount',
        'payment_date' => 'Payment Date',
        'batch' => 'Batch',
        'bank_name' => 'Bank Name',
        'account_number' => 'Account Number',
        'account_name' => 'Account Name',
        'sort_code' => 'Sort Code',
        'supplier_id' => 'Supplier ID',
    ];

    foreach ($requiredFields as $key => $label) {
        if (!array_key_exists($key, $item) || trim((string) $item[$key]) === '') {
            throw new RuntimeException('Row ' . ($index + 1) . ": $label is required.", 400);
        }
    }

    if (!is_numeric($item['payment_amount']) || (float) $item['payment_amount'] <= 0) {
        throw new RuntimeException('Row ' . ($index + 1) . ': Payment Amount must be greater than zero.', 400);
    }
    if (!is_numeric($item['batch']) || (int) $item['batch'] < 1 || (int) $item['batch'] > 10) {
        throw new RuntimeException('Row ' . ($index + 1) . ': Batch must be between 1 and 10.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $item['payment_date']))) {
        throw new RuntimeException('Row ' . ($index + 1) . ': Payment Date must be in YYYY-MM-DD format.', 400);
    }

    $invoice = trim((string) ($item['invoice_number'] ?? ''));
    $po = trim((string) ($item['po_number'] ?? ''));
    if ($invoice === '' && $po === '') {
        throw new RuntimeException('Row ' . ($index + 1) . ': Either Invoice Number or PO Number must be provided.', 400);
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
        throw new RuntimeException('Unauthorized: Only Admins can create payment schedules.', 401);
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || $payload === []) {
        throw new RuntimeException('Invalid data format. Expected Union Bank schedule rows.', 400);
    }

    $managedCompletion = isset($payload['schedules'], $payload['payment_completion'])
        && is_array($payload['schedules'])
        && is_array($payload['payment_completion']);
    $schedules = $managedCompletion ? $payload['schedules'] : $payload;
    $completion = $managedCompletion ? $payload['payment_completion'] : null;

    if (!is_array($schedules) || $schedules === []) {
        throw new RuntimeException('At least one Union Bank schedule row is required.', 400);
    }
    foreach ($schedules as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Row ' . ($index + 1) . ' is invalid.', 400);
        }
        validateUnionScheduleRow($item, $index);
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
                'Managed Union Bank Schedule completion is available only for Supplier or Advance Fund Requests.',
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
            $schedules = accountAdvanceNormalizeAllocationAmounts(
                $schedules,
                $creditPlans,
                'advance_request_ids',
                'payment_amount'
            );
        } else {
            $creditPlans = accountSupplierPrepareCreditOffsets($conn, $requestIds, $loggedInUserId);
            $schedules = accountSupplierNormalizeAllocationAmounts(
                $schedules,
                $creditPlans,
                'supplier_request_ids',
                'payment_amount'
            );
        }

        $allocations = array_map(
            static fn(array $schedule): array => [
                $managedSource === 'advance' ? 'advance_request_ids' : 'supplier_request_ids' =>
                    $schedule[$managedSource === 'advance' ? 'advance_request_ids' : 'supplier_request_ids']
                    ?? $schedule['source_request_ids']
                    ?? $schedule['request_ids']
                    ?? [],
                'amount' => $schedule['payment_amount'] ?? null,
            ],
            $schedules
        );

        if ($managedSource === 'advance') {
            accountAdvanceAssertFullPaymentAllocations($conn, $requestIds, $allocations);
        } else {
            accountSupplierAssertFullPaymentAllocations($conn, $requestIds, $allocations);
        }
    }

    $stmt = $conn->prepare(
        'INSERT INTO union_payment_schedule
            (payment_amount, payment_date, batch, narration, supplier_name, supplier_id,
             bank_name, account_name, account_number, sort_code, user_id, invoice_number, po_number)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Database error: Failed to prepare Union Bank schedule statement.', 500);
    }

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $scheduleIds = [];

    foreach ($schedules as $item) {
        $supplierName = trim((string) $item['supplier_name']);
        $paymentAmount = $managedSource === 'advance'
            ? procurementLocalAdvanceCents(procurementLocalAdvanceMoneyToCents($item['payment_amount'], 'Union Bank Schedule Amount', false))
            : procurementLocalFinalCents(procurementLocalFinalMoneyToCents($item['payment_amount'], 'Union Bank Schedule Amount', false));
        $paymentDate = trim((string) $item['payment_date']);
        $batch = (int) $item['batch'];
        $bankName = trim((string) $item['bank_name']);
        $accountNumber = trim((string) $item['account_number']);
        $accountName = trim((string) $item['account_name']);
        $sortCode = trim((string) $item['sort_code']);
        $supplierId = trim((string) $item['supplier_id']);
        $invoiceNumber = trim((string) ($item['invoice_number'] ?? ''));
        $poNumber = trim((string) ($item['po_number'] ?? ''));

        $narrationParts = [];
        if ($invoiceNumber !== '') {
            $narrationParts[] = "Inv No $invoiceNumber";
        }
        if ($poNumber !== '') {
            $narrationParts[] = "Po No $poNumber";
        }
        $narration = 'Payment against ' . implode(', ', $narrationParts);

        $stmt->bind_param(
            'dsisssssssiss',
            $paymentAmount,
            $paymentDate,
            $batch,
            $narration,
            $supplierName,
            $supplierId,
            $bankName,
            $accountName,
            $accountNumber,
            $sortCode,
            $loggedInUserId,
            $invoiceNumber,
            $poNumber
        );
        $stmt->execute();
        $scheduleId = (int) $stmt->insert_id;
        $scheduleIds[] = $scheduleId;

        if ($logStmt) {
            $sourceLabel = $managedSource === 'advance' ? 'Advance' : ($managedSource === 'supplier' ? 'Supplier' : 'Local');
            $action = "$userEmail created $sourceLabel Union Bank payment schedule ID $scheduleId";
            $logStmt->bind_param('iss', $loggedInUserId, $action, $userEmail);
            $logStmt->execute();
        }
    }
    $stmt->close();
    if ($logStmt) {
        $logStmt->close();
    }

    $paymentOperation = null;
    $scheduleRoute = '/payments/union-bank-schedule?schedule_ids=' . implode(',', $scheduleIds);
    if ($managedCompletion) {
        $firstRow = $schedules[0];
        $scheduleBatch = (int) $firstRow['batch'];
        $scheduleDate = trim((string) $firstRow['payment_date']);
        foreach ($schedules as $item) {
            if ((int) $item['batch'] !== $scheduleBatch || trim((string) $item['payment_date']) !== $scheduleDate) {
                throw new RuntimeException(
                    'All rows in a managed Union Bank Schedule operation must use the same batch and payment date.',
                    409
                );
            }
        }

        $firstScheduleId = min($scheduleIds);
        $lastScheduleId = max($scheduleIds);
        $scheduleReference = $firstScheduleId === $lastScheduleId
            ? "UNION-$firstScheduleId"
            : "UNION-$firstScheduleId-$lastScheduleId";
        $processingReference = "Union $scheduleDate / Batch $scheduleBatch / $scheduleReference";
        $actor = ['id' => $loggedInUserId, 'email' => $userEmail];

        if ($managedSource === 'advance') {
            $paymentOperation = accountAdvanceCreatePaymentBatch(
                $conn,
                [
                    'request_ids' => $requestIds,
                    'processing_method' => 'Union Bank Schedule',
                    'processing_reference' => $processingReference,
                    'processing_business_days' => $businessDays,
                    'completion_mode' => $completionMode,
                    'account_remarks' => $accountRemarks,
                ],
                $actor,
                false
            );

            $artifactRequestMap = [];
            foreach ($scheduleIds as $index => $scheduleId) {
                $rawIds = $schedules[$index]['advance_request_ids']
                    ?? $schedules[$index]['source_request_ids']
                    ?? $schedules[$index]['request_ids']
                    ?? [];
                $artifactRequestMap[$scheduleId] = array_values(array_unique(array_filter(
                    array_map('intval', is_array($rawIds) ? $rawIds : []),
                    static fn(int $id): bool => $id > 0
                )));
            }
            accountAdvanceLinkPaymentArtifacts(
                $conn,
                (int) ($paymentOperation['id'] ?? 0),
                'Union Bank Schedule',
                $scheduleIds,
                $actor,
                $scheduleRoute,
                $artifactRequestMap
            );
            $paymentOperation = accountAdvanceGetBatchFromLegacyStorage($conn, (int) $paymentOperation['id']);
        } else {
            $paymentOperation = accountSupplierCreatePaymentBatch(
                $conn,
                [
                    'request_ids' => $requestIds,
                    'processing_method' => 'Union Bank Schedule',
                    'processing_reference' => $processingReference,
                    'processing_business_days' => $businessDays,
                    'completion_mode' => $completionMode,
                    'account_remarks' => $accountRemarks,
                ],
                $actor,
                false
            );

            $artifactRequestMap = [];
            foreach ($scheduleIds as $index => $scheduleId) {
                $rawIds = $schedules[$index]['supplier_request_ids']
                    ?? $schedules[$index]['source_request_ids']
                    ?? $schedules[$index]['request_ids']
                    ?? [];
                $artifactRequestMap[$scheduleId] = array_values(array_unique(array_filter(
                    array_map('intval', is_array($rawIds) ? $rawIds : []),
                    static fn(int $id): bool => $id > 0
                )));
            }
            accountSupplierLinkPaymentArtifacts(
                $conn,
                (int) ($paymentOperation['id'] ?? 0),
                'Union Bank Schedule',
                $scheduleIds,
                $actor,
                $scheduleRoute,
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
            ? (($managedSource === 'advance' ? 'Advance' : 'Supplier') . ' Union Bank schedule and payment completion controls were saved successfully.')
            : 'Schedule has been created successfully.',
        'data' => [
            'schedule_ids' => $scheduleIds,
            'schedule_route' => $scheduleRoute,
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
        error_log('Union Bank schedule creation error: ' . $error->getMessage());
    }

    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to create the Union Bank schedule.' : $error->getMessage(),
    ]);
}
