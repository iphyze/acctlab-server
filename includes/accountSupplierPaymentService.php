<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/procurementLocalFinalPurchaseService.php';
require_once __DIR__ . '/procurementSupplierFinancialAdjustmentService.php';
require_once __DIR__ . '/accountNotificationService.php';
require_once __DIR__ . '/accountPaymentReminderService.php';
require_once __DIR__ . '/accountPaymentStorageRuntimeReadService.php';
require_once __DIR__ . '/accountPaymentStorageCanonicalWriteService.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';

const ACCOUNT_SUPPLIER_PAYMENT_METHODS = ['Bank Instruction', 'GAPS', 'Union Bank Schedule', 'Manual'];
const ACCOUNT_SUPPLIER_COMPLETION_MODES = ['Notify', 'Immediate', 'Automatic'];
const ACCOUNT_SUPPLIER_PAYMENT_STATUSES = ['Pending', 'Processing', 'Paid', 'Failed', 'Cancelled'];
const ACCOUNT_SUPPLIER_BATCH_STATUSES = ['Processing', 'Awaiting Confirmation', 'Completed', 'Completed With Exceptions', 'Cancelled'];
const ACCOUNT_SUPPLIER_ITEM_STATUSES = ['Processing', 'Awaiting Confirmation', 'Paid', 'Failed', 'Delayed', 'Cancelled'];
const ACCOUNT_SUPPLIER_MAX_BATCH = 100;


function accountSupplierNormalizePaymentMethod(mixed $method, bool $allowManual = true): string
{
    $value = trim((string) $method);
    $normalized = strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);

    $aliases = [
        'bank instruction' => 'Bank Instruction',
        'instruction' => 'Bank Instruction',
        'prepare instruction' => 'Bank Instruction',
        'gaps' => 'GAPS',
        'prepare gaps' => 'GAPS',
        'union bank schedule' => 'Union Bank Schedule',
        'union schedule' => 'Union Bank Schedule',
        'union bank' => 'Union Bank Schedule',
        'union bank instruction' => 'Union Bank Schedule',
        'union' => 'Union Bank Schedule',
        'manual' => 'Manual',
        'manual payment' => 'Manual',
    ];

    $canonical = $aliases[$normalized] ?? '';
    if ($canonical === '' || (!$allowManual && $canonical === 'Manual')) {
        throw new RuntimeException('Processing Method is invalid.', 400);
    }

    return $canonical;
}

function accountSupplierPaymentMethodLabel(mixed $method): string
{
    $value = trim((string) $method);
    if ($value === '') {
        return 'Payment';
    }

    try {
        $canonical = accountSupplierNormalizePaymentMethod($value);
        return $canonical === 'Manual' ? 'Manual Payment' : $canonical;
    } catch (RuntimeException) {
        return $value;
    }
}

function accountSupplierPaymentOperationLabel(mixed $method): string
{
    return accountSupplierPaymentMethodLabel($method) . ' operation';
}

function accountSupplierTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function accountSupplierObjectType(mysqli $conn, string $object): ?string
{
    $stmt = $conn->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $object);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return $type !== null ? strtoupper((string) $type) : null;
}

function accountSupplierEnsureColumn(mysqli $conn, string $table, string $column, string $definition): void
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();

    if (!$exists) {
        if (accountSupplierObjectType($conn, $table) === 'VIEW') {
            throw new RuntimeException(
                'Supplier Payment compatibility view is missing required column ' . $column . '.',
                500
            );
        }
        if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            throw new RuntimeException('Unable to update Supplier Payment processing storage.', 500);
        }
    }
}

function accountSupplierEnsurePaymentStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    procurementEnsureLocalFinalPurchaseStorage($conn);

    $supplierColumns = [
        'processing_method' => "VARCHAR(30) NULL AFTER `payment_status`",
        'processing_reference' => "VARCHAR(160) NULL AFTER `processing_method`",
        'processing_started_at' => "DATETIME NULL AFTER `processing_reference`",
        'processing_business_days' => "TINYINT UNSIGNED NULL AFTER `processing_started_at`",
        'expected_completion_at' => "DATETIME NULL AFTER `processing_business_days`",
        'completion_mode' => "VARCHAR(30) NULL AFTER `expected_completion_at`",
        'payment_confirmation_status' => "VARCHAR(40) NOT NULL DEFAULT 'Not Scheduled' AFTER `completion_mode`",
        'amount_paid' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `payment_confirmation_status`",
        'supplier_credit_applied' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `amount_paid`",
        'cash_amount_paid' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `supplier_credit_applied`",
        'paid_at' => "DATETIME NULL AFTER `amount_paid`",
        'payment_reference' => "VARCHAR(160) NULL AFTER `paid_at`",
        'account_remarks' => "TEXT NULL AFTER `payment_reference`",
        'payment_batch_id' => "BIGINT UNSIGNED NULL AFTER `account_remarks`",
        'payment_updated_by' => "INT NULL AFTER `payment_batch_id`",
        'payment_updated_at' => "DATETIME NULL AFTER `payment_updated_by`",
    ];
    foreach ($supplierColumns as $column => $definition) {
        accountSupplierEnsureColumn($conn, 'supplier_fund_request_table', $column, $definition);
    }

    // Local Final Purchase payment metadata now lives on canonical procurement_requests.
    procurementRequestCanonicalLocalFinalAssertReady($conn);

    $canonicalPaymentStorage = accountPaymentStorageCanonicalRuntimeVerification($conn);
    if (($canonicalPaymentStorage['healthy'] ?? false) !== true) {
        throw new RuntimeException(
            accountPaymentStorageCanonicalVerificationFailureMessage($canonicalPaymentStorage)
                . ' Deploy the verified unified payment storage before using this workflow.',
            503
        );
    }

    accountSupplierEnsureColumn($conn, 'account_payment_batch_items', 'gross_amount', 'DECIMAL(18,2) NULL AFTER `amount`');
    accountSupplierEnsureColumn($conn, 'account_payment_batch_items', 'supplier_credit_applied', 'DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `gross_amount`');

    $conn->query(
        "UPDATE supplier_fund_request_table
         SET amount_paid = CASE WHEN payment_status = 'Paid' THEN CAST(amount AS DECIMAL(18,2)) ELSE 0.00 END,
             paid_at = CASE WHEN payment_status = 'Paid' AND paid_at IS NULL THEN created_at ELSE paid_at END,
             payment_confirmation_status = CASE
                 WHEN payment_status = 'Paid' THEN 'Confirmed'
                 WHEN payment_status = 'Processing' THEN 'Scheduled'
                 ELSE payment_confirmation_status
             END
         WHERE amount_paid = 0.00 OR amount_paid IS NULL"
    );

    accountNotificationEnsureStorage($conn);
    accountPaymentReminderEnsureStorage($conn);
    $ensured = true;
}

function accountSupplierLinkPaymentArtifacts(
    mysqli $conn,
    int $batchId,
    string $artifactType,
    array $artifactIds,
    array $actor,
    ?string $artifactRoute = null,
    array $supplierRequestIdsByArtifact = []
): array {
    accountSupplierEnsurePaymentStorage($conn);
    if ($batchId <= 0) {
        throw new RuntimeException('A valid payment operation is required before linking payment records.', 400);
    }

    $artifactType = trim($artifactType);
    if ($artifactType === '') {
        throw new RuntimeException('Payment record type is required.', 400);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $artifactIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        throw new RuntimeException('At least one payment record is required.', 400);
    }

    $actor = accountSupplierActor($actor);
    $route = $artifactRoute !== null ? trim($artifactRoute) : null;
    if ($route === '') {
        $route = null;
    }

    foreach ($ids as $artifactId) {
        $reference = $artifactType . ' #' . $artifactId;
        $mappedRequestIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($supplierRequestIdsByArtifact[$artifactId] ?? null)
                ? $supplierRequestIdsByArtifact[$artifactId]
                : []
        ), static fn(int $id): bool => $id > 0)));
        $requestIdsJson = $mappedRequestIds === []
            ? null
            : json_encode($mappedRequestIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        accountPaymentStorageUpsertArtifact(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            $batchId,
            [
                'artifact_type' => $artifactType,
                'artifact_id' => $artifactId,
                'artifact_reference' => $reference,
                'artifact_route' => $route,
                'request_ids_json' => $requestIdsJson,
                'created_by' => $actor['id'],
            ]
        );
    }

    return accountSupplierGetPaymentArtifactsFromLegacyStorage($conn, $batchId);
}

function accountSupplierGetPaymentArtifactsFromLegacyStorage(mysqli $conn, int $batchId): array
{
    return accountSupplierGetPaymentArtifacts($conn, $batchId);
}

function accountSupplierGetPaymentArtifacts(mysqli $conn, int $batchId): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $readSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $artifactSource = $readSources['artifacts'];
    $stmt = $conn->prepare(
        "SELECT id, batch_id, artifact_type, artifact_id, artifact_reference, artifact_route,
                supplier_request_ids_json, artifact_status, created_by, created_at
         FROM {$artifactSource} artifact
         WHERE artifact.batch_id = ? AND artifact.artifact_status = 'Active'
         ORDER BY artifact.id ASC"
    );
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function accountSupplierDecodeArtifactRequestIds(mixed $value): array
{
    if (is_array($value)) {
        $decoded = $value;
    } else {
        $decoded = json_decode((string) ($value ?? ''), true);
    }

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_unique(array_filter(
        array_map('intval', $decoded),
        static fn(int $id): bool => $id > 0
    )));
}

function accountSupplierFetchArtifactLinks(
    mysqli $conn,
    string $artifactType,
    array $artifactIds
): array {
    accountSupplierEnsurePaymentStorage($conn);
    $readSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $batchSource = $readSources['batches'];
    $itemSource = $readSources['items'];
    $artifactSource = $readSources['artifacts'];
    $ids = array_values(array_unique(array_filter(array_map(
        'intval',
        $artifactIds
    ), static fn(int $id): bool => $id > 0)));

    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 's' . str_repeat('i', count($ids));
    $params = array_merge([$artifactType], $ids);
    $stmt = $conn->prepare(
        "SELECT artifact.id AS artifact_link_id,
                artifact.artifact_id,
                artifact.batch_id,
                artifact.supplier_request_ids_json,
                artifact.artifact_status,
                payment_batch.batch_reference,
                payment_batch.processing_method,
                payment_batch.processing_reference,
                payment_batch.status AS payment_operation_status
         FROM {$artifactSource} artifact
         INNER JOIN {$batchSource} payment_batch
           ON payment_batch.id = artifact.batch_id
         WHERE artifact.artifact_type = ?
           AND artifact.artifact_id IN ($placeholders)
           AND artifact.artifact_status = 'Active'
         ORDER BY artifact.artifact_id ASC"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows === []) {
        return [];
    }

    $batchIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) $row['batch_id'],
        $rows
    )));
    $batchPlaceholders = implode(',', array_fill(0, count($batchIds), '?'));
    $batchTypes = str_repeat('i', count($batchIds));
    $itemsStmt = $conn->prepare(
        "SELECT item.batch_id, item.supplier_fund_request_id, item.status
         FROM {$itemSource} item
         WHERE item.batch_id IN ($batchPlaceholders)"
    );
    $itemsStmt->bind_param($batchTypes, ...$batchIds);
    $itemsStmt->execute();
    $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();

    $itemsByBatch = [];
    foreach ($items as $item) {
        $batchId = (int) $item['batch_id'];
        $requestId = (int) $item['supplier_fund_request_id'];
        $itemsByBatch[$batchId][$requestId] = (string) $item['status'];
    }

    foreach ($rows as &$row) {
        $batchId = (int) $row['batch_id'];
        $mappedRequestIds = accountSupplierDecodeArtifactRequestIds($row['supplier_request_ids_json'] ?? null);
        $batchItems = $itemsByBatch[$batchId] ?? [];
        $requestIds = $mappedRequestIds !== [] ? $mappedRequestIds : array_map('intval', array_keys($batchItems));

        $row['supplier_request_ids'] = array_values(array_unique($requestIds));
        $row['removable_after_exception'] =
            (string) ($row['payment_operation_status'] ?? '') === 'Completed With Exceptions';
        unset($row['supplier_request_ids_json']);
    }
    unset($row);

    return $rows;
}

function accountSupplierRemoveExceptionPaymentArtifacts(
    mysqli $conn,
    string $artifactType,
    array $artifactIds,
    array $actor,
    string $reason
): array {
    $actor = accountSupplierActor($actor);
    $links = accountSupplierFetchArtifactLinks($conn, $artifactType, $artifactIds);
    if ($links === []) {
        return [];
    }

    $blocked = array_values(array_filter(
        $links,
        static fn(array $row): bool => empty($row['removable_after_exception'])
    ));
    if ($blocked !== []) {
        $labels = array_map(
            static fn(array $row): string => '#' . (int) $row['artifact_id']
                . ' (' . (string) ($row['batch_reference'] ?? 'payment operation') . ')',
            $blocked
        );
        throw new RuntimeException(
            'Only schedule records linked to a Completed With Exceptions payment operation can be removed. '
            . 'Resolve or correct these records first: ' . implode(', ', $labels) . '.',
            409
        );
    }

    $linkIds = array_values(array_map(
        static fn(array $row): int => (int) $row['artifact_link_id'],
        $links
    ));
    $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
    $types = 'is' . str_repeat('i', count($linkIds));
    $params = array_merge([$actor['id'], $reason], $linkIds);
    $update = $conn->prepare(
        accountPaymentStorageUpdateByLegacyIdsSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "artifact",
            "artifact_status = 'Removed', removed_at = NOW(), removed_by = ?,
             removal_reason = ?",
            $placeholders,
            "artifact_status = 'Active'"
        )
    );
    $update->bind_param($types, ...$params);
    $update->execute();
    $update->close();

    return $links;
}

