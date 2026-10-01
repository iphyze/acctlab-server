<?php

declare(strict_types=1);

require_once __DIR__ . '/fxFundRequestPaymentLifecycleService.php';
require_once __DIR__ . '/accountPaymentProcessingService.php';

/**
 * Audit-safe FX payment return/reversal lifecycle.
 *
 * A Fund Request is reopened by detaching it from its generated FX instruction
 * while preserving the instruction and canonical processing history. An
 * untreated instruction is closed as Cancelled; a Paid instruction is closed
 * as Reversed. The request can then be processed again, while the historical
 * payment remains available in the FX payment register for manual correction.
 */

function fxFundRequestReturnToPendingReason(string $reason): string
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required before returning an FX Fund Request to Pending.', 400);
    }
    if (mb_strlen($reason) > 1000) {
        throw new RuntimeException('Return-to-Pending reason must not exceed 1,000 characters.', 400);
    }
    return $reason;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestReturnToPendingCanonicalItems(
    mysqli $conn,
    int $instructionId,
    array $requestIds
): array {
    if ($instructionId <= 0 || $requestIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $types = 'i' . str_repeat('i', count($requestIds));
    $params = array_merge([$instructionId], array_values($requestIds));
    $stmt = $conn->prepare(
        "SELECT i.id, i.batch_id, i.request_type, i.request_id, i.status,
                         i.amount, i.amount_paid, i.paid_at, i.payment_reference
         FROM account_payment_batch_items i
         INNER JOIN account_payment_artifacts artifact
           ON artifact.batch_id = i.batch_id
          AND artifact.request_type = i.request_type
          AND artifact.artifact_type = 'fx_instruction'
          AND artifact.artifact_id = ?
         WHERE i.request_type IN ('fx_final_purchase','fx_advance_purchase')
           AND i.request_id IN ({$placeholders})
         ORDER BY i.id
         FOR UPDATE"
    );
    accountPaymentProcessingBind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fxFundRequestReturnToPendingCancelCanonicalItems(
    mysqli $conn,
    array $items,
    bool $isPaidReversal,
    string $reason,
    int $actorId
): array {
    if ($items === []) {
        return ['item_ids' => [], 'batch_ids' => []];
    }

    $itemIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $items)));
    $batchIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['batch_id'], $items)));
    sort($itemIds, SORT_NUMERIC);
    sort($batchIds, SORT_NUMERIC);

    $statusReason = ($isPaidReversal
        ? 'Paid FX payment reversed and Fund Request returned to Pending. Reason: '
        : 'FX payment processing cancelled and Fund Request returned to Pending. Reason: ')
        . $reason;

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $params = array_merge([$statusReason, $actorId], $itemIds);
    $types = 'si' . str_repeat('i', count($itemIds));
    $stmt = $conn->prepare(
        "UPDATE account_payment_batch_items
         SET status = 'Cancelled', status_reason = ?, updated_by = ?, updated_at = NOW()
         WHERE id IN ({$placeholders})"
    );
    accountPaymentProcessingBind($stmt, $types, $params);
    $stmt->execute();
    $stmt->close();

    foreach ($batchIds as $batchId) {
        accountPaymentProcessingUpdateCanonicalBatchStatus($conn, $batchId, $actorId);
    }

    return ['item_ids' => $itemIds, 'batch_ids' => $batchIds];
}

