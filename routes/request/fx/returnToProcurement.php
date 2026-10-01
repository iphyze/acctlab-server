<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/procurementFxFinalPurchaseService.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), ['PATCH', 'PUT'], true)) {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $userData = authenticateUser();
    if (!in_array((string) ($userData['integrity'] ?? ''), ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Only authorized Account users can return FX requests to Procurement.', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new RuntimeException('Invalid request body.', 400);
    $rawIds = $data['requestIds'] ?? $data['ids'] ?? null;
    if (!is_array($rawIds) || $rawIds === []) throw new RuntimeException('Select at least one ProcureDesk-linked FX request to return.', 400);
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0)));
    if ($requestIds === []) throw new RuntimeException('No valid FX Fund Request IDs were supplied.', 400);
    if (count($requestIds) > 100) throw new RuntimeException('A maximum of 100 FX Fund Requests can be returned at once.', 400);

    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') throw new RuntimeException('A return reason is required.', 400);

    $actor = [
        'id' => (int) ($userData['id'] ?? 0),
        'email' => (string) ($userData['email'] ?? 'account-user'),
    ];
    $successful = [];
    $failed = [];
    foreach ($requestIds as $requestId) {
        try {
            $linkedFinal = procurementFxFinalLinkedPurchaseForFundRequest($conn, $requestId, false);
            $linkedAdvance = procurementFxAdvanceLinkedPurchaseForFundRequest($conn, $requestId, false);
            if ($linkedFinal !== null) {
                $record = procurementFxFinalRetrieveFundRequestOne($conn, $requestId, $actor, $reason);
                $purchaseType = 'fx_final_purchase';
            } elseif ($linkedAdvance !== null) {
                $record = procurementFxAdvanceRetrieveFundRequestOne($conn, $requestId, $actor, $reason);
                $purchaseType = 'fx_advance_purchase';
            } else {
                throw new RuntimeException('This FX Fund Request is not linked to an active ProcureDesk Foreign Purchase.', 404);
            }
            $successful[] = [
                'id' => $requestId,
                'purchase_id' => (int) ($record['id'] ?? 0),
                'purchase_type' => $purchaseType,
                'record' => $record,
            ];
        } catch (Throwable $error) {
            $failed[] = ['id' => $requestId, 'reason' => $error->getMessage()];
        }
    }

    $statusText = $failed === [] ? 'Success' : ($successful === [] ? 'Failed' : 'Partial Success');
    $statusCode = $successful === [] && $failed !== [] ? 409 : 200;
    http_response_code($statusCode);
    echo json_encode([
        'status' => $statusText,
        'message' => $failed === []
            ? 'Selected FX requests were returned to Procurement.'
            : 'Return to Procurement completed with some exceptions.',
        'data' => ['successful' => $successful, 'failed' => $failed],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    if ($status >= 500) error_log('FX request return error: ' . $error->getMessage());
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to return the FX Fund Request to Procurement.' : $error->getMessage(),
    ]);
}
