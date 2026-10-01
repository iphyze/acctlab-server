<?php

declare(strict_types=1);

require_once __DIR__ . '/accountAdvancePaymentService.php';
require_once __DIR__ . '/accountSupplierPaymentService.php';
require_once __DIR__ . '/procurementLocalAdvancePurchaseService.php';
require_once __DIR__ . '/procurementLocalFinalPurchaseService.php';

const ACCOUNT_WHT_ALLOWED_BASIS_POINTS = [0, 200, 500];

function accountWhtNormalizeBasisPoints(mixed $value): int
{
    $raw = trim(str_replace([',', ' '], '', (string) $value));
    if ($raw === '') {
        throw new RuntimeException('WHT status is required.', 400);
    }

    $hasPercentSign = str_contains($raw, '%');
    $raw = str_replace('%', '', $raw);
    if (!preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
        throw new RuntimeException('WHT status must be 0.00%, 2.00%, or 5.00%.', 400);
    }

    $numeric = (float) $raw;
    $percent = (!$hasPercentSign && $numeric > 0 && $numeric <= 0.05)
        ? $numeric * 100
        : $numeric;
    $basisPoints = (int) round($percent * 100);

    if (!in_array($basisPoints, ACCOUNT_WHT_ALLOWED_BASIS_POINTS, true)) {
        throw new RuntimeException('WHT status must be 0.00%, 2.00%, or 5.00%.', 400);
    }

    return $basisPoints;
}

function accountWhtStatus(int $basisPoints): string
{
    return number_format($basisPoints / 100, 2, '.', '') . '%';
}

function accountWhtRate(int $basisPoints): string
{
    return number_format($basisPoints / 10000, 6, '.', '');
}

function accountWhtReason(mixed $value): string
{
    $reason = trim((string) $value);
    $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($length < 5) {
        throw new RuntimeException('A WHT adjustment reason of at least 5 characters is required.', 400);
    }
    if ($length > 1000) {
        throw new RuntimeException('WHT adjustment reason must not exceed 1000 characters.', 400);
    }
    return $reason;
}

function accountWhtLegacyVatPolicy(bool $hasVat, int $basisPoints): string
{
    if (!$hasVat) {
        return '0.00%';
    }
    return $basisPoints === 0 ? '7.50%' : accountWhtStatus($basisPoints);
}

function accountWhtCurrentTimestamp(mysqli $conn): string
{
    $row = $conn->query('SELECT NOW() AS adjusted_at')->fetch_assoc();
    return (string) ($row['adjusted_at'] ?? date('Y-m-d H:i:s'));
}

function accountWhtAssertVatCompatibility(bool $hasVat, int $basisPoints): void
{
    if (!$hasVat && $basisPoints > 0) {
        throw new RuntimeException('WHT cannot be applied because VAT is not charged on this request.', 409);
    }
}

