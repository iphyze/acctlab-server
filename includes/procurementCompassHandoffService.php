<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';

/**
 * Shared Compass account-handoff routing helpers.
 *
 * Local Final and Local Advance keep ownership of their approval/accounting
 * workflows; this service only centralizes Compass identity, destination and
 * cross-flow linkage rules.
 */
const PROCUREMENT_COMPASS_SUPPLIER_ID = 440;
const PROCUREMENT_COMPASS_SUPPLIER_NAME = 'Compass Power Solutions Ltd';

function procurementCompassNormalizeSupplierName(mixed $value): string
{
    $name = strtolower(trim((string) $value));
    if ($name === '') {
        return '';
    }

    $name = preg_replace('/\blimited\b/u', 'ltd', $name) ?? $name;
    return preg_replace('/[^a-z0-9]+/u', '', $name) ?? '';
}

function procurementCompassIsSupplier(mixed $supplierId, mixed $supplierName): bool
{
    $id = (int) $supplierId;
    if ($id > 0 && $id === PROCUREMENT_COMPASS_SUPPLIER_ID) {
        return true;
    }

    $name = procurementCompassNormalizeSupplierName($supplierName);
    return $name !== ''
        && $name === procurementCompassNormalizeSupplierName(PROCUREMENT_COMPASS_SUPPLIER_NAME);
}

/**
 * Resolve the Account destination for a LOCAL request only.
 *
 * FX is intentionally unsupported here so this helper cannot accidentally
 * change the existing FX handoff path.
 */
function procurementCompassResolveLocalAccountRequestType(
    string $requestType,
    mixed $supplierId,
    mixed $supplierName
): string {
    $defaultType = procurementRequestCanonicalDefaultLocalAccountRequestType($requestType);
    if ($defaultType === null) {
        throw new InvalidArgumentException('Compass handoff routing only supports Local Final and Local Advance requests.');
    }

    return procurementCompassIsSupplier($supplierId, $supplierName)
        ? PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS
        : $defaultType;
}

function procurementCompassRequiredStorageColumns(): array
{
    return [
        'purchase_number', 'po_number', 'purchase_date',
        'vat_rate', 'vat_status', 'wht_status', 'wht_rate',
        'wht_override_status', 'wht_override_rate', 'wht_override_amount',
        'wht_override_reason', 'wht_override_by', 'wht_override_at',
        'processing_method', 'processing_reference', 'processing_started_at',
        'processing_business_days', 'expected_completion_at', 'completion_mode',
        'payment_confirmation_status', 'amount_paid', 'paid_at', 'payment_reference',
        'account_remarks', 'payment_batch_id', 'payment_updated_by', 'payment_updated_at',
        'procurement_source', 'procurement_purchase_id', 'procurement_root_po_id',
        'procurement_request_type', 'procurement_parent_purchase_id',
        'procurement_reconciliation_id', 'procurement_revision',
        'procurement_po_revision_id', 'procurement_po_revision_number',
        'procurement_po_snapshot_json', 'procurement_current_po_revision_id',
        'procurement_current_po_revision_number',
    ];
}

function procurementCompassAssertStorageReady(mysqli $conn): void
{
    $required = procurementCompassRequiredStorageColumns();
    if (procurementRequestCanonicalRuntimeColumnsReady($conn, 'compass_fund_request_table', $required)) {
        return;
    }
    if (procurementRequestCanonicalRuntimeObjectType($conn, 'compass_fund_request_table') !== 'BASE TABLE') {
        throw new RuntimeException('Compass Fund Request storage is unavailable.', 500);
    }

    $missing = array_values(array_filter(
        $required,
        static fn(string $column): bool => !procurementRequestCanonicalRuntimeColumnExists(
            $conn,
            'compass_fund_request_table',
            $column
        )
    ));

    if ($missing !== []) {
        throw new RuntimeException(
            'Compass Fund Request storage is incomplete. Apply the Compass handoff foundation migration: '
            . implode(', ', $missing),
            500
        );
    }
}

/**
 * Return the single active ProcureDesk purchase linked to a Compass Account row.
 * Both Local Final and Local Advance use the same existing Compass table.
 */
function procurementCompassLinkedProcurementRequest(mysqli $conn, int $compassRequestId): ?array
{
    if ($compassRequestId <= 0) {
        return null;
    }

    if (procurementRequestCanonicalRuntimeObjectType($conn, 'procurement_requests') !== 'BASE TABLE') {
        throw new RuntimeException('Canonical procurement request storage is unavailable.', 500);
    }
    $stmt = $conn->prepare(
        "SELECT request_type, legacy_source_id AS purchase_id, approval_status,
                payment_status, handoff_status, account_request_type, account_request_id
         FROM procurement_requests
         WHERE account_request_type = 'compass_fund_request'
           AND account_request_id = ?
           AND request_type IN ('local_final_purchase', 'local_advance_purchase')
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT 2"
    );
    $stmt->bind_param('i', $compassRequestId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($rows) > 1) {
        throw new RuntimeException(
            'The Compass Fund Request is linked to more than one active ProcureDesk purchase.',
            409
        );
    }

    return $rows[0] ?? null;
}