function accountSupplierActor(array $actor): array
{
    return [
        'id' => (int) ($actor['id'] ?? 0),
        'email' => trim((string) ($actor['email'] ?? 'system')) ?: 'system',
    ];
}

function accountSupplierValidateChoice(mixed $value, array $allowed, string $label): string
{
    $candidate = trim((string) $value);
    foreach ($allowed as $option) {
        if (strcasecmp($candidate, $option) === 0) {
            return $option;
        }
    }
    throw new RuntimeException($label . ' is invalid.', 400);
}

function accountSupplierRequiredText(array $data, string $field, string $label, int $max = 160): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException($label . ' is required.', 400);
    }
    if (strlen($value) > $max) {
        throw new RuntimeException($label . ' is too long.', 400);
    }
    return $value;
}

function accountSupplierOptionalText(array $data, string $field, int $max = 2000): ?string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        return null;
    }
    if (strlen($value) > $max) {
        throw new RuntimeException(ucwords(str_replace('_', ' ', $field)) . ' is too long.', 400);
    }
    return $value;
}

function accountSupplierBatchIds(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Select at least one Supplier Fund Request.', 400);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $value))));
    if ($ids === []) {
        throw new RuntimeException('No valid Supplier Fund Request IDs were supplied.', 400);
    }
    if (count($ids) > ACCOUNT_SUPPLIER_MAX_BATCH) {
        throw new RuntimeException('A maximum of 100 requests can be processed at once.', 400);
    }
    return $ids;
}

function accountSupplierBusinessDueAt(int $businessDays, ?DateTimeImmutable $start = null): string
{
    if ($businessDays < 1 || $businessDays > 10) {
        throw new RuntimeException('Processing period must be between 1 and 10 business days.', 400);
    }
    $timezone = new DateTimeZone('Africa/Lagos');
    $date = $start ? $start->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);
    $remaining = $businessDays;
    while ($remaining > 0) {
        $date = $date->modify('+1 day');
        $weekday = (int) $date->format('N');
        if ($weekday <= 5) {
            $remaining--;
        }
    }
    return $date->format('Y-m-d H:i:s');
}

function accountSupplierGenerateBatchReference(): string
{
    return 'PAY-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function accountSupplierRecordEvent(
    mysqli $conn,
    int $supplierRequestId,
    ?int $batchId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $actor = accountSupplierActor($actor);
    $detailsJson = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return workflowEventRecordPayment(
        $conn,
        'local_final_purchase',
        $supplierRequestId,
        $batchId,
        $eventType,
        $actor['id'],
        $actor['email'],
        $detailsJson
    );
}

function accountSupplierCreateNotifications(
    mysqli $conn,
    string $type,
    string $title,
    string $message,
    array $payload,
    string $dedupePrefix
): int {
    return accountNotificationPublish($conn, [
        'type' => $type,
        'action_key' => $type,
        'category' => 'supplier_payment',
        'severity' => str_contains($type, 'skipped') || str_contains($type, 'exception')
            ? 'warning'
            : (str_contains($type, 'completed') ? 'success' : (str_contains($type, 'due') ? 'warning' : 'info')),
        'title' => $title,
        'message' => $message,
        'actor' => ['id' => 0, 'email' => 'system'],
        'entity_type' => 'supplier_payment_batch',
        'entity_id' => isset($payload['batch_id']) ? (string) $payload['batch_id'] : '',
        'route' => (string) ($payload['route'] ?? '/payments/processing'),
        'payload' => $payload,
        'dedupe_key' => $dedupePrefix,
    ]);
}

function accountSupplierFetchRequestsForUpdate(mysqli $conn, array $requestIds): array
{
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));
    $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
    $stmt = $conn->prepare(
        "SELECT sfr.*,
                sfr.procurement_purchase_id AS supplier_procurement_purchase_id,
                p.legacy_source_id AS linked_procurement_purchase_id,
                p.approval_status AS procurement_approval_status,
                p.handoff_status AS procurement_handoff_status
         FROM supplier_fund_request_table sfr
         LEFT JOIN procurement_requests p
           ON p.legacy_source_id = sfr.procurement_purchase_id
              AND p.account_request_id = sfr.id
              AND {$localFinalScope}
              AND p.deleted_at IS NULL
         WHERE sfr.id IN ($placeholders)
         FOR UPDATE"
    );
    $stmt->bind_param($types, ...$requestIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }
    return $byId;
}

function accountSupplierAssertRequestsCanProcess(mysqli $conn, array $requestIds): array
{
    $rows = accountSupplierFetchRequestsForUpdate($conn, $requestIds);
    $errors = [];
    foreach ($requestIds as $requestId) {
        $row = $rows[$requestId] ?? null;
        if (!$row) {
            $errors[] = "Request $requestId was not found.";
            continue;
        }
        if ((string) $row['payment_status'] !== 'Pending') {
            $errors[] = "Request $requestId is {$row['payment_status']} and cannot be added to a processing batch.";
        }
        if (!empty($row['supplier_procurement_purchase_id'])
            && (empty($row['linked_procurement_purchase_id'])
                || (string) $row['procurement_approval_status'] !== 'Approved'
                || (string) $row['procurement_handoff_status'] !== 'In Account')) {
            $errors[] = "Request $requestId is no longer an active Procurement handoff.";
        }
        $activeStmt = $conn->prepare(
            "SELECT legacy_source_id AS id
             FROM account_payment_batch_items
             WHERE request_type = 'local_final_purchase'
               AND request_id = ?
               AND status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
             LIMIT 1"
        );
        $activeStmt->bind_param('i', $requestId);
        $activeStmt->execute();
        $hasActive = $activeStmt->get_result()->num_rows > 0;
        $activeStmt->close();
        if ($hasActive) {
            $errors[] = "Request $requestId is already in an active processing batch.";
        }
    }
    if ($errors !== []) {
        throw new RuntimeException(implode(' ', $errors), 409);
    }
    return $rows;
}


function accountSupplierPrepareCreditOffsets(
    mysqli $conn,
    array $requestIds,
    int $actorId
): array {
    accountSupplierEnsurePaymentStorage($conn);
    $requestIds = accountSupplierBatchIds($requestIds);
    $requests = accountSupplierAssertRequestsCanProcess($conn, $requestIds);
    $plans = [];
    foreach ($requestIds as $requestId) {
        $request = $requests[$requestId] ?? [];
        $gross = procurementLocalFinalCents(
            procurementLocalFinalMoneyToCents($request['amount'] ?? 0, 'Supplier Fund Request Amount')
        );
        $supplierId = (int) ($request['supplier_id'] ?? 0);
        $plan = $supplierId > 0
            ? procurementSupplierReserveCreditForPayment(
                $conn,
                $supplierId,
                'NGN',
                $gross,
                'local_final_purchase',
                $requestId,
                $actorId
            )
            : ['gross_amount' => $gross, 'credit_amount' => '0.00', 'cash_required' => $gross];

        $update = $conn->prepare(
            'UPDATE supplier_fund_request_table
             SET supplier_credit_applied = ?, cash_amount_paid = 0.00,
                 payment_updated_by = ?, payment_updated_at = NOW()
             WHERE id = ?'
        );
        $update->bind_param('sii', $plan['credit_amount'], $actorId, $requestId);
        $update->execute();
        $update->close();
        $plans[$requestId] = $plan;
    }
    return $plans;
}

function accountSupplierCreditPlanCashCents(array $plan): int
{
    return procurementLocalFinalMoneyToCents($plan['cash_required'] ?? 0, 'Cash Payment Required', true);
}

function accountSupplierNormalizeAllocationAmounts(
    array $allocations,
    array $plans,
    string $requestKey = 'supplier_request_ids',
    string $amountKey = 'amount'
): array {
    foreach ($allocations as &$allocation) {
        if (!is_array($allocation)) continue;
        $rawIds = $allocation[$requestKey]
            ?? $allocation['source_request_ids']
            ?? $allocation['request_ids']
            ?? [];
        $ids = is_array($rawIds)
            ? array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0)))
            : [];
        if ($ids === []) continue;
        $cashCents = 0;
        foreach ($ids as $requestId) {
            $cashCents += accountSupplierCreditPlanCashCents($plans[$requestId] ?? []);
        }
        $allocation[$amountKey] = procurementLocalFinalCents($cashCents);
    }
    unset($allocation);
    return $allocations;
}

function accountSupplierPreviewCreditOffsets(mysqli $conn, array $requestIds): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $requestIds = accountSupplierBatchIds($requestIds);
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));
    $stmt = $conn->prepare(
        "SELECT id, supplier_id, suppliers_name, amount, payment_status
         FROM supplier_fund_request_table
         WHERE id IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$requestIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    $availableBySupplier = [];
    $items = [];
    $grossTotal = 0;
    $creditTotal = 0;
    $cashTotal = 0;
    foreach ($requestIds as $requestId) {
        $row = $byId[$requestId] ?? null;
        if (!$row) {
            throw new RuntimeException("Request $requestId was not found.", 404);
        }
        if ((string) ($row['payment_status'] ?? '') !== 'Pending') {
            throw new RuntimeException("Request $requestId is no longer Pending.", 409);
        }
        $grossCents = procurementLocalFinalMoneyToCents($row['amount'] ?? 0, 'Supplier Fund Request Amount');
        $supplierId = (int) ($row['supplier_id'] ?? 0);

        $reservation = procurementSupplierCreditReservationTotals(
            $conn,
            'local_final_purchase',
            $requestId
        );
        $reservedCents = procurementLocalFinalMoneyToCents(
            $reservation['reserved_amount'] ?? 0,
            'Reserved Supplier Offset',
            true
        );
        $appliedCents = procurementLocalFinalMoneyToCents(
            $reservation['applied_amount'] ?? 0,
            'Applied Supplier Offset',
            true
        );
        $existingOffsetCents = min($grossCents, $reservedCents + $appliedCents);

        if ($existingOffsetCents > 0) {
            $creditCents = $existingOffsetCents;
        } else {
            $identity = $supplierId > 0
                ? procurementSupplierAdjustmentResolveSupplierIdentity($conn, $supplierId)
                : ['canonical_id' => 0, 'ledger_number' => 0];
            $supplierKey = (int) ($identity['canonical_id'] ?? $supplierId);
            if (!array_key_exists($supplierKey, $availableBySupplier)) {
                $availableBySupplier[$supplierKey] = $supplierId > 0
                    ? procurementLocalFinalMoneyToCents(
                        procurementSupplierAvailableCreditForSupplier($conn, $supplierId, 'NGN'),
                        'Available Supplier Credit',
                        true
                    )
                    : 0;
            }
            $creditCents = min($grossCents, max(0, (int) $availableBySupplier[$supplierKey]));
            $availableBySupplier[$supplierKey] -= $creditCents;
        }

        $cashCents = max(0, $grossCents - $creditCents);
        $items[] = [
            'request_id' => $requestId,
            'supplier_id' => $supplierId,
            'supplier_name' => (string) ($row['suppliers_name'] ?? ''),
            'gross_amount' => procurementLocalFinalCents($grossCents),
            'supplier_credit_applied' => procurementLocalFinalCents($creditCents),
            'cash_required' => procurementLocalFinalCents($cashCents),
        ];
        $grossTotal += $grossCents;
        $creditTotal += $creditCents;
        $cashTotal += $cashCents;
    }

    return [
        'items' => $items,
        'gross_amount' => procurementLocalFinalCents($grossTotal),
        'supplier_credit_applied' => procurementLocalFinalCents($creditTotal),
        'cash_required' => procurementLocalFinalCents($cashTotal),
    ];
}

