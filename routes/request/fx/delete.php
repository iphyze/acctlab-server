<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/fxFundRequestService.php';
require_once 'includes/procurementFxFinalPurchaseService.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';
require_once 'includes/fxFundRequestPaymentLifecycleService.php';

header('Content-Type: application/json');

$transactionStarted = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Route not found', 400);
    }

    $user = requireAdmin();
    $userId = (int) $user['id'];
    $userEmail = (string) $user['email'];
    $writeConn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['requestIds']) || !is_array($data['requestIds']) || !$data['requestIds']) {
        throw new Exception('Please select at least one FX Fund Request.', 400);
    }

    $requestIds = array_values(array_unique(array_filter(array_map('intval', $data['requestIds']), static fn(int $id): bool => $id > 0)));
    if (!$requestIds) {
        throw new Exception('No valid FX Fund Request IDs were provided.', 400);
    }
    if (count($requestIds) > 200) {
        throw new Exception('A maximum of 200 requests can be deleted at once.', 400);
    }

    $writeConn->begin_transaction();
    $transactionStarted = true;

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));

    $snapshotStmt = $writeConn->prepare(
        "SELECT id, request_type, suppliers_name, purchase_number, po_number, payment_status, fx_instruction_letter_id
         FROM fx_fund_request_table WHERE id IN ({$placeholders}) FOR UPDATE"
    );
    $snapshotStmt->bind_param($types, ...$requestIds);
    $snapshotStmt->execute();
    $rows = $snapshotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $snapshotStmt->close();

    if (count($rows) !== count($requestIds)) {
        throw new Exception('One or more FX Fund Requests were not found in the active database.', 404);
    }

    procurementFxFinalAssertFundRequestsCanBeDeleted($writeConn, $requestIds);
    procurementFxAdvanceAssertFundRequestsCanBeDeleted($writeConn, $requestIds);

    $processedRequestIds = array_values(array_map(
        static fn(array $row): int => (int) $row['id'],
        array_filter($rows, static fn(array $row): bool => $row['fx_instruction_letter_id'] !== null)
    ));
    if ($processedRequestIds !== []) {
        // Direct AcctLab requests may be removed even after they were prepared
        // for payment, provided the linked payment is not Paid. Reopening first
        // safely detaches a single line from a grouped payment (or removes the
        // whole instruction when it was the last line). ProcureDesk-linked
        // requests were already blocked above by the canonical safeguard.
        fxFundRequestLifecycleReopenManualRequests(
            $writeConn,
            $processedRequestIds,
            $userId,
            $userEmail,
            'Direct AcctLab FX Fund Request deleted after payment preparation was cancelled.',
            false
        );
    }

    $deleteStmt = $writeConn->prepare("DELETE FROM fx_fund_request_table WHERE id IN ({$placeholders})");
    $deleteStmt->bind_param($types, ...$requestIds);
    $deleteStmt->execute();
    $deletedCount = $deleteStmt->affected_rows;
    $deleteStmt->close();

    fxFundRequestInsertLog(
        $writeConn,
        $userId,
        $userEmail,
        $userEmail . ' deleted FX Fund Request ID(s): ' . implode(', ', $requestIds) . '.'
    );

    $writeConn->commit();
    $transactionStarted = false;

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'FX Fund Request(s) deleted successfully.',
        'deleted_count' => $deletedCount,
    ]);
} catch (Throwable $e) {
    if ($transactionStarted && isset($writeConn) && $writeConn instanceof mysqli) {
        $writeConn->rollback();
    }

    error_log('FX Fund Request delete error: ' . $e->getMessage());
    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
