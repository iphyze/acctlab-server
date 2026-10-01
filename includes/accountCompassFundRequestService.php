<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementCompassHandoffService.php';
require_once __DIR__ . '/procurementLocalFinalPurchaseService.php';
require_once __DIR__ . '/procurementLocalAdvancePurchaseService.php';
require_once __DIR__ . '/accountWhtAdjustmentService.php';
require_once __DIR__ . '/accountSupplierPaymentService.php';

/**
 * Account-side safety and payment lifecycle helpers for ProcureDesk-linked
 * Compass Fund Requests.
 *
 * Compass remains in its existing fund-request table while payment operations
 * use the shared canonical payment tables under the dedicated
 * compass_fund_request request_type. This keeps overlapping public IDs isolated
 * from Supplier, Advance and FX payment operations without parallel tables.
 */

function accountCompassBatchIds(mixed $value): array
{
    if (!is_array($value)) {
        throw new RuntimeException('Please select at least one Compass Fund Request.', 400);
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', $value),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        throw new RuntimeException('Please select at least one Compass Fund Request.', 400);
    }
    if (count($ids) > 100) {
        throw new RuntimeException('A maximum of 100 Compass Fund Requests can be updated at once.', 400);
    }
    return $ids;
}

function accountCompassLinkedType(mysqli $conn, int $requestId): string
{
    $linked = procurementCompassLinkedProcurementRequest($conn, $requestId);
    if ($linked === null) {
        throw new RuntimeException('Only ProcureDesk-linked Compass Fund Requests support this Account action.', 409);
    }
    return (string) ($linked['request_type'] ?? '');
}

function accountCompassAssertNoPaymentBatch(array $request, string $action): void
{
    if ((int) ($request['payment_batch_id'] ?? 0) > 0) {
        throw new RuntimeException(
            "Compass Fund Request {$request['id']} belongs to a recorded payment operation and cannot be {$action} directly.",
            409
        );
    }
}

function accountCompassCurrentWhtBasisPoints(array $request): int
{
    $candidate = trim((string) ($request['wht_status'] ?? ''));
    if ($candidate === '') {
        $candidate = trim((string) ($request['vat_policy'] ?? '0.00%'));
    }
    try {
        return accountWhtNormalizeBasisPoints($candidate);
    } catch (Throwable) {
        $taxableBase = max(
            0.0,
            (float) ($request['net_value'] ?? 0) - (float) ($request['discount'] ?? 0)
        );
        $wht = max(0.0, (float) ($request['wht'] ?? 0));
        if ($taxableBase <= 0 || $wht <= 0) {
            return 0;
        }
        $derived = (int) round(($wht / $taxableBase) * 10000);
        foreach (ACCOUNT_WHT_ALLOWED_BASIS_POINTS as $allowed) {
            if (abs($derived - $allowed) <= 2) {
                return $allowed;
            }
        }
        return 0;
    }
}

