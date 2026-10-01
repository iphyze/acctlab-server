<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementLocalAdvancePurchaseService.php';

function localAdvanceAmendmentErrorResponse(Throwable $error): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Local Advance PO amendment error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500
            ? 'Unable to process the Local Advance PO amendment.'
            : $error->getMessage(),
    ], $status);
}

function localAdvanceAmendmentResolvePoId(mysqli $conn, array $data): int
{
    $poId = (int) ($data['po_id'] ?? $data['poId'] ?? $_GET['po_id'] ?? $_GET['poId'] ?? 0);
    if ($poId > 0) {
        return $poId;
    }

    $purchaseId = (int) ($data['purchase_id'] ?? $data['purchaseId'] ?? $_GET['purchase_id'] ?? $_GET['purchaseId'] ?? 0);
    if ($purchaseId <= 0) {
        throw new RuntimeException('A valid Local Advance PO or Purchase ID is required.', 400);
    }
    $purchase = procurementLocalAdvanceFetchRecord($conn, $purchaseId);
    if (!$purchase) {
        throw new RuntimeException('The Local Advance Purchase could not be found.', 404);
    }
    return (int) $purchase['po_id'];
}

try {
    procurementEnsureAuthenticationTables($conn);
    procurementLocalAdvanceEnsureStorage($conn);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'payments.local_advance.view');
        $poId = localAdvanceAmendmentResolvePoId($conn, []);
        jsonResponse([
            'status' => 'Success',
            'data' => procurementLocalAdvanceListPoAmendments($conn, $poId),
        ]);
    }

    if ($method === 'POST') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'payments.local_advance.amend_paid_po');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $poId = localAdvanceAmendmentResolvePoId($conn, $data);

        $conn->begin_transaction();
        try {
            $revision = procurementLocalAdvanceCreatePoAmendment($conn, $poId, $data, $actor);
            procurementRequestCanonicalSyncAdvancePo($conn, $poId);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        jsonResponse([
            'status' => 'Success',
            'message' => 'PO amendment submitted for approval.',
            'data' => procurementLocalAdvanceSerializePoRevision($revision),
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

        $syncPoId = 0;
        $conn->begin_transaction();
        try {
            if ($action === 'approve') {
                $actor = procurementRequirePermission($conn, 'payments.local_advance.approve_po_amendment');
                $result = procurementLocalAdvanceApprovePoAmendment(
                    $conn,
                    (int) ($data['revision_id'] ?? 0),
                    $actor
                );
                $response = [
                    'revision' => procurementLocalAdvanceSerializePoRevision($result['revision']),
                    'reconciliation' => procurementLocalAdvanceSerializeReconciliation($result['reconciliation']),
                    'account_sync' => $result['account_sync'] ?? null,
                ];
                $syncPoId = (int) ($result['revision']['po_id'] ?? 0);
                $message = 'PO amendment approved, allocated and synchronized to AcctLab.';
            } elseif ($action === 'reject') {
                $actor = procurementRequirePermission($conn, 'payments.local_advance.approve_po_amendment');
                $revision = procurementLocalAdvanceRejectPoAmendment(
                    $conn,
                    (int) ($data['revision_id'] ?? 0),
                    (string) ($data['reason'] ?? ''),
                    $actor
                );
                $response = procurementLocalAdvanceSerializePoRevision($revision);
                $syncPoId = (int) ($revision['po_id'] ?? 0);
                $message = 'PO amendment rejected.';
            } elseif ($action === 'cancel') {
                $actor = procurementRequirePermission($conn, 'payments.local_advance.amend_paid_po');
                $revision = procurementLocalAdvanceCancelPoAmendment(
                    $conn,
                    (int) ($data['revision_id'] ?? 0),
                    (string) ($data['reason'] ?? ''),
                    $actor
                );
                $response = procurementLocalAdvanceSerializePoRevision($revision);
                $syncPoId = (int) ($revision['po_id'] ?? 0);
                $message = 'PO amendment cancelled.';
            } elseif ($action === 'resolve_reconciliation') {
                throw new RuntimeException(
                    'Supplier recoveries are managed in AcctLab under Supplier Recoveries.',
                    409
                );
            } else {
                throw new RuntimeException('Unsupported PO amendment action.', 400);
            }
            if ($syncPoId > 0) {
                procurementRequestCanonicalSyncAdvancePo($conn, $syncPoId);
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
    localAdvanceAmendmentErrorResponse($error);
}
