<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageRuntimeSupportService.php';

const ACCOUNT_PAYMENT_CANONICAL_READ_MODE_ENV = 'ACCOUNT_PAYMENT_CANONICAL_READ_MODE';


function accountPaymentStorageAssertSupportedRequestType(string $requestType): void
{
    if (!in_array($requestType, [
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
        ACCOUNT_PAYMENT_TYPE_FX_FINAL,
        ACCOUNT_PAYMENT_TYPE_FX_ADVANCE,
        ACCOUNT_PAYMENT_TYPE_FX_MANUAL,
        ACCOUNT_PAYMENT_TYPE_COMPASS,
    ], true)) {
        throw new InvalidArgumentException('Unsupported payment request type.');
    }
}

function accountPaymentStorageCanonicalReadMode(): string
{
    return 'canonical';
}

function accountPaymentStorageCanonicalRuntimeVerification(mysqli $conn): array
{
    $requiredTables = accountPaymentStorageTargets();
    $objectTypes = [];
    $checks = [];

    foreach ($requiredTables as $table) {
        $objectTypes[$table] = accountPaymentStorageObjectType($conn, $table);
        $checks[$table . '_is_base_table'] = $objectTypes[$table] === 'BASE TABLE';
    }

    $requiredColumns = [
        'account_payment_batches' => [
            'id', 'request_type', 'legacy_source_table', 'legacy_source_id',
            'batch_reference', 'processing_method', 'processing_reference',
            'processing_business_days', 'expected_completion_at', 'completion_mode',
            'status', 'item_count', 'total_amount', 'account_remarks',
            'notification_created_at', 'created_by', 'created_at', 'updated_by',
            'updated_at', 'completed_at',
        ],
        'account_payment_batch_items' => [
            'id', 'batch_id', 'request_type', 'request_id', 'legacy_source_table',
            'legacy_source_id', 'amount', 'amount_paid', 'status', 'status_reason',
            'processing_started_at', 'expected_completion_at', 'paid_at',
            'payment_reference', 'updated_by', 'created_at', 'updated_at',
        ],
        'account_payment_artifacts' => [
            'id', 'batch_id', 'request_type', 'legacy_source_table', 'legacy_source_id',
            'artifact_type', 'artifact_id', 'artifact_reference', 'artifact_route',
            'request_ids_json', 'artifact_status', 'removed_at', 'removed_by',
            'removal_reason', 'created_by', 'created_at',
        ],
    ];

    $missingColumns = [];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (!accountPaymentStorageColumnExists($conn, $table, $column)) {
                $missingColumns[$table][] = $column;
            }
        }
    }
    $checks['required_columns_present'] = $missingColumns === [];

    $integrity = [
        'missing_batch_public_ids' => 0,
        'missing_item_public_ids' => 0,
        'missing_artifact_public_ids' => 0,
        'duplicate_batch_public_ids' => 0,
        'duplicate_item_public_ids' => 0,
        'duplicate_artifact_public_ids' => 0,
        'orphan_items' => 0,
        'orphan_artifacts' => 0,
        'request_type_mismatches' => 0,
    ];

    if (!in_array(false, array_slice($checks, 0, 3), true)) {
        $integrity['missing_batch_public_ids'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM account_payment_batches WHERE legacy_source_id IS NULL OR legacy_source_id <= 0'
        );
        $integrity['missing_item_public_ids'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM account_payment_batch_items WHERE legacy_source_id IS NULL OR legacy_source_id <= 0'
        );
        $integrity['missing_artifact_public_ids'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM account_payment_artifacts WHERE legacy_source_id IS NULL OR legacy_source_id <= 0'
        );
        $integrity['duplicate_batch_public_ids'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM (SELECT request_type, legacy_source_id FROM account_payment_batches GROUP BY request_type, legacy_source_id HAVING COUNT(*) > 1) duplicate_rows'
        );
        $integrity['duplicate_item_public_ids'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM (SELECT request_type, legacy_source_id FROM account_payment_batch_items GROUP BY request_type, legacy_source_id HAVING COUNT(*) > 1) duplicate_rows'
        );
        $integrity['duplicate_artifact_public_ids'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM (SELECT request_type, legacy_source_id FROM account_payment_artifacts GROUP BY request_type, legacy_source_id HAVING COUNT(*) > 1) duplicate_rows'
        );
        $integrity['orphan_items'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM account_payment_batch_items item LEFT JOIN account_payment_batches batch ON batch.id = item.batch_id WHERE batch.id IS NULL'
        );
        $integrity['orphan_artifacts'] = accountPaymentStorageCount(
            $conn,
            'SELECT COUNT(*) AS total FROM account_payment_artifacts artifact LEFT JOIN account_payment_batches batch ON batch.id = artifact.batch_id WHERE batch.id IS NULL'
        );
        $integrity['request_type_mismatches'] = accountPaymentStorageCount(
            $conn,
            'SELECT (SELECT COUNT(*) FROM account_payment_batch_items item INNER JOIN account_payment_batches batch ON batch.id = item.batch_id WHERE item.request_type <> batch.request_type) + (SELECT COUNT(*) FROM account_payment_artifacts artifact INNER JOIN account_payment_batches batch ON batch.id = artifact.batch_id WHERE artifact.request_type <> batch.request_type) AS total'
        );
    }

    $checks['public_ids_are_complete'] = $integrity['missing_batch_public_ids'] === 0
        && $integrity['missing_item_public_ids'] === 0
        && $integrity['missing_artifact_public_ids'] === 0;
    $checks['public_ids_are_unique_per_request_type'] = $integrity['duplicate_batch_public_ids'] === 0
        && $integrity['duplicate_item_public_ids'] === 0
        && $integrity['duplicate_artifact_public_ids'] === 0;
    $checks['relationships_are_valid'] = $integrity['orphan_items'] === 0
        && $integrity['orphan_artifacts'] === 0
        && $integrity['request_type_mismatches'] === 0;

    return [
        'healthy' => !in_array(false, $checks, true),
        'checks' => $checks,
        'object_types' => $objectTypes,
        'missing_columns' => $missingColumns,
        'integrity' => $integrity,
        'base_table_count' => accountPaymentStorageBaseTableCount($conn),
    ];
}

