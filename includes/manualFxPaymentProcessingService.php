<?php

declare(strict_types=1);

require_once __DIR__ . '/accountPaymentStorageCanonicalWriteService.php';
require_once __DIR__ . '/accountNotificationService.php';

/**
 * Manual FX payments use the shared canonical payment-processing storage but
 * remain independent of Fund Requests and ProcureDesk. request_type separates
 * them from FX Final/Advance without introducing another batch table.
 */

function manualFxPaymentCompletionPayload(array $data): array
{
    $provided = array_key_exists('completion_mode', $data) || array_key_exists('processing_business_days', $data);
    if (!$provided) {
        return [
            'enabled' => false,
            'completion_mode' => 'Manual',
            'processing_business_days' => 0,
            'expected_completion_at' => null,
            'payment_status' => 'Pending',
        ];
    }

    $mode = trim((string) ($data['completion_mode'] ?? 'Notify'));
    if (!in_array($mode, ['Notify', 'Immediate', 'Automatic'], true)) {
        throw new RuntimeException('Manual FX completion mode must be Notify, Immediate or Automatic.', 400);
    }

    $days = $mode === 'Immediate' ? 0 : (int) ($data['processing_business_days'] ?? 0);
    if ($mode !== 'Immediate' && ($days < 1 || $days > 5)) {
        throw new RuntimeException('Manual FX processing period must be between 1 and 5 business days.', 400);
    }

    $timezone = new DateTimeZone('Africa/Lagos');
    $now = new DateTimeImmutable('now', $timezone);
    $due = $now;
    if ($days > 0) {
        $remaining = $days;
        while ($remaining > 0) {
            $due = $due->modify('+1 day');
            if ((int) $due->format('N') <= 5) {
                $remaining--;
            }
        }
    }

    return [
        'enabled' => true,
        'completion_mode' => $mode,
        'processing_business_days' => $days,
        'expected_completion_at' => $due->format('Y-m-d H:i:s'),
        'payment_status' => $mode === 'Immediate' ? 'Paid' : 'Pending',
    ];
}

function manualFxPaymentProcessingBatchReference(int $paymentId): string
{
    return sprintf('FXM-PROC-%06d', $paymentId);
}

function manualFxPaymentProcessingCreate(
    mysqli $conn,
    int $paymentId,
    array $payment,
    array $completion,
    array $actor
): ?array {
    if (empty($completion['enabled'])) {
        return null;
    }
    if ($paymentId <= 0) {
        throw new RuntimeException('Manual FX payment ID is invalid.', 409);
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $mode = (string) $completion['completion_mode'];
    $days = (int) $completion['processing_business_days'];
    $dueAt = (string) $completion['expected_completion_at'];
    $isImmediate = $mode === 'Immediate';
    $now = date('Y-m-d H:i:s');
    $amount = round((float) ($payment['amount_figure'] ?? 0), 2);
    $reference = trim((string) ($payment['reference'] ?? '')) ?: ('Manual FX #' . $paymentId);

    $legacyBatchId = accountPaymentStorageCreateBatch($conn, ACCOUNT_PAYMENT_TYPE_FX_MANUAL, [
        'batch_reference' => manualFxPaymentProcessingBatchReference($paymentId),
        'processing_method' => 'Manual FX Payment',
        'processing_reference' => $reference,
        'processing_business_days' => $days,
        'expected_completion_at' => $dueAt,
        'completion_mode' => $mode,
        'status' => $isImmediate ? 'Completed' : 'Processing',
        'item_count' => 1,
        'total_amount' => $amount,
        'account_remarks' => 'Manual FX payment completion tracked independently from Fund Requests and ProcureDesk.',
        'created_by' => $actorId,
        'updated_by' => $actorId,
        'completed_at' => $isImmediate ? $now : null,
    ]);

    accountPaymentStorageCreateItem($conn, ACCOUNT_PAYMENT_TYPE_FX_MANUAL, $legacyBatchId, [
        'request_id' => $paymentId,
        'amount' => $amount,
        'amount_paid' => $isImmediate ? $amount : 0.0,
        'status' => $isImmediate ? 'Paid' : 'Processing',
        'status_reason' => $isImmediate
            ? 'Manual FX payment completed immediately when created.'
            : 'Manual FX payment is awaiting its selected completion policy.',
        'processing_started_at' => $now,
        'expected_completion_at' => $dueAt,
        'paid_at' => $isImmediate ? $now : null,
        'payment_reference' => $isImmediate ? $reference : null,
        'updated_by' => $actorId,
    ]);

    accountPaymentStorageUpsertArtifact($conn, ACCOUNT_PAYMENT_TYPE_FX_MANUAL, $legacyBatchId, [
        'artifact_type' => 'manual_fx_instruction',
        'artifact_id' => $paymentId,
        'artifact_reference' => $reference,
        'artifact_route' => '/payments/fx-payments/print/' . $paymentId,
        'request_ids_json' => json_encode([$paymentId], JSON_UNESCAPED_SLASHES),
        'created_by' => $actorId,
    ]);

    return [
        'batch_id' => accountPaymentStorageCanonicalBatchId($conn, ACCOUNT_PAYMENT_TYPE_FX_MANUAL, $legacyBatchId),
        'legacy_batch_id' => $legacyBatchId,
        'request_type' => ACCOUNT_PAYMENT_TYPE_FX_MANUAL,
        'completion_mode' => $mode,
        'processing_business_days' => $days,
        'expected_completion_at' => $dueAt,
        'status' => $isImmediate ? 'Completed' : 'Processing',
    ];
}

function manualFxPaymentProcessingRecalculateBatch(mysqli $conn, int $batchId, int $actorId): void
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'Processing') AS processing_count,
                SUM(status = 'Awaiting Confirmation') AS awaiting_count,
                SUM(status = 'Paid') AS paid_count,
                SUM(status IN ('Failed','Cancelled')) AS exception_count
         FROM account_payment_batch_items WHERE batch_id = ?"
    );
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $total = (int) ($row['total'] ?? 0);
    $processing = (int) ($row['processing_count'] ?? 0);
    $awaiting = (int) ($row['awaiting_count'] ?? 0);
    $paid = (int) ($row['paid_count'] ?? 0);
    $exceptions = (int) ($row['exception_count'] ?? 0);

    if ($awaiting > 0) {
        $status = 'Awaiting Confirmation';
        $completedAt = null;
    } elseif ($processing > 0) {
        $status = 'Processing';
        $completedAt = null;
    } elseif ($total > 0 && $paid === $total) {
        $status = 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    } else {
        $status = $exceptions > 0 ? 'Completed With Exceptions' : 'Completed';
        $completedAt = date('Y-m-d H:i:s');
    }

    $update = $conn->prepare('UPDATE account_payment_batches SET status = ?, updated_by = ?, completed_at = ? WHERE id = ?');
    $update->bind_param('sisi', $status, $actorId, $completedAt, $batchId);
    $update->execute();
    $update->close();
}