function accountWhtAssertNoActiveSupplierOperation(mysqli $conn, int $requestId): void
{
    accountSupplierEnsurePaymentStorage($conn);
    $stmt = $conn->prepare(
        "SELECT legacy_source_id AS id
         FROM account_payment_batch_items
         WHERE request_type = 'local_final_purchase'
           AND request_id = ?
           AND status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
         LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($active) {
        throw new RuntimeException(
            "Supplier Fund Request $requestId belongs to an active payment operation and its WHT cannot be adjusted.",
            409
        );
    }
}

function accountAdjustAdvanceRequestWht(
    mysqli $conn,
    int $requestId,
    mixed $requestedWht,
    mixed $reasonValue,
    array $actor
): array {
    if ($requestId <= 0) {
        throw new RuntimeException('A valid Advance Fund Request ID is required.', 400);
    }

    procurementLocalAdvanceEnsureStorage($conn);
    accountAdvanceEnsurePaymentStorage($conn);
    $basisPoints = accountWhtNormalizeBasisPoints($requestedWht);
    $reason = accountWhtReason($reasonValue);
    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0) {
        throw new RuntimeException('A valid Account user is required.', 401);
    }

    $localAdvanceScope = procurementRequestCanonicalLocalAdvanceScopeSql('r');
    $stmt = $conn->prepare(
        "SELECT apr.*,
                r.legacy_source_id AS local_purchase_id, r.po_id,
                r.expected_payment AS procurement_expected_payment,
                r.payment_status AS procurement_payment_status,
                r.approval_status AS procurement_approval_status,
                r.handoff_status AS procurement_handoff_status,
                p.po_vat_status
         FROM advance_payment_request apr
         INNER JOIN procurement_requests r
           ON r.legacy_source_id = apr.procurement_purchase_id
              AND r.account_request_id = apr.id
              AND {$localAdvanceScope}
              AND r.deleted_at IS NULL
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE apr.id = ?
           AND apr.procurement_source = 'local_advance_purchase'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new RuntimeException(
            'Only an active ProcureDesk-linked Advance Fund Request can receive a request-level WHT adjustment.',
            404
        );
    }
    if ((string) $request['procurement_approval_status'] !== 'Approved'
        || (string) $request['procurement_handoff_status'] !== 'In Account') {
        throw new RuntimeException('The linked Advance Fund Request is no longer an active Account handoff.', 409);
    }
    if ((string) $request['payment_status'] !== 'Pending'
        || (string) $request['procurement_payment_status'] !== 'Pending') {
        throw new RuntimeException('WHT can only be adjusted while the Advance Fund Request is Pending.', 409);
    }

    accountAdvanceAssertNoActivePaymentOperation($conn, [$requestId], 'adjusted for WHT');

    $netCents = procurementLocalAdvanceMoneyToCents($request['net_amount'], 'Advance Fund Request Net Amount');
    $vatCents = procurementLocalAdvanceMoneyToCents($request['vat'], 'Advance Fund Request VAT Amount');
    $otherChargesCents = procurementLocalAdvanceMoneyToCents(
        $request['other_charges'],
        'Advance Fund Request Other Charges'
    );
    $hasVat = $vatCents > 0
        && strcasecmp((string) ($request['po_vat_status'] ?? 'No'), 'Yes') === 0;
    accountWhtAssertVatCompatibility($hasVat, $basisPoints);

    $oldBasisPoints = procurementLocalAdvanceWhtBasisPoints((string) ($request['wht_status'] ?? '0.00%'));
    if ($oldBasisPoints === $basisPoints) {
        throw new RuntimeException('The selected WHT status is already applied to this Advance Fund Request.', 409);
    }

    $whtCents = procurementLocalAdvanceRateAmount($netCents, $basisPoints);
    $amountPayableCents = $netCents + $vatCents - $whtCents;
    if ($amountPayableCents < 0) {
        throw new RuntimeException('The adjusted Advance Fund Request amount cannot be negative.', 409);
    }
    $percentageUnits = procurementLocalAdvancePercentUnits((string) $request['percentage']);
    $advancePaymentCents = procurementLocalAdvanceMultiplyDivideRounded(
        $amountPayableCents + $otherChargesCents,
        $percentageUnits,
        PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX
    );

    $status = accountWhtStatus($basisPoints);
    $rate = accountWhtRate($basisPoints);
    $whtAmount = procurementLocalAdvanceCents($whtCents);
    $amountPayable = procurementLocalAdvanceCents($amountPayableCents);
    $advancePayment = procurementLocalAdvanceCents($advancePaymentCents);
    $vatStatus = accountWhtLegacyVatPolicy($hasVat, $basisPoints);

    $updateRequest = $conn->prepare(
        "UPDATE advance_payment_request
         SET vat_status = ?, wht_status = ?, wht_rate = ?, wht = ?,
             amount_payable = ?, advance_payment = ?,
             wht_override_status = ?, wht_override_rate = ?, wht_override_amount = ?,
             wht_override_reason = ?, wht_override_by = ?, wht_override_at = NOW(),
             payment_updated_by = ?, payment_updated_at = NOW(), updated_at = NOW()
         WHERE id = ? AND payment_status = 'Pending'"
    );
    $updateRequest->bind_param(
        'ssssssssssiii',
        $vatStatus,
        $status,
        $rate,
        $whtAmount,
        $amountPayable,
        $advancePayment,
        $status,
        $rate,
        $whtAmount,
        $reason,
        $actorId,
        $actorId,
        $requestId
    );
    $updateRequest->execute();
    if ($updateRequest->affected_rows !== 1) {
        $updateRequest->close();
        throw new RuntimeException('The Advance Fund Request changed before its WHT could be adjusted.', 409);
    }
    $updateRequest->close();

    $purchaseId = (int) $request['local_purchase_id'];
    $canonicalScope = procurementRequestCanonicalLocalAdvanceScopeSql();
    $updatePurchase = $conn->prepare(
        "UPDATE procurement_requests
         SET expected_payment = ?, account_wht_override_status = ?,
             account_wht_override_rate = ?, account_wht_override_amount = ?,
             account_expected_payment = ?, account_wht_adjustment_reason = ?,
             account_wht_adjusted_by = ?, account_wht_adjusted_at = NOW(),
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND {$canonicalScope}
           AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND payment_status = 'Pending'
           AND deleted_at IS NULL"
    );
    $updatePurchase->bind_param(
        'ssssssiii',
        $advancePayment,
        $status,
        $rate,
        $whtAmount,
        $advancePayment,
        $reason,
        $actorId,
        $actorId,
        $purchaseId
    );
    $updatePurchase->execute();
    if ($updatePurchase->affected_rows !== 1) {
        $updatePurchase->close();
        throw new RuntimeException('ProcureDesk could not be synchronized with the WHT adjustment.', 409);
    }
    $updatePurchase->close();

    procurementLocalAdvanceRefreshAccountReconciliationAfterPendingWhtAdjustment(
        $conn,
        $purchaseId,
        $advancePayment,
        $actorId
    );
    procurementRequestCanonicalSyncRequest(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE,
        $purchaseId
    );

    return [
        'request_type' => 'advance',
        'request_id' => $requestId,
        'procurement_purchase_id' => $purchaseId,
        'po_number' => (string) $request['po_number'],
        'previous_wht_status' => accountWhtStatus($oldBasisPoints),
        'previous_wht_amount' => number_format((float) ($request['wht'] ?? 0), 2, '.', ''),
        'previous_expected_amount' => number_format((float) ($request['advance_payment'] ?? 0), 2, '.', ''),
        'new_wht_status' => $status,
        'new_wht_rate' => $rate,
        'new_wht_amount' => $whtAmount,
        'new_expected_amount' => $advancePayment,
        'reason' => $reason,
        'adjusted_by' => $actorId,
        'adjusted_at' => accountWhtCurrentTimestamp($conn),
    ];
}

