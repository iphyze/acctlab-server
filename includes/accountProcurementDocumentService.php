<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementDocumentStorageService.php';

const ACCOUNT_PROCUREMENT_DOCUMENT_REQUEST_TYPES = [
    'supplier_fund_request',
    'advance_payment_request',
    'fx_fund_request',
    'compass_fund_request',
];

function accountProcurementDocumentRequestTable(string $accountRequestType): string
{
    return match ($accountRequestType) {
        'supplier_fund_request' => 'supplier_fund_request_table',
        'advance_payment_request' => 'advance_payment_request',
        'fx_fund_request' => 'fx_fund_request_table',
        'compass_fund_request' => 'compass_fund_request_table',
        default => '',
    };
}

function accountProcurementDocumentAllowedPurchaseTypes(string $accountRequestType): array
{
    return match ($accountRequestType) {
        'supplier_fund_request' => ['local_final_purchase'],
        'advance_payment_request' => ['local_advance_purchase'],
        'fx_fund_request' => ['fx_final_purchase', 'fx_advance_purchase'],
        'compass_fund_request' => ['local_final_purchase', 'local_advance_purchase'],
        default => [],
    };
}

function accountProcurementDocumentNormalizeAccountRequestType(mixed $value): string
{
    $type = strtolower(trim((string) $value));
    if (!in_array($type, ACCOUNT_PROCUREMENT_DOCUMENT_REQUEST_TYPES, true)) {
        throw new RuntimeException('Unsupported Account fund request type.', 422);
    }
    return $type;
}

function accountProcurementDocumentAssertAccountRequestExists(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): void {
    if ($accountRequestId <= 0) {
        throw new RuntimeException('A valid Account fund request is required.', 422);
    }

    $table = accountProcurementDocumentRequestTable($accountRequestType);
    if ($table === '') {
        throw new RuntimeException('Unsupported Account fund request type.', 422);
    }

    // Table names come only from the fixed map above; never from request input.
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the Account fund request.', 500);
    }
    $stmt->bind_param('i', $accountRequestId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
        throw new RuntimeException('Account fund request not found.', 404);
    }
}

function accountProcurementDocumentResolvePurchase(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): ?array {
    $accountRequestType = accountProcurementDocumentNormalizeAccountRequestType($accountRequestType);
    if ($accountRequestId <= 0) {
        throw new RuntimeException('A valid Account fund request is required.', 422);
    }

    $table = accountProcurementDocumentRequestTable($accountRequestType);
    $allowedPurchaseTypes = accountProcurementDocumentAllowedPurchaseTypes($accountRequestType);
    if ($table === '' || $allowedPurchaseTypes === []) {
        return null;
    }

    // Resolve the Account request and its latest eligible ProcureDesk handoff in
    // one query. This preserves the distinction between a valid manual Account
    // request (linked=false) and a non-existent request (404) without doing a
    // separate existence round-trip first.
    $quotedTypes = implode(',', array_fill(0, count($allowedPurchaseTypes), '?'));
    $sql = "SELECT a.id AS account_request_exists,
                   r.id AS procurement_request_id,
                   r.request_type,
                   r.request_number,
                   r.legacy_source_id,
                   r.po_number,
                   r.purchase_number,
                   r.currency,
                   r.project_code,
                   r.project_name,
                   r.supplier_name,
                   h.revision AS handoff_revision,
                   h.handoff_status,
                   h.sent_at AS handoff_sent_at,
                   h.retrieved_at AS handoff_retrieved_at
            FROM {$table} a
            LEFT JOIN procurement_request_handoffs h
              ON h.account_request_type = ?
             AND h.account_request_id = a.id
            LEFT JOIN procurement_requests r
              ON r.id = h.request_id
             AND r.deleted_at IS NULL
             AND r.request_type IN ({$quotedTypes})
            WHERE a.id = ?
            ORDER BY CASE WHEN r.id IS NULL THEN 1 ELSE 0 END ASC,
                     h.revision DESC,
                     h.id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to resolve the ProcureDesk source purchase.', 500);
    }

    $params = array_merge([$accountRequestType], $allowedPurchaseTypes, [$accountRequestId]);
    $types = 's' . str_repeat('s', count($allowedPurchaseTypes)) . 'i';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Account fund request not found.', 404);
    }
    if (empty($row['procurement_request_id'])) {
        return null;
    }

    unset($row['account_request_exists']);
    foreach (['procurement_request_id', 'legacy_source_id', 'handoff_revision'] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }
    return $row;
}

function accountProcurementDocumentList(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId
): array {
    procurementDocumentAssertStorageReady($conn);
    $purchase = accountProcurementDocumentResolvePurchase($conn, $accountRequestType, $accountRequestId);
    if (!$purchase) {
        return [
            'linked' => false,
            'source' => null,
            'documents' => [],
            'document_count' => 0,
        ];
    }

    $listed = procurementDocumentList($conn, (int) $purchase['procurement_request_id'], false);
    $documents = is_array($listed['documents'] ?? null) ? $listed['documents'] : [];

    return [
        'linked' => true,
        'source' => $purchase,
        'documents' => $documents,
        'document_count' => count($documents),
    ];
}

function accountProcurementDocumentAccessUrl(
    mysqli $conn,
    string $accountRequestType,
    int $accountRequestId,
    int $documentId,
    string $mode
): array {
    procurementDocumentAssertStorageReady($conn);
    if ($documentId <= 0) {
        throw new RuntimeException('A valid procurement document is required.', 422);
    }

    $purchase = accountProcurementDocumentResolvePurchase($conn, $accountRequestType, $accountRequestId);
    if (!$purchase) {
        throw new RuntimeException('This Account request is not linked to a ProcureDesk purchase.', 404);
    }

    $document = procurementDocumentFetch($conn, $documentId);
    if (!$document
        || (int) ($document['procurement_request_id'] ?? 0) !== (int) $purchase['procurement_request_id']
        || (string) ($document['status'] ?? '') !== PROCUREMENT_DOCUMENT_STATUS_ACTIVE) {
        throw new RuntimeException('Active procurement document not found for this Account request.', 404);
    }

    return procurementDocumentPrepareAccessUrl($document, $mode);
}
