<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

function unionScheduleMoneyToCents(mixed $value): int
{
    $normalized = str_replace(',', '', trim((string) $value));
    if ($normalized === '' || !is_numeric($normalized)) {
        throw new RuntimeException('Payment Amount must be numeric.', 400);
    }

    $amount = (float) $normalized;
    if ($amount <= 0) {
        throw new RuntimeException('Payment Amount must be greater than zero.', 400);
    }

    return (int) round($amount * 100);
}

function unionScheduleRequiredText(array $data, string $field, string $label): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException($label . ' is required.', 400);
    }
    return $value;
}

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserIntegrity = (string) ($userData['integrity'] ?? '');
    $userEmail = trim((string) ($userData['email'] ?? 'account-user')) ?: 'account-user';

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Unauthorized: Only Admins can update Union Bank schedules.', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }

    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('A valid Union Bank schedule ID is required.', 400);
    }

    $supplierName = unionScheduleRequiredText($data, 'supplier_name', 'Supplier Name');
    $supplierId = unionScheduleRequiredText($data, 'supplier_id', 'Supplier ID');
    $paymentAmount = number_format(unionScheduleMoneyToCents($data['payment_amount'] ?? null) / 100, 2, '.', '');
    $paymentDate = unionScheduleRequiredText($data, 'payment_date', 'Payment Date');
    $batch = (int) unionScheduleRequiredText($data, 'batch', 'Batch');
    $bankName = unionScheduleRequiredText($data, 'bank_name', 'Bank Name');
    $accountNumber = unionScheduleRequiredText($data, 'account_number', 'Account Number');
    $accountName = unionScheduleRequiredText($data, 'account_name', 'Account Name');
    $sortCode = unionScheduleRequiredText($data, 'sort_code', 'Sort Code');
    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
    $poNumber = trim((string) ($data['po_number'] ?? ''));

    if ($invoiceNumber === '' && $poNumber === '') {
        throw new RuntimeException('Either Invoice Number or PO Number is required.', 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        throw new RuntimeException('Payment Date must be in YYYY-MM-DD format.', 400);
    }
    if ($batch < 1 || $batch > 10) {
        throw new RuntimeException('Batch must be between 1 and 10.', 400);
    }

    accountSupplierEnsurePaymentStorage($conn);
    $paymentSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $artifactSource = $paymentSources['artifacts'];
    $batchSource = $paymentSources['batches'];
    $itemSource = $paymentSources['items'];
    $conn->begin_transaction();
    $transactionStarted = true;

    $currentStmt = $conn->prepare(
        "SELECT ups.*,
                artifact.batch_id AS payment_operation_id,
                payment_batch.batch_reference AS payment_operation_reference,
                payment_batch.processing_reference AS payment_processing_reference,
                payment_batch.processing_method AS payment_processing_method,
                payment_batch.status AS payment_operation_status
         FROM union_payment_schedule ups
         LEFT JOIN {$artifactSource} artifact
           ON artifact.id = (
               SELECT MAX(a2.id)
               FROM {$artifactSource} a2
               WHERE a2.artifact_type = 'Union Bank Schedule'
                 AND a2.artifact_id = ups.id
           )
         LEFT JOIN {$batchSource} payment_batch
           ON payment_batch.id = artifact.batch_id
         WHERE ups.id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $currentStmt->bind_param('i', $id);
    $currentStmt->execute();
    $current = $currentStmt->get_result()->fetch_assoc();
    $currentStmt->close();

    if (!$current) {
        throw new RuntimeException("Schedule with ID $id was not found.", 404);
    }

    $paymentOperationId = (int) ($current['payment_operation_id'] ?? 0);
    $linkedRequestIds = [];

    if ($paymentOperationId > 0) {
        $processingMethod = accountSupplierNormalizePaymentMethod($current['payment_processing_method'] ?? '');
        if ($processingMethod !== 'Union Bank Schedule') {
            throw new RuntimeException('This schedule is linked to an unsupported payment operation.', 409);
        }

        $operationStatus = trim((string) ($current['payment_operation_status'] ?? ''));
        if (!in_array($operationStatus, ['Processing', 'Awaiting Confirmation'], true)) {
            throw new RuntimeException(
                'This Union Bank schedule belongs to a closed payment operation and can no longer be edited. Correct or reopen the payment workflow first.',
                409
            );
        }

        if (trim((string) ($current['supplier_id'] ?? '')) !== $supplierId
            || trim((string) ($current['supplier_name'] ?? '')) !== $supplierName) {
            throw new RuntimeException(
                'The supplier is locked because this Union Bank schedule is linked to approved Supplier Fund Requests.',
                409
            );
        }

        if (unionScheduleMoneyToCents($current['payment_amount'] ?? 0) !== unionScheduleMoneyToCents($paymentAmount)) {
            throw new RuntimeException(
                'The payment amount is locked because it must remain equal to the complete approved payable amount. Use the approved payment-correction workflow instead.',
                409
            );
        }

        if ((string) ($current['payment_date'] ?? '') !== $paymentDate || (int) ($current['batch'] ?? 0) !== $batch) {
            throw new RuntimeException(
                'Payment Date and Batch are locked for a linked Union Bank payment operation so its processing reference remains consistent.',
                409
            );
        }

        $requestStmt = $conn->prepare(
            "SELECT item.supplier_fund_request_id, sfr.amount
             FROM {$itemSource} item
             INNER JOIN supplier_fund_request_table sfr
               ON sfr.id = item.supplier_fund_request_id
             WHERE item.batch_id = ?
               AND CAST(sfr.supplier_id AS CHAR) = ?
             ORDER BY item.supplier_fund_request_id ASC"
        );
        $currentSupplierId = trim((string) ($current['supplier_id'] ?? ''));
        $requestStmt->bind_param('is', $paymentOperationId, $currentSupplierId);
        $requestStmt->execute();
        $linkedRequestRows = $requestStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $requestStmt->close();

        if ($linkedRequestRows === []) {
            throw new RuntimeException('The linked Supplier Fund Requests for this Union Bank schedule could not be resolved.', 409);
        }

        $linkedRequestIds = array_map(
            static fn(array $row): int => (int) $row['supplier_fund_request_id'],
            $linkedRequestRows
        );
        $approvedCents = array_reduce(
            $linkedRequestRows,
            static fn(int $total, array $row): int => $total + unionScheduleMoneyToCents($row['amount'] ?? 0),
            0
        );
        if ($approvedCents !== unionScheduleMoneyToCents($current['payment_amount'] ?? 0)) {
            throw new RuntimeException(
                'The linked Union Bank amount no longer matches the complete approved payable amount. Resolve the source request before editing the schedule.',
                409
            );
        }
    }

    $narrationParts = [];
    if ($invoiceNumber !== '') {
        $narrationParts[] = "Inv No $invoiceNumber";
    }
    if ($poNumber !== '') {
        $narrationParts[] = "Po No $poNumber";
    }
    $narration = 'Payment against ' . implode(', ', $narrationParts);

    $updateStmt = $conn->prepare(
        'UPDATE union_payment_schedule
         SET payment_amount = ?, payment_date = ?, batch = ?, narration = ?, supplier_name = ?, supplier_id = ?,
             account_number = ?, sort_code = ?, account_name = ?, bank_name = ?, user_id = ?,
             invoice_number = ?, po_number = ?
         WHERE id = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Database error: Failed to prepare Union Bank schedule update.', 500);
    }
    $updateStmt->bind_param(
        'ssisssssssissi',
        $paymentAmount,
        $paymentDate,
        $batch,
        $narration,
        $supplierName,
        $supplierId,
        $accountNumber,
        $sortCode,
        $accountName,
        $bankName,
        $loggedInUserId,
        $invoiceNumber,
        $poNumber,
        $id
    );
    $updateStmt->execute();
    $updateStmt->close();

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $logAction = $userEmail . " updated Union Bank payment schedule with ID $id";
    $logStmt->bind_param('iss', $loggedInUserId, $logAction, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    if ($paymentOperationId > 0 && $linkedRequestIds !== []) {
        $actor = ['id' => $loggedInUserId, 'email' => $userEmail];
        $changedFields = [];
        foreach ([
            'supplier_name' => $supplierName,
            'supplier_id' => $supplierId,
            'invoice_number' => $invoiceNumber,
            'po_number' => $poNumber,
            'narration' => $narration,
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'sort_code' => $sortCode,
        ] as $field => $newValue) {
            if ((string) ($current[$field] ?? '') !== (string) $newValue) {
                $changedFields[$field] = [
                    'previous' => $current[$field] ?? null,
                    'current' => $newValue,
                ];
            }
        }

        foreach ($linkedRequestIds as $requestId) {
            accountSupplierRecordEvent($conn, $requestId, $paymentOperationId, 'payment_artifact_updated', $actor, [
                'artifact_type' => 'Union Bank Schedule',
                'artifact_id' => $id,
                'payment_operation_reference' => $current['payment_operation_reference'] ?? null,
                'processing_reference' => $current['payment_processing_reference'] ?? null,
                'changed_fields' => $changedFields,
            ]);
        }

        accountNotificationPublish($conn, [
            'type' => 'union_bank_schedule_updated',
            'action_key' => 'union_bank_schedule_updated',
            'category' => 'supplier_payment',
            'severity' => 'info',
            'title' => 'Union Bank schedule updated',
            'message' => $userEmail . ' updated Union Bank schedule #' . $id . ' linked to ' . ($current['payment_operation_reference'] ?? 'a payment operation') . '.',
            'actor' => $actor,
            'entity_type' => 'union_bank_schedule',
            'entity_id' => (string) $id,
            'route' => '/payments/union-bank-schedule?schedule_ids=' . $id,
            'payload' => [
                'schedule_id' => $id,
                'batch_id' => $paymentOperationId,
                'request_ids' => $linkedRequestIds,
                'changed_fields' => array_keys($changedFields),
            ],
        ]);

        $purchasePlaceholders = implode(',', array_fill(0, count($linkedRequestIds), '?'));
        $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
        $purchaseStmt = $conn->prepare(
            "SELECT DISTINCT p.legacy_source_id AS purchase_id, sfr.id AS supplier_request_id,
                    sfr.payment_status, sfr.payment_confirmation_status,
                    sfr.processing_reference, sfr.expected_completion_at
             FROM supplier_fund_request_table sfr
             INNER JOIN procurement_requests p
               ON p.legacy_source_id = sfr.procurement_purchase_id
              AND p.account_request_id = sfr.id
              AND p.deleted_at IS NULL
              AND {$localFinalScope}
             WHERE sfr.id IN ($purchasePlaceholders)"
        );
        $requestTypes = str_repeat('i', count($linkedRequestIds));
        $purchaseStmt->bind_param($requestTypes, ...$linkedRequestIds);
        $purchaseStmt->execute();
        $linkedPurchases = $purchaseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $purchaseStmt->close();

        foreach ($linkedPurchases as $purchase) {
            procurementLocalFinalRecordEvent(
                $conn,
                (int) $purchase['purchase_id'],
                'account_union_bank_schedule_updated',
                $actor,
                [
                    'supplier_fund_request_id' => (int) $purchase['supplier_request_id'],
                    'schedule_id' => $id,
                    'payment_batch_id' => $paymentOperationId,
                    'processing_reference' => $purchase['processing_reference'] ?? null,
                    'payment_status' => $purchase['payment_status'] ?? null,
                    'payment_confirmation_status' => $purchase['payment_confirmation_status'] ?? null,
                    'expected_completion_at' => $purchase['expected_completion_at'] ?? null,
                    'changed_fields' => array_keys($changedFields),
                ]
            );
        }
    }

    $conn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => $paymentOperationId > 0
            ? 'The linked Union Bank schedule has been updated and its payment audit history preserved.'
            : 'The schedule has been updated successfully.',
        'data' => [
            'id' => $id,
            'payment_amount' => $paymentAmount,
            'payment_date' => $paymentDate,
            'batch' => $batch,
            'invoice_number' => $invoiceNumber,
            'po_number' => $poNumber,
            'narration' => $narration,
            'supplier_name' => $supplierName,
            'supplier_id' => $supplierId,
            'account_number' => $accountNumber,
            'sort_code' => $sortCode,
            'account_name' => $accountName,
            'bank_name' => $bankName,
            'payment_operation_id' => $paymentOperationId > 0 ? $paymentOperationId : null,
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
        error_log('Union Bank schedule update error: ' . $error->getMessage());
    }

    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
