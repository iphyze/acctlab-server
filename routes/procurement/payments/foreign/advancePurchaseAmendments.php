<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';

function fxAdvanceAmendmentErrorResponse(Throwable $error): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('FX Advance PO amendment error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to process the FX Advance PO amendment.'
            : $error->getMessage(),
    ], $status);
}

function fxAdvanceAmendmentResolvePoId(mysqli $conn, array $data): int
{
    $poId = (int) ($data['po_id'] ?? $data['poId'] ?? $_GET['po_id'] ?? $_GET['poId'] ?? 0);
    if ($poId > 0) {
        $po = procurementFxAdvanceFetchPo($conn, $poId);
        if (!$po) {
            throw new RuntimeException('The FX Advance PO could not be found.', 404);
        }
        return $poId;
    }

    $purchaseId = (int) (
        $data['purchase_id'] ?? $data['purchaseId']
        ?? $_GET['purchase_id'] ?? $_GET['purchaseId'] ?? 0
    );
    if ($purchaseId <= 0) {
        throw new RuntimeException('A valid FX Advance PO or Purchase ID is required.', 400);
    }
    $purchase = procurementFxAdvanceFetchRecord($conn, $purchaseId);
    if (!$purchase) {
        throw new RuntimeException('The FX Advance Purchase could not be found.', 404);
    }
    return (int) $purchase['po_id'];
}

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxAdvancePurchaseStorage($conn);
    procurementNotificationEnsureStorage($conn);
    accountNotificationEnsureStorage($conn);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'payments.fx_advance.view');
        $poId = fxAdvanceAmendmentResolvePoId($conn, []);
        jsonResponse([
            'status' => 'Success',
            'data' => procurementFxAdvanceListPoAmendments($conn, $poId),
        ]);
    }

    if ($method === 'POST') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.fx_advance.amend_paid_po');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $poId = fxAdvanceAmendmentResolvePoId($conn, $data);

        $conn->begin_transaction();
        try {
            $revision = procurementFxAdvanceCreatePoAmendment($conn, $poId, $data, $actor);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        jsonResponse([
            'status' => 'Success',
            'message' => 'FX PO amendment submitted for approval.',
            'data' => procurementFxAdvanceSerializePoRevision($revision),
        ], 201);
    }

    if ($method === 'PATCH') {
        procurementRequireCsrfToken();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $action = strtolower(trim((string) ($data['action'] ?? '')));
        if ($action === '') {
            throw new RuntimeException('An amendment action is required.', 400);
        }

        $conn->begin_transaction();
        try {
            if ($action === 'approve') {
                $actor = procurementRequirePermission($conn, 'payments.fx_advance.approve_po_amendment');
                $result = procurementFxAdvanceApprovePoAmendment(
                    $conn,
                    (int) ($data['revision_id'] ?? 0),
                    $actor
                );
                $response = [
                    'revision' => procurementFxAdvanceSerializePoRevision($result['revision']),
                    'reconciliation' => procurementFxAdvanceSerializeReconciliation($result['reconciliation']),
                    'account_sync' => $result['account_sync'] ?? null,
                ];
                $message = 'FX PO amendment approved and synchronized to AcctLab.';
            } elseif ($action === 'reject') {
                $actor = procurementRequirePermission($conn, 'payments.fx_advance.approve_po_amendment');
                $revision = procurementFxAdvanceRejectPoAmendment(
                    $conn,
                    (int) ($data['revision_id'] ?? 0),
                    (string) ($data['reason'] ?? ''),
                    $actor
                );
                $response = procurementFxAdvanceSerializePoRevision($revision);
                $message = 'FX PO amendment rejected.';
            } elseif ($action === 'cancel') {
                $actor = procurementRequirePermission($conn, 'payments.fx_advance.amend_paid_po');
                $revision = procurementFxAdvanceCancelPoAmendment(
                    $conn,
                    (int) ($data['revision_id'] ?? 0),
                    (string) ($data['reason'] ?? ''),
                    $actor
                );
                $response = procurementFxAdvanceSerializePoRevision($revision);
                $message = 'FX PO amendment cancelled.';
            } elseif ($action === 'resolve_reconciliation') {
                $actor = procurementRequirePermission($conn, 'payments.fx_advance.resolve_po_reconciliation');
                $reconciliation = procurementFxAdvanceResolveRecoveryReconciliation(
                    $conn,
                    (int) ($data['reconciliation_id'] ?? 0),
                    $data['resolution_type'] ?? '',
                    (string) ($data['reference'] ?? ''),
                    (string) ($data['notes'] ?? ''),
                    $actor
                );
                $response = procurementFxAdvanceSerializeReconciliation($reconciliation);
                $message = 'FX PO recovery reconciliation resolved.';
            } else {
                throw new RuntimeException('Unsupported FX PO amendment action.', 400);
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        jsonResponse([
            'status' => 'Success',
            'message' => $message,
            'data' => $response,
        ]);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    fxAdvanceAmendmentErrorResponse($error);
}
