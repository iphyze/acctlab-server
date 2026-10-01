<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageRuntimeReadService.php';
require_once __DIR__ . '/databaseIdentityService.php';

const ACCOUNT_PAYMENT_CANONICAL_WRITE_MODE_ENV = 'ACCOUNT_PAYMENT_CANONICAL_WRITE_MODE';

function accountPaymentStorageCanonicalWriteMode(): string
{
    return 'canonical';
}

function accountPaymentStorageCanonicalWritesEnabled(mysqli $conn, string $requestType): bool
{
    accountPaymentStorageCanonicalReadsEnabled($conn, $requestType);
    return true;
}

function accountPaymentStorageLegacyMetadata(string $requestType): array
{
    accountPaymentStorageAssertSupportedRequestType($requestType);

    if ($requestType === ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL) {
        return [
            'batch_table' => 'account_supplier_payment_batches',
            'item_table' => 'account_supplier_payment_batch_items',
            'artifact_table' => 'account_supplier_payment_artifacts',
            'request_id_column' => 'supplier_fund_request_id',
            'request_ids_column' => 'supplier_request_ids_json',
        ];
    }

    if ($requestType === ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE) {
        return [
            'batch_table' => 'account_advance_payment_batches',
            'item_table' => 'account_advance_payment_batch_items',
            'artifact_table' => 'account_advance_payment_artifacts',
            'request_id_column' => 'advance_payment_request_id',
            'request_ids_column' => 'advance_request_ids_json',
        ];
    }

    if ($requestType === ACCOUNT_PAYMENT_TYPE_COMPASS) {
        // Compass has no parallel legacy payment tables. These source labels
        // exist only as canonical provenance namespaces so its public batch /
        // item IDs remain independent from Supplier and Advance IDs.
        return [
            'batch_table' => 'account_compass_payment_batches',
            'item_table' => 'account_compass_payment_batch_items',
            'artifact_table' => 'account_compass_payment_artifacts',
            'request_id_column' => 'compass_fund_request_id',
            'request_ids_column' => 'compass_request_ids_json',
        ];
    }

    // FX processing has never had a parallel legacy batch table. The canonical
    // tables are the source of truth, so their own names are used only as
    // provenance metadata while request_type keeps Final and Advance isolated.
    return [
        'batch_table' => 'account_payment_batches',
        'item_table' => 'account_payment_batch_items',
        'artifact_table' => 'account_payment_artifacts',
        'request_id_column' => 'fx_fund_request_id',
        'request_ids_column' => 'fx_request_ids_json',
    ];
}

function accountPaymentStorageWriteSources(mysqli $conn, string $requestType): array
{
    accountPaymentStorageCanonicalWritesEnabled($conn, $requestType);
    $type = $conn->real_escape_string($requestType);

    return [
        'canonical' => true,
        'batches' => 'account_payment_batches',
        'items' => 'account_payment_batch_items',
        'artifacts' => 'account_payment_artifacts',
        'batch_id_column' => 'legacy_source_id',
        'item_id_column' => 'legacy_source_id',
        'artifact_id_column' => 'legacy_source_id',
        'batch_scope' => "request_type = '{$type}'",
        'item_scope' => "request_type = '{$type}'",
        'artifact_scope' => "request_type = '{$type}'",
    ];
}

function accountPaymentStorageBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $index => $value) {
        $params[$index] = $value;
        $refs[$index] = &$params[$index];
    }
    $stmt->bind_param($types, ...$refs);
}

