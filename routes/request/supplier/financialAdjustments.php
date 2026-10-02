<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementSupplierFinancialAdjustmentService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    $user = requireAdmin();
    $actorId = (int) ($user['id'] ?? 0);
    $writeConn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $offsetRequestType = trim((string) ($_GET['offset_request_type'] ?? ''));
        $offsetRequestId = (int) ($_GET['offset_request_id'] ?? 0);
        if ($offsetRequestType !== '' && $offsetRequestId > 0) {
            $payload = procurementSupplierOffsetOptionsForRequest($writeConn, $offsetRequestType, $offsetRequestId);
        } else {
            $adjustmentId = (int) ($_GET['id'] ?? 0);
            $payload = $adjustmentId > 0
                ? procurementSupplierAdjustmentGet($writeConn, $adjustmentId)
                : procurementSupplierAdjustmentList($writeConn, $_GET);
        }
        echo json_encode(['status' => 'Success', 'data' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        throw new RuntimeException('Route not found.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request body.', 400);
    }
    $action = strtolower(trim((string) ($data['action'] ?? '')));

    $writeConn->begin_transaction();
    try {
        if ($action === 'register_credit_note') {
            $payload = procurementSupplierAdjustmentRegisterCreditNote(
                $writeConn,
                (int) ($data['adjustment_id'] ?? 0),
                $data,
                $actorId
            );
            $message = 'Supplier credit note registered.';
        } elseif ($action === 'record_recovery') {
            $payload = procurementSupplierAdjustmentRecordRecovery(
                $writeConn,
                (int) ($data['adjustment_id'] ?? 0),
                $data,
                $actorId
            );
            $message = 'Supplier recovery recorded.';
        } elseif ($action === 'cancel_credit_note') {
            $payload = procurementSupplierAdjustmentCancelCreditNote(
                $writeConn,
                (int) ($data['credit_note_id'] ?? 0),
                (string) ($data['reason'] ?? ''),
                $actorId
            );
            $message = 'Supplier credit note cancelled.';
        } elseif ($action === 'save_supplier_offset') {
            $payload = procurementSupplierReserveManualOffsets(
                $writeConn,
                (string) ($data['request_type'] ?? ''),
                (int) ($data['request_id'] ?? 0),
                is_array($data['allocations'] ?? null) ? $data['allocations'] : [],
                (string) ($data['agreement_reference'] ?? ''),
                (string) ($data['notes'] ?? ''),
                $actorId
            );
            $message = 'Supplier offset saved.';
        } elseif ($action === 'clear_supplier_offset') {
            $requestType = (string) ($data['request_type'] ?? '');
            $requestId = (int) ($data['request_id'] ?? 0);
            procurementSupplierOffsetTargetDetails($writeConn, $requestType, $requestId);
            procurementSupplierReleaseCreditReservations(
                $writeConn,
                $requestType,
                $requestId,
                $actorId,
                'Supplier offset cleared before payment processing.'
            );
            $payload = procurementSupplierOffsetOptionsForRequest($writeConn, $requestType, $requestId);
            $message = 'Supplier offset cleared.';
        } else {
            throw new RuntimeException('Unsupported supplier recovery action.', 400);
        }
        $writeConn->commit();
    } catch (Throwable $transactionError) {
        $writeConn->rollback();
        throw $transactionError;
    }

    echo json_encode([
        'status' => 'Success',
        'message' => $message,
        'data' => $payload,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Supplier financial adjustment error: ' . $error->getMessage());
    $status = (int) $error->getCode();
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
