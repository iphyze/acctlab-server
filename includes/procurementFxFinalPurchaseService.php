<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementLocalFinalPurchaseService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';
require_once __DIR__ . '/procurementRequestCanonicalSyncService.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';

const PROCUREMENT_FX_FINAL_CURRENCIES = ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'ZAR'];
const PROCUREMENT_FX_FINAL_MAX_BATCH = 100;

function procurementEnsureFxFinalPurchaseStorage(mysqli $conn): void
{
    $active = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    procurementRequestCanonicalFxFinalAssertReady($active);
}

function procurementFxFinalNormalizeCurrency(mixed $value): string
{
    $currency = strtoupper(trim((string) $value));
    if (!in_array($currency, PROCUREMENT_FX_FINAL_CURRENCIES, true)) {
        throw new RuntimeException(
            'Currency must be one of: ' . implode(', ', PROCUREMENT_FX_FINAL_CURRENCIES) . '.',
            400
        );
    }
    return $currency;
}

function procurementFxFinalBuildPayload(mysqli $conn, array $data): array
{
    // Foreign Final Purchase intentionally follows the proven Local Final
    // commercial/tax validation. Currency is the only additional commercial
    // dimension at this stage; handoff behavior is introduced in Batch 2.
    $payload = procurementLocalFinalBuildPayload($conn, $data);
    $payload['currency'] = procurementFxFinalNormalizeCurrency($data['currency'] ?? '');
    $payload['contact_person'] = procurementLocalFinalOptionalText($data, 'contact_person', 255);
    $payload['phone_number'] = procurementLocalFinalOptionalText($data, 'phone_number', 80);
    return $payload;
}

