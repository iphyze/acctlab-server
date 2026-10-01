<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxAdvancePurchaseStorage($conn);
    procurementNotificationEnsureStorage($conn);
    accountNotificationEnsureStorage($conn);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequireCsrfToken();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request body.', 400);
    }

    $action = strtolower(trim((string) ($data['action'] ?? '')));
    $ids = procurementFxAdvanceBatchIds($data['ids'] ?? $data['requestIds'] ?? null);
    $successful = [];
    $failed = [];

    if ($action === 'approve') {
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.approve');
        foreach ($ids as $id) {
            try {
                $record = procurementFxAdvanceApproveOne($conn, $id, $actor);
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } elseif (in_array($action, ['reverse_approval', 'unapprove'], true)) {
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.reverse_approval');
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') throw new RuntimeException('An approval-reversal reason is required.', 400);
        foreach ($ids as $id) {
            try {
                $record = procurementFxAdvanceReverseApprovalOne($conn, $id, $actor, $reason);
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } elseif (in_array($action, ['retrieve_from_account', 'retrieve'], true)) {
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.retrieve_from_account');
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') throw new RuntimeException('A retrieval reason is required.', 400);
        foreach ($ids as $id) {
            try {
                $record = procurementFxAdvanceRetrieveOne($conn, $id, $actor, $reason, 'procurement');
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } elseif ($action === 'update_po_status') {
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.update_po_status');
        $poStatus = procurementLocalAdvanceValidateStatus(
            $data['po_status'] ?? '',
            PROCUREMENT_LOCAL_ADVANCE_PO_STATUSES,
            'PO Status'
        );
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($poStatus === 'Cancelled' && $reason === '') {
            throw new RuntimeException('A reason is required when cancelling a PO.', 400);
        }
        foreach ($ids as $id) {
            try {
                $record = procurementFxAdvanceUpdatePoStatusOne($conn, $id, $poStatus, $actor, $reason);
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } else {
        throw new RuntimeException('Unsupported batch action.', 400);
    }

    $statusText = $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success');
    $statusCode = $successful === [] && $failed !== [] ? 409 : 200;
    jsonResponse([
        'status' => $statusText,
        'message' => $failed === [] ? 'Batch action completed successfully.' : 'Batch action completed with some exceptions.',
        'data' => ['successful' => $successful, 'failed' => $failed],
    ], $statusCode);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('FX Advance Purchase action error: ' . $error->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process the FX Advance Purchase action.' : $error->getMessage(),
    ], $status);
}