function accountSupplierAssertFullPaymentAllocations(
    mysqli $conn,
    array $requestIds,
    array $allocations
): void {
    $requestIds = accountSupplierBatchIds($requestIds);
    if (!is_array($allocations) || $allocations === []) {
        throw new RuntimeException('Instruction payment rows are required.', 400);
    }

    $requests = accountSupplierAssertRequestsCanProcess($conn, $requestIds);
    $operationIds = array_fill_keys($requestIds, true);
    $seen = [];

    foreach ($allocations as $index => $allocation) {
        if (!is_array($allocation)) {
            throw new RuntimeException('Instruction row ' . ($index + 1) . ' is invalid.', 400);
        }
        $rawIds = $allocation['supplier_request_ids'] ?? $allocation['request_ids'] ?? [];
        if (!is_array($rawIds) || $rawIds === []) {
            continue;
        }
        $linkedIds = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
        if ($linkedIds === []) {
            throw new RuntimeException('Instruction row ' . ($index + 1) . ' has no valid Supplier Fund Request IDs.', 400);
        }

        $expectedCents = 0;
        foreach ($linkedIds as $requestId) {
            if (!isset($operationIds[$requestId])) {
                throw new RuntimeException("Instruction row " . ($index + 1) . " includes request $requestId outside this operation.", 409);
            }
            if (isset($seen[$requestId])) {
                throw new RuntimeException("Supplier Fund Request $requestId is linked to more than one instruction row.", 409);
            }
            $seen[$requestId] = true;
            $grossCents = procurementLocalFinalMoneyToCents(
                $requests[$requestId]['amount'] ?? 0,
                'Supplier Fund Request Amount'
            );
            $creditCents = procurementLocalFinalMoneyToCents(
                $requests[$requestId]['supplier_credit_applied'] ?? 0,
                'Supplier Credit Applied',
                true
            );
            $expectedCents += max(0, $grossCents - $creditCents);
        }

        $instructionCents = procurementLocalFinalMoneyToCents(
            $allocation['amount'] ?? null,
            'Instruction Row Amount',
            false
        );
        if ($instructionCents !== $expectedCents) {
            throw new RuntimeException(
                'Instruction row ' . ($index + 1) . ' must equal the net cash payable after supplier credit for its linked request(s).',
                409
            );
        }
    }

    $missing = array_values(array_diff($requestIds, array_map('intval', array_keys($seen))));
    if ($missing !== []) {
        throw new RuntimeException(
            'Every selected Supplier Fund Request must remain linked to an instruction row. Missing request ID(s): ' . implode(', ', $missing) . '.',
            409
        );
    }
}

function accountSupplierCreatePaymentBatch(mysqli $conn, array $data, array $actor, bool $manageTransaction = true): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $requestIds = accountSupplierBatchIds($data['request_ids'] ?? null);
    $method = accountSupplierNormalizePaymentMethod($data['processing_method'] ?? '', false);
    $reference = accountSupplierRequiredText($data, 'processing_reference', 'Processing Reference');
    $completionMode = accountSupplierValidateChoice(
        $data['completion_mode'] ?? 'Notify',
        ACCOUNT_SUPPLIER_COMPLETION_MODES,
        'Completion Mode'
    );
    $businessDays = (int) ($data['processing_business_days'] ?? 0);
    if ($completionMode === 'Immediate') {
        $businessDays = 0;
        $dueAt = date('Y-m-d H:i:s');
    } else {
        if ($businessDays < 1 || $businessDays > 5) {
            throw new RuntimeException('Processing period must be between 1 and 5 business days.', 400);
        }
        $dueAt = accountSupplierBusinessDueAt($businessDays);
    }

    $remarks = accountSupplierOptionalText($data, 'account_remarks');
    $actor = accountSupplierActor($actor);
    $batchReference = accountSupplierGenerateBatchReference();
    $isImmediate = $completionMode === 'Immediate';
    $batchStatus = $isImmediate ? 'Completed' : 'Processing';
    $completedAt = $isImmediate ? date('Y-m-d H:i:s') : null;

    if ($manageTransaction) {
        $conn->begin_transaction();
    }

    try {
        $requests = accountSupplierAssertRequestsCanProcess($conn, $requestIds);
        $creditPlans = accountSupplierPrepareCreditOffsets($conn, $requestIds, $actor['id']);

        $totalCashCents = 0;
        foreach ($requestIds as $requestId) {
            $totalCashCents += accountSupplierCreditPlanCashCents($creditPlans[$requestId] ?? []);
        }
        $totalCash = procurementLocalFinalCents($totalCashCents);
        $itemCount = count($requestIds);

        $batchId = accountPaymentStorageCreateBatch(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            [
                'batch_reference' => $batchReference,
                'processing_method' => $method,
                'processing_reference' => $reference,
                'processing_business_days' => $businessDays,
                'expected_completion_at' => $dueAt,
                'completion_mode' => $completionMode,
                'status' => $batchStatus,
                'item_count' => $itemCount,
                'total_amount' => $totalCash,
                'account_remarks' => $remarks,
                'created_by' => $actor['id'],
                'updated_by' => $actor['id'],
                'completed_at' => $completedAt,
            ]
        );

        if ($isImmediate) {
            $requestUpdate = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Paid', processing_method = ?, processing_reference = ?,
                     processing_started_at = NOW(), processing_business_days = ?, expected_completion_at = ?,
                     completion_mode = ?, payment_confirmation_status = 'Confirmed',
                     amount_paid = CAST(amount AS DECIMAL(18,2)), supplier_credit_applied = ?,
                     cash_amount_paid = ?, paid_at = NOW(), payment_reference = ?,
                     account_remarks = ?, payment_batch_id = ?, payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Pending'"
            );
        } else {
            $requestUpdate = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Processing', processing_method = ?, processing_reference = ?,
                     processing_started_at = NOW(), processing_business_days = ?, expected_completion_at = ?,
                     completion_mode = ?, payment_confirmation_status = 'Scheduled', amount_paid = 0.00,
                     supplier_credit_applied = ?, cash_amount_paid = 0.00,
                     paid_at = NULL, payment_reference = NULL, account_remarks = ?, payment_batch_id = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Pending'"
            );
        }

        foreach ($requestIds as $requestId) {
            $grossAmount = procurementLocalFinalCents(
                procurementLocalFinalMoneyToCents($requests[$requestId]['amount'], 'Supplier Fund Request Amount')
            );
            $plan = $creditPlans[$requestId] ?? [
                'gross_amount' => $grossAmount,
                'credit_amount' => '0.00',
                'cash_required' => $grossAmount,
            ];
            $creditAmount = procurementLocalFinalCents(
                procurementLocalFinalMoneyToCents($plan['credit_amount'] ?? 0, 'Supplier Credit Applied', true)
            );
            $cashAmount = procurementLocalFinalCents(
                procurementLocalFinalMoneyToCents($plan['cash_required'] ?? $grossAmount, 'Cash Payment Required', true)
            );

            $itemId = accountPaymentStorageCreateItem(
                $conn,
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                $batchId,
                [
                    'request_id' => $requestId,
                    'amount' => $cashAmount,
                    'amount_paid' => $isImmediate ? $cashAmount : 0.00,
                    'status' => $isImmediate ? 'Paid' : 'Processing',
                    'status_reason' => null,
                    'processing_started_at' => date('Y-m-d H:i:s'),
                    'expected_completion_at' => $dueAt,
                    'paid_at' => $isImmediate ? date('Y-m-d H:i:s') : null,
                    'payment_reference' => $isImmediate ? $reference : null,
                    'updated_by' => $actor['id'],
                ]
            );

            $meta = $conn->prepare(
                "UPDATE account_payment_batch_items
                 SET gross_amount = ?, supplier_credit_applied = ?
                 WHERE request_type = 'local_final_purchase' AND legacy_source_id = ?"
            );
            $meta->bind_param('ssi', $grossAmount, $creditAmount, $itemId);
            $meta->execute();
            $meta->close();

            procurementSupplierAttachCreditReservationsToBatch(
                $conn,
                'local_final_purchase',
                $requestId,
                $batchId
            );

            if ($isImmediate) {
                $requestUpdate->bind_param(
                    'ssissssssiii',
                    $method,
                    $reference,
                    $businessDays,
                    $dueAt,
                    $completionMode,
                    $creditAmount,
                    $cashAmount,
                    $reference,
                    $remarks,
                    $batchId,
                    $actor['id'],
                    $requestId
                );
            } else {
                $requestUpdate->bind_param(
                    'ssissssiii',
                    $method,
                    $reference,
                    $businessDays,
                    $dueAt,
                    $completionMode,
                    $creditAmount,
                    $remarks,
                    $batchId,
                    $actor['id'],
                    $requestId
                );
            }

            $requestUpdate->execute();
            if ($requestUpdate->affected_rows !== 1) {
                throw new RuntimeException("Request $requestId changed while the payment operation was being created.", 409);
            }

            if ($isImmediate) {
                procurementSupplierFinalizeCreditReservations(
                    $conn,
                    'local_final_purchase',
                    $requestId,
                    $actor['id'],
                    $batchId,
                    $reference
                );
            }

            accountSupplierRecordEvent(
                $conn,
                $requestId,
                $batchId,
                $isImmediate ? 'payment_completed_immediately' : 'processing_started',
                $actor,
                [
                    'processing_method' => $method,
                    'processing_reference' => $reference,
                    'processing_business_days' => $businessDays,
                    'expected_completion_at' => $dueAt,
                    'completion_mode' => $completionMode,
                    'gross_amount' => $grossAmount,
                    'supplier_credit_applied' => $creditAmount,
                    'cash_amount' => $cashAmount,
                    'amount_paid' => $isImmediate ? $grossAmount : '0.00',
                    'payment_reference' => $isImmediate ? $reference : null,
                    'account_remarks' => $remarks,
                ]
            );
        }
        $requestUpdate->close();

        procurementSyncLocalFinalPurchasePaymentDetails(
            $conn,
            $requestIds,
            $actor['id'],
            $isImmediate ? 'account_payment_completed_immediately' : 'account_processing_started'
        );

        $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $methodLabel = accountSupplierPaymentMethodLabel($method);
        $operationLabel = accountSupplierPaymentOperationLabel($method);
        $action = $actor['email'] . " created $operationLabel $batchReference with $itemCount request(s) using $completionMode completion. Net cash: $totalCash.";
        $log->bind_param('iss', $actor['id'], $action, $actor['email']);
        $log->execute();
        $log->close();

        $methodQuery = rawurlencode($method);
        $notificationRoute = $isImmediate
            ? '/payments/fund-request/supplier?payment_status=Paid'
            : "/payments/processing?batch_id=$batchId&processing_method=$methodQuery";
        $notificationTitle = $isImmediate
            ? "$methodLabel payments marked Paid"
            : "$methodLabel payments moved to Processing";
        $notificationMessage = $isImmediate
            ? $actor['email'] . " prepared $operationLabel $batchReference and marked $itemCount supplier request(s) Paid. Net cash: $totalCash."
            : $actor['email'] . " prepared $operationLabel $batchReference and moved $itemCount supplier request(s) to Processing. Net cash: $totalCash. Expected completion: $dueAt.";
        accountNotificationPublishSupplierAction(
            $conn,
            $isImmediate ? 'supplier_payment_completed_immediately' : 'supplier_processing_started',
            $notificationTitle,
            $notificationMessage,
            $requestIds,
            $actor,
            $notificationRoute,
            $isImmediate ? 'success' : 'info',
            [
                'batch_id' => $batchId,
                'batch_reference' => $batchReference,
                'processing_method' => $method,
                'processing_reference' => $reference,
                'completion_mode' => $completionMode,
                'processing_business_days' => $businessDays,
                'expected_completion_at' => $dueAt,
                'net_cash_amount' => $totalCash,
            ]
        );

        if ($manageTransaction) {
            $conn->commit();
        }
        return accountSupplierGetBatchFromLegacyStorage($conn, $batchId);
    } catch (Throwable $error) {
        if ($manageTransaction) {
            $conn->rollback();
        }
        throw $error;
    }
}

function accountSupplierGetBatchFromLegacyStorage(mysqli $conn, int $batchId): array
{
    return accountSupplierGetBatch($conn, $batchId);
}

function accountSupplierGetBatch(mysqli $conn, int $batchId): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $readSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $batchSource = $readSources['batches'];
    $itemSource = $readSources['items'];
    $stmt = $conn->prepare(
        "SELECT b.*,
                CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, '')) AS created_by_name
         FROM {$batchSource} b
         LEFT JOIN user_table u ON u.id = b.created_by
         WHERE b.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$batch) {
        throw new RuntimeException('Payment batch not found.', 404);
    }
    $itemsStmt = $conn->prepare(
        "SELECT i.*, sfr.suppliers_name, sfr.purchase_number, sfr.po_number,
                sfr.invoice_number, sfr.project_code, sfr.payment_status,
                sfr.processing_method, sfr.processing_reference, sfr.completion_mode,
                sfr.payment_confirmation_status, sfr.account_remarks,
                sfr.procurement_source, sfr.procurement_purchase_id
         FROM {$itemSource} i
         INNER JOIN supplier_fund_request_table sfr ON sfr.id = i.supplier_fund_request_id
         WHERE i.batch_id = ? ORDER BY i.id ASC"
    );
    $itemsStmt->bind_param('i', $batchId);
    $itemsStmt->execute();
    $batch['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
    $batch['artifacts'] = accountSupplierGetPaymentArtifacts($conn, $batchId);
    return $batch;
}

