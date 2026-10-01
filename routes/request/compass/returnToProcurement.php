<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalFinalPurchaseService.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), ['PATCH', 'PUT'], true)) {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $userData = authenticateUser();
    if (!in_array((string) ($userData['integrity'] ?? ''), ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Only authorized Account users can return requests to Procurement.', 403);
    }

    procurementCompassAssertStorageReady($conn);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request body.', 400);
    }

    $requestIds = procurementLocalFinalBatchIds($data['requestIds'] ?? $data['ids'] ?? null);
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') {
        throw new RuntimeException('A return reason is required.', 400);
    }

    $actor = [
        'id' => (int) ($userData['id'] ?? 0),
        'email' => (string) ($userData['email'] ?? 'account-user'),
    ];
    $successful = [];
    $failed = [];

    foreach ($requestIds as $requestId) {
        try {
            $linkedPurchase = procurementCompassLinkedProcurementRequest($conn, $requestId);
            if ($linkedPurchase === null) {
                throw new RuntimeException(
                    'Only ProcureDesk-linked Compass Fund Requests can be returned.',
                    409
                );
            }

            $linkedType = (string) ($linkedPurchase['request_type'] ?? '');
            if ($linkedType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL) {
                $record = procurementLocalFinalRetrieveCompassRequestOne(
                    $conn,
                    $requestId,
                    $actor,
                    $reason
                );
            } elseif ($linkedType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE) {
                $record = procurementLocalAdvanceRetrieveCompassRequestOne(
                    $conn,
                    $requestId,
                    $actor,
                    $reason
                );
            } else {
                throw new RuntimeException('Unsupported ProcureDesk Compass request type.', 409);
            }
            $successful[] = [
                'id' => $requestId,
                'purchase_id' => (int) ($record['id'] ?? 0),
                'record' => $record,
            ];
        } catch (Throwable $error) {
            $failed[] = ['id' => $requestId, 'reason' => $error->getMessage()];
        }
    }

    if ($successful !== []) {
        try {
            $returnedIds = array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), $successful);
            $logAction = sprintf(
                '%s returned Compass Fund Request(s) %s to Procurement. Reason: %s',
                $actor['email'],
                implode(', ', $returnedIds),
                $reason
            );
            $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
            if ($logStmt) {
                $actorId = (int) $actor['id'];
                $actorEmail = (string) $actor['email'];
                $logStmt->bind_param('iss', $actorId, $logAction, $actorEmail);
                $logStmt->execute();
                $logStmt->close();
            }
        } catch (Throwable $logError) {
            error_log('Compass request return log error: ' . $logError->getMessage());
        }
    }

    $statusText = $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success');
    $statusCode = $successful === [] && $failed !== [] ? 409 : 200;
    http_response_code($statusCode);
    echo json_encode([
        'status' => $statusText,
        'message' => $failed === []
            ? 'Selected Compass requests were returned to Procurement.'
            : 'Return to Procurement completed with some exceptions.',
        'data' => ['successful' => $successful, 'failed' => $failed],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Compass request return error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to return the Compass Fund Request to Procurement.'
            : $error->getMessage(),
    ]);
}