function accountPaymentStorageCanonicalVerificationFailureMessage(array $verification): string
{
    $issues = [];

    $objectTypes = is_array($verification['object_types'] ?? null)
        ? $verification['object_types']
        : [];
    foreach (accountPaymentStorageTargets() as $table) {
        $type = strtoupper(trim((string) ($objectTypes[$table] ?? '')));
        if ($type === 'BASE TABLE') {
            continue;
        }
        $issues[] = $type === ''
            ? $table . ' is missing'
            : $table . ' must be a BASE TABLE (found ' . $type . ')';
    }

    $missingColumns = is_array($verification['missing_columns'] ?? null)
        ? $verification['missing_columns']
        : [];
    foreach ($missingColumns as $table => $columns) {
        if (!is_array($columns) || $columns === []) {
            continue;
        }
        $issues[] = $table . ' missing columns: ' . implode(', ', array_map('strval', $columns));
    }

    $integrity = is_array($verification['integrity'] ?? null)
        ? $verification['integrity']
        : [];
    foreach ($integrity as $check => $count) {
        $count = (int) $count;
        if ($count > 0) {
            $issues[] = $check . '=' . $count;
        }
    }

    if ($issues === []) {
        $checks = is_array($verification['checks'] ?? null) ? $verification['checks'] : [];
        $failedChecks = [];
        foreach ($checks as $check => $passed) {
            if ($passed !== true) {
                $failedChecks[] = (string) $check;
            }
        }
        if ($failedChecks !== []) {
            $issues[] = 'failed checks: ' . implode(', ', $failedChecks);
        }
    }

    return 'Canonical payment storage is incomplete'
        . ($issues !== [] ? ': ' . implode('; ', $issues) : '')
        . '.';
}