function accountSupplierListBatches(mysqli $conn, array $query): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $readSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $batchSource = $readSources['batches'];
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = (int) ($query['limit'] ?? 20);
    if (!in_array($limit, [10, 20, 50, 100], true)) {
        $limit = 20;
    }
    $offset = ($page - 1) * $limit;
    $status = trim((string) ($query['status'] ?? ''));
    $search = trim((string) ($query['search'] ?? ''));
    $where = ['1=1'];
    $params = [];
    $types = '';
    if ($status !== '' && strcasecmp($status, 'all') !== 0) {
        accountSupplierValidateChoice($status, ACCOUNT_SUPPLIER_BATCH_STATUSES, 'Batch Status');
        $where[] = 'b.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($search !== '') {
        $where[] = '(b.batch_reference LIKE ? OR b.processing_reference LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }
    $whereSql = implode(' AND ', $where);
    $count = $conn->prepare("SELECT COUNT(*) AS total FROM {$batchSource} b WHERE $whereSql");
    if ($params !== []) {
        $count->bind_param($types, ...$params);
    }
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $stmt = $conn->prepare(
        "SELECT b.*, CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, '')) AS created_by_name
         FROM {$batchSource} b
         LEFT JOIN user_table u ON u.id = b.created_by
         WHERE $whereSql ORDER BY b.created_at DESC LIMIT ? OFFSET ?"
    );
    $allParams = array_merge($params, [$limit, $offset]);
    $allTypes = $types . 'ii';
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [
        'data' => $rows,
        'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
    ];
}

function accountSupplierReviewItems(mysqli $conn, array $query): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $readSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $batchSource = $readSources['batches'];
    $itemSource = $readSources['items'];
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = (int) ($query['limit'] ?? 20);
    if (!in_array($limit, [10, 20, 50, 100], true)) {
        $limit = 20;
    }
    $offset = ($page - 1) * $limit;
    $batchId = (int) ($query['batch_id'] ?? 0);
    $status = trim((string) ($query['status'] ?? 'active'));
    $processingMethod = trim((string) ($query['processing_method'] ?? $query['method'] ?? ''));
    $search = trim((string) ($query['search'] ?? ''));
    $where = ['1=1'];
    $params = [];
    $types = '';
    if ($batchId > 0) {
        $where[] = 'i.batch_id = ?';
        $params[] = $batchId;
        $types .= 'i';
    }
    if ($processingMethod !== '' && strcasecmp($processingMethod, 'all') !== 0) {
        $processingMethod = accountSupplierNormalizePaymentMethod($processingMethod);
        $where[] = 'b.processing_method = ?';
        $params[] = $processingMethod;
        $types .= 's';
    }
    if (strcasecmp($status, 'active') === 0) {
        $where[] = "i.status IN ('Processing', 'Awaiting Confirmation', 'Delayed')";
        $where[] = "sfr.payment_status = 'Processing'";
    } elseif ($status !== '' && strcasecmp($status, 'all') !== 0) {
        accountSupplierValidateChoice($status, ACCOUNT_SUPPLIER_ITEM_STATUSES, 'Item Status');
        $where[] = 'i.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($search !== '') {
        $where[] = '(sfr.suppliers_name LIKE ? OR sfr.purchase_number LIKE ? OR sfr.po_number LIKE ? OR sfr.invoice_number LIKE ? OR sfr.project_code LIKE ? OR b.batch_reference LIKE ? OR b.processing_reference LIKE ? OR b.processing_method LIKE ?)';
        $like = '%' . $search . '%';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }
    $whereSql = implode(' AND ', $where);
    $from = "FROM {$itemSource} i
             INNER JOIN {$batchSource} b ON b.id = i.batch_id
             INNER JOIN supplier_fund_request_table sfr ON sfr.id = i.supplier_fund_request_id";
    $count = $conn->prepare("SELECT COUNT(*) AS total $from WHERE $whereSql");
    if ($params !== []) {
        $count->bind_param($types, ...$params);
    }
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $stmt = $conn->prepare(
        "SELECT i.*, b.batch_reference, b.processing_method, b.processing_reference,
                b.completion_mode, b.status AS batch_status,
                sfr.suppliers_name, sfr.purchase_number, sfr.po_number, sfr.invoice_number,
                sfr.project_code, sfr.payment_status, sfr.amount AS payable_amount,
                    sfr.supplier_credit_applied, sfr.cash_amount_paid,
                sfr.procurement_source, sfr.procurement_purchase_id,
                'supplier_fund_request' AS source_type,
                'Supplier Fund Request' AS source_label
         $from WHERE $whereSql ORDER BY i.expected_completion_at ASC, i.id ASC LIMIT ? OFFSET ?"
    );
    $allParams = array_merge($params, [$limit, $offset]);
    $allTypes = $types . 'ii';
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [
        'data' => $rows,
        'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
    ];
}

function accountSupplierUpdateBatchStatus(mysqli $conn, int $batchId, int $actorId): void
{
    $stmt = $conn->prepare(
        "SELECT
            SUM(item.status = 'Processing') AS processing_count,
            SUM(item.status = 'Awaiting Confirmation') AS awaiting_count,
            SUM(item.status = 'Paid') AS paid_count,
            SUM(item.status IN ('Failed', 'Cancelled', 'Delayed')) AS exception_count,
            COUNT(*) AS total,
            MAX(CASE WHEN item.status = 'Processing' THEN item.expected_completion_at ELSE NULL END) AS next_due_at
         FROM account_payment_batch_items item
         INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
         WHERE item.request_type = 'local_final_purchase'
           AND batch.request_type = 'local_final_purchase'
           AND batch.legacy_source_id = ?"
    );
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $processing = (int) ($summary['processing_count'] ?? 0);
    $awaiting = (int) ($summary['awaiting_count'] ?? 0);
    $paid = (int) ($summary['paid_count'] ?? 0);
    $exceptions = (int) ($summary['exception_count'] ?? 0);
    $total = (int) ($summary['total'] ?? 0);

    if ($awaiting > 0) {
        $status = 'Awaiting Confirmation';
        $completedAt = null;
    } elseif ($processing > 0) {
        $status = 'Processing';
        $completedAt = null;
    } elseif ($paid === $total && $total > 0) {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    } else {
        $status = 'Completed With Exceptions';
        $completedAt = date('Y-m-d H:i:s');
    }
    $nextDueAt = $summary['next_due_at'] ?? null;
    $update = $conn->prepare(
        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "batch",
            "status = ?, updated_by = ?, completed_at = ?,
             expected_completion_at = COALESCE(?, expected_completion_at)"
        )
    );
    $update->bind_param('sissi', $status, $actorId, $completedAt, $nextDueAt, $batchId);
    $update->execute();
    $update->close();
}

function accountSupplierFetchActivePaymentItemsForRequests(mysqli $conn, array $requestIds): array
{
    if ($requestIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));
    $itemColumns = accountPaymentStorageCanonicalItemLegacyColumns(
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
        'i',
        'b'
    );
    $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
    $stmt = $conn->prepare(
        "SELECT {$itemColumns}, b.processing_reference AS batch_processing_reference,
                sfr.payment_status, sfr.amount AS payable_amount,
                    sfr.supplier_credit_applied, sfr.cash_amount_paid,
                sfr.procurement_purchase_id, p.legacy_source_id AS linked_purchase_id,
                p.approval_status AS procurement_approval_status,
                p.handoff_status AS procurement_handoff_status
         FROM account_payment_batch_items i
         INNER JOIN account_payment_batches b ON b.id = i.batch_id
         INNER JOIN supplier_fund_request_table sfr ON sfr.id = i.request_id
         LEFT JOIN procurement_requests p
           ON p.legacy_source_id = sfr.procurement_purchase_id
          AND p.account_request_id = sfr.id
          AND {$localFinalScope}
          AND p.deleted_at IS NULL
         WHERE i.request_type = 'local_final_purchase'
           AND b.request_type = 'local_final_purchase'
           AND i.request_id IN ($placeholders)
           AND i.status IN ('Processing', 'Awaiting Confirmation', 'Delayed')
         ORDER BY i.legacy_source_id DESC
         FOR UPDATE"
    );
    $stmt->bind_param($types, ...$requestIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byRequest = [];
    foreach ($rows as $row) {
        $requestId = (int) $row['supplier_fund_request_id'];
        if (isset($byRequest[$requestId])) {
            throw new RuntimeException(
                "Supplier Fund Request $requestId belongs to more than one active payment operation. Resolve the duplicate processing records before continuing.",
                409
            );
        }
        $byRequest[$requestId] = $row;
    }

    return $byRequest;
}

function accountSupplierAssertLinkedPurchaseCanComplete(array $row): void
{
    if ((int) ($row['procurement_purchase_id'] ?? 0) <= 0) {
        return;
    }

    if (empty($row['linked_purchase_id'])
        || (string) ($row['procurement_approval_status'] ?? '') !== 'Approved'
        || (string) ($row['procurement_handoff_status'] ?? '') !== 'In Account') {
        throw new RuntimeException(
            'The linked Procurement handoff is no longer eligible for payment confirmation.',
            409
        );
    }
}

function accountSupplierCompleteActivePaymentItem(
    mysqli $conn,
    array $row,
    array $actor,
    ?string $paymentReference = null,
    string $source = 'processing_review'
): array {
    $actor = accountSupplierActor($actor);
    $itemId = (int) ($row['id'] ?? 0);
    $requestId = (int) ($row['supplier_fund_request_id'] ?? 0);
    $batchId = (int) ($row['batch_id'] ?? 0);

    if ($itemId <= 0 || $requestId <= 0 || $batchId <= 0) {
        throw new RuntimeException('The active payment operation is invalid.', 409);
    }
    if (!in_array((string) ($row['status'] ?? ''), ['Processing', 'Awaiting Confirmation', 'Delayed'], true)) {
        throw new RuntimeException('This payment is no longer eligible for confirmation.', 409);
    }
    if ((string) ($row['payment_status'] ?? '') !== 'Processing') {
        throw new RuntimeException('The linked Supplier Fund Request is no longer Processing.', 409);
    }

    accountSupplierAssertLinkedPurchaseCanComplete($row);

    $reference = trim((string) ($paymentReference ?? ''));
    if ($reference === '') {
        $reference = trim((string) ($row['batch_processing_reference'] ?? ''));
    }
    if ($reference === '') {
        throw new RuntimeException('A payment reference is required to complete this processing payment.', 409);
    }

    $grossAmount = procurementLocalFinalCents(
        procurementLocalFinalMoneyToCents($row['payable_amount'] ?? 0, 'Supplier Fund Request Amount')
    );
    $creditAmount = procurementLocalFinalCents(
        procurementLocalFinalMoneyToCents($row['supplier_credit_applied'] ?? 0, 'Supplier Credit Applied', true)
    );
    $cashAmount = procurementLocalFinalCents(max(
        0,
        procurementLocalFinalMoneyToCents($grossAmount, 'Supplier Fund Request Amount') -
        procurementLocalFinalMoneyToCents($creditAmount, 'Supplier Credit Applied', true)
    ));

    $itemUpdate = $conn->prepare(
        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Paid', amount_paid = ?, paid_at = NOW(), payment_reference = ?,
             status_reason = NULL, updated_by = ?",
            "status IN ('Processing', 'Awaiting Confirmation', 'Delayed')"
        )
    );
    $itemUpdate->bind_param('dsii', $cashAmount, $reference, $actor['id'], $itemId);
    $itemUpdate->execute();
    $itemChanged = $itemUpdate->affected_rows === 1;
    $itemUpdate->close();
    if (!$itemChanged) {
        throw new RuntimeException('This processing payment changed before it could be marked Paid.', 409);
    }

    $requestUpdate = $conn->prepare(
        "UPDATE supplier_fund_request_table
         SET payment_status = 'Paid', payment_confirmation_status = 'Confirmed',
             amount_paid = CAST(amount AS DECIMAL(18,2)), cash_amount_paid = ?, paid_at = NOW(), payment_reference = ?,
             payment_updated_by = ?, payment_updated_at = NOW()
         WHERE id = ? AND payment_status = 'Processing'"
    );
    $requestUpdate->bind_param('dsii', $cashAmount, $reference, $actor['id'], $requestId);
    $requestUpdate->execute();
    $requestChanged = $requestUpdate->affected_rows === 1;
    $requestUpdate->close();
    if (!$requestChanged) {
        throw new RuntimeException('The Supplier Fund Request changed before payment confirmation completed.', 409);
    }

    procurementSupplierFinalizeCreditReservations(
        $conn,
        'local_final_purchase',
        $requestId,
        $actor['id'],
        $batchId,
        $reference
    );

    accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_confirmed', $actor, [
        'gross_amount' => $grossAmount,
        'supplier_credit_applied' => $creditAmount,
        'cash_amount_paid' => $cashAmount,
        'amount_paid' => $grossAmount,
        'payment_reference' => $reference,
        'source' => $source,
    ]);

    return [
        'item_id' => $itemId,
        'request_id' => $requestId,
        'batch_id' => $batchId,
        'gross_amount' => $grossAmount,
        'supplier_credit_applied' => $creditAmount,
        'cash_amount_paid' => $cashAmount,
        'amount_paid' => $grossAmount,
        'payment_reference' => $reference,
    ];
}