function fxFundRequestReturnToPendingCancelReminders(
    mysqli $conn,
    array $requestIds,
    string $reason,
    int $actorId
): int {
    if ($requestIds === []) {
        return 0;
    }

    $reason = mb_substr(trim($reason), 0, 255);
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $params = array_merge(
        [$reason, $actorId],
        array_values($requestIds)
    );
    $types = 'si' . str_repeat('i', count($requestIds));
    $stmt = $conn->prepare(
        "UPDATE account_payment_reminders
         SET lifecycle_status = 'Cancelled', cancelled_at = COALESCE(cancelled_at, NOW()),
             cancellation_reason = ?, updated_by = ?,
             lease_token = NULL, lease_expires_at = NULL
         WHERE request_type IN ('FX Final','FX Advance')
           AND request_id IN ({$placeholders})
           AND lifecycle_status NOT IN ('Completed','Cancelled')"
    );
    accountPaymentProcessingBind($stmt, $types, $params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function fxFundRequestReturnToPendingCloseArtifact(
    mysqli $conn,
    int $instructionId,
    string $reason,
    int $actorId
): int {
    $reason = mb_substr(trim($reason), 0, 255);
    $stmt = $conn->prepare(
        "UPDATE account_payment_artifacts
         SET artifact_status = 'Removed', removed_at = NOW(), removed_by = ?, removal_reason = ?
         WHERE artifact_type = 'fx_instruction' AND artifact_id = ? AND artifact_status = 'Active'"
    );
    $stmt->bind_param('isi', $actorId, $reason, $instructionId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function fxFundRequestReturnToPendingAdjustRemainingInstruction(
    mysqli $conn,
    int $instructionId,
    array $remainingRequests,
    string $paymentCurrency
): void {
    $remainingAmount = 0.0;
    foreach ($remainingRequests as $request) {
        if ($request['payment_amount'] === null) {
            throw new RuntimeException(
                'FX Instruction #' . $instructionId . ' has a remaining request without a payment allocation.',
                409
            );
        }
        $remainingAmount = round($remainingAmount + (float) $request['payment_amount'], 2);
    }
    if ($remainingAmount <= 0) {
        throw new RuntimeException('Remaining grouped FX payment amount must be greater than zero.', 409);
    }
    if (trim($paymentCurrency) === '') {
        throw new RuntimeException('FX Instruction #' . $instructionId . ' is missing its payment currency.', 409);
    }

    $amountWords = fxFundRequestAmountWords($remainingAmount, $paymentCurrency);
    $amountFigure = number_format($remainingAmount, 2, '.', '');
    $stmt = $conn->prepare(
        'UPDATE fx_instruction_letter_table SET amount_figure = ?, amount_words = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('ssi', $amountFigure, $amountWords, $instructionId);
    $stmt->execute();
    $stmt->close();
}

function fxFundRequestReturnToPendingRecordProcurementHistory(
    mysqli $conn,
    array $fundRequest,
    int $instructionId,
    string $instructionPreviousStatus,
    bool $isPaidReversal,
    string $reason,
    array $actor,
    array $canonicalContext
): void {
    $fundRequestId = (int) $fundRequest['id'];
    $eventType = $isPaidReversal
        ? 'account_payment_reversed_to_pending'
        : 'account_payment_returned_to_pending';

    $finalSync = procurementFxFinalSyncFundRequestToProcurement(
        $conn,
        $fundRequestId,
        (int) $actor['id'],
        (string) $actor['email'],
        $eventType
    );
    $advanceSync = procurementFxAdvanceSyncFundRequestToProcurement(
        $conn,
        $fundRequestId,
        (int) $actor['id'],
        (string) $actor['email'],
        $eventType
    );

    $payload = [
        'fx_fund_request_id' => $fundRequestId,
        'original_fx_instruction_letter_id' => $instructionId,
        'original_instruction_status' => $instructionPreviousStatus,
        'return_type' => $isPaidReversal ? 'paid_reversal' : 'processing_cancellation',
        'reason' => $reason,
        'canonical_item_ids' => $canonicalContext['item_ids'] ?? [],
        'canonical_batch_ids' => $canonicalContext['batch_ids'] ?? [],
        'payment_status' => 'Pending',
    ];

    if (!empty($finalSync['purchase_id'])) {
        procurementFxFinalRecordEvent(
            $conn,
            (int) $finalSync['purchase_id'],
            $eventType . '_audit',
            $actor,
            $payload
        );
    }
    if (!empty($advanceSync['purchase_id'])) {
        procurementFxAdvanceRecordEvent(
            $conn,
            (int) $advanceSync['purchase_id'],
            $eventType . '_audit',
            $actor,
            $payload
        );
        procurementRequestCanonicalSyncRequest(
            $conn,
            PROCUREMENT_REQUEST_TYPE_FX_ADVANCE,
            (int) $advanceSync['purchase_id']
        );
    }
}

/**
 * @return array<string,mixed>
 */
function fxFundRequestReturnToPending(
    mysqli $conn,
    array $requestIds,
    array $actor,
    string $reason
): array {
    $ids = fxFundRequestLifecycleNormalizeRequestIds($requestIds);
    if ($ids === []) {
        throw new RuntimeException('Please select at least one FX Fund Request.', 400);
    }
    if (count($ids) > 100) {
        throw new RuntimeException('Too many FX Fund Requests selected. Maximum allowed is 100.', 400);
    }
    $reason = fxFundRequestReturnToPendingReason($reason);
    $actorId = (int) ($actor['id'] ?? 0);
    $actorEmail = trim((string) ($actor['email'] ?? '')) ?: 'account-user';
    if ($actorId <= 0) {
        throw new RuntimeException('A valid account user is required.', 401);
    }

    $requests = fxFundRequestLifecycleLockRequests($conn, $ids);
    $byInstruction = [];
    foreach ($ids as $requestId) {
        $request = $requests[$requestId];
        $instructionId = (int) ($request['fx_instruction_letter_id'] ?? 0);
        if ($instructionId <= 0 || empty($request['processed_at'])) {
            throw new RuntimeException(
                'FX Fund Request #' . $requestId . ' is not linked to an active/historical processed payment.',
                409
            );
        }
        if (strcasecmp(trim((string) ($request['payment_status'] ?? '')), 'Pending') === 0) {
            throw new RuntimeException('FX Fund Request #' . $requestId . ' is already Pending.', 409);
        }
        $byInstruction[$instructionId][] = $requestId;
    }

    $results = [];
    $allBatchIds = [];
    $allItemIds = [];
    $reopenedIds = [];
    $cancelledReminders = 0;

    foreach ($byInstruction as $instructionId => $selectedIds) {
        $instructionStmt = $conn->prepare(
            'SELECT id, payment_status, currency_table, amount_figure, reference FROM fx_instruction_letter_table WHERE id = ? FOR UPDATE'
        );
        $instructionStmt->bind_param('i', $instructionId);
        $instructionStmt->execute();
        $instruction = $instructionStmt->get_result()->fetch_assoc();
        $instructionStmt->close();
        if (!$instruction) {
            throw new RuntimeException('Linked FX Instruction #' . $instructionId . ' was not found.', 409);
        }

        $instructionStatus = trim((string) ($instruction['payment_status'] ?? ''));
        $allLinked = fxFundRequestLifecycleLinkedRequestsByInstruction($conn, (int) $instructionId);
        $allLinkedIds = array_map(static fn(array $row): int => (int) $row['id'], $allLinked);
        sort($allLinkedIds, SORT_NUMERIC);
        $selectedSorted = $selectedIds;
        sort($selectedSorted, SORT_NUMERIC);

        $isPaidReversal = strcasecmp($instructionStatus, 'Paid') === 0
            || count(array_filter($allLinked, static fn(array $row): bool => strcasecmp((string) ($row['payment_status'] ?? ''), 'Paid') === 0)) > 0;

        // Once a shared instruction has moved beyond Pending, its status is
        // shared by every sibling request. Reopen the whole instruction to
        // avoid creating a half-reversed payment history.
        if (strcasecmp($instructionStatus, 'Pending') !== 0 && $selectedSorted !== $allLinkedIds) {
            throw new RuntimeException(
                'FX Instruction #' . $instructionId
                    . ' is ' . ($instructionStatus !== '' ? $instructionStatus : 'already in payment lifecycle')
                    . '. Select every Fund Request linked to this grouped payment before returning it to Pending.',
                409
            );
        }

        $selectedLookup = array_fill_keys($selectedSorted, true);
        $remaining = array_values(array_filter(
            $allLinked,
            static fn(array $row): bool => !isset($selectedLookup[(int) $row['id']])
        ));

        $canonicalItems = fxFundRequestReturnToPendingCanonicalItems($conn, (int) $instructionId, $selectedSorted);
        $canonical = fxFundRequestReturnToPendingCancelCanonicalItems(
            $conn,
            $canonicalItems,
            $isPaidReversal,
            $reason,
            $actorId
        );
        $allBatchIds = array_merge($allBatchIds, $canonical['batch_ids']);
        $allItemIds = array_merge($allItemIds, $canonical['item_ids']);

        $cancelledReminders += fxFundRequestReturnToPendingCancelReminders(
            $conn,
            $selectedSorted,
            'FX Fund Request returned to Pending. ' . $reason,
            $actorId
        );

        if (!$isPaidReversal) {
            foreach ($selectedSorted as $requestId) {
                $requestType = fxFundRequestSupplierCreditRequestType($requests[$requestId]);
                procurementSupplierReleaseCreditReservations(
                    $conn,
                    $requestType,
                    $requestId,
                    $actorId,
                    'FX payment processing returned to Pending. ' . $reason
                );
            }
        }

        $reset = $conn->prepare(
            "UPDATE fx_fund_request_table
             SET payment_status = 'Pending', fx_instruction_letter_id = NULL,
                 payment_currency = NULL, payment_amount = NULL, exchange_rate = NULL,
                 processed_by = NULL, processed_at = NULL, updated_by = ?, updated_at = NOW()
             WHERE id = ? AND fx_instruction_letter_id = ?"
        );
        foreach ($selectedSorted as $requestId) {
            $reset->bind_param('iii', $actorId, $requestId, $instructionId);
            $reset->execute();
            if ($reset->affected_rows !== 1) {
                throw new RuntimeException('FX Fund Request #' . $requestId . ' changed before it could be returned to Pending.', 409);
            }
            $reopenedIds[] = $requestId;
        }
        $reset->close();

        $instructionNewStatus = $instructionStatus;
        $artifactRowsClosed = 0;
        if ($remaining === []) {
            $instructionNewStatus = $isPaidReversal ? 'Reversed' : 'Cancelled';
            $instructionUpdate = $conn->prepare(
                'UPDATE fx_instruction_letter_table SET payment_status = ?, updated_at = NOW() WHERE id = ?'
            );
            $instructionUpdate->bind_param('si', $instructionNewStatus, $instructionId);
            $instructionUpdate->execute();
            $instructionUpdate->close();
            $artifactRowsClosed = fxFundRequestReturnToPendingCloseArtifact(
                $conn,
                (int) $instructionId,
                ($isPaidReversal ? 'Paid FX payment reversed. ' : 'FX payment processing cancelled. ') . $reason,
                $actorId
            );
        } else {
            // Partial detach is only permitted while the shared instruction is
            // still Pending. Keep the instruction alive for its remaining
            // siblings and reduce the payment total to their allocations.
            fxFundRequestReturnToPendingAdjustRemainingInstruction(
                $conn,
                (int) $instructionId,
                $remaining,
                trim((string) ($instruction['currency_table'] ?? ''))
            );
        }

        foreach ($selectedSorted as $requestId) {
            fxFundRequestInsertLog(
                $conn,
                $actorId,
                $actorEmail,
                $actorEmail . ($isPaidReversal ? ' reversed payment for' : ' cancelled payment processing for')
                    . ' FX Fund Request #' . $requestId
                    . ' (FX Instruction #' . $instructionId . ') and returned it to Pending.'
                    . ' Reason: ' . $reason
            );

            fxFundRequestReturnToPendingRecordProcurementHistory(
                $conn,
                $requests[$requestId],
                (int) $instructionId,
                $instructionStatus,
                $isPaidReversal,
                $reason,
                ['id' => $actorId, 'email' => $actorEmail],
                $canonical
            );
        }

        $results[] = [
            'instruction_id' => (int) $instructionId,
            'instruction_previous_status' => $instructionStatus,
            'instruction_status' => $instructionNewStatus,
            'request_ids' => $selectedSorted,
            'remaining_request_ids' => array_map(static fn(array $row): int => (int) $row['id'], $remaining),
            'return_type' => $isPaidReversal ? 'paid_reversal' : 'processing_cancellation',
            'canonical_item_ids' => $canonical['item_ids'],
            'canonical_batch_ids' => $canonical['batch_ids'],
            'artifact_rows_closed' => $artifactRowsClosed,
        ];
    }

    sort($reopenedIds, SORT_NUMERIC);
    $allBatchIds = array_values(array_unique(array_map('intval', $allBatchIds)));
    $allItemIds = array_values(array_unique(array_map('intval', $allItemIds)));
    sort($allBatchIds, SORT_NUMERIC);
    sort($allItemIds, SORT_NUMERIC);

    return [
        'target_status' => 'Pending',
        'reopened_request_ids' => array_values(array_unique($reopenedIds)),
        'canonical_batch_ids' => $allBatchIds,
        'canonical_item_ids' => $allItemIds,
        'cancelled_reminders' => $cancelledReminders,
        'instructions' => $results,
    ];
}