function accountPaymentStorageCanonicalReadsEnabled(mysqli $conn, string $requestType): bool
{
    accountPaymentStorageAssertSupportedRequestType($requestType);
    static $cache = [];
    $cacheKey = spl_object_id($conn);
    if (!array_key_exists($cacheKey, $cache)) {
        $cache[$cacheKey] = accountPaymentStorageCanonicalRuntimeVerification($conn);
    }
    if (($cache[$cacheKey]['healthy'] ?? false) !== true) {
        throw new RuntimeException(accountPaymentStorageCanonicalVerificationFailureMessage($cache[$cacheKey]), 503);
    }
    return true;
}

function accountPaymentStorageCanonicalProjectionType(string $requestType): string
{
    accountPaymentStorageAssertSupportedRequestType($requestType);
    return $requestType;
}


function accountPaymentStorageCanonicalBatchLegacyColumns(string $alias = 'batch'): string
{
    return "{$alias}.legacy_source_id AS id,
            {$alias}.batch_reference, {$alias}.processing_method, {$alias}.processing_reference,
            {$alias}.processing_business_days, {$alias}.expected_completion_at,
            {$alias}.completion_mode, {$alias}.status, {$alias}.item_count,
            {$alias}.total_amount, {$alias}.account_remarks, {$alias}.notification_created_at,
            {$alias}.created_by, {$alias}.created_at, {$alias}.updated_by,
            {$alias}.updated_at, {$alias}.completed_at";
}

function accountPaymentStorageCanonicalItemLegacyColumns(
    string $requestType,
    string $itemAlias = 'item',
    string $batchAlias = 'batch'
): string {
    accountPaymentStorageAssertSupportedRequestType($requestType);
    $requestIdAlias = match ($requestType) {
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => 'supplier_fund_request_id',
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => 'advance_payment_request_id',
        ACCOUNT_PAYMENT_TYPE_FX_FINAL, ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => 'fx_fund_request_id',
        ACCOUNT_PAYMENT_TYPE_FX_MANUAL => 'manual_fx_payment_id',
        ACCOUNT_PAYMENT_TYPE_COMPASS => 'compass_fund_request_id',
        default => 'request_id',
    };

    return "{$itemAlias}.legacy_source_id AS id,
            {$batchAlias}.legacy_source_id AS batch_id,
            {$itemAlias}.request_id AS {$requestIdAlias},
            {$itemAlias}.amount, {$itemAlias}.amount_paid, {$itemAlias}.status,
            {$itemAlias}.status_reason, {$itemAlias}.processing_started_at,
            {$itemAlias}.expected_completion_at, {$itemAlias}.paid_at,
            {$itemAlias}.payment_reference, {$itemAlias}.updated_by,
            {$itemAlias}.created_at, {$itemAlias}.updated_at";
}

function accountPaymentStorageCanonicalArtifactLegacyColumns(
    string $requestType,
    string $artifactAlias = 'artifact',
    string $batchAlias = 'batch'
): string {
    accountPaymentStorageAssertSupportedRequestType($requestType);
    $requestIdsAlias = match ($requestType) {
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => 'supplier_request_ids_json',
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => 'advance_request_ids_json',
        ACCOUNT_PAYMENT_TYPE_FX_FINAL, ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => 'fx_request_ids_json',
        ACCOUNT_PAYMENT_TYPE_FX_MANUAL => 'manual_fx_payment_ids_json',
        ACCOUNT_PAYMENT_TYPE_COMPASS => 'compass_request_ids_json',
        default => 'request_ids_json',
    };

    return "{$artifactAlias}.legacy_source_id AS id,
            {$batchAlias}.legacy_source_id AS batch_id,
            {$artifactAlias}.artifact_type, {$artifactAlias}.artifact_id,
            {$artifactAlias}.artifact_reference, {$artifactAlias}.artifact_route,
            {$artifactAlias}.request_ids_json AS {$requestIdsAlias},
            {$artifactAlias}.artifact_status, {$artifactAlias}.removed_at,
            {$artifactAlias}.removed_by, {$artifactAlias}.removal_reason,
            {$artifactAlias}.created_by, {$artifactAlias}.created_at";
}