function accountSupplierMarkItems(
    mysqli $conn,
    array $itemIds,
    string $action,
    array $data,
    array $actor
): array {
    accountSupplierEnsurePaymentStorage($conn);
    $itemIds = accountSupplierBatchIds($itemIds);
    $actor = accountSupplierActor($actor);
    $allowedActions = ['paid', 'failed', 'delayed', 'cancelled'];
    if (!in_array($action, $allowedActions, true)) {
        throw new RuntimeException('Payment batch action is invalid.', 400);
    }
    $reason = accountSupplierOptionalText($data, 'reason');
    if (in_array($action, ['failed', 'delayed', 'cancelled'], true) && $reason === null) {
        throw new RuntimeException('A reason is required for this action.', 400);
    }
    $paymentReference = trim((string) ($data['payment_reference'] ?? ''));
    $additionalDays = (int) ($data['additional_business_days'] ?? 0);
    if ($action === 'delayed' && ($additionalDays < 1 || $additionalDays > 10)) {
        throw new RuntimeException('Delayed payments require 1 to 10 additional business days.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $types = str_repeat('i', count($itemIds));
    $conn->begin_transaction();
    try {
        $itemColumns = accountPaymentStorageCanonicalItemLegacyColumns(
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            'i',
            'b'
        );
        $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
        $stmt = $conn->prepare(
            "SELECT {$itemColumns}, b.processing_reference AS batch_processing_reference,
                    sfr.payment_status, sfr.amount AS payable_amount,
                    sfr.supplier_credit_applied, sfr.cash_amount_paid,
                    sfr.procurement_purchase_id, p.legacy_source_id AS linked_purchase_id,
                    p.approval_status AS procurement_approval_status,
                    p.handoff_status AS procurement_handoff_status
             FROM account_payment_batch_items i
             INNER JOIN account_payment_batches b ON b.id = i.batch_id
             INNER JOIN supplier_fund_request_table sfr ON sfr.id = i.request_id
             LEFT JOIN procurement_requests p
               ON p.legacy_source_id = sfr.procurement_purchase_id
              AND p.account_request_id = sfr.id
              AND {$localFinalScope}
              AND p.deleted_at IS NULL
             WHERE i.request_type = 'local_final_purchase'
               AND b.request_type = 'local_final_purchase'
               AND i.legacy_source_id IN ($placeholders)
             FOR UPDATE"
        );
        $stmt->bind_param($types, ...$itemIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $successful = [];
        $failed = [];
        $batchIds = [];
        $requestIds = [];

        foreach ($itemIds as $itemId) {
            $row = $byId[$itemId] ?? null;
            if (!$row) {
                $failed[] = ['id' => $itemId, 'reason' => 'Payment batch item not found.'];
                continue;
            }
            if (!in_array((string) $row['status'], ['Processing', 'Awaiting Confirmation', 'Delayed'], true)) {
                $failed[] = ['id' => $itemId, 'reason' => 'This payment is no longer eligible for review.'];
                continue;
            }
            if ((string) $row['payment_status'] !== 'Processing') {
                $failed[] = ['id' => $itemId, 'reason' => 'The linked Supplier Fund Request is no longer Processing.'];
                continue;
            }
            if ((int) ($row['procurement_purchase_id'] ?? 0) > 0
                && (empty($row['linked_purchase_id'])
                    || (string) ($row['procurement_approval_status'] ?? '') !== 'Approved'
                    || (string) ($row['procurement_handoff_status'] ?? '') !== 'In Account')) {
                $failed[] = ['id' => $itemId, 'reason' => 'The linked Procurement handoff is no longer eligible for payment confirmation.'];
                continue;
            }
            $requestId = (int) $row['supplier_fund_request_id'];
            $batchId = (int) $row['batch_id'];
            $batchIds[$batchId] = true;
            $requestIds[] = $requestId;

            if ($action === 'paid') {
                accountSupplierCompleteActivePaymentItem(
                    $conn,
                    $row,
                    $actor,
                    $paymentReference !== '' ? $paymentReference : null,
                    'processing_payments_screen'
                );
            } elseif ($action === 'delayed') {
                $newDue = accountSupplierBusinessDueAt($additionalDays);
                $itemUpdate = $conn->prepare(
                    accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Delayed', status_reason = ?, expected_completion_at = ?, updated_by = ?"
        )
                );
                $itemUpdate->bind_param('ssii', $reason, $newDue, $actor['id'], $itemId);
                $itemUpdate->execute();
                $itemUpdate->close();
                $requestUpdate = $conn->prepare(
                    "UPDATE supplier_fund_request_table
                     SET payment_status = 'Processing', payment_confirmation_status = 'Delayed',
                         expected_completion_at = ?, account_remarks = ?, payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ?"
                );
                $requestUpdate->bind_param('ssii', $newDue, $reason, $actor['id'], $requestId);
                $requestUpdate->execute();
                $requestUpdate->close();
                accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_delayed', $actor, [
                    'reason' => $reason,
                    'expected_completion_at' => $newDue,
                ]);
            } else {
                $newStatus = $action === 'failed' ? 'Failed' : 'Cancelled';
                $itemStatus = ucfirst($action);
                $itemUpdate = $conn->prepare(
                    accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = ?, status_reason = ?, amount_paid = 0.00, paid_at = NULL,
                         updated_by = ?"
        )
                );
                $itemUpdate->bind_param('ssii', $itemStatus, $reason, $actor['id'], $itemId);
                $itemUpdate->execute();
                $itemUpdate->close();
                procurementSupplierReleaseCreditReservations(
                    $conn,
                    'local_final_purchase',
                    $requestId,
                    $actor['id'],
                    $reason ?? 'Payment operation cancelled.',
                    $batchId
                );
                $requestUpdate = $conn->prepare(
                    "UPDATE supplier_fund_request_table
                     SET payment_status = ?, payment_confirmation_status = ?, amount_paid = 0.00,
                         supplier_credit_applied = 0.00, cash_amount_paid = 0.00,
                         paid_at = NULL, account_remarks = ?, payment_updated_by = ?, payment_updated_at = NOW()
                     WHERE id = ? AND payment_status = 'Processing'"
                );
                $confirmation = $itemStatus;
                $requestUpdate->bind_param('sssii', $newStatus, $confirmation, $reason, $actor['id'], $requestId);
                $requestUpdate->execute();
                $requestUpdate->close();
                accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_' . $action, $actor, ['reason' => $reason]);
            }
            $successful[] = $itemId;
        }

        if ($requestIds !== []) {
            procurementSyncLocalFinalPurchasePaymentDetails(
                $conn,
                array_values(array_unique($requestIds)),
                $actor['id'],
                'account_payment_reviewed'
            );
        }
        foreach (array_keys($batchIds) as $batchId) {
            accountSupplierUpdateBatchStatus($conn, (int) $batchId, $actor['id']);
        }

        $uniqueRequestIds = array_values(array_unique($requestIds));
        if ($successful !== [] && $uniqueRequestIds !== []) {
            $notificationTitle = 'Supplier processing payments updated';
            $notificationSeverity = 'info';
            $notificationRoute = '/payments/processing';
            $notificationStatus = ucfirst($action);
            if ($action === 'paid') {
                $notificationTitle = 'Supplier payments marked Paid';
                $notificationSeverity = 'success';
                $notificationRoute = '/payments/fund-request/supplier?payment_status=Paid';
                $notificationStatus = 'Paid';
            } elseif ($action === 'delayed') {
                $notificationTitle = 'Supplier payments delayed';
                $notificationSeverity = 'warning';
                $notificationStatus = 'Delayed';
            } elseif ($action === 'failed') {
                $notificationTitle = 'Supplier payments marked Failed';
                $notificationSeverity = 'error';
                $notificationRoute = '/payments/fund-request/supplier?payment_status=Failed';
                $notificationStatus = 'Failed';
            } elseif ($action === 'cancelled') {
                $notificationTitle = 'Supplier payments cancelled';
                $notificationSeverity = 'warning';
                $notificationRoute = '/payments/fund-request/supplier?payment_status=Cancelled';
                $notificationStatus = 'Cancelled';
            }

            $notificationMessage = $actor['email'] . ' updated ' . count($uniqueRequestIds)
                . " supplier processing payment(s) to $notificationStatus.";
            if ($reason !== null) {
                $notificationMessage .= ' Reason: ' . $reason;
            }
            accountNotificationPublishSupplierAction(
                $conn,
                'supplier_processing_' . $action,
                $notificationTitle,
                $notificationMessage,
                $uniqueRequestIds,
                $actor,
                $notificationRoute,
                $notificationSeverity,
                [
                    'item_ids' => $successful,
                    'batch_ids' => array_map('intval', array_keys($batchIds)),
                    'action' => $action,
                    'reason' => $reason,
                    'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
                    'additional_business_days' => $additionalDays > 0 ? $additionalDays : null,
                ]
            );
        }

        $conn->commit();
        return ['successful' => $successful, 'failed' => $failed];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function accountSupplierProcessDueBatches(mysqli $conn): array
{
    accountSupplierEnsurePaymentStorage($conn);
    $systemActor = ['id' => 0, 'email' => 'system'];
    $now = date('Y-m-d H:i:s');
    $batchColumns = accountPaymentStorageCanonicalBatchLegacyColumns('batch');
    $stmt = $conn->prepare(
        "SELECT {$batchColumns}
         FROM account_payment_batches batch
         WHERE batch.request_type = 'local_final_purchase'
           AND batch.status = 'Processing'
           AND batch.expected_completion_at <= ?
         ORDER BY batch.legacy_source_id ASC"
    );
    $stmt->bind_param('s', $now);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $result = ['review_batches' => [], 'automatic_batches' => [], 'skipped' => []];

    foreach ($batches as $batch) {
        $batchId = (int) $batch['id'];
        $conn->begin_transaction();
        try {
            $batchColumns = accountPaymentStorageCanonicalBatchLegacyColumns('batch');
            $lock = $conn->prepare(
                "SELECT {$batchColumns}
                 FROM account_payment_batches batch
                 WHERE batch.request_type = 'local_final_purchase'
                   AND batch.legacy_source_id = ?
                 FOR UPDATE"
            );
            $lock->bind_param('i', $batchId);
            $lock->execute();
            $fresh = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$fresh || (string) $fresh['status'] !== 'Processing') {
                $conn->rollback();
                continue;
            }

            $itemColumns = accountPaymentStorageCanonicalItemLegacyColumns(
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                'i',
                'b'
            );
            $localFinalScope = procurementRequestCanonicalLocalFinalScopeSql('p');
            $itemsStmt = $conn->prepare(
                "SELECT {$itemColumns}, sfr.payment_status, sfr.amount AS payable_amount,
                    sfr.supplier_credit_applied, sfr.cash_amount_paid,
                        sfr.procurement_purchase_id, p.legacy_source_id AS linked_purchase_id,
                        p.approval_status AS procurement_approval_status,
                        p.handoff_status AS procurement_handoff_status
                 FROM account_payment_batch_items i
                 INNER JOIN account_payment_batches b ON b.id = i.batch_id
                 INNER JOIN supplier_fund_request_table sfr ON sfr.id = i.request_id
                 LEFT JOIN procurement_requests p
                   ON p.legacy_source_id = sfr.procurement_purchase_id
                  AND p.account_request_id = sfr.id
                  AND {$localFinalScope}
                  AND p.deleted_at IS NULL
                 WHERE i.request_type = 'local_final_purchase'
                   AND b.request_type = 'local_final_purchase'
                   AND b.legacy_source_id = ?
                 FOR UPDATE"
            );
            $itemsStmt->bind_param('i', $batchId);
            $itemsStmt->execute();
            $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();
            if ($items === []) {
                accountSupplierUpdateBatchStatus($conn, $batchId, 0);
                $conn->commit();
                continue;
            }

            $dueItems = array_values(array_filter($items, static function (array $item) use ($now): bool {
                return (string) ($item['status'] ?? '') === 'Processing'
                    && (string) ($item['expected_completion_at'] ?? '') !== ''
                    && (string) $item['expected_completion_at'] <= $now;
            }));

            if ((string) $fresh['completion_mode'] === 'Automatic') {
                $requestIds = [];
                $completedCount = 0;
                $exceptionCount = 0;
                $exceptionRequestIds = [];
                foreach ($dueItems as $item) {
                    $requestId = (int) $item['supplier_fund_request_id'];
                    $ineligibleReason = null;
                    if ((string) $item['payment_status'] !== 'Processing') {
                        $ineligibleReason = 'Automatic completion skipped because the request is no longer Processing.';
                    } elseif ((int) ($item['procurement_purchase_id'] ?? 0) > 0
                        && (empty($item['linked_purchase_id'])
                            || (string) ($item['procurement_approval_status'] ?? '') !== 'Approved'
                            || (string) ($item['procurement_handoff_status'] ?? '') !== 'In Account')) {
                        $ineligibleReason = 'Automatic completion skipped because the Procurement handoff is no longer eligible.';
                    }

                    if ($ineligibleReason !== null) {
                        procurementSupplierReleaseCreditReservations(
                            $conn,
                            'local_final_purchase',
                            $requestId,
                            0,
                            $ineligibleReason,
                            $batchId
                        );
                        $creditReset = $conn->prepare(
                            "UPDATE supplier_fund_request_table
                             SET supplier_credit_applied = 0.00, cash_amount_paid = 0.00, payment_updated_at = NOW()
                             WHERE id = ? AND payment_status <> 'Paid'"
                        );
                        $creditReset->bind_param('i', $requestId);
                        $creditReset->execute();
                        $creditReset->close();
                        $skipItem = $conn->prepare(
                            accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Failed', status_reason = ?, updated_by = 0",
            "status = 'Processing'"
        )
                        );
                        $itemId = (int) $item['id'];
                        $skipItem->bind_param('si', $ineligibleReason, $itemId);
                        $skipItem->execute();
                        $skipItem->close();
                        accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_auto_completion_skipped', $systemActor, [
                            'reason' => $ineligibleReason,
                            'current_payment_status' => (string) $item['payment_status'],
                        ]);
                        $exceptionCount++;
                        $exceptionRequestIds[] = $requestId;
                        continue;
                    }

                    $grossAmount = procurementLocalFinalCents(
                        procurementLocalFinalMoneyToCents($item['payable_amount'], 'Supplier Fund Request Amount')
                    );
                    $creditAmount = procurementLocalFinalCents(
                        procurementLocalFinalMoneyToCents($item['supplier_credit_applied'] ?? 0, 'Supplier Credit Applied', true)
                    );
                    $cashAmount = procurementLocalFinalCents(max(
                        0,
                        procurementLocalFinalMoneyToCents($grossAmount, 'Supplier Fund Request Amount') -
                        procurementLocalFinalMoneyToCents($creditAmount, 'Supplier Credit Applied', true)
                    ));
                    $reference = (string) $fresh['processing_reference'];
                    $requestUpdate = $conn->prepare(
                        "UPDATE supplier_fund_request_table
                         SET payment_status = 'Paid', payment_confirmation_status = 'Auto Completed',
                             amount_paid = CAST(amount AS DECIMAL(18,2)), cash_amount_paid = ?,
                             paid_at = NOW(), payment_reference = ?,
                             payment_updated_by = 0, payment_updated_at = NOW()
                         WHERE id = ? AND payment_status = 'Processing'"
                    );
                    $requestUpdate->bind_param('dsi', $cashAmount, $reference, $requestId);
                    $requestUpdate->execute();
                    $requestChanged = $requestUpdate->affected_rows === 1;
                    $requestUpdate->close();
                    if (!$requestChanged) {
                        $reason = 'Automatic completion skipped because the request changed before completion.';
                        procurementSupplierReleaseCreditReservations(
                            $conn,
                            'local_final_purchase',
                            $requestId,
                            0,
                            $reason,
                            $batchId
                        );
                        $creditReset = $conn->prepare(
                            "UPDATE supplier_fund_request_table
                             SET supplier_credit_applied = 0.00, cash_amount_paid = 0.00, payment_updated_at = NOW()
                             WHERE id = ? AND payment_status <> 'Paid'"
                        );
                        $creditReset->bind_param('i', $requestId);
                        $creditReset->execute();
                        $creditReset->close();
                        $skipItem = $conn->prepare(
                            accountPaymentStorageUpdateByLegacyIdSql(
                                $conn,
                                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                                "item",
                                "status = 'Failed', status_reason = ?, updated_by = 0",
                                "status = 'Processing'"
                            )
                        );
                        $itemId = (int) $item['id'];
                        $skipItem->bind_param('si', $reason, $itemId);
                        $skipItem->execute();
                        $skipItem->close();
                        accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_auto_completion_skipped', $systemActor, ['reason' => $reason]);
                        $exceptionCount++;
                        $exceptionRequestIds[] = $requestId;
                        continue;
                    }

                    $itemUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
                            $conn,
                            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                            "item",
                            "status = 'Paid', amount_paid = ?, paid_at = NOW(), payment_reference = ?,
                             status_reason = 'Automatically completed at the configured due time.', updated_by = 0",
                            "status = 'Processing'"
                        )
                    );
                    $itemId = (int) $item['id'];
                    $itemUpdate->bind_param('dsi', $cashAmount, $reference, $itemId);
                    $itemUpdate->execute();
                    $itemUpdate->close();

                    procurementSupplierFinalizeCreditReservations(
                        $conn,
                        'local_final_purchase',
                        $requestId,
                        0,
                        $batchId,
                        $reference
                    );

                    $requestIds[] = $requestId;
                    $completedCount++;
                    accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_auto_completed', $systemActor, [
                        'gross_amount' => $grossAmount,
                        'supplier_credit_applied' => $creditAmount,
                        'cash_amount_paid' => $cashAmount,
                        'amount_paid' => $grossAmount,
                        'payment_reference' => $reference,
                    ]);
                }
                if ($requestIds !== []) {
                    procurementSyncLocalFinalPurchasePaymentDetails($conn, $requestIds, 0, 'account_payment_auto_completed');
                }
                accountSupplierUpdateBatchStatus($conn, $batchId, 0);
                $methodLabel = accountSupplierPaymentMethodLabel($fresh['processing_method'] ?? '');
                $operationLabel = accountSupplierPaymentOperationLabel($fresh['processing_method'] ?? '');
                if ($completedCount > 0) {
                    accountSupplierCreateNotifications(
                        $conn,
                        'payment_batch_auto_completed',
                        "$methodLabel supplier payments completed",
                        "$operationLabel {$fresh['batch_reference']} automatically marked $completedCount eligible request(s) as Paid.",
                        [
                            'batch_id' => $batchId,
                            'processing_method' => $fresh['processing_method'] ?? null,
                            'processing_reference' => $fresh['processing_reference'] ?? null,
                            'route' => "/payments/fund-request/supplier?payment_status=Paid",
                        ],
                        'payment-batch-auto-completed-' . $batchId
                    );
                    $result['automatic_batches'][] = $batchId;
                }
                if ($exceptionCount > 0) {
                    accountSupplierCreateNotifications(
                        $conn,
                        'payment_batch_auto_completion_exception',
                        "$methodLabel automatic completion needs attention",
                        "$operationLabel {$fresh['batch_reference']} skipped $exceptionCount request(s) because they were no longer eligible for automatic completion.",
                        [
                            'batch_id' => $batchId,
                            'processing_method' => $fresh['processing_method'] ?? null,
                            'processing_reference' => $fresh['processing_reference'] ?? null,
                            'request_ids' => array_values(array_unique($exceptionRequestIds)),
                            'route' => '/payments/fund-request/supplier',
                        ],
                        'payment-batch-auto-exception-' . $batchId
                    );
                }
            } else {
                $requestIds = [];
                $reminderIds = [];
                $exceptionCount = 0;
                $exceptionRequestIds = [];
                foreach ($dueItems as $item) {
                    $requestId = (int) $item['supplier_fund_request_id'];
                    $ineligibleReason = null;
                    if ((string) $item['payment_status'] !== 'Processing') {
                        $ineligibleReason = 'Confirmation was not scheduled because the request is no longer Processing.';
                    } elseif ((int) ($item['procurement_purchase_id'] ?? 0) > 0
                        && (empty($item['linked_purchase_id'])
                            || (string) ($item['procurement_approval_status'] ?? '') !== 'Approved'
                            || (string) ($item['procurement_handoff_status'] ?? '') !== 'In Account')) {
                        $ineligibleReason = 'Confirmation was not scheduled because the Procurement handoff is no longer eligible.';
                    }

                    $itemId = (int) $item['id'];
                    if ($ineligibleReason !== null) {
                        $skipItem = $conn->prepare(
                            accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Failed', status_reason = ?, updated_by = 0",
            "status = 'Processing'"
        )
                        );
                        $skipItem->bind_param('si', $ineligibleReason, $itemId);
                        $skipItem->execute();
                        $skipItem->close();
                        accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_confirmation_skipped', $systemActor, ['reason' => $ineligibleReason]);
                        $exceptionCount++;
                        $exceptionRequestIds[] = $requestId;
                        continue;
                    }

                    $requestUpdate = $conn->prepare(
                        "UPDATE supplier_fund_request_table
                         SET payment_confirmation_status = 'Due', payment_updated_at = NOW()
                         WHERE id = ? AND payment_status = 'Processing'"
                    );
                    $requestUpdate->bind_param('i', $requestId);
                    $requestUpdate->execute();
                    $requestChanged = $requestUpdate->affected_rows === 1;
                    $requestUpdate->close();
                    if (!$requestChanged) {
                        $exceptionCount++;
                        $exceptionRequestIds[] = $requestId;
                        accountSupplierRecordEvent(
                            $conn,
                            $requestId,
                            $batchId,
                            'payment_confirmation_skipped',
                            $systemActor,
                            ['reason' => 'Confirmation was skipped because the request changed before it could be scheduled.']
                        );
                        continue;
                    }
                    $itemUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Awaiting Confirmation', updated_by = 0",
            "status = 'Processing'"
        )
                    );
                    $itemUpdate->bind_param('i', $itemId);
                    $itemUpdate->execute();
                    $itemUpdate->close();
                    $requestIds[] = $requestId;
                    accountSupplierRecordEvent($conn, $requestId, $batchId, 'payment_confirmation_due', $systemActor, [
                        'expected_completion_at' => (string) $item['expected_completion_at'],
                    ]);
                    $reminderIds[] = accountPaymentReminderSchedule(
                        $conn,
                        'Supplier',
                        $requestId,
                        $batchId,
                        $itemId,
                        (string) $item['expected_completion_at'],
                        [
                            'batch_reference' => $fresh['batch_reference'] ?? null,
                            'processing_method' => $fresh['processing_method'] ?? null,
                            'processing_reference' => $fresh['processing_reference'] ?? null,
                            'source' => 'supplier_payment_batch_due',
                        ],
                        $systemActor
                    );
                }

                if ($requestIds !== []) {
                    procurementSyncLocalFinalPurchasePaymentDetails($conn, $requestIds, 0, 'account_payment_confirmation_due');
                }
                accountSupplierUpdateBatchStatus($conn, $batchId, 0);
                $methodLabel = accountSupplierPaymentMethodLabel($fresh['processing_method'] ?? '');
                $operationLabel = accountSupplierPaymentOperationLabel($fresh['processing_method'] ?? '');
                $methodQuery = rawurlencode((string) ($fresh['processing_method'] ?? ''));
                if ($requestIds !== []) {
                    $batchUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "batch",
            "notification_created_at = NOW(), updated_by = 0"
        )
                    );
                    $batchUpdate->bind_param('i', $batchId);
                    $batchUpdate->execute();
                    $batchUpdate->close();
                    $notificationRecipients = accountSupplierCreateNotifications(
                        $conn,
                        'payment_batch_confirmation_due',
                        "$methodLabel payments ready for confirmation",
                        "$operationLabel {$fresh['batch_reference']} has reached its expected completion date. Select the eligible requests that should be marked Paid.",
                        [
                            'batch_id' => $batchId,
                            'request_ids' => array_values(array_unique($requestIds)),
                            'reminder_ids' => array_values(array_unique($reminderIds)),
                            'delivery_channel' => 'batch_confirmation_due',
                            'processing_method' => $fresh['processing_method'] ?? null,
                            'processing_reference' => $fresh['processing_reference'] ?? null,
                            'route' => "/payments/processing?batch_id=$batchId&status=Awaiting%20Confirmation&processing_method=$methodQuery",
                        ],
                        'payment-batch-confirmation-due-' . $batchId
                    );
                    accountPaymentReminderInitialDelivery($conn, $reminderIds, $notificationRecipients, $systemActor);
                    $result['review_batches'][] = $batchId;
                }
                if ($exceptionCount > 0) {
                    accountSupplierCreateNotifications(
                        $conn,
                        'payment_batch_confirmation_exception',
                        "$methodLabel confirmation needs attention",
                        "$operationLabel {$fresh['batch_reference']} could not schedule $exceptionCount request(s) for confirmation because they were no longer eligible.",
                        [
                            'batch_id' => $batchId,
                            'processing_method' => $fresh['processing_method'] ?? null,
                            'processing_reference' => $fresh['processing_reference'] ?? null,
                            'request_ids' => array_values(array_unique($exceptionRequestIds)),
                            'route' => '/payments/fund-request/supplier',
                        ],
                        'payment-batch-confirmation-exception-' . $batchId
                    );
                }
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            $result['skipped'][] = ['batch_id' => $batchId, 'reason' => $error->getMessage()];
        }
    }
    return $result;
}