function procurementFxFinalNextPublicId(mysqli $conn): int
{
    $stmt = $conn->prepare(
        "SELECT legacy_source_id
         FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ?
         ORDER BY legacy_source_id DESC
         LIMIT 1 FOR UPDATE"
    );
    $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE;
    $stmt->bind_param('ss', $requestType, $source);
    $stmt->execute();
    $lastId = (int) ($stmt->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $stmt->close();
    return $lastId + 1;
}

function procurementFxFinalCanonicalRequestId(mysqli $conn, int $publicId, bool $forUpdate = false): int
{
    if ($publicId <= 0) {
        throw new RuntimeException('A valid FX Final Purchase ID is required.', 400);
    }
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare(
        "SELECT id FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ? AND legacy_source_id = ?
           AND deleted_at IS NULL LIMIT 1{$lock}"
    );
    $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE;
    $stmt->bind_param('ssi', $requestType, $source, $publicId);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($id <= 0) {
        throw new RuntimeException('FX Final Purchase not found.', 404);
    }
    return $id;
}

function procurementFxFinalRecordEvent(
    mysqli $conn,
    int $publicId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $canonicalId = procurementFxFinalCanonicalRequestId($conn, $publicId, true);
    $detailsJson = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $eventId = workflowEventRecordRequest(
        $conn,
        $canonicalId,
        PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE,
        $eventType,
        (int) ($actor['id'] ?? 0),
        (string) ($actor['email'] ?? 'system'),
        $detailsJson
    );

    $notificationDetails = $details;
    if ($eventId > 0) {
        $notificationDetails['procurement_event_id'] = $eventId;
    }
    procurementNotificationPublishFxFinalEvent($conn, $publicId, $eventType, $actor, $notificationDetails);
    procurementNotificationMirrorFxFinalEventToAccount($conn, $publicId, $eventType, $actor, $notificationDetails);
    return $eventId;
}

function procurementFxFinalEvents(mysqli $conn, int $publicId): array
{
    if ($publicId <= 0) return [];
    $eventSource = workflowEventReadSource($conn, WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST);
    $requestType = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE;
    $stmt = $conn->prepare(
        "SELECT COALESCE(e.legacy_source_id, e.id) AS id, e.event_type, e.actor_user_id,
                e.actor_email, e.details_json, e.created_at
         FROM {$eventSource} e
         INNER JOIN procurement_requests r ON r.id = e.request_id
         WHERE r.request_type = ? AND r.legacy_source_table = ? AND r.legacy_source_id = ?
         ORDER BY e.created_at DESC, e.id DESC"
    );
    $stmt->bind_param('ssi', $requestType, $source, $publicId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$event) {
        $event['id'] = (int) $event['id'];
        $event['actor_user_id'] = (int) ($event['actor_user_id'] ?? 0);
        $decoded = json_decode((string) ($event['details_json'] ?? ''), true);
        $event['details'] = is_array($decoded) ? $decoded : null;
        unset($event['details_json']);
    }
    unset($event);
    return $rows;
}

function procurementFxFinalAssertPurchaseNumberAvailable(
    mysqli $conn,
    string $normalized,
    ?int $excludeId = null
): void {
    $relation = procurementRequestCanonicalFxFinalReadRelation();
    if ($excludeId !== null) {
        $stmt = $conn->prepare(
            "SELECT id FROM {$relation} source
             WHERE purchase_number_normalized = ? AND id <> ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->bind_param('si', $normalized, $excludeId);
    } else {
        $stmt = $conn->prepare(
            "SELECT id FROM {$relation} source
             WHERE purchase_number_normalized = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->bind_param('s', $normalized);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) {
        throw new RuntimeException('Purchase Number already exists for an FX Final Purchase.', 409);
    }
}

function procurementFxFinalFetchRecord(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $relation = procurementRequestCanonicalFxFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT p.*,
                ffr.payment_status AS account_payment_status,
                COALESCE(ffr.payable_amount, p.account_payable_amount,
                    GREATEST(p.purchase_value - p.wht_amount, 0.00)) AS account_payable_amount,
                COALESCE(ffr.payment_amount, p.account_amount_paid, 0.00) AS amount_paid,
                ffr.payment_currency AS account_payment_currency,
                ffr.exchange_rate AS account_exchange_rate,
                ffr.fx_instruction_letter_id,
                CONCAT(COALESCE(cu.fname, ''), ' ', COALESCE(cu.lname, '')) AS created_by_name,
                CONCAT(COALESCE(uu.fname, ''), ' ', COALESCE(uu.lname, '')) AS updated_by_name,
                CONCAT(COALESCE(au.fname, ''), ' ', COALESCE(au.lname, '')) AS approved_by_name,
                CONCAT(COALESCE(ru.fname, ''), ' ', COALESCE(ru.lname, '')) AS approval_reversed_by_name,
                CONCAT(COALESCE(rtu.fname, ''), ' ', COALESCE(rtu.lname, '')) AS retrieved_by_name
         FROM {$relation} p
         LEFT JOIN fx_fund_request_table ffr ON ffr.id = p.fx_fund_request_id
         LEFT JOIN user_table cu ON cu.id = p.created_by
         LEFT JOIN user_table uu ON uu.id = p.updated_by
         LEFT JOIN user_table au ON au.id = p.approved_by
         LEFT JOIN user_table ru ON ru.id = p.approval_reversed_by
         LEFT JOIN user_table rtu ON rtu.id = p.retrieved_by
         WHERE p.id = ? AND p.deleted_at IS NULL
         LIMIT 1{$lock}"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementFxFinalSerializeRecord(array $row): array
{
    foreach ([
        'id', 'canonical_request_id', 'project_id', 'supplier_id', 'fx_fund_request_id',
        'previous_fx_fund_request_id', 'approved_by', 'approval_reversed_by', 'retrieved_by',
        'created_by', 'updated_by', 'version', 'handoff_revision', 'account_payment_batch_id',
        'fx_instruction_letter_id', 'account_wht_adjusted_by',
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
    }
    $row['is_retrieved'] = ($row['handoff_status'] ?? '') === 'Retrieved';
    $row['is_editable'] = ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending'
        && empty($row['fx_fund_request_id']);
    $row['is_deletable'] = $row['is_editable'] && !$row['is_retrieved'];
    $accountPaymentStatus = trim((string) ($row['account_payment_status'] ?? ''));
    $row['is_retrievable'] = ($row['approval_status'] ?? '') === 'Approved'
        && ($row['handoff_status'] ?? '') === 'In Account'
        && ($row['payment_status'] ?? '') === 'Pending'
        && ($accountPaymentStatus === '' || $accountPaymentStatus === 'Pending')
        && !empty($row['fx_fund_request_id']);
    $row['is_resubmittable'] = $row['is_retrieved']
        && ($row['approval_status'] ?? '') === 'Unapproved'
        && ($row['payment_status'] ?? '') === 'Pending'
        && empty($row['fx_fund_request_id']);
    return $row;
}

function procurementFxFinalBatchIds(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Select at least one FX Final Purchase.', 400);
    }
    $ids = array_values(array_unique(array_filter(
        array_map(static fn(mixed $id): int => (int) $id, $value),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        throw new RuntimeException('No valid FX Final Purchase IDs were supplied.', 400);
    }
    if (count($ids) > PROCUREMENT_FX_FINAL_MAX_BATCH) {
        throw new RuntimeException('A maximum of 100 records can be processed at once.', 400);
    }
    return $ids;
}

function procurementFxFinalExpectedPayableCents(array $purchase): int
{
    $purchaseValue = procurementLocalFinalMoneyToCents($purchase['purchase_value'] ?? '0.00', 'Purchase Value');
    $whtAmount = procurementLocalFinalMoneyToCents($purchase['wht_amount'] ?? '0.00', 'WHT Amount');
    $payable = $purchaseValue - $whtAmount;
    if ($payable < 0) {
        throw new RuntimeException('Calculated FX Fund Request amount cannot be negative.', 500);
    }
    return $payable;
}

function procurementFxFinalFundRequestMatchesPurchase(array $request, array $purchase): bool
{
    $moneyMatches = static function (mixed $left, mixed $right, string $label): bool {
        return procurementLocalFinalMoneyToCents($left ?? '0.00', $label)
            === procurementLocalFinalMoneyToCents($right ?? '0.00', $label);
    };

    return (string) ($request['request_type'] ?? '') === 'Final'
        && (int) ($request['suppliers_id'] ?? 0) === (int) ($purchase['supplier_id'] ?? 0)
        && strcasecmp(trim((string) ($request['suppliers_name'] ?? '')), trim((string) ($purchase['supplier_name'] ?? ''))) === 0
        && strcasecmp(trim((string) ($request['project_code'] ?? '')), trim((string) ($purchase['project_code'] ?? ''))) === 0
        && strtoupper(trim((string) ($request['currency'] ?? ''))) === strtoupper(trim((string) ($purchase['currency'] ?? '')))
        && trim((string) ($request['invoice_number'] ?? '')) === trim((string) ($purchase['invoice_number'] ?? ''))
        && trim((string) ($request['purchase_number'] ?? '')) === trim((string) ($purchase['purchase_number'] ?? ''))
        && trim((string) ($request['po_number'] ?? '')) === trim((string) ($purchase['po_number'] ?? ''))
        && trim((string) ($request['invoice_date'] ?? '')) === trim((string) ($purchase['invoice_date'] ?? ''))
        && trim((string) ($request['purchase_date'] ?? '')) === trim((string) ($purchase['purchase_date'] ?? ''))
        && $moneyMatches($request['sub_total'] ?? 0, $purchase['purchase_subtotal'] ?? 0, 'Subtotal')
        && $moneyMatches($request['discount'] ?? 0, $purchase['purchase_discount'] ?? 0, 'Discount')
        && $moneyMatches($request['other_charges'] ?? 0, $purchase['purchase_other_charges'] ?? 0, 'Other Charges')
        && abs((float) ($request['vat_rate'] ?? 0) - (float) ($purchase['purchase_vat_rate'] ?? 0)) < 0.000001
        && abs((float) ($request['wht_rate'] ?? 0) - (float) ($purchase['wht_rate'] ?? 0)) < 0.000001
        && $moneyMatches($request['payable_amount'] ?? 0, procurementLocalFinalCents(procurementFxFinalExpectedPayableCents($purchase)), 'Payable Amount');
}

function procurementFxFinalFindReusableFundRequest(mysqli $conn, array $purchase): ?array
{
    $purchaseNumber = trim((string) ($purchase['purchase_number'] ?? ''));
    $stmt = $conn->prepare(
        "SELECT * FROM fx_fund_request_table
         WHERE request_type = 'Final' AND purchase_number = ?
         ORDER BY id DESC FOR UPDATE"
    );
    $stmt->bind_param('s', $purchaseNumber);
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($candidates === []) {
        return null;
    }

    $matches = [];
    foreach ($candidates as $candidate) {
        $requestId = (int) ($candidate['id'] ?? 0);
        $link = $conn->prepare(
            "SELECT legacy_source_id FROM procurement_requests
             WHERE request_type = ? AND account_request_id = ? AND deleted_at IS NULL
             LIMIT 1 FOR UPDATE"
        );
        $requestType = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
        $link->bind_param('si', $requestType, $requestId);
        $link->execute();
        $linkedPublicId = (int) ($link->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
        $link->close();
        if ($linkedPublicId > 0 && $linkedPublicId !== (int) $purchase['id']) {
            continue;
        }
        if ((string) ($candidate['payment_status'] ?? '') !== 'Pending'
            || $candidate['fx_instruction_letter_id'] !== null
            || $candidate['processed_at'] !== null) {
            continue;
        }
        if (procurementFxFinalFundRequestMatchesPurchase($candidate, $purchase)) {
            $matches[] = $candidate;
        }
    }

    if (count($matches) > 1) {
        throw new RuntimeException('Approval cannot continue because more than one matching FX Fund Request exists.', 409);
    }
    if ($matches === []) {
        throw new RuntimeException(
            'Approval cannot continue because this Purchase Number already belongs to a different FX Fund Request.',
            409
        );
    }
    return $matches[0];
}

function procurementFxFinalCreateFundRequest(mysqli $conn, array $purchase, int $actorId): array
{
    $reusable = procurementFxFinalFindReusableFundRequest($conn, $purchase);
    if ($reusable !== null) {
        return ['id' => (int) $reusable['id'], 'reused' => true, 'request' => $reusable];
    }

    $approvalDate = (string) (($conn->query('SELECT CURRENT_DATE() AS approval_date')->fetch_assoc()['approval_date'] ?? date('Y-m-d')));
    $requestType = 'Final';
    $supplierName = (string) $purchase['supplier_name'];
    $supplierId = (int) $purchase['supplier_id'];
    $contactPerson = trim((string) ($purchase['contact_person'] ?? ''));
    $phoneNumber = trim((string) ($purchase['phone_number'] ?? ''));
    $invoiceNumber = (string) $purchase['invoice_number'];
    $purchaseNumber = (string) $purchase['purchase_number'];
    $poNumber = trim((string) ($purchase['po_number'] ?? ''));
    $invoiceDate = (string) $purchase['invoice_date'];
    $purchaseDate = (string) $purchase['purchase_date'];
    $projectCode = (string) $purchase['project_code'];
    $currency = procurementFxFinalNormalizeCurrency($purchase['currency'] ?? '');
    $subTotal = (float) $purchase['purchase_subtotal'];
    $discount = (float) $purchase['purchase_discount'];
    $otherCharges = (float) $purchase['purchase_other_charges'];
    $vatRate = (float) $purchase['purchase_vat_rate'];
    $vatAmount = (float) $purchase['purchase_vat_amount'];
    $whtRate = (float) $purchase['wht_rate'];
    $whtAmount = (float) $purchase['wht_amount'];
    $payableAmount = (float) procurementLocalFinalCents(procurementFxFinalExpectedPayableCents($purchase));

    $stmt = $conn->prepare(
        "INSERT INTO fx_fund_request_table
            (request_type, suppliers_name, suppliers_id, contact_person, phone_number, invoice_number, purchase_number,
             po_number, invoice_date, purchase_date, date_received, project_code, currency,
             sub_total, discount, other_charges, vat_rate, vat_amount, wht_rate, wht_amount,
             percentage, payable_amount, payment_status, created_by, updated_by)
         VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 'Pending', ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the FX Fund Request handoff.', 500);
    }
    $stmt->bind_param(
        'ssissssssssssddddddddii',
        $requestType,
        $supplierName,
        $supplierId,
        $contactPerson,
        $phoneNumber,
        $invoiceNumber,
        $purchaseNumber,
        $poNumber,
        $invoiceDate,
        $purchaseDate,
        $approvalDate,
        $projectCode,
        $currency,
        $subTotal,
        $discount,
        $otherCharges,
        $vatRate,
        $vatAmount,
        $whtRate,
        $whtAmount,
        $payableAmount,
        $actorId,
        $actorId
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return ['id' => $id, 'reused' => false, 'request' => null];
}

function procurementFxFinalFundRequestSnapshot(array $request): string
{
    return (string) json_encode(
        $request,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function procurementFxFinalCreateHandoff(mysqli $conn, int $purchaseId, int $revision, int $fundRequestId, int $actorId): void
{
    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_FX_FINAL,
        $purchaseId,
        $revision,
        'fx_fund_request',
        $fundRequestId,
        'In Account',
        $actorId
    );
}

function procurementFxFinalArchiveHandoff(
    mysqli $conn,
    int $purchaseId,
    int $revision,
    int $fundRequestId,
    array $fundRequest,
    array $actor,
    string $status,
    string $source,
    string $reason
): void {
    procurementRequestCanonicalUpsertHandoff(
        $conn,
        PROCUREMENT_REQUEST_TYPE_FX_FINAL,
        $purchaseId,
        $revision,
        'fx_fund_request',
        $fundRequestId,
        $status,
        (int) ($actor['id'] ?? 0),
        (int) ($actor['id'] ?? 0),
        $source,
        $reason,
        procurementFxFinalFundRequestSnapshot($fundRequest)
    );
}

function procurementFxFinalApproveOne(mysqli $conn, int $id, array $actor): array
{
    $conn->begin_transaction();
    try {
        $purchase = procurementFxFinalFetchRecord($conn, $id, true);
        if (!$purchase) throw new RuntimeException('FX Final Purchase not found.', 404);
        if ((string) $purchase['approval_status'] !== 'Unapproved') throw new RuntimeException('This FX Final Purchase is already approved.', 409);
        if (trim((string) ($purchase['material_type'] ?? '')) === '') throw new RuntimeException('Select a Material Type before approving this FX Final Purchase.', 409);
        if ((string) $purchase['payment_status'] !== 'Pending') throw new RuntimeException('Only pending FX Final Purchases can be approved.', 409);
        if ((string) $purchase['po_status'] === 'Cancelled') throw new RuntimeException('A cancelled PO cannot be approved.', 409);
        if (!empty($purchase['fx_fund_request_id'])) throw new RuntimeException('This purchase still has an active Account handoff.', 409);

        $previousHandoffStatus = (string) ($purchase['handoff_status'] ?? 'Not Sent');
        if (!in_array($previousHandoffStatus, ['Not Sent', 'Retrieved'], true)) {
            throw new RuntimeException('This FX Final Purchase is not eligible for approval or resubmission.', 409);
        }

        $revision = max(0, (int) ($purchase['handoff_revision'] ?? 0)) + 1;
        $actorId = (int) ($actor['id'] ?? 0);
        $handoffRequest = procurementFxFinalCreateFundRequest($conn, $purchase, $actorId);
        $fundRequestId = (int) $handoffRequest['id'];
        procurementFxFinalCreateHandoff($conn, $id, $revision, $fundRequestId, $actorId);
        $payable = procurementLocalFinalCents(procurementFxFinalExpectedPayableCents($purchase));

        $scope = procurementRequestCanonicalFxFinalScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Approved', handoff_status = 'In Account', handoff_revision = ?,
                 account_request_type = 'fx_fund_request', account_request_id = ?, account_payable_amount = ?,
                 approved_by = ?, approved_at = NOW(), approval_reversed_by = NULL, approval_reversed_at = NULL,
                 retrieved_by = NULL, retrieved_at = NULL, retrieval_reason = NULL, retrieval_source = NULL,
                 payment_status = 'Pending', payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
        );
        $update->bind_param('iisiii', $revision, $fundRequestId, $payable, $actorId, $actorId, $id);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('FX Final Purchase approval state could not be saved.', 409);
        }
        $update->close();

        $eventType = $previousHandoffStatus === 'Retrieved' ? 'resubmitted_to_account' : 'approved';
        procurementFxFinalRecordEvent($conn, $id, $eventType, $actor, [
            'fx_fund_request_id' => $fundRequestId,
            'handoff_revision' => $revision,
            'previous_handoff_status' => $previousHandoffStatus,
            'existing_fx_fund_request_linked' => (bool) ($handoffRequest['reused'] ?? false),
            'currency' => (string) $purchase['currency'],
            'payable_amount' => $payable,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_FINAL, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $id) ?? []);
}

function procurementFxFinalCancellationSnapshot(array $purchase): array
{
    $fields = [
        'id', 'currency', 'po_number', 'purchase_number', 'grn_ref', 'material_type',
        'project_id', 'project_code', 'project_name', 'supplier_id', 'supplier_name', 'supplier_ledger',
        'invoice_number', 'invoice_date', 'purchase_date', 'purchase_value', 'po_value', 'wht_amount',
        'approval_status', 'payment_status', 'po_status', 'handoff_revision', 'fx_fund_request_id'
    ];
    $snapshot = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $purchase)) {
            $snapshot[$field] = $purchase[$field];
        }
    }
    return $snapshot;
}

function procurementFxFinalPaidAmountForSupplier(
    mysqli $conn,
    int $purchaseId,
    int $supplierId,
    string $currency
): string {
    $canonicalId = procurementFxFinalCanonicalRequestId($conn, $purchaseId, false);
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(x.payable_amount), 0.00) AS paid_amount
         FROM (
             SELECT DISTINCT ffr.id, ffr.payable_amount
             FROM procurement_request_handoffs h
             INNER JOIN fx_fund_request_table ffr ON ffr.id = h.account_request_id
             WHERE h.request_id = ?
               AND h.account_request_type = 'fx_fund_request'
               AND ffr.request_type = 'Final'
               AND ffr.payment_status = 'Paid'
               AND ffr.suppliers_id = ?
               AND ffr.currency = ?
         ) x"
    );
    $stmt->bind_param('iis', $canonicalId, $supplierId, $currency);
    $stmt->execute();
    $amount = (string) ($stmt->get_result()->fetch_assoc()['paid_amount'] ?? '0.00');
    $stmt->close();
    return $amount;
}

function procurementFxFinalCancelOne(
    mysqli $conn,
    int $id,
    array $actor,
    string $reason
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required when cancelling an FX PO.', 400);
    }

    procurementSupplierAdjustmentEnsureStorage($conn);
    $conn->begin_transaction();
    try {
        $record = procurementFxFinalFetchRecord($conn, $id, true);
        if (!$record) {
            throw new RuntimeException('FX Final Purchase not found.', 404);
        }
        if ((string) ($record['po_status'] ?? '') === 'Cancelled') {
            $conn->commit();
            return procurementFxFinalSerializeRecord($record);
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $currency = procurementFxFinalNormalizeCurrency($record['currency'] ?? '');
        $fundRequestId = (int) ($record['fx_fund_request_id'] ?? 0);
        $fundRequest = null;
        $actualPaymentStatus = trim((string) ($record['account_payment_status'] ?? $record['payment_status'] ?? 'Pending'));
        if ($actualPaymentStatus === 'Unconfirmed') {
            $actualPaymentStatus = 'Processing';
        }
        if ($fundRequestId > 0) {
            $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('i', $fundRequestId);
            $stmt->execute();
            $fundRequest = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($fundRequest) {
                $actualPaymentStatus = trim((string) ($fundRequest['payment_status'] ?? $actualPaymentStatus));
                if ($actualPaymentStatus === 'Unconfirmed') {
                    $actualPaymentStatus = 'Processing';
                }
                if ((int) ($fundRequest['fx_instruction_letter_id'] ?? 0) > 0 && $actualPaymentStatus === 'Pending') {
                    $actualPaymentStatus = 'Processing';
                }
            }
        }

        if ($actualPaymentStatus === 'Processing') {
            throw new RuntimeException(
                'Complete or cancel the current Account FX processing payment before cancelling this PO.',
                409
            );
        }

        $previousFundRequestId = null;
        if ($actualPaymentStatus === 'Pending' && $fundRequestId > 0 && $fundRequest) {
            if ((string) ($fundRequest['payment_status'] ?? '') !== 'Pending'
                || (int) ($fundRequest['fx_instruction_letter_id'] ?? 0) > 0
                || !empty($fundRequest['processed_at'])) {
                throw new RuntimeException('The linked FX Fund Request started processing before cancellation completed.', 409);
            }
            $revision = max(1, (int) ($record['handoff_revision'] ?? 1));
            procurementFxFinalArchiveHandoff(
                $conn,
                $id,
                $revision,
                $fundRequestId,
                $fundRequest,
                $actor,
                'Cancelled',
                'procurement',
                $reason
            );
            $delete = $conn->prepare(
                "DELETE FROM fx_fund_request_table
                 WHERE id = ? AND payment_status = 'Pending'
                   AND fx_instruction_letter_id IS NULL AND processed_at IS NULL"
            );
            $delete->bind_param('i', $fundRequestId);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                $delete->close();
                throw new RuntimeException('The linked FX Fund Request could not be removed safely.', 409);
            }
            $delete->close();
            $previousFundRequestId = $fundRequestId;
        }

        procurementSupplierAdjustmentCancelOpenPayablesForSource(
            $conn,
            'fx_final_purchase',
            $id,
            $actorId,
            $reason
        );

        $supplierId = (int) ($record['supplier_id'] ?? 0);
        $supplierLedger = trim((string) ($record['supplier_ledger'] ?? ''));
        $paidCents = procurementLocalFinalMoneyToCents(
            procurementFxFinalPaidAmountForSupplier($conn, $id, $supplierId, $currency),
            'Paid FX Amount',
            true
        );
        if ($paidCents <= 0 && $actualPaymentStatus === 'Paid') {
            $paidCents = procurementLocalFinalMoneyToCents(
                $fundRequest['payable_amount'] ?? $record['account_payable_amount'] ?? '0.00',
                'Paid FX Amount',
                true
            );
        }

        $newRecoveryCents = 0;
        if ($paidCents > 0 && $supplierId > 0) {
            $recordedRecoveryCents = procurementLocalFinalMoneyToCents(
                procurementSupplierAdjustmentRecoverableRecordedForSourceSupplier(
                    $conn,
                    'fx_final_purchase',
                    $id,
                    $supplierId,
                    $currency
                ),
                'Existing FX Recovery',
                true
            );
            $newRecoveryCents = max(0, $paidCents - $recordedRecoveryCents);
            if ($newRecoveryCents > 0) {
                $originSnapshot = procurementFxFinalCancellationSnapshot($record);
                $revisedSnapshot = $originSnapshot;
                $revisedSnapshot['po_status'] = 'Cancelled';
                procurementSupplierAdjustmentCreate($conn, [
                    'source_type' => 'fx_final_purchase',
                    'source_purchase_id' => $id,
                    'source_revision_number' => max(1, (int) ($record['handoff_revision'] ?? 1)),
                    'adjustment_kind' => 'Cancellation',
                    'adjustment_direction' => 'Recoverable',
                    'supplier_id' => $supplierId,
                    'supplier_name' => (string) ($record['supplier_name'] ?? ''),
                    'supplier_ledger' => $supplierLedger,
                    'currency' => $currency,
                    'amount' => procurementLocalFinalCents($newRecoveryCents),
                    'reason' => $reason,
                    'origin_snapshot' => $originSnapshot,
                    'revised_snapshot' => $revisedSnapshot,
                ], $actorId);
            }
        }

        $hasPaidHistory = $paidCents > 0 || $actualPaymentStatus === 'Paid';
        $scope = procurementRequestCanonicalFxFinalScopeSql();
        if ($hasPaidHistory) {
            $paidAmount = procurementLocalFinalCents($paidCents);
            $update = $conn->prepare(
                "UPDATE procurement_requests
                 SET po_status = 'Cancelled', payment_status = 'Paid',
                     payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                     account_amount_paid = ?, updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
            );
            $update->bind_param('sii', $paidAmount, $actorId, $id);
        } else {
            $previousValue = (int) ($previousFundRequestId ?? 0);
            $update = $conn->prepare(
                "UPDATE procurement_requests
                 SET po_status = 'Cancelled', payment_status = 'Cancelled',
                     payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                     handoff_status = 'Not Sent', account_request_type = NULL, account_request_id = NULL,
                     previous_account_request_id = COALESCE(NULLIF(?, 0), previous_account_request_id),
                     updated_by = ?, updated_at = NOW(), version = version + 1
                 WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
            );
            $update->bind_param('iii', $previousValue, $actorId, $id);
        }
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('The FX Final Purchase changed before cancellation completed.', 409);
        }
        $update->close();

        procurementFxFinalRecordEvent($conn, $id, 'po_cancelled', $actor, [
            'reason' => $reason,
            'payment_history_preserved' => $hasPaidHistory,
            'currency' => $currency,
            'paid_amount' => procurementLocalFinalCents($paidCents),
            'new_recoverable_amount' => procurementLocalFinalCents($newRecoveryCents),
            'removed_fx_fund_request_id' => $previousFundRequestId,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_FINAL, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $id) ?? []);
}

function procurementFxFinalReverseApprovalOne(mysqli $conn, int $id, array $actor, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('An approval-reversal reason is required.', 400);

    $conn->begin_transaction();
    try {
        $purchase = procurementFxFinalFetchRecord($conn, $id, true);
        if (!$purchase) throw new RuntimeException('FX Final Purchase not found.', 404);
        if ((string) $purchase['approval_status'] !== 'Approved' || empty($purchase['fx_fund_request_id'])) {
            throw new RuntimeException('Only an approved FX Final Purchase can be unapproved.', 409);
        }
        $fundRequestId = (int) $purchase['fx_fund_request_id'];
        $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $fundRequestId);
        $stmt->execute();
        $fundRequest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fundRequest) throw new RuntimeException('The linked FX Fund Request could not be found. Reversal was blocked.', 409);
        if ((string) $fundRequest['payment_status'] !== 'Pending' || $fundRequest['fx_instruction_letter_id'] !== null || $fundRequest['processed_at'] !== null) {
            throw new RuntimeException('Approval cannot be reversed after Account has started processing the FX Fund Request.', 409);
        }

        $revision = max(1, (int) ($purchase['handoff_revision'] ?? 1));
        procurementFxFinalArchiveHandoff($conn, $id, $revision, $fundRequestId, $fundRequest, $actor, 'Approval Reversed', 'procurement', $reason);
        $delete = $conn->prepare("DELETE FROM fx_fund_request_table WHERE id = ? AND payment_status = 'Pending' AND fx_instruction_letter_id IS NULL AND processed_at IS NULL");
        $delete->bind_param('i', $fundRequestId);
        $delete->execute();
        if ($delete->affected_rows !== 1) {
            $delete->close();
            throw new RuntimeException('The linked FX Fund Request could not be removed safely.', 409);
        }
        $delete->close();

        $actorId = (int) ($actor['id'] ?? 0);
        $scope = procurementRequestCanonicalFxFinalScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Not Sent', account_request_type = NULL,
                 account_request_id = NULL, previous_account_request_id = ?, approved_by = NULL, approved_at = NULL,
                 approval_reversed_by = ?, approval_reversed_at = NOW(), payment_status = 'Pending',
                 payment_status_source = 'procuredesk', payment_status_updated_at = NOW(), updated_by = ?, updated_at = NOW(),
                 version = version + 1
             WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
        );
        $update->bind_param('iiii', $fundRequestId, $actorId, $actorId, $id);
        $update->execute();
        $update->close();

        procurementFxFinalRecordEvent($conn, $id, 'approval_reversed', $actor, [
            'reason' => $reason,
            'removed_fx_fund_request_id' => $fundRequestId,
            'handoff_revision' => $revision,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_FINAL, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $id) ?? []);
}

