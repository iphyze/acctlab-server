<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountAdvancePaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

function validateAdvanceGapsRow(array $item, int $index): void
{
    $requiredFields = [
        'supplier_name' => 'Supplier Name',
        'payment_amount' => 'Payment Amount',
        'payment_date' => 'Payment Date',
        'batch' => 'Batch',
        'po_numbers' => 'PO Numbers',
        'bank_name' => 'Bank Name',
        'account_number' => 'Account Number',
        'account_name' => 'Account Name',
        'sort_code' => 'Sort Code',
        'suppliers_id' => 'Suppliers ID',
        'percentages' => 'PO Percentage',
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
        throw new RuntimeException('Invalid data format. Expected GAPS schedule rows.', 400);
    }

    $managedCompletion = isset($payload['schedules'], $payload['payment_completion'])
        && is_array($payload['schedules'])
        && is_array($payload['payment_completion']);
    $schedules = $managedCompletion ? $payload['schedules'] : $payload;
    $completion = $managedCompletion ? $payload['payment_completion'] : null;

    if (!is_array($schedules) || $schedules === []) {
        throw new RuntimeException('At least one Advance GAPS schedule row is required.', 400);
    }
    foreach ($schedules as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Row ' . ($index + 1) . ' is invalid.', 400);
        }
        validateAdvanceGapsRow($item, $index);
    }

    $requestIds = [];
    $completionMode = null;
    $businessDays = 0;
    $accountRemarks = null;
    if ($managedCompletion) {
        $source = strtolower(trim((string) ($completion['source'] ?? 'advance')));
        if ($source !== 'advance') {
            throw new RuntimeException('Managed Advance GAPS completion requires Advance Fund Requests.', 400);
        }
        $requestIds = accountAdvanceBatchIds($completion['request_ids'] ?? null);
        $completionMode = accountAdvanceValidateChoice(
            $completion['completion_mode'] ?? '',
            ACCOUNT_ADVANCE_COMPLETION_MODES,
            'Completion Mode'
        );
        $businessDays = (int) ($completion['processing_business_days'] ?? 0);
        if ($completionMode !== 'Immediate' && ($businessDays < 1 || $businessDays > 5)) {
            throw new RuntimeException('Select between 1 and 5 business days for this completion mode.', 400);
        }
        if ($completionMode === 'Immediate') {
            $businessDays = 0;
        }
        $accountRemarks = accountAdvanceOptionalText($completion, 'account_remarks');
        accountAdvanceEnsurePaymentStorage($conn);
    }

    $conn->begin_transaction();
    $transactionStarted = true;

    if ($managedCompletion) {
        $creditPlans = accountAdvancePrepareCreditOffsets($conn, $requestIds, $loggedInUserId);
        $schedules = accountAdvanceNormalizeAllocationAmounts(
            $schedules,
            $creditPlans,
            'advance_request_ids',
            'payment_amount'
        );
        $allocations = array_map(
            static fn(array $schedule): array => [
                'advance_request_ids' => $schedule['advance_request_ids']
                    ?? $schedule['source_request_ids']
                    ?? $schedule['request_ids']
                    ?? [],
                'amount' => $schedule['payment_amount'] ?? null,
            ],
            $schedules
        );
        accountAdvanceAssertFullPaymentAllocations($conn, $requestIds, $allocations, 'GAPS');
    }

    $stmt = $conn->prepare(
        'INSERT INTO advance_payment_schedule_tab
            (payment_amount, payment_date, batch, po_numbers, remark, suppliers_name,
             suppliers_id, account_number, sort_code, account_name, bank_name, percentages, userId)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Database error: Failed to prepare Advance GAPS schedule statement.', 500);
    }

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $scheduleIds = [];

    foreach ($schedules as $item) {
        $supplierName = trim((string) $item['supplier_name']);
        $paymentAmount = procurementLocalAdvanceCents(
            procurementLocalAdvanceMoneyToCents($item['payment_amount'], 'Advance GAPS Schedule Amount', false)
        );
        $paymentDate = trim((string) $item['payment_date']);
        $batch = (int) $item['batch'];
        $poNumbers = trim((string) $item['po_numbers']);
        $percentages = trim((string) $item['percentages']);
        $remark = trim((string) ($item['remark'] ?? ''));
        if ($remark === '') {
            $remark = "$percentages Advance Payment against PO No. $poNumbers";
        }
        $bankName = trim((string) $item['bank_name']);
        $accountNumber = trim((string) $item['account_number']);
        $accountName = trim((string) $item['account_name']);
        $sortCode = trim((string) $item['sort_code']);
        $supplierId = trim((string) $item['suppliers_id']);

        $stmt->bind_param(
            'dsisssssssssi',
            $paymentAmount,
            $paymentDate,
            $batch,
            $poNumbers,
            $remark,
            $supplierName,
            $supplierId,
            $accountNumber,
            $sortCode,
            $accountName,
            $bankName,
            $percentages,
            $loggedInUserId
        );
        $stmt->execute();
        $scheduleId = (int) $stmt->insert_id;
        $scheduleIds[] = $scheduleId;

        if ($logStmt) {
            $action = "$userEmail created Advance GAPS payment schedule ID $scheduleId";
            $logStmt->bind_param('iss', $loggedInUserId, $action, $userEmail);
            $logStmt->execute();
        }
    }
    $stmt->close();
    if ($logStmt) {
        $logStmt->close();
    }

    $paymentOperation = null;
    $scheduleRoute = '/payments/gaps/advance?schedule_ids=' . implode(',', $scheduleIds);
    if ($managedCompletion) {
        $scheduleBatch = (int) $schedules[0]['batch'];
        $scheduleDate = trim((string) $schedules[0]['payment_date']);
        foreach ($schedules as $item) {
            if ((int) $item['batch'] !== $scheduleBatch || trim((string) $item['payment_date']) !== $scheduleDate) {
                throw new RuntimeException(
                    'All rows in a managed Advance GAPS operation must use the same batch and payment date.',
                    409
                );
            }
        }

        $firstScheduleId = min($scheduleIds);
        $lastScheduleId = max($scheduleIds);
        $scheduleReference = $firstScheduleId === $lastScheduleId
            ? "ADV-GAPS-$firstScheduleId"
            : "ADV-GAPS-$firstScheduleId-$lastScheduleId";
        $processingReference = "Advance GAPS $scheduleDate / Batch $scheduleBatch / $scheduleReference";

        $paymentOperation = accountAdvanceCreatePaymentBatch(
            $conn,
            [
                'request_ids' => $requestIds,
                'processing_method' => 'GAPS',
                'processing_reference' => $processingReference,
                'processing_business_days' => $businessDays,
                'completion_mode' => $completionMode,
                'account_remarks' => $accountRemarks,
            ],
            ['id' => $loggedInUserId, 'email' => $userEmail],
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
            'GAPS Schedule',
            $scheduleIds,
            ['id' => $loggedInUserId, 'email' => $userEmail],
            $scheduleRoute,
            $artifactRequestMap
        );
        $paymentOperation = accountAdvanceGetBatchFromLegacyStorage($conn, (int) $paymentOperation['id']);
    }

    $conn->commit();
    $transactionStarted = false;

    http_response_code($managedCompletion ? 201 : 200);
    echo json_encode([
        'status' => 'Success',
        'message' => $managedCompletion
            ? 'Advance GAPS schedule and payment completion controls were saved successfully.'
            : 'Advance GAPS schedule has been created successfully.',
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
        error_log('Advance GAPS creation error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to create the Advance GAPS schedule.' : $error->getMessage(),
    ]);
}