function accountSupplierFetchLatestPaymentItemsForRequests(mysqli $conn, array $requestIds): array
{
    if ($requestIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = str_repeat('i', count($requestIds));
    $itemColumns = accountPaymentStorageCanonicalItemLegacyColumns(
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
        'i',
        'b'
    );
    $stmt = $conn->prepare(
        "SELECT {$itemColumns}, b.batch_reference,
                b.processing_method AS batch_processing_method,
                b.processing_reference AS batch_processing_reference,
                b.completion_mode AS batch_completion_mode
         FROM account_payment_batch_items i
         INNER JOIN account_payment_batches b ON b.id = i.batch_id
         INNER JOIN (
             SELECT request_id, MAX(legacy_source_id) AS latest_item_id
             FROM account_payment_batch_items
             WHERE request_type = 'local_final_purchase'
               AND request_id IN ($placeholders)
             GROUP BY request_id
         ) latest
           ON latest.request_id = i.request_id
          AND latest.latest_item_id = i.legacy_source_id
         WHERE i.request_type = 'local_final_purchase'
           AND b.request_type = 'local_final_purchase'
         FOR UPDATE"
    );
    $stmt->bind_param($types, ...$requestIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byRequest = [];
    foreach ($rows as $row) {
        $byRequest[(int) $row['supplier_fund_request_id']] = $row;
    }
    return $byRequest;
}

function accountSupplierCreatePaidCorrectionBatch(
    mysqli $conn,
    array $requestIds,
    array $requests,
    string $requestedTarget,
    int $businessDays,
    string $reason,
    array $actor
): array {
    if ($requestIds === []) {
        return ['batch_id' => 0, 'items' => []];
    }

    $actor = accountSupplierActor($actor);
    $isUnconfirmed = strcasecmp($requestedTarget, 'Unconfirmed') === 0;
    $dueAt = $isUnconfirmed ? date('Y-m-d H:i:s') : accountSupplierBusinessDueAt($businessDays);
    $itemStatus = $isUnconfirmed ? 'Awaiting Confirmation' : 'Processing';
    $batchStatus = $isUnconfirmed ? 'Awaiting Confirmation' : 'Processing';
    $batchReference = accountSupplierGenerateBatchReference();
    $processingReference = 'Paid correction ' . $batchReference;
    $totalCents = 0;

    foreach ($requestIds as $requestId) {
        $totalCents += procurementLocalFinalMoneyToCents(
            $requests[$requestId]['amount'] ?? 0,
            'Supplier Fund Request Amount'
        );
    }
    $totalAmount = procurementLocalFinalCents($totalCents);
    $itemCount = count($requestIds);
    $remarks = 'Paid status correction: ' . $reason;
    $completionMode = 'Notify';
    $method = 'Manual';

    $batchId = accountPaymentStorageCreateBatch(
        $conn,
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
        [
            'batch_reference' => $batchReference,
            'processing_method' => $method,
            'processing_reference' => $processingReference,
            'processing_business_days' => $businessDays,
            'expected_completion_at' => $dueAt,
            'completion_mode' => $completionMode,
            'status' => $batchStatus,
            'item_count' => $itemCount,
            'total_amount' => $totalAmount,
            'account_remarks' => $remarks,
            'created_by' => $actor['id'],
            'updated_by' => $actor['id'],
            'completed_at' => null,
        ]
    );

    $items = [];
    foreach ($requestIds as $requestId) {
        $amount = procurementLocalFinalCents(
            procurementLocalFinalMoneyToCents($requests[$requestId]['amount'] ?? 0, 'Supplier Fund Request Amount')
        );
        $itemId = accountPaymentStorageCreateItem(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            $batchId,
            [
                'request_id' => $requestId,
                'amount' => $amount,
                'amount_paid' => 0.00,
                'status' => $itemStatus,
                'status_reason' => $remarks,
                'processing_started_at' => date('Y-m-d H:i:s'),
                'expected_completion_at' => $dueAt,
                'paid_at' => null,
                'payment_reference' => null,
                'updated_by' => $actor['id'],
            ]
        );
        $items[$requestId] = [
            'id' => $itemId,
            'batch_id' => $batchId,
            'status' => $itemStatus,
            'expected_completion_at' => $dueAt,
            'batch_processing_method' => $method,
            'batch_processing_reference' => $processingReference,
            '_created_for_correction' => true,
        ];
    }
    return [
        'batch_id' => $batchId,
        'batch_reference' => $batchReference,
        'processing_method' => $method,
        'processing_reference' => $processingReference,
        'expected_completion_at' => $dueAt,
        'items' => $items,
    ];
}

function accountSupplierReversePaidStatus(
    mysqli $conn,
    array $requestIds,
    string $targetStatus,
    string $reason,
    bool $confirmedNotPaid,
    int $processingBusinessDays,
    array $actor
): array {
    accountSupplierEnsurePaymentStorage($conn);
    $requestIds = accountSupplierBatchIds($requestIds);
    $actor = accountSupplierActor($actor);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A correction reason is required.', 400);
    }
    if (strlen($reason) > 2000) {
        throw new RuntimeException('Correction reason is too long.', 400);
    }
    if (!$confirmedNotPaid) {
        throw new RuntimeException('Confirm that the payment was not completed before reversing Paid status.', 400);
    }

    $requestedTarget = accountSupplierValidateChoice(
        $targetStatus,
        ['Pending', 'Processing', 'Unconfirmed', 'Failed', 'Cancelled'],
        'Correction Status'
    );
    $normalizedTarget = $requestedTarget === 'Unconfirmed' ? 'Processing' : $requestedTarget;
    if ($requestedTarget === 'Processing') {
        if ($processingBusinessDays < 1 || $processingBusinessDays > 5) {
            throw new RuntimeException('Processing corrections require a period between 1 and 5 business days.', 400);
        }
    } else {
        $processingBusinessDays = 0;
    }

    $requests = accountSupplierFetchRequestsForUpdate($conn, $requestIds);
    $latestItems = accountSupplierFetchLatestPaymentItemsForRequests($conn, $requestIds);
    $missingProcessingItems = [];

    foreach ($requestIds as $requestId) {
        $request = $requests[$requestId] ?? null;
        if (!$request) {
            throw new RuntimeException("Supplier Fund Request $requestId was not found.", 404);
        }
        if ((string) ($request['payment_status'] ?? '') !== 'Paid') {
            throw new RuntimeException(
                "Supplier Fund Request $requestId is not Paid. Paid-status correction can only be used for Paid requests.",
                409
            );
        }
        if (in_array($requestedTarget, ['Processing', 'Unconfirmed'], true)) {
            $hasProcurementLink = (int) ($request['supplier_procurement_purchase_id'] ?? 0) > 0;
            $procurementEligible = !$hasProcurementLink
                || (!empty($request['linked_procurement_purchase_id'])
                    && (string) ($request['procurement_approval_status'] ?? '') === 'Approved'
                    && (string) ($request['procurement_handoff_status'] ?? '') === 'In Account');
            if (!$procurementEligible) {
                throw new RuntimeException(
                    "Supplier Fund Request $requestId cannot return to Processing because its Procurement approval or Account handoff is no longer valid.",
                    409
                );
            }
        }

        $currentBatchId = (int) ($request['payment_batch_id'] ?? 0);
        $latestItem = $latestItems[$requestId] ?? null;
        if ($latestItem === null
            || $currentBatchId <= 0
            || (int) ($latestItem['batch_id'] ?? 0) !== $currentBatchId
            || (string) ($latestItem['status'] ?? '') !== 'Paid') {
            unset($latestItems[$requestId]);
        }

        if (in_array($requestedTarget, ['Processing', 'Unconfirmed'], true) && !isset($latestItems[$requestId])) {
            $missingProcessingItems[] = $requestId;
        }
    }

    $createdCorrectionBatch = accountSupplierCreatePaidCorrectionBatch(
        $conn,
        $missingProcessingItems,
        $requests,
        $requestedTarget,
        $processingBusinessDays,
        $reason,
        $actor
    );
    foreach ($createdCorrectionBatch['items'] ?? [] as $requestId => $item) {
        $latestItems[(int) $requestId] = $item;
    }

    $affectedBatchIds = [];
    $results = [];

    foreach ($requestIds as $requestId) {
        $request = $requests[$requestId];
        $item = $latestItems[$requestId] ?? null;
        $batchId = $item ? (int) ($item['batch_id'] ?? 0) : 0;
        $previousSnapshot = [
            'payment_status' => (string) ($request['payment_status'] ?? ''),
            'payment_confirmation_status' => $request['payment_confirmation_status'] ?? null,
            'amount_paid' => number_format((float) ($request['amount_paid'] ?? 0), 2, '.', ''),
            'paid_at' => $request['paid_at'] ?? null,
            'payment_reference' => $request['payment_reference'] ?? null,
            'processing_method' => $request['processing_method'] ?? null,
            'processing_reference' => $request['processing_reference'] ?? null,
            'processing_started_at' => $request['processing_started_at'] ?? null,
            'processing_business_days' => $request['processing_business_days'] ?? null,
            'expected_completion_at' => $request['expected_completion_at'] ?? null,
            'completion_mode' => $request['completion_mode'] ?? null,
            'payment_batch_id' => $request['payment_batch_id'] ?? null,
            'account_remarks' => $request['account_remarks'] ?? null,
        ];
        $previousItemSnapshot = ($item && empty($item['_created_for_correction'])) ? [
            'item_id' => (int) ($item['id'] ?? 0),
            'batch_id' => (int) ($item['batch_id'] ?? 0),
            'status' => (string) ($item['status'] ?? ''),
            'amount_paid' => number_format((float) ($item['amount_paid'] ?? 0), 2, '.', ''),
            'paid_at' => $item['paid_at'] ?? null,
            'payment_reference' => $item['payment_reference'] ?? null,
            'expected_completion_at' => $item['expected_completion_at'] ?? null,
        ] : null;

        $remarks = "Paid status reversed to $requestedTarget: $reason";
        $processingMethod = trim((string) (
            $item['batch_processing_method']
            ?? $request['processing_method']
            ?? 'Manual'
        )) ?: 'Manual';
        $processingReference = trim((string) (
            $item['batch_processing_reference']
            ?? $request['processing_reference']
            ?? $request['payment_reference']
            ?? 'Paid status correction'
        )) ?: 'Paid status correction';
        $dueAt = null;
        $confirmationStatus = $requestedTarget;

        if ($requestedTarget === 'Processing') {
            $dueAt = accountSupplierBusinessDueAt($processingBusinessDays);
            $confirmationStatus = 'Scheduled';
        } elseif ($requestedTarget === 'Unconfirmed') {
            $dueAt = date('Y-m-d H:i:s');
            $confirmationStatus = 'Awaiting Confirmation';
        }

        if ($item !== null) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId > 0) {
                if ($requestedTarget === 'Processing') {
                    $itemUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Processing', status_reason = ?, amount_paid = 0.00,
                             processing_started_at = NOW(), expected_completion_at = ?, paid_at = NULL,
                             payment_reference = NULL, updated_by = ?"
        )
                    );
                    $itemUpdate->bind_param('ssii', $remarks, $dueAt, $actor['id'], $itemId);
                } elseif ($requestedTarget === 'Unconfirmed') {
                    $itemUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = 'Awaiting Confirmation', status_reason = ?, amount_paid = 0.00,
                             processing_started_at = NOW(), expected_completion_at = ?, paid_at = NULL,
                             payment_reference = NULL, updated_by = ?"
        )
                    );
                    $itemUpdate->bind_param('ssii', $remarks, $dueAt, $actor['id'], $itemId);
                } else {
                    $itemStatus = $requestedTarget === 'Failed' ? 'Failed' : 'Cancelled';
                    $itemUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = ?, status_reason = ?, amount_paid = 0.00, paid_at = NULL,
                             payment_reference = NULL, updated_by = ?"
        )
                    );
                    $itemUpdate->bind_param('ssii', $itemStatus, $remarks, $actor['id'], $itemId);
                }
                $itemUpdate->execute();
                $itemUpdate->close();
            }
            if ($batchId > 0) {
                if (in_array($requestedTarget, ['Processing', 'Unconfirmed'], true)) {
                    $batchStatus = $requestedTarget === 'Unconfirmed' ? 'Awaiting Confirmation' : 'Processing';
                    $effectiveDays = $requestedTarget === 'Processing' ? $processingBusinessDays : 0;
                    $batchUpdate = $conn->prepare(
                        accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "batch",
            "processing_business_days = ?, expected_completion_at = ?, completion_mode = 'Notify',
                             status = ?, account_remarks = ?, notification_created_at = NULL,
                             completed_at = NULL, updated_by = ?"
        )
                    );
                    $batchUpdate->bind_param(
                        'isssii',
                        $effectiveDays,
                        $dueAt,
                        $batchStatus,
                        $remarks,
                        $actor['id'],
                        $batchId
                    );
                    $batchUpdate->execute();
                    $batchUpdate->close();
                }
                $affectedBatchIds[$batchId] = true;
            }
        }

        if ($requestedTarget === 'Pending') {
            $requestUpdate = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Pending', payment_confirmation_status = 'Pending', amount_paid = 0.00,
                     supplier_credit_applied = 0.00, cash_amount_paid = 0.00,
                     processing_method = NULL, processing_reference = NULL, processing_started_at = NULL,
                     processing_business_days = NULL, expected_completion_at = NULL, completion_mode = NULL,
                     paid_at = NULL, payment_reference = NULL, account_remarks = ?, payment_batch_id = NULL,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Paid'"
            );
            $requestUpdate->bind_param('sii', $remarks, $actor['id'], $requestId);
        } elseif (in_array($requestedTarget, ['Processing', 'Unconfirmed'], true)) {
            $effectiveDays = $requestedTarget === 'Processing' ? $processingBusinessDays : 0;
            $requestUpdate = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Processing', processing_method = ?, processing_reference = ?,
                     processing_started_at = NOW(), processing_business_days = ?, expected_completion_at = ?,
                     completion_mode = 'Notify', payment_confirmation_status = ?, amount_paid = 0.00,
                     paid_at = NULL, payment_reference = NULL, account_remarks = ?, payment_batch_id = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = 'Paid'"
            );
            $requestUpdate->bind_param(
                'ssisssiii',
                $processingMethod,
                $processingReference,
                $effectiveDays,
                $dueAt,
                $confirmationStatus,
                $remarks,
                $batchId,
                $actor['id'],
                $requestId
            );
        } else {
            $requestUpdate = $conn->prepare(
                'UPDATE supplier_fund_request_table
                 SET payment_status = ?, payment_confirmation_status = ?, amount_paid = 0.00,
                     paid_at = NULL, payment_reference = NULL, account_remarks = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status = \'Paid\''
            );
            $requestUpdate->bind_param(
                'sssii',
                $normalizedTarget,
                $requestedTarget,
                $remarks,
                $actor['id'],
                $requestId
            );
        }
        $requestUpdate->execute();
        $changed = $requestUpdate->affected_rows === 1;
        $requestUpdate->close();
        if (!$changed) {
            throw new RuntimeException(
                "Supplier Fund Request $requestId changed before its Paid status could be corrected.",
                409
            );
        }

        accountSupplierRecordEvent(
            $conn,
            $requestId,
            $batchId > 0 ? $batchId : null,
            'paid_status_reversed',
            $actor,
            [
                'reason' => $reason,
                'payment_was_not_completed' => true,
                'requested_status' => $requestedTarget,
                'new_payment_status' => $normalizedTarget,
                'new_confirmation_status' => $confirmationStatus,
                'processing_business_days' => $processingBusinessDays,
                'expected_completion_at' => $dueAt,
                'previous_request' => $previousSnapshot,
                'previous_payment_item' => $previousItemSnapshot,
                'instruction_records_modified' => false,
                'source' => 'supplier_paid_status_correction',
            ]
        );

        $results[] = [
            'request_id' => $requestId,
            'payment_status' => $normalizedTarget,
            'requested_status' => $requestedTarget,
            'batch_id' => $batchId > 0 ? $batchId : null,
        ];
    }

    procurementSyncLocalFinalPurchasePaymentDetails(
        $conn,
        $requestIds,
        $actor['id'],
        'account_paid_status_reversed'
    );
    foreach (array_keys($affectedBatchIds) as $affectedBatchId) {
        accountSupplierUpdateBatchStatus($conn, (int) $affectedBatchId, $actor['id']);
    }

    $notificationRouteStatus = $normalizedTarget === 'Processing' ? 'Processing' : $normalizedTarget;
    accountNotificationPublishSupplierAction(
        $conn,
        'supplier_paid_status_corrected',
        'Paid status corrected',
        $actor['email'] . ' corrected ' . count($requestIds)
            . " supplier request(s) from Paid to $requestedTarget. Reason: $reason",
        $requestIds,
        $actor,
        '/payments/fund-request/supplier?payment_status=' . rawurlencode($notificationRouteStatus),
        'warning',
        [
            'previous_status' => 'Paid',
            'target_status' => $requestedTarget,
            'normalized_payment_status' => $normalizedTarget,
            'reason' => $reason,
            'payment_was_not_completed' => true,
            'processing_business_days' => $processingBusinessDays > 0 ? $processingBusinessDays : null,
            'created_correction_batch_id' => (int) ($createdCorrectionBatch['batch_id'] ?? 0) ?: null,
            'instruction_records_modified' => false,
        ]
    );

    return [
        'updated' => $results,
        'target_status' => $requestedTarget,
        'created_correction_batch_id' => (int) ($createdCorrectionBatch['batch_id'] ?? 0) ?: null,
    ];
}