function accountCompassAdjustWht(
    mysqli $conn,
    int $requestId,
    mixed $requestedWht,
    mixed $reasonValue,
    array $actor
): array {
    if ($requestId <= 0) {
        throw new RuntimeException('A valid Compass Fund Request ID is required.', 400);
    }

    procurementCompassAssertStorageReady($conn);
    $basisPoints = accountWhtNormalizeBasisPoints($requestedWht);
    $reason = accountWhtReason($reasonValue);
    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0) {
        throw new RuntimeException('A valid Account user is required.', 401);
    }

    $stmt = $conn->prepare(
        "SELECT cfr.*, r.request_type AS linked_request_type,
                r.legacy_source_id AS local_purchase_id,
                r.approval_status AS procurement_approval_status,
                r.handoff_status AS procurement_handoff_status,
                r.payment_status AS procurement_payment_status
         FROM compass_fund_request_table cfr
         INNER JOIN procurement_requests r
           ON r.account_request_type = 'compass_fund_request'
          AND r.account_request_id = cfr.id
          AND r.deleted_at IS NULL
         WHERE cfr.id = ?
           AND r.request_type IN ('local_final_purchase','local_advance_purchase')
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new RuntimeException(
            'Only an active ProcureDesk-linked Compass Fund Request can receive a WHT adjustment.',
            404
        );
    }
    if ((string) ($request['procurement_approval_status'] ?? '') !== 'Approved'
        || (string) ($request['procurement_handoff_status'] ?? '') !== 'In Account') {
        throw new RuntimeException('The linked Compass Fund Request is no longer an active Account handoff.', 409);
    }
    if (strcasecmp((string) ($request['payment_status'] ?? ''), 'Pending') !== 0
        || strcasecmp((string) ($request['procurement_payment_status'] ?? ''), 'Pending') !== 0) {
        throw new RuntimeException('WHT can only be adjusted while the Compass Fund Request is Pending.', 409);
    }
    accountCompassAssertNoPaymentBatch($request, 'adjusted for WHT');

    $requestType = (string) $request['linked_request_type'];
    if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE
        && strcasecmp(trim((string) ($request['procurement_request_type'] ?? 'Original')), 'Original') !== 0) {
        throw new RuntimeException(
            'WHT adjustment is unavailable on supplementary/recovery Compass advances because their amount is revision-specific. Adjust the originating PO workflow instead.',
            409
        );
    }

    $subtotalCents = procurementLocalFinalMoneyToCents(
        $request['net_value'],
        'Compass Fund Request Subtotal',
        false
    );
    $discountCents = procurementLocalFinalMoneyToCents(
        $request['discount'],
        'Compass Fund Request Discount'
    );
    if ($discountCents > $subtotalCents) {
        throw new RuntimeException('Compass Fund Request Discount cannot exceed its subtotal.', 409);
    }
    $taxableCents = $subtotalCents - $discountCents;
    $vatCents = procurementLocalFinalMoneyToCents($request['vat'], 'Compass Fund Request VAT Amount');
    $otherChargesCents = procurementLocalFinalMoneyToCents(
        $request['other_charges'],
        'Compass Fund Request Other Charges'
    );
    $vatRate = (float) ($request['vat_rate'] ?? 0);
    $hasVat = $vatCents > 0 && (
        strcasecmp((string) ($request['vat_status'] ?? ''), 'Yes') === 0
        || $vatRate > 0
    );
    accountWhtAssertVatCompatibility($hasVat, $basisPoints);

    $oldBasisPoints = accountCompassCurrentWhtBasisPoints($request);
    if ($oldBasisPoints === $basisPoints) {
        throw new RuntimeException('The selected WHT status is already applied to this Compass Fund Request.', 409);
    }

    $whtCents = procurementLocalFinalRateAmount($taxableCents, $basisPoints);
    $basePayableCents = $taxableCents + $vatCents - $whtCents + $otherChargesCents;
    if ($basePayableCents < 0) {
        throw new RuntimeException('The adjusted Compass Fund Request amount cannot be negative.', 409);
    }

    $newExpectedCents = $basePayableCents;
    if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE) {
        $percentageUnits = procurementLocalAdvancePercentUnits((string) ($request['percentage'] ?? '0'));
        $newExpectedCents = procurementLocalAdvanceMultiplyDivideRounded(
            $basePayableCents,
            $percentageUnits,
            PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
        );
    }

    $status = accountWhtStatus($basisPoints);
    $rate = accountWhtRate($basisPoints);
    $whtAmount = procurementLocalFinalCents($whtCents);
    $expectedAmount = procurementLocalFinalCents($newExpectedCents);
    $vatPolicy = accountWhtLegacyVatPolicy($hasVat, $basisPoints);

    $update = $conn->prepare(
        "UPDATE compass_fund_request_table
         SET vat_policy = ?, wht = ?, wht_status = ?, wht_rate = ?, amount = ?,
             wht_override_status = ?, wht_override_rate = ?, wht_override_amount = ?,
             wht_override_reason = ?, wht_override_by = ?, wht_override_at = NOW(),
             payment_updated_by = ?, payment_updated_at = NOW(), updated_at = NOW()
         WHERE id = ? AND payment_status = 'Pending'"
    );
    $update->bind_param(
        'sssssssssiii',
        $vatPolicy,
        $whtAmount,
        $status,
        $rate,
        $expectedAmount,
        $status,
        $rate,
        $whtAmount,
        $reason,
        $actorId,
        $actorId,
        $requestId
    );
    $update->execute();
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The Compass Fund Request changed before its WHT could be adjusted.', 409);
    }
    $update->close();

    $purchaseId = (int) $request['local_purchase_id'];
    if ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL) {
        $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
        $sync = $conn->prepare(
            "UPDATE procurement_requests
             SET wht_status = ?, wht_rate = ?, wht_amount = ?,
                 account_wht_override_status = ?, account_wht_override_rate = ?,
                 account_wht_override_amount = ?, account_payable_amount = ?,
                 account_wht_adjustment_reason = ?, account_wht_adjusted_by = ?,
                 account_wht_adjusted_at = NOW(), updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE legacy_source_id = ? AND {$canonicalScope}
               AND account_request_type = 'compass_fund_request'
               AND approval_status = 'Approved' AND handoff_status = 'In Account'
               AND payment_status = 'Pending' AND deleted_at IS NULL"
        );
        $sync->bind_param(
            'ssssssssiii',
            $status,
            $rate,
            $whtAmount,
            $status,
            $rate,
            $whtAmount,
            $expectedAmount,
            $reason,
            $actorId,
            $actorId,
            $purchaseId
        );
        $sync->execute();
        if ($sync->affected_rows !== 1) {
            $sync->close();
            throw new RuntimeException('ProcureDesk could not be synchronized with the Compass WHT adjustment.', 409);
        }
        $sync->close();
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL, $purchaseId);
    } elseif ($requestType === PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE) {
        $canonicalScope = procurementRequestCanonicalLocalAdvanceScopeSql();
        $sync = $conn->prepare(
            "UPDATE procurement_requests
             SET expected_payment = ?, account_wht_override_status = ?,
                 account_wht_override_rate = ?, account_wht_override_amount = ?,
                 account_expected_payment = ?, account_wht_adjustment_reason = ?,
                 account_wht_adjusted_by = ?, account_wht_adjusted_at = NOW(),
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$canonicalScope}
               AND account_request_type = 'compass_fund_request'
               AND approval_status = 'Approved' AND handoff_status = 'In Account'
               AND payment_status = 'Pending' AND deleted_at IS NULL"
        );
        $sync->bind_param(
            'ssssssiii',
            $expectedAmount,
            $status,
            $rate,
            $whtAmount,
            $expectedAmount,
            $reason,
            $actorId,
            $actorId,
            $purchaseId
        );
        $sync->execute();
        if ($sync->affected_rows !== 1) {
            $sync->close();
            throw new RuntimeException('ProcureDesk could not be synchronized with the Compass advance WHT adjustment.', 409);
        }
        $sync->close();
        procurementLocalAdvanceRefreshAccountReconciliationAfterPendingWhtAdjustment(
            $conn,
            $purchaseId,
            $expectedAmount,
            $actorId
        );
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE, $purchaseId);
    } else {
        throw new RuntimeException('Unsupported ProcureDesk Compass request type.', 409);
    }

    return [
        'request_type' => $requestType,
        'request_id' => $requestId,
        'procurement_purchase_id' => $purchaseId,
        'po_number' => (string) ($request['po_number'] ?? ''),
        'previous_wht_status' => accountWhtStatus($oldBasisPoints),
        'previous_wht_amount' => number_format((float) ($request['wht'] ?? 0), 2, '.', ''),
        'previous_expected_amount' => number_format((float) ($request['amount'] ?? 0), 2, '.', ''),
        'new_wht_status' => $status,
        'new_wht_rate' => $rate,
        'new_wht_amount' => $whtAmount,
        'new_expected_amount' => $expectedAmount,
        'reason' => $reason,
        'adjusted_by' => $actorId,
        'adjusted_at' => accountWhtCurrentTimestamp($conn),
    ];
}

