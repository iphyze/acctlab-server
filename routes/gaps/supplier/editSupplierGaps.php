<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

function supplierGapsMoneyToCents(mixed $value): int
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

function supplierGapsRequiredText(array $data, string $field, string $label): string
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
        throw new RuntimeException('Unauthorized: Only Admins can update GAPS schedules.', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid input format.', 400);
    }

    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('A valid GAPS schedule ID is required.', 400);
    }

    $supplierName = supplierGapsRequiredText($data, 'supplier_name', 'Supplier Name');
    $supplierId = (int) supplierGapsRequiredText($data, 'suppliers_id', 'Suppliers ID');
    $paymentAmount = number_format(supplierGapsMoneyToCents($data['payment_amount'] ?? null) / 100, 2, '.', '');
    $paymentDate = supplierGapsRequiredText($data, 'payment_date', 'Payment Date');
    $batch = (int) supplierGapsRequiredText($data, 'batch', 'Batch');
    $invoiceNumbers = supplierGapsRequiredText($data, 'invoice_numbers', 'Invoice Numbers');
    $poNumbers = supplierGapsRequiredText($data, 'po_numbers', 'PO Numbers');
    $bankName = supplierGapsRequiredText($data, 'bank_name', 'Bank Name');
    $accountNumber = supplierGapsRequiredText($data, 'account_number', 'Account Number');
    $accountName = supplierGapsRequiredText($data, 'account_name', 'Account Name');
    $sortCode = supplierGapsRequiredText($data, 'sort_code', 'Sort Code');

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
        "SELECT ps.*,
                artifact.batch_id AS payment_operation_id,
                payment_batch.batch_reference AS payment_operation_reference,
                payment_batch.processing_reference AS payment_processing_reference,
                payment_batch.processing_method AS payment_processing_method,
                payment_batch.status AS payment_operation_status
         FROM payment_schedule_tab ps
         LEFT JOIN {$artifactSource} artifact
           ON artifact.id = (
               SELECT MAX(a2.id)
               FROM {$artifactSource} a2
               WHERE a2.artifact_type = 'GAPS Schedule'
                 AND a2.artifact_id = ps.id
           )
         LEFT JOIN {$batchSource} payment_batch
           ON payment_batch.id = artifact.batch_id
         WHERE ps.id = ?
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
        if (strcasecmp((string) ($current['payment_processing_method'] ?? ''), 'GAPS') !== 0) {
            throw new RuntimeException('This schedule is linked to an unsupported payment operation.', 409);
        }

        $operationStatus = (string) ($current['payment_operation_status'] ?? '');
        if (!in_array($operationStatus, ['Processing', 'Awaiting Confirmation'], true)) {
            throw new RuntimeException(
                'This GAPS schedule belongs to a closed payment operation and can no longer be edited. Correct or reopen the payment workflow first.',
                409
            );
        }

        if ((string) ($current['supplier_id'] ?? '') !== (string) $supplierId
            || trim((string) ($current['suppliers_name'] ?? '')) !== $supplierName) {
            throw new RuntimeException(
                'The supplier is locked because this GAPS record is linked to approved Supplier Fund Requests.',
                409
            );
        }
        if (supplierGapsMoneyToCents($current['payment_amount'] ?? 0) !== supplierGapsMoneyToCents($paymentAmount)) {
            throw new RuntimeException(
                'The payment amount is locked because it must remain equal to the approved payable amount. Update the source request through the approved correction workflow instead.',
                409
            );
        }
        if ((string) ($current['payment_date'] ?? '') !== $paymentDate || (int) ($current['batch'] ?? 0) !== $batch) {
            throw new RuntimeException(
                'Payment Date and Batch are locked for a linked GAPS payment operation so its processing reference remains consistent.',
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
        $currentSupplierId = (string) ($current['supplier_id'] ?? '');
        $requestStmt->bind_param('is', $paymentOperationId, $currentSupplierId);
        $requestStmt->execute();
        $linkedRequestRows = $requestStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $requestStmt->close();
        if ($linkedRequestRows === []) {
            throw new RuntimeException('The linked Supplier Fund Requests for this GAPS schedule could not be resolved.', 409);
        }
        $linkedRequestIds = array_map(
            static fn(array $row): int => (int) $row['supplier_fund_request_id'],
            $linkedRequestRows
        );
        $approvedCents = array_reduce(
            $linkedRequestRows,
            static fn(int $total, array $row): int => $total + supplierGapsMoneyToCents($row['amount'] ?? 0),
            0
        );
        if ($approvedCents !== supplierGapsMoneyToCents($current['payment_amount'] ?? 0)) {
            throw new RuntimeException(
                'The linked GAPS amount no longer matches the complete approved payable amount. Resolve the source request before editing the schedule.',
                409
            );
        }
    }

    $remark = "Payment against Inv No $invoiceNumbers, Po No $poNumbers";
    $updateStmt = $conn->prepare(
        'UPDATE payment_schedule_tab
         SET payment_amount = ?, payment_date = ?, batch = ?, invoice_numbers = ?,
             po_numbers = ?, remark = ?, suppliers_name = ?, supplier_id = ?,
             account_number = ?, sort_code = ?, account_name = ?, bank_name = ?, userId = ?
         WHERE id = ?'
    );
    $updateStmt->bind_param(
        'ssissssissssii',
        $paymentAmount,
        $paymentDate,
        $batch,
        $invoiceNumbers,
        $poNumbers,
        $remark,
        $supplierName,
        $supplierId,
        $accountNumber,
        $sortCode,
        $accountName,
        $bankName,
        $loggedInUserId,
        $id
    );
    $updateStmt->execute();
    $updateStmt->close();

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $logAction = $userEmail . " updated supplier GAPS payment schedule with ID $id";
    $logStmt->bind_param('iss', $loggedInUserId, $logAction, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    if ($paymentOperationId > 0 && $linkedRequestIds !== []) {
        $actor = ['id' => $loggedInUserId, 'email' => $userEmail];
        $changedFields = [];
        foreach ([
            'suppliers_name' => $supplierName,
            'supplier_id' => $supplierId,
            'invoice_numbers' => $invoiceNumbers,
            'po_numbers' => $poNumbers,
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
                'artifact_type' => 'GAPS Schedule',
                'artifact_id' => $id,
                'payment_operation_reference' => $current['payment_operation_reference'] ?? null,
                'processing_reference' => $current['payment_processing_reference'] ?? null,
                'changed_fields' => $changedFields,
            ]);
        }

        accountNotificationPublish($conn, [
            'type' => 'gaps_schedule_updated',
            'action_key' => 'gaps_schedule_updated',
            'category' => 'supplier_payment',
            'severity' => 'info',
            'title' => 'GAPS schedule updated',
            'message' => $userEmail . ' updated GAPS schedule #' . $id . ' linked to ' . ($current['payment_operation_reference'] ?? 'a payment operation') . '.',
            'actor' => $actor,
            'entity_type' => 'gaps_schedule',
            'entity_id' => (string) $id,
            'route' => '/payments/gaps/suppliers?schedule_ids=' . $id,
            'payload' => [
                'schedule_id' => $id,
                'batch_id' => $paymentOperationId,
                'request_ids' => $linkedRequestIds,
                'changed_fields' => array_keys($changedFields),
            ],
        ]);

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
             WHERE sfr.id IN (" . implode(',', array_fill(0, count($linkedRequestIds), '?')) . ')'
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
                'account_gaps_schedule_updated',
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
            ? 'The linked GAPS schedule has been updated and the payment audit history preserved.'
            : 'The schedule has been updated successfully.',
        'data' => [
            'id' => $id,
            'payment_amount' => $paymentAmount,
            'payment_date' => $paymentDate,
            'batch' => $batch,
            'invoice_numbers' => $invoiceNumbers,
            'po_numbers' => $poNumbers,
            'remark' => $remark,
            'suppliers_name' => $supplierName,
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
        error_log('Supplier GAPS update error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
