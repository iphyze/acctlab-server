<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserIntegrity = (string) ($userData['integrity'] ?? '');
    $loggedInUserEmail = trim((string) ($userData['email'] ?? 'account-user')) ?: 'account-user';

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Unauthorized: Only Admins can remove GAPS schedules.', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $paymentIds = array_values(array_unique(array_filter(array_map(
        'intval',
        is_array($data['paymentIds'] ?? null) ? $data['paymentIds'] : []
    ), static fn(int $id): bool => $id > 0)));

    if ($paymentIds === []) {
        throw new RuntimeException('Please select a payment first.', 400);
    }
    if (count($paymentIds) > 100) {
        throw new RuntimeException('A maximum of 100 GAPS schedules can be removed at once.', 400);
    }

    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Removed from the downloadable GAPS schedule after the linked payment operation completed with exceptions.';
    }
    if (strlen($reason) > 255) {
        throw new RuntimeException('Removal reason is too long.', 400);
    }

    accountSupplierEnsurePaymentStorage($conn);
    $conn->begin_transaction();
    $transactionStarted = true;

    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
    $types = str_repeat('i', count($paymentIds));

    $snapshotStmt = $conn->prepare(
        "SELECT id, payment_amount, payment_date, batch, invoice_numbers, po_numbers, remark,
                suppliers_name, supplier_id, account_number, sort_code, account_name, bank_name, userId
         FROM payment_schedule_tab
         WHERE id IN ($placeholders)
         FOR UPDATE"
    );
    $snapshotStmt->bind_param($types, ...$paymentIds);
    $snapshotStmt->execute();
    $scheduleRows = $snapshotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $snapshotStmt->close();

    if ($scheduleRows === []) {
        throw new RuntimeException('No matching GAPS schedule records were found.', 404);
    }

    $snapshotById = [];
    foreach ($scheduleRows as $row) {
        $snapshotById[(int) $row['id']] = $row;
    }

    $links = accountSupplierRemoveExceptionPaymentArtifacts(
        $conn,
        'GAPS Schedule',
        $paymentIds,
        ['id' => $loggedInUserId, 'email' => $loggedInUserEmail],
        $reason
    );
    $linkedIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) $row['artifact_id'],
        $links
    )));
    $unlinkedIds = array_values(array_diff($paymentIds, $linkedIds));

    $deleteStmt = $conn->prepare("DELETE FROM payment_schedule_tab WHERE id IN ($placeholders)");
    $deleteStmt->bind_param($types, ...$paymentIds);
    $deleteStmt->execute();
    $affectedRows = $deleteStmt->affected_rows;
    $deleteStmt->close();

    if ($affectedRows === 0) {
        throw new RuntimeException('No matching GAPS schedule records were removed.', 404);
    }

    $actor = ['id' => $loggedInUserId, 'email' => $loggedInUserEmail];
    $requestIds = [];
    foreach ($links as $link) {
        $scheduleId = (int) $link['artifact_id'];
        $batchId = (int) $link['batch_id'];
        $linkRequestIds = array_values(array_unique(array_map(
            'intval',
            is_array($link['supplier_request_ids'] ?? null) ? $link['supplier_request_ids'] : []
        )));
        foreach ($linkRequestIds as $requestId) {
            $requestIds[] = $requestId;
            accountSupplierRecordEvent(
                $conn,
                $requestId,
                $batchId,
                'payment_artifact_removed_after_exception',
                $actor,
                [
                    'artifact_type' => 'GAPS Schedule',
                    'artifact_id' => $scheduleId,
                    'payment_operation_reference' => $link['batch_reference'] ?? null,
                    'processing_reference' => $link['processing_reference'] ?? null,
                    'payment_operation_status' => $link['payment_operation_status'] ?? null,
                    'reason' => $reason,
                    'schedule_snapshot' => $snapshotById[$scheduleId] ?? null,
                ]
            );
        }
    }
    $requestIds = array_values(array_unique(array_filter($requestIds)));

    if ($requestIds !== []) {
        $requestPlaceholders = implode(',', array_fill(0, count($requestIds), '?'));
        $requestTypes = str_repeat('i', count($requestIds));
        $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
        $purchaseStmt = $conn->prepare(
            "SELECT DISTINCT p.legacy_source_id AS purchase_id, sfr.id AS supplier_request_id
             FROM supplier_fund_request_table sfr
             INNER JOIN procurement_requests p
               ON p.legacy_source_id = sfr.procurement_purchase_id
              AND p.account_request_id = sfr.id
              AND p.deleted_at IS NULL
              AND {$localFinalScope}
             WHERE sfr.id IN ($requestPlaceholders)"
        );
        $purchaseStmt->bind_param($requestTypes, ...$requestIds);
        $purchaseStmt->execute();
        $linkedPurchases = $purchaseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $purchaseStmt->close();

        foreach ($linkedPurchases as $purchase) {
            $supplierRequestId = (int) $purchase['supplier_request_id'];
            $requestScheduleIds = [];
            foreach ($links as $link) {
                if (in_array($supplierRequestId, $link['supplier_request_ids'] ?? [], true)) {
                    $requestScheduleIds[] = (int) $link['artifact_id'];
                }
            }
            procurementLocalFinalRecordEvent(
                $conn,
                (int) $purchase['purchase_id'],
                'account_gaps_schedule_removed_after_exception',
                $actor,
                [
                    'supplier_fund_request_id' => $supplierRequestId,
                    'schedule_ids' => array_values(array_unique($requestScheduleIds)),
                    'reason' => $reason,
                ]
            );
        }
    }

    $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $logAction = $loggedInUserEmail . ' removed supplier GAPS schedule record(s) with ID(s): '
        . implode(', ', $paymentIds)
        . '. Exception-linked records: ' . count($linkedIds)
        . '; unlinked records: ' . count($unlinkedIds) . '.';
    $logStmt->bind_param('iss', $loggedInUserId, $logAction, $loggedInUserEmail);
    $logStmt->execute();
    $logStmt->close();

    accountNotificationPublish($conn, [
        'type' => 'gaps_schedule_removed',
        'action_key' => 'gaps_schedule_removed',
        'category' => 'supplier_payment',
        'severity' => $linkedIds !== [] ? 'warning' : 'info',
        'title' => 'GAPS schedule records removed',
        'message' => $loggedInUserEmail . ' removed ' . $affectedRows . ' GAPS schedule record'
            . ($affectedRows === 1 ? '' : 's')
            . ($linkedIds !== [] ? ' after payment exception review.' : '.'),
        'actor' => $actor,
        'entity_type' => 'gaps_schedule',
        'entity_id' => count($paymentIds) === 1 ? (string) $paymentIds[0] : count($paymentIds) . '-records',
        'route' => '/payments/gaps/suppliers',
        'payload' => [
            'schedule_ids' => $paymentIds,
            'exception_linked_schedule_ids' => $linkedIds,
            'unlinked_schedule_ids' => $unlinkedIds,
            'request_ids' => $requestIds,
            'reason' => $reason,
        ],
    ]);

    $conn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => $linkedIds !== []
            ? 'Selected GAPS schedule records were removed. Exception-linked payment operations and audit history were preserved.'
            : 'Selected GAPS schedule records were deleted successfully.',
        'data' => [
            'removed_ids' => $paymentIds,
            'removed_count' => $affectedRows,
            'exception_linked_ids' => $linkedIds,
            'unlinked_ids' => $unlinkedIds,
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
        error_log('Supplier GAPS removal error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
