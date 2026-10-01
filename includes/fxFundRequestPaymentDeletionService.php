<?php

declare(strict_types=1);

require_once __DIR__ . '/fxFundRequestReturnToPendingService.php';
require_once __DIR__ . '/fxFundRequestPaymentCanonicalStatusService.php';
require_once __DIR__ . '/manualFxPaymentProcessingService.php';

/**
 * Flexible, audit-safe FX payment deletion.
 *
 * Direct FX payments remain deletable. Fund-Request-generated FX payments are
 * also deletable, including Paid instructions, but the linked Fund Request(s)
 * are first returned to Pending and all canonical audit artifacts remain.
 */

function fxFundRequestPaymentDeletionReason(string $reason, bool $required): string
{
    $reason = trim($reason);
    if ($required && $reason === '') {
        throw new RuntimeException('A reason is required before deleting a Paid FX payment.', 400);
    }
    if ($reason !== '' && mb_strlen($reason) > 1000) {
        throw new RuntimeException('FX payment deletion reason must not exceed 1,000 characters.', 400);
    }
    return $reason;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestPaymentDeletionLockPayments(mysqli $conn, array $paymentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static fn(int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    if ($ids === []) {
        throw new RuntimeException('Please select at least one valid FX payment to delete.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT id, payment_status, reference, amount_figure, currency_table,
                beneficiary_name, payment_bank, payment_date, created_at
         FROM fx_instruction_letter_table
         WHERE id IN ({$placeholders})
         ORDER BY id
         FOR UPDATE"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $indexed = [];
    foreach ($rows as $row) {
        $indexed[(int) $row['id']] = $row;
    }
    $missing = array_values(array_diff($ids, array_keys($indexed)));
    if ($missing !== []) {
        throw new RuntimeException('FX payment ID(s) not found: ' . implode(', ', $missing) . '.', 404);
    }
    return $indexed;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestPaymentDeletionArtifacts(mysqli $conn, int $paymentId): array
{
    $stmt = $conn->prepare(
        "SELECT id, batch_id, request_type, artifact_status, artifact_reference,
                request_ids_json, removed_at, removal_reason
         FROM account_payment_artifacts
         WHERE artifact_type = 'fx_instruction' AND artifact_id = ?
         ORDER BY id
         FOR UPDATE"
    );
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fxFundRequestPaymentDeletionSnapshotText(array $payment, string $actorEmail, string $reason): string
{
    $reference = trim((string) ($payment['reference'] ?? '')) ?: ('FX Instruction #' . (int) ($payment['id'] ?? 0));
    $currency = trim((string) ($payment['currency_table'] ?? '')) ?: 'FX';
    $amount = number_format((float) ($payment['amount_figure'] ?? 0), 2, '.', ',');
    $status = trim((string) ($payment['payment_status'] ?? '')) ?: 'Unknown';
    $reasonText = trim($reason) !== '' ? trim($reason) : 'No additional reason supplied.';

    return 'FX payment deletion audit — Reference: ' . $reference
        . '; Amount: ' . $currency . ' ' . $amount
        . '; Original status: ' . $status
        . '; Deleted by: ' . $actorEmail
        . '; Reason: ' . $reasonText;
}

/**
 * Record the final deletion event in ProcureDesk after every linked Fund
 * Request has already been reopened. This survives removal of the FX payment
 * row and makes grouped Final/Advance reversals explicit in workflow history.
 *
 * @return array<int,int>
 */
function fxFundRequestPaymentDeletionRecordProcurementAudit(
    mysqli $conn,
    array $linkedRequests,
    array $payment,
    array $actor,
    string $reason,
    array $canonicalContext
): array {
    if ($linkedRequests === []) {
        return [];
    }

    $instructionId = (int) ($payment['id'] ?? 0);
    $reference = trim((string) ($payment['reference'] ?? '')) ?: ('FX Instruction #' . $instructionId);
    $originalStatus = trim((string) ($payment['payment_status'] ?? '')) ?: 'Unknown';
    $currency = trim((string) ($payment['currency_table'] ?? '')) ?: null;
    $amount = number_format((float) ($payment['amount_figure'] ?? 0), 2, '.', '');
    $groupRequestIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $linkedRequests
    )));
    $groupRequestIds = array_values(array_filter($groupRequestIds, static fn(int $id): bool => $id > 0));
    sort($groupRequestIds, SORT_NUMERIC);

    $payload = [
        'deleted_fx_instruction_letter_id' => $instructionId,
        'deleted_payment_reference' => $reference,
        'original_instruction_status' => $originalStatus,
        'payment_currency' => $currency,
        'payment_amount' => $amount,
        'deletion_reason' => trim($reason),
        'payment_status' => 'Pending',
        'grouped_request_ids' => $groupRequestIds,
        'grouped_request_count' => count($groupRequestIds),
        'canonical_item_ids' => $canonicalContext['item_ids'] ?? [],
        'canonical_batch_ids' => $canonicalContext['batch_ids'] ?? [],
    ];

    $purchaseIds = [];
    foreach ($linkedRequests as $request) {
        $fundRequestId = (int) ($request['id'] ?? 0);
        $requestType = trim((string) ($request['request_type'] ?? ''));
        if ($fundRequestId <= 0) {
            continue;
        }

        if ($requestType === 'Final') {
            $purchase = procurementFxFinalLinkedPurchaseForFundRequest($conn, $fundRequestId, false);
            $purchaseId = (int) ($purchase['legacy_source_id'] ?? 0);
            if ($purchaseId > 0) {
                procurementFxFinalRecordEvent(
                    $conn,
                    $purchaseId,
                    'account_payment_deleted_after_reopen_audit',
                    $actor,
                    array_merge($payload, ['fx_fund_request_id' => $fundRequestId])
                );
                $purchaseIds[] = $purchaseId;
            }
            continue;
        }

        if ($requestType === 'Advance') {
            $purchase = procurementFxAdvanceLinkedPurchaseForFundRequest($conn, $fundRequestId, false);
            $purchaseId = (int) ($purchase['id'] ?? 0);
            if ($purchaseId > 0) {
                procurementFxAdvanceRecordEvent(
                    $conn,
                    $purchaseId,
                    'account_payment_deleted_after_reopen_audit',
                    $actor,
                    array_merge($payload, ['fx_fund_request_id' => $fundRequestId])
                );
                procurementRequestCanonicalSyncRequest($conn, PROCUREMENT_REQUEST_TYPE_FX_ADVANCE, $purchaseId);
                $purchaseIds[] = $purchaseId;
            }
        }
    }

    $purchaseIds = array_values(array_unique(array_map('intval', $purchaseIds)));
    sort($purchaseIds, SORT_NUMERIC);
    return $purchaseIds;
}

/**
 * Preserve the deleted instruction snapshot in the shared canonical processing
 * items. amount/amount_paid/paid_at/payment_reference are intentionally kept.
 *
 * @return array{item_ids:array<int,int>,batch_ids:array<int,int>}
 */
function fxFundRequestPaymentDeletionPreserveCanonicalSnapshot(
    mysqli $conn,
    int $paymentId,
    string $snapshot,
    int $actorId
): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT i.id, i.batch_id
         FROM account_payment_artifacts artifact
         INNER JOIN account_payment_batch_items i
           ON i.batch_id = artifact.batch_id
          AND i.request_type = artifact.request_type
         WHERE artifact.artifact_type = 'fx_instruction'
           AND artifact.artifact_id = ?
           AND artifact.request_type IN ('fx_final_purchase','fx_advance_purchase')
         ORDER BY i.id
         FOR UPDATE"
    );
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows === []) {
        return ['item_ids' => [], 'batch_ids' => []];
    }

    $itemIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $rows)));
    $batchIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['batch_id'], $rows)));
    sort($itemIds, SORT_NUMERIC);
    sort($batchIds, SORT_NUMERIC);

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $types = 'si' . str_repeat('i', count($itemIds));
    $params = array_merge([$snapshot, $actorId], $itemIds);
    $update = $conn->prepare(
        "UPDATE account_payment_batch_items
         SET status = 'Cancelled', status_reason = ?, updated_by = ?, updated_at = NOW()
         WHERE id IN ({$placeholders})"
    );
    $update->bind_param($types, ...$params);
    $update->execute();
    $update->close();

    foreach ($batchIds as $batchId) {
        fxFundRequestCanonicalRecalculateBatch($conn, $batchId, $actorId);
    }

    return ['item_ids' => $itemIds, 'batch_ids' => $batchIds];
}