function procurementFxFinalRetrieveOne(
    mysqli $conn,
    int $id,
    array $actor,
    string $reason,
    string $source = 'procurement'
): array {
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('A return or retrieval reason is required.', 400);
    $source = strtolower(trim($source)) === 'account' ? 'account' : 'procurement';

    $conn->begin_transaction();
    try {
        $purchase = procurementFxFinalFetchRecord($conn, $id, true);
        if (!$purchase) throw new RuntimeException('FX Final Purchase not found.', 404);
        if ((string) $purchase['approval_status'] !== 'Approved'
            || (string) ($purchase['handoff_status'] ?? '') !== 'In Account'
            || empty($purchase['fx_fund_request_id'])) {
            throw new RuntimeException('Only an FX Final Purchase currently awaiting Account payment can be retrieved.', 409);
        }
        if ((string) $purchase['payment_status'] !== 'Pending') {
            throw new RuntimeException('This FX Final Purchase cannot be retrieved after Account has started processing it.', 409);
        }

        $fundRequestId = (int) $purchase['fx_fund_request_id'];
        $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $fundRequestId);
        $stmt->execute();
        $fundRequest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fundRequest) throw new RuntimeException('The active FX Fund Request could not be found.', 409);
        if ((string) $fundRequest['payment_status'] !== 'Pending' || $fundRequest['fx_instruction_letter_id'] !== null || $fundRequest['processed_at'] !== null) {
            throw new RuntimeException('This request is already being processed and cannot be returned.', 409);
        }

        $revision = max(1, (int) ($purchase['handoff_revision'] ?? 1));
        procurementFxFinalArchiveHandoff($conn, $id, $revision, $fundRequestId, $fundRequest, $actor, 'Retrieved', $source, $reason);
        $delete = $conn->prepare("DELETE FROM fx_fund_request_table WHERE id = ? AND payment_status = 'Pending' AND fx_instruction_letter_id IS NULL AND processed_at IS NULL");
        $delete->bind_param('i', $fundRequestId);
        $delete->execute();
        if ($delete->affected_rows !== 1) {
            $delete->close();
            throw new RuntimeException('The FX Fund Request changed before it could be returned.', 409);
        }
        $delete->close();

        $actorId = (int) ($actor['id'] ?? 0);
        $scope = procurementRequestCanonicalFxFinalScopeSql();
        $update = $conn->prepare(
            "UPDATE procurement_requests
             SET approval_status = 'Unapproved', handoff_status = 'Retrieved', account_request_type = NULL,
                 account_request_id = NULL, previous_account_request_id = ?, approved_by = NULL, approved_at = NULL,
                 retrieved_by = ?, retrieved_at = NOW(), retrieval_reason = ?, retrieval_source = ?,
                 payment_status = 'Pending', payment_status_source = 'procuredesk', payment_status_updated_at = NOW(),
                 updated_by = ?, updated_at = NOW(), version = version + 1
             WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
        );
        $update->bind_param('iissii', $fundRequestId, $actorId, $reason, $source, $actorId, $id);
        $update->execute();
        $update->close();

        $eventType = $source === 'account' ? 'returned_by_account' : 'retrieved_from_account';
        procurementFxFinalRecordEvent($conn, $id, $eventType, $actor, [
            'reason' => $reason,
            'source' => $source,
            'removed_fx_fund_request_id' => $fundRequestId,
            'handoff_revision' => $revision,
        ]);
        procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_FINAL, $id);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return procurementFxFinalSerializeRecord(procurementFxFinalFetchRecord($conn, $id) ?? []);
}

