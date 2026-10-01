<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';
require_once 'includes/accountNotificationService.php';

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

    procurementLocalAdvanceEnsureStorage($conn);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request body.', 400);
    }

    $requestIds = procurementLocalAdvanceBatchIds($data['requestIds'] ?? $data['ids'] ?? null);
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
            $record = procurementLocalAdvanceRetrieveAdvanceRequestOne($conn, $requestId, $actor, $reason);
            $successful[] = [
                'id' => $requestId,
                'purchase_id' => (int) ($record['id'] ?? 0),
                'record' => procurementLocalAdvanceSerializeRecord($record),
            ];
        } catch (Throwable $error) {
            $failed[] = ['id' => $requestId, 'reason' => $error->getMessage()];
        }
    }

    if ($successful !== []) {
        try {
            $returnedIds = array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), $successful);
            $logAction = sprintf(
                '%s returned Advance Fund Request(s) %s to Procurement. Reason: %s',
                $actor['email'],
                implode(', ', $returnedIds),
                $reason
            );
            $logStmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
            if ($logStmt) {
                $logStmt->bind_param('iss', $actor['id'], $logAction, $actor['email']);
                $logStmt->execute();
                $logStmt->close();
            }
        } catch (Throwable $logError) {
            error_log('Advance request return log error: ' . $logError->getMessage());
        }

        try {
            $returnedIds = array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), $successful);
            accountNotificationPublish($conn, [
                'type' => 'advance_request_returned_to_procurement',
                'action_key' => 'advance_request_returned_to_procurement',
                'source_app' => 'acctlab',
                'category' => 'local_advance_purchase',
                'severity' => 'warning',
                'title' => 'Advance requests returned to Procurement',
                'message' => $actor['email'] . ' returned ' . count($returnedIds)
                    . ' Advance Fund Request(s) to Procurement. Reason: ' . $reason,
                'actor' => $actor,
                'entity_type' => 'advance_payment_request',
                'entity_id' => implode(',', $returnedIds),
                'route' => '/payments/fund-request/advance',
                'roles' => ['Admin', 'Super_Admin'],
                'department' => 'account',
                'payload' => [
                    'reason' => $reason,
                    'advance_payment_request_ids' => $returnedIds,
                    'successful_count' => count($successful),
                    'failed_count' => count($failed),
                ],
            ]);
        } catch (Throwable $notificationError) {
            error_log('Advance request return notification error: ' . $notificationError->getMessage());
        }
    }

    $statusText = $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success');
    $statusCode = $successful === [] && $failed !== [] ? 409 : 200;
    http_response_code($statusCode);
    echo json_encode([
        'status' => $statusText,
        'message' => $failed === []
            ? 'Selected requests were returned to Procurement.'
            : 'Return to Procurement completed with some exceptions.',
        'data' => ['successful' => $successful, 'failed' => $failed],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Advance request return error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to return the Advance Fund Request to Procurement.'
            : $error->getMessage(),
    ]);
}
