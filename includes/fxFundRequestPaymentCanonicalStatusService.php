<?php

declare(strict_types=1);

/**
 * Keep the shared canonical processing workspace aligned when an FX
 * instruction status is changed from either the FX Payment screen or the
 * FX Fund Request lifecycle. No parallel FX processing tables are used.
 */

function fxFundRequestCanonicalItemStatusForInstruction(string $instructionStatus): string
{
    $status = trim($instructionStatus);
    if (strcasecmp($status, 'Paid') === 0) {
        return 'Paid';
    }
    if (strcasecmp($status, 'Unconfirmed') === 0) {
        return 'Awaiting Confirmation';
    }
    return 'Processing';
}

function fxFundRequestCanonicalRecalculateBatch(mysqli $conn, int $batchId, int $actorId): void
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'Processing') AS processing_count,
                SUM(status = 'Awaiting Confirmation') AS awaiting_count,
                SUM(status = 'Delayed') AS delayed_count,
                SUM(status = 'Paid') AS paid_count,
                SUM(status IN ('Failed','Cancelled')) AS exception_count
         FROM account_payment_batch_items
         WHERE batch_id = ?"
    );
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $total = (int) ($summary['total'] ?? 0);
    $active = (int) ($summary['processing_count'] ?? 0)
        + (int) ($summary['awaiting_count'] ?? 0)
        + (int) ($summary['delayed_count'] ?? 0);
    $paid = (int) ($summary['paid_count'] ?? 0);
    $exceptions = (int) ($summary['exception_count'] ?? 0);

    if ((int) ($summary['awaiting_count'] ?? 0) > 0) {
        $status = 'Awaiting Confirmation';
        $completedAt = null;
    } elseif ($active > 0) {
        $status = 'Processing';
        $completedAt = null;
    } elseif ($total > 0 && $paid === $total) {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    } elseif ($exceptions > 0 || $total > 0) {
        $status = 'Completed With Exceptions';
        $completedAt = date('Y-m-d H:i:s');
    } else {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    }

    $update = $conn->prepare(
        'UPDATE account_payment_batches
         SET status = ?, updated_by = ?, completed_at = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $update->bind_param('sisi', $status, $actorId, $completedAt, $batchId);
    $update->execute();
    $update->close();
}

/**
 * @return array{item_ids:array<int,int>,batch_ids:array<int,int>,canonical_status:string}
 */
function fxFundRequestCanonicalSyncInstructionStatus(
    mysqli $conn,
    int $instructionId,
    string $instructionStatus,
    int $actorId,
    string $sourceAction
): array {
    if ($instructionId <= 0) {
        return ['item_ids' => [], 'batch_ids' => [], 'canonical_status' => ''];
    }

    $canonicalStatus = fxFundRequestCanonicalItemStatusForInstruction($instructionStatus);
    $stmt = $conn->prepare(
        "SELECT DISTINCT i.id, i.batch_id
         FROM account_payment_artifacts artifact
         INNER JOIN account_payment_batch_items i
           ON i.batch_id = artifact.batch_id
          AND i.request_type = artifact.request_type
         WHERE artifact.artifact_type = 'fx_instruction'
           AND artifact.artifact_id = ?
           AND artifact.artifact_status = 'Active'
           AND artifact.request_type IN ('fx_final_purchase','fx_advance_purchase')
         ORDER BY i.id
         FOR UPDATE"
    );
    $stmt->bind_param('i', $instructionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows === []) {
        return ['item_ids' => [], 'batch_ids' => [], 'canonical_status' => $canonicalStatus];
    }

    $itemIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $rows)));
    $batchIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['batch_id'], $rows)));
    sort($itemIds, SORT_NUMERIC);
    sort($batchIds, SORT_NUMERIC);

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $reason = 'FX payment status synchronized through ' . trim($sourceAction) . '.';

    if ($canonicalStatus === 'Paid') {
        $sql = "UPDATE account_payment_batch_items
                SET status = 'Paid', amount_paid = amount,
                    paid_at = COALESCE(paid_at, NOW()),
                    status_reason = ?, updated_by = ?, updated_at = NOW()
                WHERE id IN ({$placeholders})";
    } else {
        $sql = "UPDATE account_payment_batch_items
                SET status = ?, amount_paid = 0.00, paid_at = NULL,
                    status_reason = ?, updated_by = ?, updated_at = NOW()
                WHERE id IN ({$placeholders})";
    }

    $update = $conn->prepare($sql);
    if ($canonicalStatus === 'Paid') {
        $types = 'si' . str_repeat('i', count($itemIds));
        $params = array_merge([$reason, $actorId], $itemIds);
    } else {
        $types = 'ssi' . str_repeat('i', count($itemIds));
        $params = array_merge([$canonicalStatus, $reason, $actorId], $itemIds);
    }
    $update->bind_param($types, ...$params);
    $update->execute();
    $update->close();

    foreach ($batchIds as $batchId) {
        fxFundRequestCanonicalRecalculateBatch($conn, $batchId, $actorId);
    }

    return [
        'item_ids' => $itemIds,
        'batch_ids' => $batchIds,
        'canonical_status' => $canonicalStatus,
    ];
}