function accountPaymentStorageAcquireIdLock(mysqli $conn, string $sourceTable = ''): string
{
    static $held = [];

    $connectionId = spl_object_id($conn);
    $lock = 'acct_payment_canonical_write_ids';
    if (($held[$connectionId] ?? false) === true) {
        return $lock;
    }

    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $stmt->bind_param('s', $lock);
    $stmt->execute();
    $acquired = (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $stmt->close();
    if (!$acquired) {
        throw new RuntimeException('Unable to reserve a payment storage identifier.', 503);
    }

    $held[$connectionId] = true;
    register_shutdown_function(static function () use ($conn, $lock): void {
        try {
            $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt) {
                $stmt->bind_param('s', $lock);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable) {
            // Connection shutdown will release the advisory lock.
        }
    });

    return $lock;
}

function accountPaymentStorageReleaseIdLock(mysqli $conn, string $lock): void
{
    // Canonical write identifiers stay reserved until request shutdown so
    // uncommitted rows cannot race another payment creation request.
}

function accountPaymentStorageNextLegacyId(mysqli $conn, string $sourceTable): int
{
    // Allocate the next scoped legacy source ID inside the canonical DB.
    $canonicalTable = str_contains($sourceTable, 'batch_items')
        ? 'account_payment_batch_items'
        : (str_contains($sourceTable, 'artifacts')
            ? 'account_payment_artifacts'
            : 'account_payment_batches');

    return databaseIdentityNextScopedId(
        $conn,
        $canonicalTable,
        'legacy_source_id',
        'legacy_source_table',
        $sourceTable
    );
}

function accountPaymentStorageCanonicalBatchId(mysqli $conn, string $requestType, int $legacyBatchId): int
{
    $stmt = $conn->prepare(
        'SELECT id FROM account_payment_batches
         WHERE request_type = ? AND legacy_source_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('si', $requestType, $legacyBatchId);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    if ($id <= 0) {
        throw new RuntimeException('The canonical payment batch mapping is missing.', 409);
    }
    return $id;
}

function accountPaymentStorageCreateBatch(mysqli $conn, string $requestType, array $data): int
{
    $legacy = accountPaymentStorageLegacyMetadata($requestType);
    accountPaymentStorageCanonicalWritesEnabled($conn, $requestType);


    $sourceTable = $legacy['batch_table'];
    accountPaymentStorageAcquireIdLock($conn, $sourceTable);
    $legacyId = accountPaymentStorageNextLegacyId($conn, $sourceTable);
        $stmt = $conn->prepare(
            'INSERT INTO account_payment_batches
                (request_type, legacy_source_table, legacy_source_id, batch_reference,
                 processing_method, processing_reference, processing_business_days,
                 expected_completion_at, completion_mode, status, item_count, total_amount,
                 account_remarks, created_by, updated_by, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $types = 'ssisssisssidsiis';
        $params = [
            $requestType, $sourceTable, $legacyId, $data['batch_reference'],
            $data['processing_method'], $data['processing_reference'], $data['processing_business_days'],
            $data['expected_completion_at'], $data['completion_mode'], $data['status'],
            $data['item_count'], $data['total_amount'], $data['account_remarks'],
            $data['created_by'], $data['updated_by'], $data['completed_at'],
        ];
        accountPaymentStorageBind($stmt, $types, $params);
        $stmt->execute();
    $stmt->close();
    return $legacyId;
}

function accountPaymentStorageCreateItem(mysqli $conn, string $requestType, int $legacyBatchId, array $data): int
{
    $legacy = accountPaymentStorageLegacyMetadata($requestType);
    accountPaymentStorageCanonicalWritesEnabled($conn, $requestType);


    $sourceTable = $legacy['item_table'];
    accountPaymentStorageAcquireIdLock($conn, $sourceTable);
    $legacyId = accountPaymentStorageNextLegacyId($conn, $sourceTable);
        $canonicalBatchId = accountPaymentStorageCanonicalBatchId($conn, $requestType, $legacyBatchId);
        $hasLegacyBatchId = accountPaymentStorageColumnExists(
            $conn,
            'account_payment_batch_items',
            'legacy_batch_id'
        );
        if ($hasLegacyBatchId) {
            $stmt = $conn->prepare(
                'INSERT INTO account_payment_batch_items
                    (batch_id, legacy_batch_id, request_type, request_id, legacy_source_table, legacy_source_id,
                     amount, amount_paid, status, status_reason, processing_started_at,
                     expected_completion_at, paid_at, payment_reference, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $types = 'iisisiddssssssi';
            $params = [
                $canonicalBatchId, $legacyBatchId, $requestType, $data['request_id'], $sourceTable, $legacyId,
                $data['amount'], $data['amount_paid'], $data['status'], $data['status_reason'],
                $data['processing_started_at'], $data['expected_completion_at'], $data['paid_at'],
                $data['payment_reference'], $data['updated_by'],
            ];
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO account_payment_batch_items
                    (batch_id, request_type, request_id, legacy_source_table, legacy_source_id,
                     amount, amount_paid, status, status_reason, processing_started_at,
                     expected_completion_at, paid_at, payment_reference, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $types = 'isisiddssssssi';
            $params = [
                $canonicalBatchId, $requestType, $data['request_id'], $sourceTable, $legacyId,
                $data['amount'], $data['amount_paid'], $data['status'], $data['status_reason'],
                $data['processing_started_at'], $data['expected_completion_at'], $data['paid_at'],
                $data['payment_reference'], $data['updated_by'],
            ];
        }
        accountPaymentStorageBind($stmt, $types, $params);
        $stmt->execute();
    $stmt->close();
    return $legacyId;
}

function accountPaymentStorageUpsertArtifact(
    mysqli $conn,
    string $requestType,
    int $legacyBatchId,
    array $data
): int {
    $legacy = accountPaymentStorageLegacyMetadata($requestType);
    accountPaymentStorageCanonicalWritesEnabled($conn, $requestType);


    $canonicalBatchId = accountPaymentStorageCanonicalBatchId($conn, $requestType, $legacyBatchId);
    $find = $conn->prepare(
        'SELECT legacy_source_id FROM account_payment_artifacts
         WHERE batch_id = ? AND request_type = ? AND artifact_type = ? AND artifact_id = ?
         LIMIT 1'
    );
    $find->bind_param('issi', $canonicalBatchId, $requestType, $data['artifact_type'], $data['artifact_id']);
    $find->execute();
    $existingId = (int) ($find->get_result()->fetch_assoc()['legacy_source_id'] ?? 0);
    $find->close();

    if ($existingId > 0) {
        $update = $conn->prepare(
            "UPDATE account_payment_artifacts
             SET artifact_reference = ?, artifact_route = ?, request_ids_json = ?,
                 artifact_status = 'Active', removed_at = NULL, removed_by = NULL,
                 removal_reason = NULL, created_by = ?
             WHERE request_type = ? AND legacy_source_id = ?"
        );
        $update->bind_param(
            'sssisi',
            $data['artifact_reference'],
            $data['artifact_route'],
            $data['request_ids_json'],
            $data['created_by'],
            $requestType,
            $existingId
        );
        $update->execute();
        $update->close();
        return $existingId;
    }

    $sourceTable = $legacy['artifact_table'];
    accountPaymentStorageAcquireIdLock($conn, $sourceTable);
    $legacyId = accountPaymentStorageNextLegacyId($conn, $sourceTable);
        $hasLegacyBatchId = accountPaymentStorageColumnExists(
            $conn,
            'account_payment_artifacts',
            'legacy_batch_id'
        );
        if ($hasLegacyBatchId) {
            $stmt = $conn->prepare(
                "INSERT INTO account_payment_artifacts
                    (batch_id, legacy_batch_id, request_type, legacy_source_table, legacy_source_id,
                     artifact_type, artifact_id, artifact_reference, artifact_route,
                     request_ids_json, artifact_status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)"
            );
            $types = 'iissisisssi';
            $params = [
                $canonicalBatchId, $legacyBatchId, $requestType, $sourceTable, $legacyId,
                $data['artifact_type'], $data['artifact_id'], $data['artifact_reference'],
                $data['artifact_route'], $data['request_ids_json'], $data['created_by'],
            ];
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO account_payment_artifacts
                    (batch_id, request_type, legacy_source_table, legacy_source_id,
                     artifact_type, artifact_id, artifact_reference, artifact_route,
                     request_ids_json, artifact_status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)"
            );
            $types = 'issisisssi';
            $params = [
                $canonicalBatchId, $requestType, $sourceTable, $legacyId,
                $data['artifact_type'], $data['artifact_id'], $data['artifact_reference'],
                $data['artifact_route'], $data['request_ids_json'], $data['created_by'],
            ];
        }
        accountPaymentStorageBind($stmt, $types, $params);
        $stmt->execute();
    $stmt->close();
    return $legacyId;
}

function accountPaymentStorageWriteEntityMetadata(mysqli $conn, string $requestType, string $entity): array
{
    $sources = accountPaymentStorageWriteSources($conn, $requestType);
    return match ($entity) {
        'batch' => [
            'table' => $sources['batches'],
            'id_column' => $sources['batch_id_column'],
            'scope' => $sources['batch_scope'],
        ],
        'item' => [
            'table' => $sources['items'],
            'id_column' => $sources['item_id_column'],
            'scope' => $sources['item_scope'],
        ],
        'artifact' => [
            'table' => $sources['artifacts'],
            'id_column' => $sources['artifact_id_column'],
            'scope' => $sources['artifact_scope'],
        ],
        default => throw new InvalidArgumentException('Unsupported payment write entity.'),
    };
}

function accountPaymentStorageUpdateByLegacyIdSql(
    mysqli $conn,
    string $requestType,
    string $entity,
    string $setSql,
    string $extraWhere = ''
): string {
    $target = accountPaymentStorageWriteEntityMetadata($conn, $requestType, $entity);
    $extra = trim($extraWhere) !== '' ? ' AND (' . trim($extraWhere) . ')' : '';
    return "UPDATE {$target['table']} SET {$setSql}
            WHERE {$target['id_column']} = ? AND ({$target['scope']}){$extra}";
}

function accountPaymentStorageUpdateByLegacyIdsSql(
    mysqli $conn,
    string $requestType,
    string $entity,
    string $setSql,
    string $placeholders,
    string $extraWhere = ''
): string {
    $target = accountPaymentStorageWriteEntityMetadata($conn, $requestType, $entity);
    $extra = trim($extraWhere) !== '' ? ' AND (' . trim($extraWhere) . ')' : '';
    return "UPDATE {$target['table']} SET {$setSql}
            WHERE {$target['id_column']} IN ({$placeholders}) AND ({$target['scope']}){$extra}";
}
