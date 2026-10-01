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
        throw new RuntimeException('Unauthorized: Only Admins can remove Union Bank schedules.', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleIds = array_values(array_unique(array_filter(array_map(
        'intval',
        is_array($data['scheduleIds'] ?? null) ? $data['scheduleIds'] : []
    ), static fn(int $id): bool => $id > 0)));

    if ($scheduleIds === []) {
        throw new RuntimeException('Please select a payment first.', 400);
    }
    if (count($scheduleIds) > 100) {
        throw new RuntimeException('A maximum of 100 Union Bank schedules can be removed at once.', 400);
    }

    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Removed from the downloadable Union Bank schedule after the linked payment operation completed with exceptions.';
    }
    if (strlen($reason) > 255) {
        throw new RuntimeException('Removal reason is too long.', 400);
    }

    accountSupplierEnsurePaymentStorage($conn);
    $conn->begin_transaction();
    $transactionStarted = true;

    $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
    $types = str_repeat('i', count($scheduleIds));

    $snapshotStmt = $conn->prepare(
        "SELECT id, payment_amount, payment_date, batch, narration, supplier_name, supplier_id,
                bank_name, account_name, account_number, sort_code, user_id, invoice_number, po_number
         FROM union_payment_schedule
         WHERE id IN ($placeholders)
         FOR UPDATE"
    );
    $snapshotStmt->bind_param($types, ...$scheduleIds);
    $snapshotStmt->execute();
    $scheduleRows = $snapshotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $snapshotStmt->close();

    if ($scheduleRows === []) {
        throw new RuntimeException('No matching Union Bank schedule records were found.', 404);
    }

    $snapshotById = [];
    foreach ($scheduleRows as $row) {
        $snapshotById[(int) $row['id']] = $row;
    }

    $links = accountSupplierRemoveExceptionPaymentArtifacts(
        $conn,
        'Union Bank Schedule',
        $scheduleIds,
        ['id' => $loggedInUserId, 'email' => $loggedInUserEmail],
        $reason
    );
    $linkedIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) $row['artifact_id'],
        $links
    )));
    $unlinkedIds = array_values(array_diff($scheduleIds, $linkedIds));

    $deleteStmt = $conn->prepare("DELETE FROM union_payment_schedule WHERE id IN ($placeholders)");
    $deleteStmt->bind_param($types, ...$scheduleIds);
    $deleteStmt->execute();
    $affectedRows = $deleteStmt->affected_rows;
    $deleteStmt->close();

    if ($affectedRows === 0) {
        throw new RuntimeException('No matching Union Bank schedule records were removed.', 404);
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
                    'artifact_type' => 'Union Bank Schedule',
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
                'account_union_bank_schedule_removed_after_exception',
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
    $logAction = $loggedInUserEmail . ' removed Union Bank schedule record(s) with ID(s): '
        . implode(', ', $scheduleIds)
        . '. Exception-linked records: ' . count($linkedIds)
        . '; unlinked records: ' . count($unlinkedIds) . '.';
    $logStmt->bind_param('iss', $loggedInUserId, $logAction, $loggedInUserEmail);
    $logStmt->execute();
    $logStmt->close();

    accountNotificationPublish($conn, [
        'type' => 'union_bank_schedule_removed',
        'action_key' => 'union_bank_schedule_removed',
        'category' => 'supplier_payment',
        'severity' => $linkedIds !== [] ? 'warning' : 'info',
        'title' => 'Union Bank schedule records removed',
        'message' => $loggedInUserEmail . ' removed ' . $affectedRows . ' Union Bank schedule record'
            . ($affectedRows === 1 ? '' : 's')
            . ($linkedIds !== [] ? ' after payment exception review.' : '.'),
        'actor' => $actor,
        'entity_type' => 'union_bank_schedule',
        'entity_id' => count($scheduleIds) === 1 ? (string) $scheduleIds[0] : count($scheduleIds) . '-records',
        'route' => '/payments/union-bank-schedule',
        'payload' => [
            'schedule_ids' => $scheduleIds,
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
            ? 'Selected Union Bank schedule records were removed. Exception-linked payment operations and audit history were preserved.'
            : 'Selected Union Bank schedule records were deleted successfully.',
        'data' => [
            'removed_ids' => $scheduleIds,
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
        error_log('Union Bank schedule removal error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