function manualFxPaymentProcessingSyncStatus(
    mysqli $conn,
    int $paymentId,
    string $paymentStatus,
    array $actor,
    ?float $amount = null,
    ?string $reference = null
): array {
    $paymentStatus = trim($paymentStatus);
    if (!in_array($paymentStatus, ['Pending', 'Paid', 'Unconfirmed'], true)) {
        return ['updated_items' => [], 'batch_ids' => []];
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT i.id, i.batch_id, i.amount, i.status, b.completion_mode
         FROM account_payment_batch_items i
         INNER JOIN account_payment_batches b ON b.id = i.batch_id
         WHERE i.request_type = ? AND i.request_id = ?
         ORDER BY i.id DESC
         FOR UPDATE"
    );
    $type = ACCOUNT_PAYMENT_TYPE_FX_MANUAL;
    $stmt->bind_param('si', $type, $paymentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($rows === []) {
        return ['updated_items' => [], 'batch_ids' => []];
    }

    $updated = [];
    $batchIds = [];
    foreach ($rows as $row) {
        $itemId = (int) $row['id'];
        $batchId = (int) $row['batch_id'];
        $itemAmount = $amount !== null ? round($amount, 2) : (float) $row['amount'];
        $itemReference = $reference !== null ? trim($reference) : null;

        if ($paymentStatus === 'Paid') {
            $status = 'Paid';
            $reason = 'Manual FX payment status was updated to Paid.';
            $amountPaid = $itemAmount;
            $paidAt = date('Y-m-d H:i:s');
        } elseif ($paymentStatus === 'Unconfirmed') {
            $status = 'Awaiting Confirmation';
            $reason = 'Manual FX payment is awaiting Account confirmation.';
            $amountPaid = 0.0;
            $paidAt = null;
        } else {
            $status = 'Processing';
            $reason = 'Manual FX payment was returned to Pending for further adjustment.';
            $amountPaid = 0.0;
            $paidAt = null;
        }

        $update = $conn->prepare(
            'UPDATE account_payment_batch_items
             SET amount = ?, amount_paid = ?, status = ?, status_reason = ?, paid_at = ?,
                 payment_reference = COALESCE(?, payment_reference), updated_by = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $update->bind_param('ddssssii', $itemAmount, $amountPaid, $status, $reason, $paidAt, $itemReference, $actorId, $itemId);
        $update->execute();
        $update->close();
        $updated[] = $itemId;
        $batchIds[] = $batchId;
    }

    $batchIds = array_values(array_unique($batchIds));
    foreach ($batchIds as $batchId) {
        if ($amount !== null || $reference !== null) {
            $batchUpdate = $conn->prepare(
                'UPDATE account_payment_batches
                 SET total_amount = COALESCE(?, total_amount), processing_reference = COALESCE(?, processing_reference), updated_by = ?
                 WHERE id = ?'
            );
            $amountValue = $amount !== null ? round($amount, 2) : null;
            $referenceValue = $reference !== null ? trim($reference) : null;
            $batchUpdate->bind_param('dsii', $amountValue, $referenceValue, $actorId, $batchId);
            $batchUpdate->execute();
            $batchUpdate->close();
        }
        manualFxPaymentProcessingRecalculateBatch($conn, $batchId, $actorId);
    }

    if ($reference !== null) {
        $artifact = $conn->prepare(
            "UPDATE account_payment_artifacts
             SET artifact_reference = ?
             WHERE request_type = ? AND artifact_type = 'manual_fx_instruction' AND artifact_id = ? AND artifact_status = 'Active'"
        );
        $referenceValue = trim($reference);
        $artifact->bind_param('ssi', $referenceValue, $type, $paymentId);
        $artifact->execute();
        $artifact->close();
    }

    return ['updated_items' => $updated, 'batch_ids' => $batchIds];
}

function manualFxPaymentProcessingCancel(mysqli $conn, int $paymentId, string $reason, array $actor): array
{
    $actorId = (int) ($actor['id'] ?? 0);
    $reason = trim($reason) ?: 'Manual FX payment deleted by Account user.';
    $type = ACCOUNT_PAYMENT_TYPE_FX_MANUAL;

    $stmt = $conn->prepare(
        'SELECT id, batch_id FROM account_payment_batch_items WHERE request_type = ? AND request_id = ? FOR UPDATE'
    );
    $stmt->bind_param('si', $type, $paymentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $itemIds = [];
    $batchIds = [];
    foreach ($rows as $row) {
        $itemIds[] = (int) $row['id'];
        $batchIds[] = (int) $row['batch_id'];
    }

    if ($itemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = array_merge([$reason, $actorId], $itemIds);
        $types = 'si' . str_repeat('i', count($itemIds));
        $update = $conn->prepare(
            "UPDATE account_payment_batch_items
             SET status = 'Cancelled', status_reason = ?, updated_by = ?, updated_at = NOW()
             WHERE id IN ({$placeholders})"
        );
        accountPaymentStorageBind($update, $types, $params);
        $update->execute();
        $update->close();
    }

    $artifact = $conn->prepare(
        "UPDATE account_payment_artifacts
         SET artifact_status = 'Removed', removed_at = NOW(), removed_by = ?, removal_reason = ?
         WHERE request_type = ? AND artifact_type = 'manual_fx_instruction' AND artifact_id = ? AND artifact_status = 'Active'"
    );
    $artifact->bind_param('issi', $actorId, $reason, $type, $paymentId);
    $artifact->execute();
    $artifact->close();

    $batchIds = array_values(array_unique($batchIds));
    foreach ($batchIds as $batchId) {
        manualFxPaymentProcessingRecalculateBatch($conn, $batchId, $actorId);
    }

    return ['item_ids' => $itemIds, 'batch_ids' => $batchIds];
}

function manualFxPaymentProcessingProcessDue(mysqli $conn): array
{
    $now = date('Y-m-d H:i:s');
    $type = ACCOUNT_PAYMENT_TYPE_FX_MANUAL;
    $stmt = $conn->prepare(
        "SELECT i.id AS item_id, i.batch_id, i.request_id AS payment_id, i.amount,
                i.expected_completion_at, b.completion_mode, b.batch_reference,
                p.reference, p.payment_status, p.currency_table, p.beneficiary_name
         FROM account_payment_batch_items i
         INNER JOIN account_payment_batches b ON b.id = i.batch_id AND b.request_type = i.request_type
         INNER JOIN fx_instruction_letter_table p ON p.id = i.request_id
         WHERE i.request_type = ?
           AND i.status = 'Processing'
           AND b.status = 'Processing'
           AND b.completion_mode IN ('Notify','Automatic')
           AND i.expected_completion_at <= ?
         ORDER BY i.id ASC"
    );
    $stmt->bind_param('ss', $type, $now);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $result = ['notify_payment_ids' => [], 'automatic_payment_ids' => [], 'batch_ids' => [], 'skipped' => []];
    foreach ($rows as $row) {
        $paymentId = (int) $row['payment_id'];
        $batchId = (int) $row['batch_id'];
        $itemId = (int) $row['item_id'];
        $mode = (string) $row['completion_mode'];

        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT id, reference, payment_status FROM fx_instruction_letter_table WHERE id = ? FOR UPDATE');
            $lock->bind_param('i', $paymentId);
            $lock->execute();
            $payment = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if ($payment === null) {
                manualFxPaymentProcessingCancel($conn, $paymentId, 'Manual FX payment no longer exists.', ['id' => 0, 'email' => 'system']);
                $conn->commit();
                continue;
            }

            if (strcasecmp((string) $payment['payment_status'], 'Paid') === 0) {
                manualFxPaymentProcessingSyncStatus($conn, $paymentId, 'Paid', ['id' => 0, 'email' => 'system']);
                $conn->commit();
                continue;
            }

            if ($mode === 'Automatic') {
                $paid = $conn->prepare("UPDATE fx_instruction_letter_table SET payment_status = 'Paid', updated_at = NOW() WHERE id = ?");
                $paid->bind_param('i', $paymentId);
                $paid->execute();
                $paid->close();
                manualFxPaymentProcessingSyncStatus($conn, $paymentId, 'Paid', ['id' => 0, 'email' => 'system']);

                accountNotificationPublishDetailed($conn, [
                    'type' => 'manual_fx_payment_auto_completed',
                    'action_key' => 'manual_fx_payment_auto_completed',
                    'category' => 'fx_payment_processing',
                    'severity' => 'success',
                    'title' => 'Manual FX payment automatically completed',
                    'message' => 'Manual FX payment ' . ((string) ($payment['reference'] ?? '') ?: ('#' . $paymentId)) . ' was marked Paid automatically.',
                    'actor' => ['id' => 0, 'email' => 'system'],
                    'entity_type' => 'manual_fx_payment',
                    'entity_id' => (string) $paymentId,
                    'route' => '/payments/fx-payments/payments?search=' . rawurlencode((string) ($payment['reference'] ?? $paymentId)),
                    'roles' => ['Admin', 'Super_Admin'],
                    'department' => 'account',
                    'payload' => ['payment_id' => $paymentId, 'batch_id' => $batchId, 'completion_mode' => 'Automatic'],
                    'dedupe_key' => 'manual-fx-auto-completed-' . $paymentId . '-' . $itemId,
                ]);
                $result['automatic_payment_ids'][] = $paymentId;
            } else {
                $update = $conn->prepare(
                    "UPDATE account_payment_batch_items
                     SET status = 'Awaiting Confirmation',
                         status_reason = 'Manual FX payment reached its expected completion time; Account confirmation is required.',
                         updated_by = 0, updated_at = NOW()
                     WHERE id = ? AND status = 'Processing'"
                );
                $update->bind_param('i', $itemId);
                $update->execute();
                $update->close();
                manualFxPaymentProcessingRecalculateBatch($conn, $batchId, 0);

                $route = '/payments/fx-payments/payments?search=' . rawurlencode((string) ($payment['reference'] ?? $paymentId));
                accountNotificationPublishDetailed($conn, [
                    'type' => 'manual_fx_payment_confirmation_due',
                    'action_key' => 'manual_fx_payment_confirmation_due',
                    'category' => 'fx_payment_processing',
                    'severity' => 'warning',
                    'title' => 'Manual FX payment ready for confirmation',
                    'message' => 'Confirm whether manual FX payment ' . ((string) ($payment['reference'] ?? '') ?: ('#' . $paymentId)) . ' should now be marked Paid.',
                    'actor' => ['id' => 0, 'email' => 'system'],
                    'entity_type' => 'manual_fx_payment',
                    'entity_id' => (string) $paymentId,
                    'route' => $route,
                    'roles' => ['Admin', 'Super_Admin'],
                    'department' => 'account',
                    'payload' => ['payment_id' => $paymentId, 'batch_id' => $batchId, 'completion_mode' => 'Notify', 'route' => $route],
                    'dedupe_key' => 'manual-fx-confirmation-due-' . $paymentId . '-' . $itemId,
                ]);
                $notify = $conn->prepare('UPDATE account_payment_batches SET notification_created_at = NOW() WHERE id = ?');
                $notify->bind_param('i', $batchId);
                $notify->execute();
                $notify->close();
                $result['notify_payment_ids'][] = $paymentId;
            }

            $result['batch_ids'][] = $batchId;
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            $result['skipped'][] = ['payment_id' => $paymentId, 'reason' => $error->getMessage(), 'retryable' => true];
        }
    }

    foreach (['notify_payment_ids', 'automatic_payment_ids', 'batch_ids'] as $key) {
        $result[$key] = array_values(array_unique(array_map('intval', $result[$key])));
        sort($result[$key], SORT_NUMERIC);
    }
    return $result;
}