function accountCompassReversePaidStatus(
    mysqli $conn,
    array $requestIds,
    string $targetStatus,
    string $reason,
    bool $confirmedNotPaid,
    int $processingBusinessDays,
    array $actor
): array {
    procurementCompassAssertStorageReady($conn);
    $ids = accountCompassBatchIds($requestIds);
    $targetStatus = trim($targetStatus);
    $allowed = ['Pending', 'Processing', 'Unconfirmed', 'Failed', 'Cancelled'];
    if (!in_array($targetStatus, $allowed, true)) {
        throw new RuntimeException('Corrected payment status is invalid.', 400);
    }
    $reason = trim($reason);
    if (strlen($reason) < 5 || strlen($reason) > 2000) {
        throw new RuntimeException('Enter a correction reason between 5 and 2,000 characters.', 400);
    }
    if (!$confirmedNotPaid) {
        throw new RuntimeException('Confirm that the payment was not completed before reversing Paid status.', 400);
    }
    if ($targetStatus === 'Processing' && ($processingBusinessDays < 1 || $processingBusinessDays > 5)) {
        throw new RuntimeException('Processing corrections require a period between 1 and 5 business days.', 400);
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $actorEmail = trim((string) ($actor['email'] ?? 'account-user'));
    if ($actorId <= 0) {
        throw new RuntimeException('A valid Account user is required.', 401);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT * FROM compass_fund_request_table
         WHERE id IN ({$placeholders}) FOR UPDATE"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int) $row['id']] = $row;
    }
    $missing = array_values(array_filter($ids, static fn(int $id): bool => !isset($rowsById[$id])));
    if ($missing !== []) {
        throw new RuntimeException('Some selected Compass Fund Requests no longer exist.', 404);
    }

    $dueAt = $targetStatus === 'Processing'
        ? accountSupplierBusinessDueAt($processingBusinessDays)
        : null;
    $successful = [];

    foreach ($ids as $id) {
        $row = $rowsById[$id];
        if (strcasecmp((string) ($row['payment_status'] ?? ''), 'Paid') !== 0) {
            throw new RuntimeException("Compass Fund Request {$id} is not currently Paid.", 409);
        }
        accountCompassAssertNoPaymentBatch($row, 'corrected from Paid');
        accountCompassLinkedType($conn, $id);

        if ($targetStatus === 'Processing') {
            $update = $conn->prepare(
                "UPDATE compass_fund_request_table
                 SET payment_status = 'Processing', amount_paid = 0.00, paid_at = NULL,
                     payment_reference = NULL, processing_method = 'Manual Correction',
                     processing_reference = NULL, processing_started_at = NOW(),
                     processing_business_days = ?, expected_completion_at = ?, completion_mode = 'Notify',
                     payment_confirmation_status = 'Scheduled', account_remarks = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Paid'"
            );
            $remarks = 'Paid status corrected: ' . $reason;
            $update->bind_param('issii', $processingBusinessDays, $dueAt, $remarks, $actorId, $id);
        } elseif ($targetStatus === 'Unconfirmed') {
            $update = $conn->prepare(
                "UPDATE compass_fund_request_table
                 SET payment_status = 'Unconfirmed', amount_paid = 0.00, paid_at = NULL,
                     payment_reference = NULL, processing_method = COALESCE(processing_method, 'Manual Correction'),
                     processing_started_at = COALESCE(processing_started_at, NOW()),
                     payment_confirmation_status = 'Awaiting Confirmation', account_remarks = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Paid'"
            );
            $remarks = 'Paid status corrected: ' . $reason;
            $update->bind_param('sii', $remarks, $actorId, $id);
        } else {
            $update = $conn->prepare(
                "UPDATE compass_fund_request_table
                 SET payment_status = ?, amount_paid = 0.00, paid_at = NULL,
                     payment_reference = NULL, processing_method = NULL,
                     processing_reference = NULL, processing_started_at = NULL,
                     processing_business_days = NULL, expected_completion_at = NULL,
                     completion_mode = NULL, payment_confirmation_status = ?, account_remarks = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Paid'"
            );
            $confirmation = $targetStatus === 'Pending' ? 'Not Scheduled' : $targetStatus;
            $remarks = 'Paid status corrected: ' . $reason;
            $update->bind_param('sssii', $targetStatus, $confirmation, $remarks, $actorId, $id);
        }

        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException("Compass Fund Request {$id} changed before its Paid status could be corrected.", 409);
        }
        $update->close();
        $successful[] = $id;
    }

    procurementSyncLocalFinalCompassPaymentDetails(
        $conn,
        $successful,
        $actorId,
        'account_compass_paid_status_corrected'
    );
    procurementSyncLocalAdvanceCompassPaymentDetails(
        $conn,
        $successful,
        $actorId,
        'account_compass_advance_paid_status_corrected',
        $actorEmail,
        ['reason' => $reason, 'target_status' => $targetStatus]
    );

    return [
        'request_ids' => $successful,
        'target_status' => $targetStatus,
        'reason' => $reason,
        'processing_business_days' => $targetStatus === 'Processing' ? $processingBusinessDays : null,
        'expected_completion_at' => $dueAt,
    ];
}

/**
 * Canonical Compass payment-processing support.
 *
 * Compass uses its own request_type namespace in the shared canonical payment
 * tables, so Compass request IDs can safely overlap Supplier/Advance IDs.
 */
