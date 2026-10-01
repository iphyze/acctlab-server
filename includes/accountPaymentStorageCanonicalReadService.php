<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageRuntimeReadService.php';

function accountPaymentCanonicalReadScalar(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc() ?: [];
    return (int) ($row['total'] ?? 0);
}

function accountPaymentCanonicalReadSamples(mysqli $conn, string $sql): array
{
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    return array_values(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $rows
    ));
}

function accountPaymentCanonicalReadBatchMismatchSql(string $sourceTable, string $requestType): string
{
    return "SELECT COUNT(*) AS total
            FROM `{$sourceTable}` source
            LEFT JOIN account_payment_batches canonical
              ON canonical.legacy_source_table = '{$sourceTable}'
             AND canonical.legacy_source_id = source.id
             AND canonical.request_type = '{$requestType}'
            WHERE canonical.id IS NULL
               OR NOT (
                    canonical.batch_reference <=> source.batch_reference
                AND canonical.processing_method <=> source.processing_method
                AND canonical.processing_reference <=> source.processing_reference
                AND canonical.processing_business_days <=> source.processing_business_days
                AND canonical.expected_completion_at <=> source.expected_completion_at
                AND canonical.completion_mode <=> source.completion_mode
                AND canonical.status <=> source.status
                AND canonical.item_count <=> source.item_count
                AND canonical.total_amount <=> source.total_amount
                AND canonical.account_remarks <=> source.account_remarks
                AND canonical.notification_created_at <=> source.notification_created_at
                AND canonical.created_by <=> source.created_by
                AND canonical.created_at <=> source.created_at
                AND canonical.updated_by <=> source.updated_by
                AND canonical.updated_at <=> source.updated_at
                AND canonical.completed_at <=> source.completed_at
               )";
}

function accountPaymentCanonicalReadBatchSampleSql(string $sourceTable, string $requestType): string
{
    return str_replace(
        'SELECT COUNT(*) AS total',
        'SELECT source.id',
        accountPaymentCanonicalReadBatchMismatchSql($sourceTable, $requestType)
    ) . ' ORDER BY source.id ASC LIMIT 20';
}

function accountPaymentCanonicalReadItemMismatchSql(
    string $sourceTable,
    string $batchSourceTable,
    string $requestType,
    string $requestIdColumn
): string {
    return "SELECT COUNT(*) AS total
            FROM `{$sourceTable}` source
            LEFT JOIN account_payment_batch_items canonical
              ON canonical.legacy_source_table = '{$sourceTable}'
             AND canonical.legacy_source_id = source.id
             AND canonical.request_type = '{$requestType}'
            LEFT JOIN account_payment_batches canonical_batch
              ON canonical_batch.id = canonical.batch_id
            WHERE canonical.id IS NULL
               OR canonical_batch.id IS NULL
               OR canonical_batch.legacy_source_table <> '{$batchSourceTable}'
               OR canonical_batch.legacy_source_id <> source.batch_id
               OR NOT (
                    canonical.request_id <=> source.`{$requestIdColumn}`
                AND canonical.amount <=> source.amount
                AND canonical.amount_paid <=> source.amount_paid
                AND canonical.status <=> source.status
                AND canonical.status_reason <=> source.status_reason
                AND canonical.processing_started_at <=> source.processing_started_at
                AND canonical.expected_completion_at <=> source.expected_completion_at
                AND canonical.paid_at <=> source.paid_at
                AND canonical.payment_reference <=> source.payment_reference
                AND canonical.updated_by <=> source.updated_by
                AND canonical.created_at <=> source.created_at
                AND canonical.updated_at <=> source.updated_at
               )";
}

function accountPaymentCanonicalReadItemSampleSql(
    string $sourceTable,
    string $batchSourceTable,
    string $requestType,
    string $requestIdColumn
): string {
    return str_replace(
        'SELECT COUNT(*) AS total',
        'SELECT source.id',
        accountPaymentCanonicalReadItemMismatchSql(
            $sourceTable,
            $batchSourceTable,
            $requestType,
            $requestIdColumn
        )
    ) . ' ORDER BY source.id ASC LIMIT 20';
}