function accountSupplierApplyDirectStatus(
    mysqli $conn,
    array $requestIds,
    string $status,
    array $actor,
    array $options = []
): void {
    accountSupplierEnsurePaymentStorage($conn);

    $requestedStatus = trim($status);
    $status = strcasecmp($requestedStatus, 'Unconfirmed') === 0
        ? 'Processing'
        : accountSupplierValidateChoice($requestedStatus, ACCOUNT_SUPPLIER_PAYMENT_STATUSES, 'Payment Status');
    $actor = accountSupplierActor($actor);
    $requests = accountSupplierFetchRequestsForUpdate($conn, $requestIds);
    $activeItems = accountSupplierFetchActivePaymentItemsForRequests($conn, $requestIds);
    $affectedBatchIds = [];

    foreach ($requestIds as $requestId) {
        $request = $requests[$requestId] ?? null;
        if (!$request) {
            throw new RuntimeException("Supplier Fund Request $requestId was not found.", 404);
        }

        $current = (string) $request['payment_status'];
        $activeItem = $activeItems[$requestId] ?? null;
        $activeBatchId = $activeItem ? (int) $activeItem['batch_id'] : 0;

        if ($current === 'Paid') {
            throw new RuntimeException(
                "Supplier Fund Request $requestId is already Paid and is locked against further bulk status changes.",
                409
            );
        }

        if ($activeItem !== null && in_array($status, ['Pending', 'Processing'], true)) {
            $displayStatus = strcasecmp($requestedStatus, 'Unconfirmed') === 0 ? 'Unconfirmed' : $status;
            throw new RuntimeException(
                "Supplier Fund Request $requestId is in active payment operation #$activeBatchId and cannot be changed to $displayStatus from the general bulk action. Resolve it from View Processing first.",
                409
            );
        }

        $hasProcurementLink = (int) ($request['supplier_procurement_purchase_id'] ?? 0) > 0;
        $procurementEligible = !$hasProcurementLink
            || (!empty($request['linked_procurement_purchase_id'])
                && (string) ($request['procurement_approval_status'] ?? '') === 'Approved'
                && (string) ($request['procurement_handoff_status'] ?? '') === 'In Account');
        if (in_array($status, ['Processing', 'Paid'], true) && !$procurementEligible) {
            throw new RuntimeException(
                "Supplier Fund Request $requestId cannot be changed to $status because its Procurement approval or Account handoff is no longer valid.",
                409
            );
        }

        if ($status === 'Paid' && $activeItem !== null) {
            $result = accountSupplierCompleteActivePaymentItem(
                $conn,
                $activeItem,
                $actor,
                accountSupplierOptionalText($options, 'payment_reference', 160),
                'supplier_fund_request_bulk_status'
            );
            $affectedBatchIds[(int) $result['batch_id']] = true;
            continue;
        }

        $batchId = (int) ($request['payment_batch_id'] ?? 0);
        $offsetPlan = null;
        if ($activeItem === null && in_array($status, ['Processing', 'Paid'], true)) {
            $gross = procurementLocalFinalCents(
                procurementLocalFinalMoneyToCents($request['amount'] ?? 0, 'Supplier Fund Request Amount')
            );
            $supplierId = (int) ($request['supplier_id'] ?? 0);
            $offsetPlan = $supplierId > 0
                ? procurementSupplierReserveCreditForPayment(
                    $conn,
                    $supplierId,
                    'NGN',
                    $gross,
                    'local_final_purchase',
                    $requestId,
                    $actor['id']
                )
                : ['gross_amount' => $gross, 'credit_amount' => '0.00', 'cash_required' => $gross];
        }

        if ($status === 'Processing') {
            $method = trim((string) ($options['processing_method'] ?? $request['processing_method'] ?? 'Manual')) ?: 'Manual';
            $reference = trim((string) ($options['processing_reference'] ?? $request['processing_reference'] ?? 'Manual update')) ?: 'Manual update';
            $creditAmount = (string) ($offsetPlan['credit_amount'] ?? '0.00');
            $update = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Processing', processing_method = ?, processing_reference = ?,
                     processing_started_at = COALESCE(processing_started_at, NOW()),
                     payment_confirmation_status = CASE
                         WHEN expected_completion_at IS NULL THEN 'Not Scheduled' ELSE 'Scheduled' END,
                     supplier_credit_applied = ?, cash_amount_paid = 0.00,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ?"
            );
            $update->bind_param('sssii', $method, $reference, $creditAmount, $actor['id'], $requestId);
            $update->execute();
            $update->close();
        } elseif ($status === 'Paid') {
            $reference = trim((string) (
                $options['payment_reference']
                ?? $request['payment_reference']
                ?? $request['processing_reference']
                ?? 'Manual confirmation'
            ));
            $creditAmount = (string) ($offsetPlan['credit_amount'] ?? '0.00');
            $cashAmount = (string) ($offsetPlan['cash_required'] ?? ($request['amount'] ?? '0.00'));
            $update = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Paid', payment_confirmation_status = 'Confirmed',
                     amount_paid = CAST(amount AS DECIMAL(18,2)), supplier_credit_applied = ?,
                     cash_amount_paid = ?, paid_at = NOW(), payment_reference = ?,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ? AND payment_status <> 'Paid'"
            );
            $update->bind_param('sssii', $creditAmount, $cashAmount, $reference, $actor['id'], $requestId);
            $update->execute();
            $changed = $update->affected_rows === 1;
            $update->close();
            if (!$changed) {
                throw new RuntimeException("Supplier Fund Request $requestId changed before it could be marked Paid.", 409);
            }
            procurementSupplierFinalizeCreditReservations(
                $conn,
                'local_final_purchase',
                $requestId,
                $actor['id'],
                null,
                $reference
            );
        } elseif ($status === 'Pending') {
            $remarks = trim((string) ($options['reason'] ?? $request['account_remarks'] ?? ''));
            $update = $conn->prepare(
                "UPDATE supplier_fund_request_table
                 SET payment_status = 'Pending', payment_confirmation_status = 'Pending', amount_paid = 0.00,
                     processing_method = NULL, processing_reference = NULL, processing_started_at = NULL,
                     processing_business_days = NULL, expected_completion_at = NULL, completion_mode = NULL,
                     paid_at = NULL, payment_reference = NULL, account_remarks = ?, payment_batch_id = NULL,
                     payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ?"
            );
            $update->bind_param('sii', $remarks, $actor['id'], $requestId);
            $update->execute();
            $update->close();
        } else {
            $confirmation = $status;
            $remarks = trim((string) ($options['reason'] ?? $request['account_remarks'] ?? ''));
            $update = $conn->prepare(
                'UPDATE supplier_fund_request_table
                 SET payment_status = ?, payment_confirmation_status = ?, amount_paid = 0.00,
                     supplier_credit_applied = 0.00, cash_amount_paid = 0.00,
                     paid_at = NULL, account_remarks = ?, payment_updated_by = ?, payment_updated_at = NOW()
                 WHERE id = ?'
            );
            $update->bind_param('sssii', $status, $confirmation, $remarks, $actor['id'], $requestId);
            $update->execute();
            $update->close();
        }

        if ($activeItem !== null && in_array($status, ['Failed', 'Cancelled'], true)) {
            $batchId = (int) $activeItem['batch_id'];
            $affectedBatchIds[$batchId] = true;
            $reason = trim((string) ($options['reason'] ?? 'Updated directly in Account.'));
            $item = $conn->prepare(
                accountPaymentStorageUpdateByLegacyIdSql(
            $conn,
            ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
            "item",
            "status = ?, amount_paid = 0.00, paid_at = NULL, status_reason = ?, updated_by = ?",
            "status IN ('Processing', 'Awaiting Confirmation', 'Delayed')"
        )
            );
            $itemStatus = $status;
            $itemId = (int) $activeItem['id'];
            $item->bind_param('ssii', $itemStatus, $reason, $actor['id'], $itemId);
            $item->execute();
            $item->close();
        }

        if ($activeItem === null && in_array($status, ['Pending', 'Failed', 'Cancelled'], true)) {
            procurementSupplierReleaseCreditReservations(
                $conn,
                'local_final_purchase',
                $requestId,
                $actor['id'],
                'Payment was returned or cancelled before completion.'
            );
        }

        accountSupplierRecordEvent(
            $conn,
            $requestId,
            $batchId > 0 ? $batchId : null,
            'payment_status_updated',
            $actor,
            [
                'previous_status' => $current,
                'new_status' => $status,
                'requested_status' => $requestedStatus,
                'source' => 'supplier_fund_request_bulk_status',
            ]
        );
    }

    procurementSyncLocalFinalPurchasePaymentDetails(
        $conn,
        $requestIds,
        $actor['id'],
        'account_payment_status_updated'
    );
    foreach (array_keys($affectedBatchIds) as $batchId) {
        accountSupplierUpdateBatchStatus($conn, (int) $batchId, $actor['id']);
    }

    if (empty($options['suppress_notification'])) {
        $displayStatus = strcasecmp($requestedStatus, 'Unconfirmed') === 0 ? 'Unconfirmed' : $status;
        $routeStatus = $status === 'Processing' ? 'Processing' : $status;
        $severity = 'info';
        if ($status === 'Paid') {
            $severity = 'success';
        } elseif ($status === 'Failed') {
            $severity = 'error';
        } elseif ($status === 'Cancelled') {
            $severity = 'warning';
        }
        accountNotificationPublishSupplierAction(
            $conn,
            'supplier_payment_status_updated',
            'Supplier request status updated',
            $actor['email'] . ' updated ' . count($requestIds)
                . " supplier request(s) to $displayStatus.",
            $requestIds,
            $actor,
            '/payments/fund-request/supplier?payment_status=' . rawurlencode($routeStatus),
            $severity,
            [
                'requested_status' => $requestedStatus,
                'payment_status' => $status,
                'reason' => accountSupplierOptionalText($options, 'reason'),
                'source' => 'supplier_fund_request_bulk_status',
            ]
        );
    }
}