function accountCompassPaymentActor(array $actor): array
{
    $id = (int) ($actor['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('A valid Account user is required.', 401);
    }
    return [
        'id' => $id,
        'email' => trim((string) ($actor['email'] ?? 'account-user')) ?: 'account-user',
    ];
}

function accountCompassPaymentMethod(mixed $value): string
{
    $method = trim((string) $value);
    $allowed = ['Bank Instruction', 'GAPS', 'Union Bank Schedule', 'Manual'];
    if (!in_array($method, $allowed, true)) {
        throw new RuntimeException('Compass processing method is invalid.', 400);
    }
    return $method;
}

function accountCompassPaymentCompletionMode(mixed $value): string
{
    $mode = trim((string) $value);
    if (!in_array($mode, ['Manual', 'Notify', 'Automatic', 'Immediate'], true)) {
        throw new RuntimeException('Compass completion mode must be Manual, Notify, Automatic or Immediate.', 400);
    }
    return $mode;
}

function accountCompassPaymentReference(mixed $value): string
{
    $reference = trim((string) $value);
    if ($reference === '' || strlen($reference) > 160) {
        throw new RuntimeException('Enter a Compass processing reference of up to 160 characters.', 400);
    }
    return $reference;
}

function accountCompassGeneratePaymentBatchReference(): string
{
    return 'CMP-PROC-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function accountCompassGetPaymentBatch(mysqli $conn, int $legacyBatchId): array
{
    $stmt = $conn->prepare(
        "SELECT id AS canonical_id, legacy_source_id AS id, request_type, batch_reference,
                processing_method, processing_reference, processing_business_days,
                expected_completion_at, completion_mode, status, item_count, total_amount,
                account_remarks, created_by, created_at, updated_by, updated_at, completed_at
         FROM account_payment_batches
         WHERE request_type = ? AND legacy_source_id = ? LIMIT 1"
    );
    $type = ACCOUNT_PAYMENT_TYPE_COMPASS;
    $stmt->bind_param('si', $type, $legacyBatchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Compass payment batch was not found.', 404);
    }
    return $row;
}

function accountCompassCreatePaymentBatch(mysqli $conn, array $data, array $actor): array
{
    procurementCompassAssertStorageReady($conn);
    accountPaymentStorageCanonicalReadsEnabled($conn, ACCOUNT_PAYMENT_TYPE_COMPASS);

    $requestIds = accountCompassBatchIds($data['request_ids'] ?? null);
    $method = accountCompassPaymentMethod($data['processing_method'] ?? '');
    $reference = accountCompassPaymentReference($data['processing_reference'] ?? '');
    $completionMode = accountCompassPaymentCompletionMode($data['completion_mode'] ?? 'Manual');
    $businessDays = (int) ($data['processing_business_days'] ?? 0);
    if ($completionMode === 'Immediate') {
        $businessDays = 0;
        $dueAt = date('Y-m-d H:i:s');
    } else {
        if ($businessDays < 1 || $businessDays > 5) {
            throw new RuntimeException('Compass processing period must be between 1 and 5 business days.', 400);
        }
        $dueAt = accountSupplierBusinessDueAt($businessDays);
    }
    $remarks = trim((string) ($data['account_remarks'] ?? ''));
    if (strlen($remarks) > 2000) {
        throw new RuntimeException('Compass account remarks must not exceed 2,000 characters.', 400);
    }
    $remarks = $remarks !== '' ? $remarks : null;
    $actor = accountCompassPaymentActor($actor);
    $batchReference = accountCompassGeneratePaymentBatchReference();
    $isImmediate = $completionMode === 'Immediate';

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT cfr.*, r.id AS linked_canonical_id, r.approval_status AS procurement_approval_status,
                    r.handoff_status AS procurement_handoff_status
             FROM compass_fund_request_table cfr
             LEFT JOIN procurement_requests r
               ON r.account_request_type = 'compass_fund_request'
              AND r.account_request_id = cfr.id
              AND r.deleted_at IS NULL
             WHERE cfr.id IN ({$placeholders}) FOR UPDATE"
        );
        $stmt->bind_param($types, ...$requestIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($requestIds as $requestId) {
            $row = $byId[$requestId] ?? null;
            if (!$row) {
                throw new RuntimeException("Compass Fund Request {$requestId} no longer exists.", 404);
            }
            if (strcasecmp((string) ($row['payment_status'] ?? ''), 'Pending') !== 0) {
                throw new RuntimeException("Compass Fund Request {$requestId} is no longer Pending.", 409);
            }
            if ((int) ($row['payment_batch_id'] ?? 0) > 0) {
                throw new RuntimeException("Compass Fund Request {$requestId} already belongs to a payment operation.", 409);
            }
            if ((int) ($row['procurement_purchase_id'] ?? 0) > 0
                && ((int) ($row['linked_canonical_id'] ?? 0) <= 0
                    || (string) ($row['procurement_approval_status'] ?? '') !== 'Approved'
                    || (string) ($row['procurement_handoff_status'] ?? '') !== 'In Account')) {
                throw new RuntimeException("Compass Fund Request {$requestId} is no longer an active Procurement handoff.", 409);
            }
        }

        $total = 0.0;
        foreach ($requestIds as $requestId) {
            $total += round((float) ($byId[$requestId]['amount'] ?? 0), 2);
        }

        $legacyBatchId = accountPaymentStorageCreateBatch($conn, ACCOUNT_PAYMENT_TYPE_COMPASS, [
            'batch_reference' => $batchReference,
            'processing_method' => $method,
            'processing_reference' => $reference,
            'processing_business_days' => $businessDays,
            'expected_completion_at' => $dueAt,
            'completion_mode' => $completionMode,
            'status' => $isImmediate ? 'Completed' : 'Processing',
            'item_count' => count($requestIds),
            'total_amount' => round($total, 2),
            'account_remarks' => $remarks,
            'created_by' => $actor['id'],
            'updated_by' => $actor['id'],
            'completed_at' => $isImmediate ? date('Y-m-d H:i:s') : null,
        ]);

        foreach ($requestIds as $requestId) {
            $amount = round((float) ($byId[$requestId]['amount'] ?? 0), 2);
            accountPaymentStorageCreateItem($conn, ACCOUNT_PAYMENT_TYPE_COMPASS, $legacyBatchId, [
                'request_id' => $requestId,
                'amount' => $amount,
                'amount_paid' => $isImmediate ? $amount : 0.0,
                'status' => $isImmediate ? 'Paid' : 'Processing',
                'status_reason' => null,
                'processing_started_at' => date('Y-m-d H:i:s'),
                'expected_completion_at' => $dueAt,
                'paid_at' => $isImmediate ? date('Y-m-d H:i:s') : null,
                'payment_reference' => $isImmediate ? $reference : null,
                'updated_by' => $actor['id'],
            ]);

            if ($isImmediate) {
                $update = $conn->prepare(
                    "UPDATE compass_fund_request_table
                     SET payment_status = 'Paid', processing_method = ?, processing_reference = ?,
                         processing_started_at = NOW(), processing_business_days = ?, expected_completion_at = ?,
                         completion_mode = ?, payment_confirmation_status = 'Confirmed', amount_paid = amount,
                         paid_at = NOW(), payment_reference = ?, account_remarks = ?, payment_batch_id = ?,
                         payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Pending' AND payment_batch_id IS NULL"
                );
                $update->bind_param('ssissssiii', $method, $reference, $businessDays, $dueAt, $completionMode, $reference, $remarks, $legacyBatchId, $actor['id'], $requestId);
            } else {
                $update = $conn->prepare(
                    "UPDATE compass_fund_request_table
                     SET payment_status = 'Processing', processing_method = ?, processing_reference = ?,
                         processing_started_at = NOW(), processing_business_days = ?, expected_completion_at = ?,
                         completion_mode = ?, payment_confirmation_status = 'Scheduled', amount_paid = 0.00,
                         paid_at = NULL, payment_reference = NULL, account_remarks = ?, payment_batch_id = ?,
                         payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Pending' AND payment_batch_id IS NULL"
                );
                $update->bind_param('ssisssiii', $method, $reference, $businessDays, $dueAt, $completionMode, $remarks, $legacyBatchId, $actor['id'], $requestId);
            }
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException("Compass Fund Request {$requestId} changed while processing was being created.", 409);
            }
            $update->close();
        }

        procurementSyncLocalFinalCompassPaymentDetails(
            $conn,
            $requestIds,
            $actor['id'],
            $isImmediate ? 'account_compass_payment_completed_immediately' : 'account_compass_processing_started'
        );
        procurementSyncLocalAdvanceCompassPaymentDetails(
            $conn,
            $requestIds,
            $actor['id'],
            $isImmediate ? 'account_compass_advance_payment_completed_immediately' : 'account_compass_advance_processing_started',
            $actor['email'],
            ['batch_id' => $legacyBatchId, 'processing_reference' => $reference]
        );

        $conn->commit();
        return accountCompassGetPaymentBatch($conn, $legacyBatchId);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function accountCompassRefreshCanonicalBatchStatus(mysqli $conn, int $canonicalBatchId, int $actorId = 0): void
{
    if ($canonicalBatchId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT
            SUM(status IN ('Processing','Delayed')) AS processing_count,
            SUM(status = 'Awaiting Confirmation') AS awaiting_count,
            SUM(status = 'Paid') AS paid_count,
            SUM(status IN ('Failed','Cancelled')) AS exception_count,
            COUNT(*) AS total_count
         FROM account_payment_batch_items
         WHERE batch_id = ? AND request_type = ?"
    );
    $type = ACCOUNT_PAYMENT_TYPE_COMPASS;
    $stmt->bind_param('is', $canonicalBatchId, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $total = (int) ($row['total_count'] ?? 0);
    $processing = (int) ($row['processing_count'] ?? 0);
    $awaiting = (int) ($row['awaiting_count'] ?? 0);
    $paid = (int) ($row['paid_count'] ?? 0);
    $exceptions = (int) ($row['exception_count'] ?? 0);

    if ($total <= 0 || $processing > 0) {
        $status = 'Processing';
    } elseif ($awaiting > 0) {
        $status = 'Awaiting Confirmation';
    } elseif ($paid > 0 && $exceptions > 0) {
        $status = 'Completed With Exceptions';
    } elseif ($paid > 0) {
        $status = 'Completed';
    } elseif ($exceptions > 0) {
        $status = 'Completed With Exceptions';
    } else {
        $status = 'Processing';
    }

    $completedAt = str_starts_with($status, 'Completed') ? date('Y-m-d H:i:s') : null;
    $update = $conn->prepare(
        'UPDATE account_payment_batches
         SET status = ?, updated_by = ?, completed_at = ?
         WHERE id = ? AND request_type = ?'
    );
    $update->bind_param('sisis', $status, $actorId, $completedAt, $canonicalBatchId, $type);
    $update->execute();
    $update->close();
}

function accountCompassNotifyPaymentLifecycle(
    mysqli $conn,
    string $type,
    string $title,
    string $message,
    int $legacyBatchId,
    array $payload = [],
    string $severity = 'info',
    ?string $dedupeKey = null
): array {
    $route = '/payments/processing?request_type=' . rawurlencode(ACCOUNT_PAYMENT_TYPE_COMPASS);
    if ($legacyBatchId > 0) {
        $route .= '&legacy_batch_id=' . $legacyBatchId;
    }
    return accountNotificationPublishDetailed($conn, [
        'type' => $type,
        'action_key' => $type,
        'category' => 'compass_payment_processing',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => ['id' => 0, 'email' => 'system'],
        'entity_type' => 'compass_payment_batch',
        'entity_id' => $legacyBatchId > 0 ? (string) $legacyBatchId : null,
        'route' => $route,
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
        'payload' => array_merge($payload, [
            'request_type' => ACCOUNT_PAYMENT_TYPE_COMPASS,
            'batch_id' => $legacyBatchId,
            'route' => $route,
        ]),
        'dedupe_key' => $dedupeKey,
    ]);
}

/**
 * Complete or notify due Compass payment batches using the same scheduler
 * lifecycle as Supplier and Advance, while keeping Compass isolated by its
 * canonical request_type namespace.
 */
function accountCompassProcessDueBatches(mysqli $conn): array
{
    procurementCompassAssertStorageReady($conn);
    accountPaymentReminderEnsureStorage($conn);

    $now = date('Y-m-d H:i:s');
    $type = ACCOUNT_PAYMENT_TYPE_COMPASS;
    $stmt = $conn->prepare(
        "SELECT id AS canonical_batch_id, legacy_source_id AS batch_id, batch_reference,
                processing_method, processing_reference, completion_mode, expected_completion_at
         FROM account_payment_batches
         WHERE request_type = ?
           AND status = 'Processing'
           AND completion_mode IN ('Notify','Automatic')
           AND expected_completion_at IS NOT NULL
           AND expected_completion_at <= ?
         ORDER BY legacy_source_id ASC"
    );
    $stmt->bind_param('ss', $type, $now);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $result = ['review_batches' => [], 'automatic_batches' => [], 'skipped' => []];
    $systemActor = ['id' => 0, 'email' => 'system'];

    foreach ($batches as $batch) {
        $canonicalBatchId = (int) ($batch['canonical_batch_id'] ?? 0);
        $legacyBatchId = (int) ($batch['batch_id'] ?? 0);
        if ($canonicalBatchId <= 0 || $legacyBatchId <= 0) {
            continue;
        }

        $conn->begin_transaction();
        try {
            $batchLock = $conn->prepare(
                "SELECT id, legacy_source_id, batch_reference, processing_method, processing_reference,
                        completion_mode, status, expected_completion_at
                 FROM account_payment_batches
                 WHERE id = ? AND request_type = ? FOR UPDATE"
            );
            $batchLock->bind_param('is', $canonicalBatchId, $type);
            $batchLock->execute();
            $fresh = $batchLock->get_result()->fetch_assoc() ?: null;
            $batchLock->close();
            if ($fresh === null || (string) ($fresh['status'] ?? '') !== 'Processing') {
                $conn->rollback();
                continue;
            }

            $itemsStmt = $conn->prepare(
                "SELECT i.id AS canonical_item_id, i.legacy_source_id AS batch_item_id,
                        i.request_id, i.status AS item_status, i.amount,
                        i.expected_completion_at,
                        cfr.payment_status, cfr.amount AS request_amount,
                        cfr.procurement_purchase_id, cfr.po_number, cfr.suppliers_name,
                        r.id AS linked_canonical_id,
                        r.request_type AS procurement_request_type,
                        r.approval_status AS procurement_approval_status,
                        r.handoff_status AS procurement_handoff_status
                 FROM account_payment_batch_items i
                 INNER JOIN compass_fund_request_table cfr ON cfr.id = i.request_id
                 LEFT JOIN procurement_requests r
                   ON r.account_request_type = 'compass_fund_request'
                  AND r.account_request_id = cfr.id
                  AND r.deleted_at IS NULL
                 WHERE i.batch_id = ? AND i.request_type = ?
                 ORDER BY i.id ASC
                 FOR UPDATE"
            );
            $itemsStmt->bind_param('is', $canonicalBatchId, $type);
            $itemsStmt->execute();
            $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();

            $dueItems = array_values(array_filter($items, static function (array $item) use ($now): bool {
                return in_array((string) ($item['item_status'] ?? ''), ['Processing', 'Delayed'], true)
                    && trim((string) ($item['expected_completion_at'] ?? '')) !== ''
                    && (string) $item['expected_completion_at'] <= $now;
            }));
            if ($dueItems === []) {
                $conn->commit();
                continue;
            }

            $completionMode = (string) ($fresh['completion_mode'] ?? '');
            $successfulRequestIds = [];
            $exceptionRequestIds = [];
            $reminderIds = [];

            foreach ($dueItems as $item) {
                $requestId = (int) ($item['request_id'] ?? 0);
                $canonicalItemId = (int) ($item['canonical_item_id'] ?? 0);
                $legacyItemId = (int) ($item['batch_item_id'] ?? 0);
                $ineligibleReason = null;
                if (strcasecmp((string) ($item['payment_status'] ?? ''), 'Processing') !== 0) {
                    $ineligibleReason = $completionMode === 'Automatic'
                        ? 'Automatic completion skipped because the Compass request is no longer Processing.'
                        : 'Confirmation was not scheduled because the Compass request is no longer Processing.';
                } elseif ((int) ($item['procurement_purchase_id'] ?? 0) > 0
                    && ((int) ($item['linked_canonical_id'] ?? 0) <= 0
                        || (string) ($item['procurement_approval_status'] ?? '') !== 'Approved'
                        || (string) ($item['procurement_handoff_status'] ?? '') !== 'In Account')) {
                    $ineligibleReason = $completionMode === 'Automatic'
                        ? 'Automatic completion skipped because the Procurement handoff is no longer eligible.'
                        : 'Confirmation was not scheduled because the Procurement handoff is no longer eligible.';
                }

                if ($ineligibleReason !== null) {
                    $failItem = $conn->prepare(
                        "UPDATE account_payment_batch_items
                         SET status = 'Failed', status_reason = ?, updated_by = 0
                         WHERE id = ? AND request_type = ? AND status IN ('Processing','Delayed')"
                    );
                    $failItem->bind_param('sis', $ineligibleReason, $canonicalItemId, $type);
                    $failItem->execute();
                    $failItem->close();
                    accountPaymentReminderRecordSourceEvent(
                        $conn,
                        'Compass',
                        $requestId,
                        $legacyBatchId,
                        $completionMode === 'Automatic' ? 'payment_auto_completion_skipped' : 'payment_confirmation_skipped',
                        $systemActor,
                        ['reason' => $ineligibleReason]
                    );
                    $exceptionRequestIds[] = $requestId;
                    continue;
                }

                if ($completionMode === 'Automatic') {
                    $reference = trim((string) ($fresh['processing_reference'] ?? ''));
                    if ($reference === '') {
                        $reference = 'COMPASS-AUTO-' . $requestId;
                    }
                    $requestUpdate = $conn->prepare(
                        "UPDATE compass_fund_request_table
                         SET payment_status = 'Paid', payment_confirmation_status = 'Auto Completed',
                             amount_paid = amount, paid_at = NOW(), payment_reference = ?,
                             payment_updated_by = 0, payment_updated_at = NOW()
                         WHERE id = ? AND payment_status = 'Processing'"
                    );
                    $requestUpdate->bind_param('si', $reference, $requestId);
                    $requestUpdate->execute();
                    $changed = $requestUpdate->affected_rows === 1;
                    $requestUpdate->close();
                    if (!$changed) {
                        $reason = 'Automatic completion skipped because the Compass request changed before completion.';
                        $failItem = $conn->prepare(
                            "UPDATE account_payment_batch_items
                             SET status = 'Failed', status_reason = ?, updated_by = 0
                             WHERE id = ? AND request_type = ? AND status IN ('Processing','Delayed')"
                        );
                        $failItem->bind_param('sis', $reason, $canonicalItemId, $type);
                        $failItem->execute();
                        $failItem->close();
                        $exceptionRequestIds[] = $requestId;
                        accountPaymentReminderRecordSourceEvent($conn, 'Compass', $requestId, $legacyBatchId, 'payment_auto_completion_skipped', $systemActor, ['reason' => $reason]);
                        continue;
                    }

                    $amount = round((float) ($item['request_amount'] ?? $item['amount'] ?? 0), 2);
                    $itemUpdate = $conn->prepare(
                        "UPDATE account_payment_batch_items
                         SET status = 'Paid', amount_paid = ?, paid_at = NOW(), payment_reference = ?,
                             status_reason = 'Automatically completed at the configured due time.', updated_by = 0
                         WHERE id = ? AND request_type = ? AND status IN ('Processing','Delayed')"
                    );
                    $itemUpdate->bind_param('dsis', $amount, $reference, $canonicalItemId, $type);
                    $itemUpdate->execute();
                    $itemUpdate->close();
                    $successfulRequestIds[] = $requestId;
                    accountPaymentReminderRecordSourceEvent($conn, 'Compass', $requestId, $legacyBatchId, 'payment_auto_completed', $systemActor, [
                        'amount_paid' => $amount,
                        'payment_reference' => $reference,
                    ]);
                    continue;
                }

                $requestUpdate = $conn->prepare(
                    "UPDATE compass_fund_request_table
                     SET payment_confirmation_status = 'Due', payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Processing'"
                );
                $requestUpdate->bind_param('i', $requestId);
                $requestUpdate->execute();
                $changed = $requestUpdate->affected_rows === 1;
                $requestUpdate->close();
                if (!$changed) {
                    $exceptionRequestIds[] = $requestId;
                    continue;
                }

                $itemUpdate = $conn->prepare(
                    "UPDATE account_payment_batch_items
                     SET status = 'Awaiting Confirmation', status_reason = 'Expected Compass completion time reached; Account confirmation is required.', updated_by = 0
                     WHERE id = ? AND request_type = ? AND status IN ('Processing','Delayed')"
                );
                $itemUpdate->bind_param('is', $canonicalItemId, $type);
                $itemUpdate->execute();
                $itemUpdate->close();
                $successfulRequestIds[] = $requestId;
                $reminderIds[] = accountPaymentReminderSchedule(
                    $conn,
                    'Compass',
                    $requestId,
                    $legacyBatchId,
                    $legacyItemId,
                    (string) ($item['expected_completion_at'] ?? $now),
                    [
                        'batch_reference' => $fresh['batch_reference'] ?? null,
                        'processing_method' => $fresh['processing_method'] ?? null,
                        'processing_reference' => $fresh['processing_reference'] ?? null,
                        'source' => 'compass_payment_batch_due',
                    ],
                    $systemActor
                );
                accountPaymentReminderRecordSourceEvent($conn, 'Compass', $requestId, $legacyBatchId, 'payment_confirmation_due', $systemActor, [
                    'expected_completion_at' => $item['expected_completion_at'] ?? null,
                ]);
            }

            $successfulRequestIds = array_values(array_unique($successfulRequestIds));
            if ($successfulRequestIds !== []) {
                $event = $completionMode === 'Automatic'
                    ? 'account_compass_payment_auto_completed'
                    : 'account_compass_payment_confirmation_due';
                procurementSyncLocalFinalCompassPaymentDetails($conn, $successfulRequestIds, 0, $event);
                procurementSyncLocalAdvanceCompassPaymentDetails(
                    $conn,
                    $successfulRequestIds,
                    0,
                    $completionMode === 'Automatic'
                        ? 'account_compass_advance_payment_auto_completed'
                        : 'account_compass_advance_payment_confirmation_due',
                    'system',
                    ['batch_id' => $legacyBatchId]
                );
            }

            accountCompassRefreshCanonicalBatchStatus($conn, $canonicalBatchId, 0);

            if ($completionMode === 'Automatic' && $successfulRequestIds !== []) {
                accountCompassNotifyPaymentLifecycle(
                    $conn,
                    'compass_payment_batch_auto_completed',
                    'Compass payments automatically completed',
                    sprintf(
                        'Compass payment operation %s automatically marked %d eligible request(s) as Paid.',
                        (string) ($fresh['batch_reference'] ?? ('#' . $legacyBatchId)),
                        count($successfulRequestIds)
                    ),
                    $legacyBatchId,
                    ['request_ids' => $successfulRequestIds, 'completion_mode' => 'Automatic'],
                    'success',
                    'compass-payment-auto-completed-' . $legacyBatchId
                );
                $result['automatic_batches'][] = $legacyBatchId;
            } elseif ($completionMode === 'Notify' && $successfulRequestIds !== []) {
                $notification = accountCompassNotifyPaymentLifecycle(
                    $conn,
                    'compass_payment_batch_confirmation_due',
                    'Compass payments ready for confirmation',
                    sprintf(
                        'Compass payment operation %s reached its expected completion time. Confirm the %d eligible request(s) that should be marked Paid.',
                        (string) ($fresh['batch_reference'] ?? ('#' . $legacyBatchId)),
                        count($successfulRequestIds)
                    ),
                    $legacyBatchId,
                    [
                        'request_ids' => $successfulRequestIds,
                        'reminder_ids' => array_values(array_unique($reminderIds)),
                        'completion_mode' => 'Notify',
                    ],
                    'warning',
                    'compass-payment-confirmation-due-' . $legacyBatchId
                );
                accountPaymentReminderInitialDelivery(
                    $conn,
                    $reminderIds,
                    (int) ($notification['resolved'] ?? 0),
                    $systemActor
                );
                $notificationStamp = $conn->prepare(
                    'UPDATE account_payment_batches SET notification_created_at = NOW(), updated_by = 0 WHERE id = ? AND request_type = ?'
                );
                $notificationStamp->bind_param('is', $canonicalBatchId, $type);
                $notificationStamp->execute();
                $notificationStamp->close();
                $result['review_batches'][] = $legacyBatchId;
            }

            if ($exceptionRequestIds !== []) {
                accountCompassNotifyPaymentLifecycle(
                    $conn,
                    'compass_payment_batch_completion_exception',
                    'Compass payment completion needs attention',
                    sprintf(
                        'Compass payment operation %s skipped %d request(s) because they were no longer eligible.',
                        (string) ($fresh['batch_reference'] ?? ('#' . $legacyBatchId)),
                        count(array_unique($exceptionRequestIds))
                    ),
                    $legacyBatchId,
                    ['request_ids' => array_values(array_unique($exceptionRequestIds)), 'completion_mode' => $completionMode],
                    'warning',
                    'compass-payment-completion-exception-' . $legacyBatchId . '-' . strtolower($completionMode)
                );
            }

            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            $result['skipped'][] = [
                'batch_id' => $legacyBatchId,
                'reason' => $error->getMessage(),
                'retryable' => true,
            ];
        }
    }

    return $result;
}

function accountCompassMarkPaymentItems(
    mysqli $conn,
    array $itemLegacyIds,
    string $action,
    array $data,
    array $actor
): array {
    procurementCompassAssertStorageReady($conn);
    accountPaymentStorageCanonicalReadsEnabled($conn, ACCOUNT_PAYMENT_TYPE_COMPASS);
    $itemLegacyIds = accountCompassBatchIds($itemLegacyIds);
    $actor = accountCompassPaymentActor($actor);
    if (!in_array($action, ['paid', 'failed', 'delayed', 'cancelled'], true)) {
        throw new RuntimeException('Compass payment action is invalid.', 400);
    }
    $reason = trim((string) ($data['reason'] ?? ''));
    if (in_array($action, ['failed', 'delayed', 'cancelled'], true) && $reason === '') {
        throw new RuntimeException('A reason is required for this Compass payment action.', 400);
    }
    $paymentReference = trim((string) ($data['payment_reference'] ?? ''));
    $additionalDays = (int) ($data['additional_business_days'] ?? 0);
    if ($action === 'delayed' && ($additionalDays < 1 || $additionalDays > 10)) {
        throw new RuntimeException('Delayed Compass payments require 1 to 10 additional business days.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($itemLegacyIds), '?'));
    $types = str_repeat('i', count($itemLegacyIds));
    $type = ACCOUNT_PAYMENT_TYPE_COMPASS;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT i.id AS canonical_item_id, i.legacy_source_id AS item_id, i.request_id,
                    i.status, i.amount, i.batch_id AS canonical_batch_id,
                    b.legacy_source_id AS batch_id, b.processing_reference,
                    cfr.payment_status, cfr.procurement_purchase_id,
                    r.id AS linked_canonical_id, r.approval_status AS procurement_approval_status,
                    r.handoff_status AS procurement_handoff_status
             FROM account_payment_batch_items i
             INNER JOIN account_payment_batches b ON b.id = i.batch_id AND b.request_type = i.request_type
             INNER JOIN compass_fund_request_table cfr ON cfr.id = i.request_id
             LEFT JOIN procurement_requests r
               ON r.account_request_type = 'compass_fund_request'
              AND r.account_request_id = cfr.id
              AND r.deleted_at IS NULL
             WHERE i.request_type = ? AND i.legacy_source_id IN ({$placeholders}) FOR UPDATE"
        );
        $params = array_merge([$type], $itemLegacyIds);
        $bindTypes = 's' . $types;
        $stmt->bind_param($bindTypes, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['item_id']] = $row;
        $successful = [];
        $failed = [];
        $requestIds = [];
        $canonicalBatchIds = [];

        foreach ($itemLegacyIds as $itemId) {
            $row = $byId[$itemId] ?? null;
            if (!$row) {
                $failed[] = ['id' => $itemId, 'reason' => 'Compass processing item not found.'];
                continue;
            }
            if (!in_array((string) $row['status'], ['Processing', 'Awaiting Confirmation', 'Delayed'], true)) {
                $failed[] = ['id' => $itemId, 'reason' => 'This Compass payment is no longer eligible for review.'];
                continue;
            }
            if (strcasecmp((string) $row['payment_status'], 'Processing') !== 0) {
                $failed[] = ['id' => $itemId, 'reason' => 'The Compass Fund Request is no longer Processing.'];
                continue;
            }
            if ((int) ($row['procurement_purchase_id'] ?? 0) > 0
                && ((int) ($row['linked_canonical_id'] ?? 0) <= 0
                    || (string) ($row['procurement_approval_status'] ?? '') !== 'Approved'
                    || (string) ($row['procurement_handoff_status'] ?? '') !== 'In Account')) {
                $failed[] = ['id' => $itemId, 'reason' => 'The linked Procurement handoff is no longer eligible for payment confirmation.'];
                continue;
            }

            $requestId = (int) $row['request_id'];
            $canonicalBatchIds[(int) $row['canonical_batch_id']] = true;
            $requestIds[] = $requestId;

            if ($action === 'paid') {
                $reference = $paymentReference !== '' ? $paymentReference : trim((string) ($row['processing_reference'] ?? ''));
                if ($reference === '') $reference = 'COMPASS-PAID-' . $requestId;
                $itemUpdate = $conn->prepare(
                    "UPDATE account_payment_batch_items
                     SET status = 'Paid', amount_paid = amount, paid_at = NOW(), payment_reference = ?,
                         status_reason = NULL, updated_by = ?
                     WHERE id = ? AND request_type = ? AND status IN ('Processing','Awaiting Confirmation','Delayed')"
                );
                $canonicalItemId = (int) $row['canonical_item_id'];
                $itemUpdate->bind_param('siis', $reference, $actor['id'], $canonicalItemId, $type);
                $itemUpdate->execute();
                $itemUpdate->close();

                $requestUpdate = $conn->prepare(
                    "UPDATE compass_fund_request_table
                     SET payment_status = 'Paid', payment_confirmation_status = 'Confirmed', amount_paid = amount,
                         paid_at = NOW(), payment_reference = ?, payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Processing'"
                );
                $requestUpdate->bind_param('sii', $reference, $actor['id'], $requestId);
                $requestUpdate->execute();
                $requestUpdate->close();
            } elseif ($action === 'delayed') {
                $newDue = accountSupplierBusinessDueAt($additionalDays);
                $itemUpdate = $conn->prepare(
                    "UPDATE account_payment_batch_items
                     SET status = 'Delayed', status_reason = ?, expected_completion_at = ?, updated_by = ?
                     WHERE id = ? AND request_type = ?"
                );
                $canonicalItemId = (int) $row['canonical_item_id'];
                $itemUpdate->bind_param('ssiis', $reason, $newDue, $actor['id'], $canonicalItemId, $type);
                $itemUpdate->execute();
                $itemUpdate->close();
                $requestUpdate = $conn->prepare(
                    "UPDATE compass_fund_request_table
                     SET payment_confirmation_status = 'Delayed', expected_completion_at = ?, account_remarks = ?,
                         payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Processing'"
                );
                $requestUpdate->bind_param('ssii', $newDue, $reason, $actor['id'], $requestId);
                $requestUpdate->execute();
                $requestUpdate->close();
            } else {
                $newStatus = $action === 'failed' ? 'Failed' : 'Cancelled';
                $itemStatus = ucfirst($action);
                $itemUpdate = $conn->prepare(
                    "UPDATE account_payment_batch_items
                     SET status = ?, status_reason = ?, amount_paid = 0.00, paid_at = NULL,
                         payment_reference = NULL, updated_by = ?
                     WHERE id = ? AND request_type = ?"
                );
                $canonicalItemId = (int) $row['canonical_item_id'];
                $itemUpdate->bind_param('ssiis', $itemStatus, $reason, $actor['id'], $canonicalItemId, $type);
                $itemUpdate->execute();
                $itemUpdate->close();
                $requestUpdate = $conn->prepare(
                    "UPDATE compass_fund_request_table
                     SET payment_status = ?, payment_confirmation_status = ?, amount_paid = 0.00,
                         paid_at = NULL, payment_reference = NULL, account_remarks = ?,
                         payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Processing'"
                );
                $requestUpdate->bind_param('sssii', $newStatus, $itemStatus, $reason, $actor['id'], $requestId);
                $requestUpdate->execute();
                $requestUpdate->close();
            }
            $successful[] = $itemId;
        }

        if ($requestIds !== []) {
            $requestIds = array_values(array_unique($requestIds));
            procurementSyncLocalFinalCompassPaymentDetails($conn, $requestIds, $actor['id'], 'account_compass_processing_updated');
            procurementSyncLocalAdvanceCompassPaymentDetails(
                $conn,
                $requestIds,
                $actor['id'],
                'account_compass_advance_processing_updated',
                $actor['email'],
                ['action' => $action, 'reason' => $reason]
            );
        }
        foreach (array_keys($canonicalBatchIds) as $canonicalBatchId) {
            accountPaymentProcessingUpdateCanonicalBatchStatus($conn, (int) $canonicalBatchId, $actor['id']);
        }

        $conn->commit();
        return ['successful' => $successful, 'failed' => $failed];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