/**
 * @return array<string,mixed>
 */
function fxFundRequestPaymentDelete(
    mysqli $conn,
    array $paymentIds,
    array $actor,
    string $reason = ''
): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static fn(int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    if ($ids === []) {
        throw new RuntimeException('Please select at least one valid FX payment to delete.', 400);
    }
    if (count($ids) > 100) {
        throw new RuntimeException('Too many IDs provided. Maximum allowed is 100.', 400);
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $actorEmail = trim((string) ($actor['email'] ?? '')) ?: 'account-user';
    if ($actorId <= 0) {
        throw new RuntimeException('A valid account user is required.', 401);
    }

    $payments = fxFundRequestPaymentDeletionLockPayments($conn, $ids);
    $deleted = [];
    $direct = [];
    $generated = [];
    $reopened = [];
    $artifactIds = [];
    $batchIds = [];
    $itemIds = [];
    $procurementPurchaseIds = [];

    foreach ($ids as $paymentId) {
        $payment = $payments[$paymentId];
        $linkedRequests = fxFundRequestLifecycleLinkedRequestsForInstruction($conn, $paymentId, true);
        $artifacts = fxFundRequestPaymentDeletionArtifacts($conn, $paymentId);
        $isGenerated = $linkedRequests !== [] || $artifacts !== [];
        $isPaid = strcasecmp(trim((string) ($payment['payment_status'] ?? '')), 'Paid') === 0;
        $paymentReason = fxFundRequestPaymentDeletionReason($reason, $isPaid);
        if ($paymentReason === '' && $isGenerated) {
            $paymentReason = 'FX payment deleted before settlement so the linked Fund Request can be corrected and reprocessed.';
        }

        if ($linkedRequests !== []) {
            $linkedRequestIds = array_map(static fn(array $row): int => (int) $row['id'], $linkedRequests);
            sort($linkedRequestIds, SORT_NUMERIC);
            $returnResult = fxFundRequestReturnToPending(
                $conn,
                $linkedRequestIds,
                ['id' => $actorId, 'email' => $actorEmail],
                $paymentReason
            );
            $reopened = array_merge($reopened, $returnResult['reopened_request_ids'] ?? []);
            $batchIds = array_merge($batchIds, $returnResult['canonical_batch_ids'] ?? []);
            $itemIds = array_merge($itemIds, $returnResult['canonical_item_ids'] ?? []);
        } elseif ($isGenerated) {
            // A generated instruction may already have been detached by the
            // explicit Return-to-Pending action. Keep its canonical artifact,
            // but make sure no Active artifact survives deletion of the row.
            fxFundRequestReturnToPendingCloseArtifact(
                $conn,
                $paymentId,
                'FX payment record deleted after Fund Request lifecycle detachment. ' . $paymentReason,
                $actorId
            );
        }

        $snapshot = fxFundRequestPaymentDeletionSnapshotText($payment, $actorEmail, $paymentReason);
        if ($isGenerated) {
            $canonical = fxFundRequestPaymentDeletionPreserveCanonicalSnapshot(
                $conn,
                $paymentId,
                $snapshot,
                $actorId
            );
            $batchIds = array_merge($batchIds, $canonical['batch_ids']);
            $itemIds = array_merge($itemIds, $canonical['item_ids']);
            foreach ($artifacts as $artifact) {
                $artifactIds[] = (int) $artifact['id'];
            }
            $generated[] = $paymentId;
        } else {
            $direct[] = $paymentId;
        }

        // Manual FX payments may carry a shared completion batch even though
        // they are intentionally not Fund-Request/ProcureDesk linked. Close
        // that processing context before freely deleting the payment row.
        manualFxPaymentProcessingCancel(
            $conn,
            $paymentId,
            $paymentReason !== '' ? $paymentReason : 'Manual FX payment deleted by Account user.',
            ['id' => $actorId, 'email' => $actorEmail]
        );

        fxFundRequestInsertLog($conn, $actorId, $actorEmail, $snapshot);

        $delete = $conn->prepare('DELETE FROM fx_instruction_letter_table WHERE id = ?');
        $delete->bind_param('i', $paymentId);
        $delete->execute();
        if ($delete->affected_rows !== 1) {
            throw new RuntimeException('FX payment #' . $paymentId . ' could not be deleted.', 409);
        }
        $delete->close();

        if ($linkedRequests !== []) {
            $procurementPurchaseIds = array_merge(
                $procurementPurchaseIds,
                fxFundRequestPaymentDeletionRecordProcurementAudit(
                    $conn,
                    $linkedRequests,
                    $payment,
                    ['id' => $actorId, 'email' => $actorEmail],
                    $paymentReason,
                    [
                        'item_ids' => $canonical['item_ids'] ?? [],
                        'batch_ids' => $canonical['batch_ids'] ?? [],
                    ]
                )
            );
        }
        $deleted[] = $paymentId;
    }

    foreach ([&$deleted, &$direct, &$generated, &$reopened, &$artifactIds, &$batchIds, &$itemIds, &$procurementPurchaseIds] as &$values) {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);
    }
    unset($values);

    return [
        'deleted_payment_ids' => $deleted,
        'direct_payment_ids' => $direct,
        'generated_payment_ids' => $generated,
        'reopened_request_ids' => $reopened,
        'preserved_artifact_ids' => $artifactIds,
        'canonical_batch_ids' => $batchIds,
        'canonical_item_ids' => $itemIds,
        'procurement_purchase_ids' => $procurementPurchaseIds,
    ];
}