function accountPaymentStorageCanonicalBatchProjection(string $requestType): string
{
    $type = accountPaymentStorageCanonicalProjectionType($requestType);
    return "(SELECT legacy_source_id AS id, batch_reference, processing_method, processing_reference,
                    processing_business_days, expected_completion_at, completion_mode, status,
                    item_count, total_amount, account_remarks, notification_created_at,
                    created_by, created_at, updated_by, updated_at, completed_at
             FROM account_payment_batches WHERE request_type = '{$type}')";
}

function accountPaymentStorageCanonicalItemProjection(string $requestType): string
{
    $type = accountPaymentStorageCanonicalProjectionType($requestType);
    $requestIdAlias = match ($requestType) {
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => 'supplier_fund_request_id',
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => 'advance_payment_request_id',
        ACCOUNT_PAYMENT_TYPE_FX_FINAL, ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => 'fx_fund_request_id',
        ACCOUNT_PAYMENT_TYPE_FX_MANUAL => 'manual_fx_payment_id',
        ACCOUNT_PAYMENT_TYPE_COMPASS => 'compass_fund_request_id',
        default => 'request_id',
    };

    return "(SELECT item.legacy_source_id AS id,
                    batch.legacy_source_id AS batch_id,
                    item.request_id AS {$requestIdAlias},
                    item.amount, item.amount_paid, item.status, item.status_reason,
                    item.processing_started_at, item.expected_completion_at, item.paid_at,
                    item.payment_reference, item.updated_by, item.created_at, item.updated_at
             FROM account_payment_batch_items item
             INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
             WHERE item.request_type = '{$type}' AND batch.request_type = '{$type}')";
}

function accountPaymentStorageCanonicalArtifactProjection(string $requestType): string
{
    $type = accountPaymentStorageCanonicalProjectionType($requestType);
    $requestIdsAlias = match ($requestType) {
        ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL => 'supplier_request_ids_json',
        ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE => 'advance_request_ids_json',
        ACCOUNT_PAYMENT_TYPE_FX_FINAL, ACCOUNT_PAYMENT_TYPE_FX_ADVANCE => 'fx_request_ids_json',
        ACCOUNT_PAYMENT_TYPE_FX_MANUAL => 'manual_fx_payment_ids_json',
        ACCOUNT_PAYMENT_TYPE_COMPASS => 'compass_request_ids_json',
        default => 'request_ids_json',
    };

    return "(SELECT artifact.legacy_source_id AS id,
                    batch.legacy_source_id AS batch_id,
                    artifact.artifact_type, artifact.artifact_id, artifact.artifact_reference,
                    artifact.artifact_route, artifact.request_ids_json AS {$requestIdsAlias},
                    artifact.artifact_status, artifact.removed_at, artifact.removed_by,
                    artifact.removal_reason, artifact.created_by, artifact.created_at
             FROM account_payment_artifacts artifact
             INNER JOIN account_payment_batches batch ON batch.id = artifact.batch_id
             WHERE artifact.request_type = '{$type}' AND batch.request_type = '{$type}')";
}

function accountPaymentStorageReadSources(mysqli $conn, string $requestType): array
{
    accountPaymentStorageCanonicalReadsEnabled($conn, $requestType);
    return [
        'canonical' => true,
        'batches' => accountPaymentStorageCanonicalBatchProjection($requestType),
        'items' => accountPaymentStorageCanonicalItemProjection($requestType),
        'artifacts' => accountPaymentStorageCanonicalArtifactProjection($requestType),
    ];
}