function procurementFxFinalRetrieveFundRequestOne(mysqli $conn, int $fundRequestId, array $actor, string $reason): array
{
    $stmt = $conn->prepare(
        "SELECT legacy_source_id FROM procurement_requests
         WHERE request_type = ? AND account_request_id = ? AND approval_status = 'Approved'
           AND handoff_status = 'In Account' AND deleted_at IS NULL LIMIT 1"
    );
    $type = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $stmt->bind_param('si', $type, $fundRequestId);
    $stmt->execute();
    $purchaseId = (int) ($stmt->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $stmt->close();
    if ($purchaseId <= 0) {
        throw new RuntimeException('This FX Fund Request is not linked to an active ProcureDesk FX Final Purchase.', 404);
    }
    return procurementFxFinalRetrieveOne($conn, $purchaseId, $actor, $reason, 'account');
}

function procurementFxFinalMapAccountPaymentStatus(string $status): string
{
    $status = trim($status);
    if (strcasecmp($status, 'Unconfirmed') === 0) return 'Processing';
    if (in_array($status, PROCUREMENT_LOCAL_FINAL_PAYMENT_STATUSES, true)) return $status;
    if (strcasecmp($status, 'Pending') === 0) return 'Pending';
    return 'Processing';
}

function procurementFxFinalPaymentProcessingContext(mysqli $conn, int $fundRequestId): array
{
    $stmt = $conn->prepare(
        "SELECT i.batch_id AS canonical_batch_id, i.status AS item_status,
                i.processing_started_at, i.expected_completion_at, i.paid_at, i.payment_reference,
                b.processing_method, b.processing_reference, b.processing_business_days,
                b.completion_mode, b.status AS batch_status
         FROM account_payment_batch_items i
         INNER JOIN account_payment_batches b ON b.id = i.batch_id AND b.request_type = i.request_type
         WHERE i.request_type = 'fx_final_purchase' AND i.request_id = ?
         ORDER BY i.id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $fundRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function procurementFxFinalSyncFundRequestToProcurement(
    mysqli $conn,
    int $fundRequestId,
    int $actorId,
    string $actorEmail,
    string $eventType = 'account_payment_status_updated'
): array {
    $stmt = $conn->prepare('SELECT * FROM fx_fund_request_table WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $fundRequestId);
    $stmt->execute();
    $fundRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fundRequest) return [];
    $processingContext = procurementFxFinalPaymentProcessingContext($conn, $fundRequestId);

    $link = $conn->prepare(
        "SELECT legacy_source_id, payment_status FROM procurement_requests
         WHERE request_type = ? AND account_request_id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE"
    );
    $type = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $link->bind_param('si', $type, $fundRequestId);
    $link->execute();
    $purchase = $link->get_result()->fetch_assoc();
    $link->close();
    if (!$purchase) return [];

    $purchaseId = (int) $purchase['legacy_source_id'];
    $previousStatus = (string) ($purchase['payment_status'] ?? 'Pending');
    $paymentStatus = procurementFxFinalMapAccountPaymentStatus((string) ($fundRequest['payment_status'] ?? 'Pending'));
    $payable = number_format((float) ($fundRequest['payable_amount'] ?? 0), 2, '.', '');
    $amountPaid = $paymentStatus === 'Paid' ? $payable : '0.00';
    $paidAt = $paymentStatus === 'Paid' ? date('Y-m-d H:i:s') : null;
    $instructionId = (int) ($fundRequest['fx_instruction_letter_id'] ?? 0);
    $paymentReference = $instructionId > 0 ? 'FX Instruction #' . $instructionId : null;
    $paymentCurrency = trim((string) ($fundRequest['payment_currency'] ?? ''));
    $paymentAmount = $fundRequest['payment_amount'] === null ? null : number_format((float) $fundRequest['payment_amount'], 2, '.', '');
    $rate = $fundRequest['exchange_rate'] === null ? null : number_format((float) $fundRequest['exchange_rate'], 10, '.', '');
    $remarks = null;
    if ($paymentCurrency !== '' && $paymentAmount !== null) {
        $remarks = 'FX payment allocation: ' . $paymentCurrency . ' ' . $paymentAmount;
        if ($rate !== null) $remarks .= ' | Effective rate: ' . $rate;
    }
    $method = trim((string) ($processingContext['processing_method'] ?? '')) ?: ($instructionId > 0 ? 'FX Instruction' : null);
    $processingReference = trim((string) ($processingContext['processing_reference'] ?? '')) ?: $paymentReference;
    $processingStartedAt = $processingContext['processing_started_at'] ?? null;
    $expectedCompletionAt = $processingContext['expected_completion_at'] ?? null;
    $completionMode = trim((string) ($processingContext['completion_mode'] ?? '')) ?: null;
    $batchId = (int) ($processingContext['canonical_batch_id'] ?? 0);
    $itemStatus = trim((string) ($processingContext['item_status'] ?? ''));
    $confirmationStatus = match ($itemStatus) {
        'Paid' => 'Confirmed',
        'Awaiting Confirmation' => 'Due',
        'Processing', 'Delayed' => 'Scheduled',
        'Failed' => 'Failed',
        'Cancelled' => 'Cancelled',
        default => $expectedCompletionAt !== null ? 'Scheduled' : 'Not Scheduled',
    };
    if ($paymentStatus === 'Paid' && !empty($processingContext['paid_at'])) {
        $paidAt = (string) $processingContext['paid_at'];
    }
    if ($remarks !== null && $completionMode !== null) {
        $remarks .= ' | Completion: ' . $completionMode;
        if ($expectedCompletionAt !== null && $completionMode !== 'Immediate') {
            $remarks .= ' | Expected: ' . $expectedCompletionAt;
        }
    }

    $scope = procurementRequestCanonicalFxFinalScopeSql();
    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET payment_status = ?, payment_status_source = 'account', payment_status_updated_at = NOW(),
             account_payable_amount = ?, account_amount_paid = ?, account_processing_method = ?,
             account_processing_reference = ?, account_processing_started_at = COALESCE(?, account_processing_started_at),
             account_expected_completion_at = ?, account_completion_mode = ?, account_confirmation_status = ?,
             account_payment_reference = ?, account_paid_at = ?, account_payment_remarks = ?, account_payment_batch_id = ?,
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND {$scope} AND deleted_at IS NULL"
    );
    $batchIdValue = $batchId > 0 ? $batchId : null;
    $update->bind_param(
        'ssssssssssssiii',
        $paymentStatus,
        $payable,
        $amountPaid,
        $method,
        $processingReference,
        $processingStartedAt,
        $expectedCompletionAt,
        $completionMode,
        $confirmationStatus,
        $paymentReference,
        $paidAt,
        $remarks,
        $batchIdValue,
        $actorId,
        $purchaseId
    );
    $update->execute();
    $update->close();

    $actor = ['id' => $actorId, 'email' => $actorEmail !== '' ? $actorEmail : 'account-user'];
    procurementFxFinalRecordEvent($conn, $purchaseId, $eventType, $actor, [
        'fx_fund_request_id' => $fundRequestId,
        'previous_payment_status' => $previousStatus,
        'payment_status' => $paymentStatus,
        'request_currency' => (string) ($fundRequest['currency'] ?? ''),
        'payable_amount' => $payable,
        'payment_currency' => $paymentCurrency !== '' ? $paymentCurrency : null,
        'payment_amount' => $paymentAmount,
        'exchange_rate' => $rate,
        'fx_instruction_letter_id' => $instructionId > 0 ? $instructionId : null,
        'payment_batch_id' => $batchId > 0 ? $batchId : null,
        'completion_mode' => $completionMode,
        'expected_completion_at' => $expectedCompletionAt,
        'confirmation_status' => $confirmationStatus,
    ]);

    return [
        'purchase_id' => $purchaseId,
        'fund_request_id' => $fundRequestId,
        'previous_status' => $previousStatus,
        'payment_status' => $paymentStatus,
    ];
}


function procurementFxFinalLinkedPurchaseForFundRequest(
    mysqli $conn,
    int $fundRequestId,
    bool $forUpdate = false
): ?array {
    if ($fundRequestId <= 0) {
        return null;
    }

    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $requestType = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $stmt = $conn->prepare(
        "SELECT legacy_source_id, approval_status, handoff_status, payment_status,
                request_number, currency
         FROM procurement_requests
         WHERE request_type = ? AND account_request_id = ? AND deleted_at IS NULL
         LIMIT 1{$lock}"
    );
    $stmt->bind_param('si', $requestType, $fundRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function procurementSyncFxFinalPurchaseFromFundRequest(
    mysqli $conn,
    int $fundRequestId,
    array $actor
): array {
    procurementEnsureFxFinalPurchaseStorage($conn);
    if ($fundRequestId <= 0) {
        throw new RuntimeException('A valid FX Fund Request ID is required.', 400);
    }

    $requestType = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $stmt = $conn->prepare(
        "SELECT ffr.*,
                p.legacy_source_id AS procurement_purchase_id,
                p.payment_status AS procurement_payment_status,
                p.approval_status AS procurement_approval_status,
                p.handoff_status AS procurement_handoff_status,
                p.material_type AS procurement_material_type,
                p.remark AS procurement_remark
         FROM fx_fund_request_table ffr
         INNER JOIN procurement_requests p
           ON p.request_type = ?
          AND p.account_request_id = ffr.id
          AND p.deleted_at IS NULL
         WHERE ffr.id = ?
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('si', $requestType, $fundRequestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException(
            'The linked ProcureDesk FX Final Purchase could not be found.',
            404
        );
    }
    if ((string) ($row['request_type'] ?? '') !== 'Final') {
        throw new RuntimeException(
            'Only ProcureDesk-linked Final FX Fund Requests can synchronize to FX Final Purchase.',
            409
        );
    }
    if ((string) ($row['procurement_approval_status'] ?? '') !== 'Approved'
        || (string) ($row['procurement_handoff_status'] ?? '') !== 'In Account') {
        throw new RuntimeException(
            'The linked FX Final Purchase is no longer an active Account handoff.',
            409
        );
    }
    if ((string) ($row['payment_status'] ?? '') !== 'Pending'
        || (string) ($row['procurement_payment_status'] ?? '') !== 'Pending'
        || $row['fx_instruction_letter_id'] !== null
        || $row['processed_at'] !== null) {
        throw new RuntimeException(
            'Commercial details can only be changed before Account starts processing payment.',
            409
        );
    }

    $purchaseId = (int) $row['procurement_purchase_id'];
    [$purchaseNumber, $normalizedPurchaseNumber] = procurementLocalFinalNormalizePurchaseNumber(
        (string) ($row['purchase_number'] ?? '')
    );
    procurementFxFinalAssertPurchaseNumberAvailable($conn, $normalizedPurchaseNumber, $purchaseId);

    $project = procurementLocalFinalResolveProject(
        $conn,
        ['project_code' => (string) ($row['project_code'] ?? '')]
    );
    $supplier = procurementLocalFinalResolveSupplier(
        $conn,
        ['supplier_id' => (int) ($row['suppliers_id'] ?? 0)]
    );
    $currency = procurementFxFinalNormalizeCurrency($row['currency'] ?? '');

    $subtotalCents = procurementLocalFinalMoneyToCents(
        $row['sub_total'] ?? 0,
        'FX Fund Request Subtotal',
        false
    );
    $discountCents = procurementLocalFinalMoneyToCents(
        $row['discount'] ?? 0,
        'FX Fund Request Discount'
    );
    $otherChargesCents = procurementLocalFinalMoneyToCents(
        $row['other_charges'] ?? 0,
        'FX Fund Request Other Charges'
    );
    if ($discountCents > $subtotalCents) {
        throw new RuntimeException('FX Fund Request Discount cannot exceed its subtotal.', 409);
    }

    $netCents = $subtotalCents - $discountCents;
    $vatRate = (float) ($row['vat_rate'] ?? 0);
    $whtRate = (float) ($row['wht_rate'] ?? 0);
    $vatBasisPoints = (int) round($vatRate * 10000);
    $whtBasisPoints = (int) round($whtRate * 10000);
    if (!in_array($vatBasisPoints, [0, 750], true)) {
        throw new RuntimeException('FX Fund Request VAT rate must be 0.00% or 7.50%.', 409);
    }
    if (!in_array($whtBasisPoints, [0, 200, 500], true)) {
        throw new RuntimeException('FX Fund Request WHT rate must be 0.00%, 2.00%, or 5.00%.', 409);
    }

    $vatCents = procurementLocalFinalRateAmount($netCents, $vatBasisPoints);
    $whtCents = procurementLocalFinalRateAmount($netCents, $whtBasisPoints);
    $purchaseValueCents = $netCents + $otherChargesCents + $vatCents;
    $payableCents = $purchaseValueCents - $whtCents;
    if ($payableCents < 0) {
        throw new RuntimeException('Calculated FX Final Purchase payable amount cannot be negative.', 409);
    }

    $fundPayableCents = procurementLocalFinalMoneyToCents(
        $row['payable_amount'] ?? 0,
        'FX Fund Request Payable Amount'
    );
    if ($fundPayableCents !== $payableCents) {
        throw new RuntimeException(
            'FX Fund Request commercial totals are inconsistent. Refresh the request and try again.',
            409
        );
    }

    $poNumber = trim((string) ($row['po_number'] ?? ''));
    $invoiceNumber = trim((string) ($row['invoice_number'] ?? ''));
    $invoiceDate = procurementLocalFinalDate(
        ['invoice_date' => $row['invoice_date'] ?? null],
        'invoice_date',
        'Invoice Date'
    );
    $purchaseDate = procurementLocalFinalDate(
        ['purchase_date' => $row['purchase_date'] ?? null],
        'purchase_date',
        'Purchase Date'
    );
    $vatStatus = $vatBasisPoints > 0 ? 'Yes' : 'No';
    $vatRateValue = procurementLocalFinalPercentFromBasisPoints($vatBasisPoints);
    $whtStatus = number_format($whtBasisPoints / 100, 2, '.', '') . '%';
    $whtRateValue = procurementLocalFinalPercentFromBasisPoints($whtBasisPoints);
    $vatAmount = procurementLocalFinalCents($vatCents);
    $whtAmount = procurementLocalFinalCents($whtCents);
    $subtotal = procurementLocalFinalCents($subtotalCents);
    $discount = procurementLocalFinalCents($discountCents);
    $otherCharges = procurementLocalFinalCents($otherChargesCents);
    $purchaseValue = procurementLocalFinalCents($purchaseValueCents);
    $payableAmount = procurementLocalFinalCents($payableCents);
    $actorId = (int) ($actor['id'] ?? 0);
    $scope = procurementRequestCanonicalFxFinalScopeSql();

    $update = $conn->prepare(
        "UPDATE procurement_requests
         SET currency = ?, po_number = ?, purchase_number = ?, purchase_number_normalized = ?,
             project_id = ?, project_code = ?, project_name = ?,
             supplier_id = ?, supplier_name = ?, supplier_ledger = ?,
             wht_status = ?, wht_rate = ?, wht_amount = ?,
             invoice_number = ?, invoice_date = ?, purchase_date = ?,
             purchase_subtotal = ?, purchase_discount = ?, purchase_other_charges = ?,
             purchase_vat_status = ?, purchase_vat_rate = ?, purchase_vat_amount = ?,
             purchase_value = ?, account_payable_amount = ?,
             payment_status_source = 'account', payment_status_updated_at = NOW(),
             updated_by = ?, updated_at = NOW(), version = version + 1
         WHERE legacy_source_id = ? AND {$scope}
           AND approval_status = 'Approved' AND handoff_status = 'In Account'
           AND payment_status = 'Pending' AND deleted_at IS NULL"
    );
    $projectId = (int) $project['id'];
    $supplierId = (int) $supplier['id'];
    $update->bind_param(
        'ssssississssssssssssssssii',
        $currency,
        $poNumber,
        $purchaseNumber,
        $normalizedPurchaseNumber,
        $projectId,
        $project['code'],
        $project['name'],
        $supplierId,
        $supplier['name'],
        $supplier['ledger'],
        $whtStatus,
        $whtRateValue,
        $whtAmount,
        $invoiceNumber,
        $invoiceDate,
        $purchaseDate,
        $subtotal,
        $discount,
        $otherCharges,
        $vatStatus,
        $vatRateValue,
        $vatAmount,
        $purchaseValue,
        $payableAmount,
        $actorId,
        $purchaseId
    );
    $update->execute();
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException(
            'ProcureDesk changed before the FX Fund Request update could be synchronized.',
            409
        );
    }
    $update->close();

    procurementFxFinalRecordEvent(
        $conn,
        $purchaseId,
        'account_updated_sent_purchase',
        [
            'id' => $actorId,
            'email' => (string) ($actor['email'] ?? 'account-user'),
        ],
        [
            'fx_fund_request_id' => $fundRequestId,
            'purchase_number' => $purchaseNumber,
            'po_number' => $poNumber !== '' ? $poNumber : null,
            'supplier_name' => $supplier['name'],
            'project_code' => $project['code'],
            'currency' => $currency,
            'purchase_value' => $purchaseValue,
            'account_payable_amount' => $payableAmount,
        ]
    );
    procurementRequestCanonicalSyncRequest(
        $conn,
        PROCUREMENT_REQUEST_TYPE_FX_FINAL,
        $purchaseId
    );

    return procurementFxFinalFetchRecord($conn, $purchaseId) ?? [];
}

function procurementFxFinalAssertFundRequestsCanBeDeleted(mysqli $conn, array $fundRequestIds): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $fundRequestIds))));
    if ($ids === []) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $requestType = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $stmt = $conn->prepare(
        "SELECT legacy_source_id, account_request_id FROM procurement_requests
         WHERE request_type = ? AND account_request_id IN ($placeholders)
           AND approval_status = 'Approved' AND handoff_status = 'In Account' AND deleted_at IS NULL"
    );
    $params = array_merge([$requestType], $ids);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($links !== []) {
        throw new RuntimeException(
            'ProcureDesk-linked FX Fund Requests cannot be deleted in Account. Return them to Procurement while they are still Pending.',
            409
        );
    }
}