function accountPaymentCanonicalReadArtifactMismatchSql(
    string $sourceTable,
    string $batchSourceTable,
    string $requestType,
    string $requestIdsColumn
): string {
    return "SELECT COUNT(*) AS total
            FROM `{$sourceTable}` source
            LEFT JOIN account_payment_artifacts canonical
              ON canonical.legacy_source_table = '{$sourceTable}'
             AND canonical.legacy_source_id = source.id
             AND canonical.request_type = '{$requestType}'
            LEFT JOIN account_payment_batches canonical_batch
              ON canonical_batch.id = canonical.batch_id
            WHERE canonical.id IS NULL
               OR canonical_batch.id IS NULL
               OR canonical_batch.legacy_source_table <> '{$batchSourceTable}'
               OR canonical_batch.legacy_source_id <> source.batch_id
               OR NOT (
                    canonical.artifact_type <=> source.artifact_type
                AND canonical.artifact_id <=> source.artifact_id
                AND canonical.artifact_reference <=> source.artifact_reference
                AND canonical.artifact_route <=> source.artifact_route
                AND canonical.request_ids_json <=> source.`{$requestIdsColumn}`
                AND canonical.artifact_status <=> source.artifact_status
                AND canonical.removed_at <=> source.removed_at
                AND canonical.removed_by <=> source.removed_by
                AND canonical.removal_reason <=> source.removal_reason
                AND canonical.created_by <=> source.created_by
                AND canonical.created_at <=> source.created_at
               )";
}

function accountPaymentCanonicalReadArtifactSampleSql(
    string $sourceTable,
    string $batchSourceTable,
    string $requestType,
    string $requestIdsColumn
): string {
    return str_replace(
        'SELECT COUNT(*) AS total',
        'SELECT source.id',
        accountPaymentCanonicalReadArtifactMismatchSql(
            $sourceTable,
            $batchSourceTable,
            $requestType,
            $requestIdsColumn
        )
    ) . ' ORDER BY source.id ASC LIMIT 20';
}

function accountPaymentCanonicalReadDefinitions(): array
{
    return [
        'local_final_batches' => [
            'mismatch_sql' => accountPaymentCanonicalReadBatchMismatchSql(
                'account_supplier_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL
            ),
            'sample_sql' => accountPaymentCanonicalReadBatchSampleSql(
                'account_supplier_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL
            ),
        ],
        'local_advance_batches' => [
            'mismatch_sql' => accountPaymentCanonicalReadBatchMismatchSql(
                'account_advance_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE
            ),
            'sample_sql' => accountPaymentCanonicalReadBatchSampleSql(
                'account_advance_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE
            ),
        ],
        'local_final_items' => [
            'mismatch_sql' => accountPaymentCanonicalReadItemMismatchSql(
                'account_supplier_payment_batch_items',
                'account_supplier_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                'supplier_fund_request_id'
            ),
            'sample_sql' => accountPaymentCanonicalReadItemSampleSql(
                'account_supplier_payment_batch_items',
                'account_supplier_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                'supplier_fund_request_id'
            ),
        ],
        'local_advance_items' => [
            'mismatch_sql' => accountPaymentCanonicalReadItemMismatchSql(
                'account_advance_payment_batch_items',
                'account_advance_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
                'advance_payment_request_id'
            ),
            'sample_sql' => accountPaymentCanonicalReadItemSampleSql(
                'account_advance_payment_batch_items',
                'account_advance_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
                'advance_payment_request_id'
            ),
        ],
        'local_final_artifacts' => [
            'mismatch_sql' => accountPaymentCanonicalReadArtifactMismatchSql(
                'account_supplier_payment_artifacts',
                'account_supplier_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                'supplier_request_ids_json'
            ),
            'sample_sql' => accountPaymentCanonicalReadArtifactSampleSql(
                'account_supplier_payment_artifacts',
                'account_supplier_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL,
                'supplier_request_ids_json'
            ),
        ],
        'local_advance_artifacts' => [
            'mismatch_sql' => accountPaymentCanonicalReadArtifactMismatchSql(
                'account_advance_payment_artifacts',
                'account_advance_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
                'advance_request_ids_json'
            ),
            'sample_sql' => accountPaymentCanonicalReadArtifactSampleSql(
                'account_advance_payment_artifacts',
                'account_advance_payment_batches',
                ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE,
                'advance_request_ids_json'
            ),
        ],
    ];
}