function accountAdjustSupplierRequestWht(
    mysqli $conn,
    int $requestId,
    mixed $requestedWht,
    mixed $reasonValue,
    array $actor
): array {
    if ($requestId <= 0) {
        throw new RuntimeException('A valid Supplier Fund Request ID is required.', 400);
    }

    procurementEnsureLocalFinalPurchaseStorage($conn);
    accountSupplierEnsurePaymentStorage($conn);
    $basisPoints = accountWhtNormalizeBasisPoints($requestedWht);
    $reason = accountWhtReason($reasonValue);
    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0) {
        throw new RuntimeException('A valid Account user is required.', 401);
    }

    $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
    $stmt = $conn->prepare(
        "SELECT sfr.*,
                p.legacy_source_id AS local_purchase_id,
                p.payment_status AS procurement_payment_status,
                p.approval_status AS procurement_approval_status,
                p.handoff_status AS procurement_handoff_status,
                p.purchase_vat_status
         FROM supplier_fund_request_table sfr
         INNER JOIN procurement_requests p
           ON p.legacy_source_id = sfr.procurement_purchase_id
              AND p.account_request_id = sfr.id
              AND {$localFinalScope}
              AND p.deleted_at IS NULL
         WHERE sfr.id = ?
           AND sfr.procurement_source = 'local_final_purchase'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new RuntimeException(
            'Only an active ProcureDesk-linked Supplier Fund Request can receive a request-level WHT adjustment.',
            404
        );
    }
    if ((string) $request['procurement_approval_status'] !== 'Approved'
        || (string) $request['procurement_handoff_status'] !== 'In Account') {
        throw new RuntimeException('The linked Supplier Fund Request is no longer an active Account handoff.', 409);
    }
    if ((string) $request['payment_status'] !== 'Pending'
        || (string) $request['procurement_payment_status'] !== 'Pending') {
        throw new RuntimeException('WHT can only be adjusted while the Supplier Fund Request is Pending.', 409);
    }

    accountWhtAssertNoActiveSupplierOperation($conn, $requestId);

    $subtotalCents = procurementLocalFinalMoneyToCents(
        $request['net_value'],
        'Supplier Fund Request Subtotal',
        false
    );
    $discountCents = procurementLocalFinalMoneyToCents(
        $request['discount'],
        'Supplier Fund Request Discount'
    );
    if ($discountCents > $subtotalCents) {
        throw new RuntimeException('Supplier Fund Request Discount cannot exceed its subtotal.', 409);
    }
    $netCents = $subtotalCents - $discountCents;
    $vatCents = procurementLocalFinalMoneyToCents($request['vat'], 'Supplier Fund Request VAT Amount');
    $otherChargesCents = procurementLocalFinalMoneyToCents(
        $request['other_charges'],
        'Supplier Fund Request Other Charges'
    );
    $hasVat = $vatCents > 0
        && strcasecmp((string) ($request['purchase_vat_status'] ?? 'No'), 'Yes') === 0;
    accountWhtAssertVatCompatibility($hasVat, $basisPoints);

    $oldBasisPoints = procurementLocalFinalWhtBasisPoints((string) ($request['vat_policy'] ?? '0.00%'));
    if ($oldBasisPoints === $basisPoints) {
        throw new RuntimeException('The selected WHT status is already applied to this Supplier Fund Request.', 409);
    }

    $whtCents = procurementLocalFinalRateAmount($netCents, $basisPoints);
    $payableCents = $netCents + $vatCents + $otherChargesCents - $whtCents;
    if ($payableCents < 0) {
        throw new RuntimeException('The adjusted Supplier Fund Request amount cannot be negative.', 409);
    }

    $status = accountWhtStatus($basisPoints);
    $rate = accountWhtRate($basisPoints);
    $whtAmount = procurementLocalFinalCents($whtCents);
    $payableAmount = procurementLocalFinalCents($payableCents);
    $vatPolicy = accountWhtLegacyVatPolicy($hasVat, $basisPoints);

    $updateRequest = $conn->prepare(
        "UPDATE supplier_fund_request_table
         SET vat_policy = ?, wht = ?, amount = ?,
             wht_override_status = ?, wht_override_rate = ?, wht_override_amount = ?,
             wht_override_reason = ?, wht_override_by = ?, wht_override_at = NOW(),
             payment_updated_by = ?, payment_updated_at = NOW(), updated_at = NOW()
         WHERE id = ? AND payment_status = 'Pending'"
    );
    $updateRequest->bind_param(
        'sssssssiii',
        $vatPolicy,
        $whtAmount,
        $payableAmount,
        $status,
        $rate,
        $whtAmount,
        $reason,
        $actorId,
        $actorId,
        $requestId
    );
    $updateRequest->execute();
    if ($updateRequest->affected_rows !== 1) {
        $updateRequest->close();
        throw new RuntimeException('The Supplier Fund Request changed before its WHT could be adjusted.', 409);
    }
    $updateRequest->close();

    $purchaseId = (int) $request['local_purchase_id'];
    $canonicalScope = procurementRequestCanonicalLocalFinalScopeSql();
    $updatePurchase = $conn->prepare(
        "UPDATE procurement_requests
         SET wht_status = ?, wht_rate = ?, wht_amount = ?,
             account_wht_override_status = ?, account_wht_override_rate = ?,
             account_wht_override_amount = ?, account_payable_amount = ?,
             account_wht_adjustment_reason = ?, account_wht_adjusted_by = ?,
             account_wht_adjusted_at = NOW(), updated_by = ?, updated_at = NOW(),
             version = version + 1
         WHERE legacy_source_id = ?
           AND {$canonicalScope}
           AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND payment_status = 'Pending'
           AND deleted_at IS NULL"
    );
    $updatePurchase->bind_param(
        'ssssssssiii',
        $status,
        $rate,
        $whtAmount,
        $status,
        $rate,
        $whtAmount,
        $payableAmount,
        $reason,
        $actorId,
        $actorId,
        $purchaseId
    );
    $updatePurchase->execute();
    if ($updatePurchase->affected_rows !== 1) {
        $updatePurchase->close();
        throw new RuntimeException('ProcureDesk could not be synchronized with the WHT adjustment.', 409);
    }
    $updatePurchase->close();
    procurementRequestCanonicalSyncRequest(
        $conn,
        PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL,
        $purchaseId
    );

    return [
        'request_type' => 'supplier',
        'request_id' => $requestId,
        'procurement_purchase_id' => $purchaseId,
        'po_number' => (string) $request['po_number'],
        'previous_wht_status' => accountWhtStatus($oldBasisPoints),
        'previous_wht_amount' => number_format((float) ($request['wht'] ?? 0), 2, '.', ''),
        'previous_expected_amount' => number_format((float) ($request['amount'] ?? 0), 2, '.', ''),
        'new_wht_status' => $status,
        'new_wht_rate' => $rate,
        'new_wht_amount' => $whtAmount,
        'new_expected_amount' => $payableAmount,
        'reason' => $reason,
        'adjusted_by' => $actorId,
        'adjusted_at' => accountWhtCurrentTimestamp($conn),
    ];
}
