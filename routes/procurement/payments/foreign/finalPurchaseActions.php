<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxFinalPurchaseService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxFinalPurchaseStorage($conn);
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
    $ids = procurementFxFinalBatchIds($data['ids'] ?? $data['requestIds'] ?? null);
    $successful = [];
    $failed = [];

    if ($action === 'approve') {
        $actor = procurementRequirePermission($conn, 'payments.fx_final.approve');
        foreach ($ids as $id) {
            try {
                $record = procurementFxFinalApproveOne($conn, $id, $actor);
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } elseif (in_array($action, ['reverse_approval', 'unapprove'], true)) {
        $actor = procurementRequirePermission($conn, 'payments.fx_final.reverse_approval');
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('An approval-reversal reason is required.', 400);
        }
        foreach ($ids as $id) {
            try {
                $record = procurementFxFinalReverseApprovalOne($conn, $id, $actor, $reason);
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } elseif (in_array($action, ['retrieve_from_account', 'retrieve'], true)) {
        $actor = procurementRequirePermission($conn, 'payments.fx_final.retrieve_from_account');
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('A retrieval reason is required.', 400);
        }
        foreach ($ids as $id) {
            try {
                $record = procurementFxFinalRetrieveOne($conn, $id, $actor, $reason, 'procurement');
                $successful[] = ['id' => $id, 'record' => $record];
            } catch (Throwable $error) {
                $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
            }
        }
    } elseif ($action === 'update_po_status') {
        $actor = procurementRequirePermission($conn, 'payments.fx_final.update_po_status');
        $poStatus = procurementLocalFinalValidateStatus(
            $data['po_status'] ?? '',
            PROCUREMENT_LOCAL_FINAL_PO_STATUSES,
            'PO Status'
        );
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($poStatus === 'Cancelled' && $reason === '') {
            throw new RuntimeException('A reason is required when cancelling a PO.', 400);
        }
        $canModifyApproved = (string) $actor['role'] === 'super_admin'
            || in_array('payments.fx_final.approve', $actor['permissions'], true);

        foreach ($ids as $id) {
            if ($poStatus === 'Cancelled') {
                try {
                    $current = procurementFxFinalFetchRecord($conn, $id);
                    if (!$current) throw new RuntimeException('FX Final Purchase not found.', 404);
                    if ((string) ($current['approval_status'] ?? '') === 'Approved' && !$canModifyApproved) {
                        throw new RuntimeException('Only a supervisor or delegated approver can cancel an approved transaction.', 403);
                    }
                    $record = procurementFxFinalCancelOne($conn, $id, $actor, $reason);
                    $successful[] = ['id' => $id, 'record' => $record];
                } catch (Throwable $error) {
                    $failed[] = ['id' => $id, 'reason' => $error->getMessage()];
                }
                continue;
            }

            $conn->begin_transaction();
            try {
                $record = procurementFxFinalFetchRecord($conn, $id, true);
                if (!$record) throw new RuntimeException('FX Final Purchase not found.', 404);
                if ((string) $record['approval_status'] === 'Approved' && !$canModifyApproved) {
                    throw new RuntimeException('Only a supervisor or delegated approver can update an approved transaction.', 403);
                }
                $actorId = (int) $actor['id'];
                $scope = procurementRequestCanonicalFxFinalScopeSql();
                $stmt = $conn->prepare(
                    "UPDATE procurement_requests
                     SET po_status = ?, updated_by = ?, updated_at = NOW(), version = version + 1
                     WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
                );
                $stmt->bind_param('sii', $poStatus, $actorId, $id);
                $stmt->execute();
                $stmt->close();
                procurementFxFinalRecordEvent($conn, $id, 'po_status_updated', $actor, [
                    'previous_status' => (string) $record['po_status'],
                    'po_status' => $poStatus,
                    'reason' => $reason !== '' ? $reason : null,
                ]);
                procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_FINAL, $id);
                $conn->commit();
                $successful[] = [
                    'id' => $id,
                    'record' => procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $id) ?? []),
                ];
            } catch (Throwable $error) {
                $conn->rollback();
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
    if ($status >= 500) error_log('FX Final Purchase action error: ' . $error->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process the FX Final Purchase action.' : $error->getMessage(),
    ], $status);
}