function accountPaymentCanonicalReadCompatibilityPhase(mysqli $conn): array
{
    $types = [];
    foreach (accountPaymentStorageRequiredSources() as $name) {
        $types[$name] = accountPaymentStorageObjectType($conn, $name);
    }

    $total = count($types);
    $viewCount = count(array_filter(
        $types,
        static fn(?string $type): bool => $type === 'VIEW'
    ));
    $absentCount = count(array_filter(
        $types,
        static fn(?string $type): bool => $type === null
    ));

    return [
        'supported' => $viewCount === $total || $absentCount === $total,
        'all_views' => $viewCount === $total,
        'all_retired' => $absentCount === $total,
        'mixed' => $viewCount !== $total && $absentCount !== $total,
        'object_types' => $types,
        'phase' => $viewCount === $total
            ? 'compatibility_views_present'
            : ($absentCount === $total ? 'compatibility_views_retired' : 'unsupported_mixed_state'),
    ];
}

function accountPaymentCanonicalReadVerify(mysqli $conn): array
{
    $phase = accountPaymentCanonicalReadCompatibilityPhase($conn);
    $canonical = accountPaymentStorageCanonicalRuntimeVerification($conn);
    $mismatches = array_fill_keys(array_keys(accountPaymentCanonicalReadDefinitions()), 0);
    $samples = array_fill_keys(array_keys(accountPaymentCanonicalReadDefinitions()), []);
    $storage = $canonical;
    $bridge = [
        'healthy' => true,
        'trigger_count' => 0,
        'expected_trigger_count' => 0,
        'missing_triggers' => [],
        'status' => $phase['all_retired'] ? 'not_required_after_view_retirement' : 'not_checked',
    ];

    if ($phase['all_views']) {
        $storage = accountPaymentStorageVerify($conn);
        $bridge = accountPaymentStorageBridgeVerify($conn);

        foreach (accountPaymentCanonicalReadDefinitions() as $name => $definition) {
            $mismatches[$name] = accountPaymentCanonicalReadScalar(
                $conn,
                (string) $definition['mismatch_sql']
            );
            $samples[$name] = $mismatches[$name] > 0
                ? accountPaymentCanonicalReadSamples($conn, (string) $definition['sample_sql'])
                : [];
        }
    }

    $legacyComparisonsHealthy = $phase['all_retired'] || array_sum($mismatches) === 0;
    $checks = [
        'canonical_storage_healthy' => ($canonical['healthy'] ?? false) === true,
        'compatibility_storage_objects_in_supported_phase' => $phase['supported'] === true,
        'legacy_projection_comparisons_healthy_or_retired' => $legacyComparisonsHealthy,
        'batch_rows_match_or_views_retired' => $phase['all_retired']
            || (($mismatches['local_final_batches'] ?? 1) === 0
                && ($mismatches['local_advance_batches'] ?? 1) === 0),
        'item_rows_match_or_views_retired' => $phase['all_retired']
            || (($mismatches['local_final_items'] ?? 1) === 0
                && ($mismatches['local_advance_items'] ?? 1) === 0),
        'artifact_rows_match_or_views_retired' => $phase['all_retired']
            || (($mismatches['local_final_artifacts'] ?? 1) === 0
                && ($mismatches['local_advance_artifacts'] ?? 1) === 0),
        'canonical_public_identity_is_healthy' =>
            (int) ($canonical['integrity']['missing_batch_public_ids'] ?? 1) === 0
            && (int) ($canonical['integrity']['missing_item_public_ids'] ?? 1) === 0
            && (int) ($canonical['integrity']['missing_artifact_public_ids'] ?? 1) === 0
            && (int) ($canonical['integrity']['duplicate_batch_public_ids'] ?? 1) === 0
            && (int) ($canonical['integrity']['duplicate_item_public_ids'] ?? 1) === 0
            && (int) ($canonical['integrity']['duplicate_artifact_public_ids'] ?? 1) === 0,
        'canonical_relationships_are_healthy' =>
            (int) ($canonical['integrity']['orphan_items'] ?? 1) === 0
            && (int) ($canonical['integrity']['orphan_artifacts'] ?? 1) === 0
            && (int) ($canonical['integrity']['request_type_mismatches'] ?? 1) === 0,
    ];
    $healthy = !in_array(false, $checks, true);

    return [
        'healthy' => $healthy,
        'read_mode' => 'canonical',
        'canonical_read_ready' => $healthy,
        'checks' => $checks,
        'compatibility_phase' => $phase,
        'mismatches' => $mismatches,
        'mismatch_samples' => $samples,
        'storage_verification' => $storage,
        'canonical_verification' => $canonical,
        'bridge_verification' => $bridge,
        'finalized_compatibility_ready' => $phase['all_views'],
        'compatibility_views_retired' => $phase['all_retired'],
        'runtime_cutover' => $healthy,
        'table_count_change' => 0,
    ];
}
