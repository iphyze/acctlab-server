<?php

declare(strict_types=1);

require_once __DIR__ . '/fxFundRequestService.php';
require_once __DIR__ . '/fxFundRequestPaymentService.php';
require_once __DIR__ . '/procurementFxFinalPurchaseService.php';
require_once __DIR__ . '/procurementFxAdvancePurchaseService.php';
require_once __DIR__ . '/fxFundRequestPaymentCanonicalStatusService.php';

/**
 * Lifecycle bridge between FX payment instructions and FX Fund Requests.
 *
 * Directly-created FX payments have no fx_fund_request_table link and are
 * therefore deliberately ignored by every synchronization helper below.
 */

function fxFundRequestLifecycleMapInstructionStatus(string $instructionStatus): string
{
    $status = trim($instructionStatus);

    // A Fund Request has already left Pending once it is converted to an FX
    // instruction. While that instruction is still Pending at the bank/payment
    // layer, the source Fund Request remains Processing.
    if (strcasecmp($status, 'Pending') === 0) {
        return 'Processing';
    }
    if (strcasecmp($status, 'Paid') === 0) {
        return 'Paid';
    }
    if (strcasecmp($status, 'Unconfirmed') === 0) {
        return 'Unconfirmed';
    }

    return $status;
}

/** @return array<int,string> */
function fxFundRequestLifecycleLockInstructionStatuses(mysqli $conn, array $paymentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        throw new Exception('Please select at least one FX payment.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT id, payment_status
         FROM fx_instruction_letter_table
         WHERE id IN ($placeholders)
         FOR UPDATE"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $statuses = [];
    while ($row = $result->fetch_assoc()) {
        $statuses[(int) $row['id']] = (string) ($row['payment_status'] ?? '');
    }
    $stmt->close();

    $missing = array_values(array_diff($ids, array_keys($statuses)));
    if ($missing !== []) {
        throw new Exception(
            'The following FX payment IDs do not exist: ' . implode(', ', $missing),
            404
        );
    }

    return $statuses;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestLifecycleLinkedRequestsForInstruction(
    mysqli $conn,
    int $instructionId,
    bool $forUpdate = true
): array {
    $sql = 'SELECT id, request_type, payment_status, fx_instruction_letter_id,
                   payment_currency, payment_amount, exchange_rate
            FROM fx_fund_request_table
            WHERE fx_instruction_letter_id = ?
            ORDER BY id';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $instructionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestLifecycleSyncLinkedInstructionStatus(
    mysqli $conn,
    int $instructionId,
    string $instructionStatus,
    int $actorUserId,
    string $actorEmail,
    string $sourceAction
): array {
    // Keep the one shared Processing workspace aligned no matter whether
    // the status change originated from FX Payments or FX Fund Requests.
    fxFundRequestCanonicalSyncInstructionStatus(
        $conn,
        $instructionId,
        $instructionStatus,
        $actorUserId,
        $sourceAction
    );

    $linkedRequests = fxFundRequestLifecycleLinkedRequestsForInstruction($conn, $instructionId, true);
    if ($linkedRequests === []) {
        // Direct FX payment: no Fund Request relationship, so do nothing.
        return [];
    }

    $fundRequestStatus = fxFundRequestLifecycleMapInstructionStatus($instructionStatus);
    if ($fundRequestStatus === '') {
        throw new Exception('Linked FX payment status cannot be empty.', 400);
    }

    $results = [];
    foreach ($linkedRequests as $linked) {
        $requestId = (int) $linked['id'];
        $previousStatus = trim((string) ($linked['payment_status'] ?? ''));

        if ($previousStatus !== $fundRequestStatus) {
            $stmt = $conn->prepare(
                'UPDATE fx_fund_request_table
                 SET payment_status = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ? AND fx_instruction_letter_id = ?'
            );
            $stmt->bind_param('siii', $fundRequestStatus, $actorUserId, $requestId, $instructionId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected !== 1) {
                throw new Exception('Linked FX Fund Request #' . $requestId . ' status could not be synchronized.', 409);
            }
        }

        fxFundRequestInsertLog(
            $conn,
            $actorUserId,
            $actorEmail,
            $actorEmail . ' synchronized FX Fund Request #' . $requestId
                . " from '{$previousStatus}' to '{$fundRequestStatus}'"
                . ' after linked FX Instruction #' . $instructionId
                . " moved to '{$instructionStatus}' via {$sourceAction}."
        );

        procurementFxFinalSyncFundRequestToProcurement(
            $conn,
            $requestId,
            $actorUserId,
            $actorEmail,
            'account_payment_status_updated'
        );
        procurementFxAdvanceSyncFundRequestToProcurement(
            $conn,
            $requestId,
            $actorUserId,
            $actorEmail,
            'account_payment_status_updated'
        );

        $results[] = [
            'request_id' => $requestId,
            'instruction_id' => $instructionId,
            'previous_status' => $previousStatus,
            'instruction_status' => $instructionStatus,
            'fund_request_status' => $fundRequestStatus,
            'changed' => $previousStatus !== $fundRequestStatus,
        ];
    }

    return $results;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestLifecycleSyncMany(
    mysqli $conn,
    array $instructionStatuses,
    int $actorUserId,
    string $actorEmail,
    string $sourceAction
): array {
    $synced = [];
    foreach ($instructionStatuses as $instructionId => $status) {
        $results = fxFundRequestLifecycleSyncLinkedInstructionStatus(
            $conn,
            (int) $instructionId,
            (string) $status,
            $actorUserId,
            $actorEmail,
            $sourceAction
        );
        foreach ($results as $result) {
            $synced[] = $result;
        }
    }

    return $synced;
}

/** @return array<int,int> */
function fxFundRequestLifecycleNormalizeRequestIds(array $requestIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $requestIds),
        static fn(int $id): bool => $id > 0
    )));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestLifecycleLockRequests(mysqli $conn, array $requestIds): array
{
    $ids = fxFundRequestLifecycleNormalizeRequestIds($requestIds);
    if ($ids === []) {
        throw new Exception('Please select at least one FX Fund Request.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT * FROM fx_fund_request_table WHERE id IN ($placeholders) ORDER BY id FOR UPDATE"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[(int) $row['id']] = $row;
    }
    $stmt->close();

    if (count($rows) !== count($ids)) {
        $missing = array_values(array_diff($ids, array_keys($rows)));
        throw new Exception('FX Fund Request ID(s) not found: ' . implode(', ', $missing) . '.', 404);
    }

    return $rows;
}

function fxFundRequestLifecycleAssertManualRequest(mysqli $conn, int $requestId): void
{
    $linkedFinal = procurementFxFinalLinkedPurchaseForFundRequest($conn, $requestId, true);
    $linkedAdvance = procurementFxAdvanceLinkedPurchaseForFundRequest($conn, $requestId, true);
    if ($linkedFinal !== null || $linkedAdvance !== null) {
        $label = $linkedAdvance !== null ? 'FX Advance' : 'FX Final';
        throw new Exception(
            'ProcureDesk-linked ' . $label . ' Request #' . $requestId
                . ' cannot be reopened or detached from its payment directly in AcctLab.',
            409
        );
    }
}

/** @return array<int,array<string,mixed>> */
function fxFundRequestLifecycleLinkedRequestsByInstruction(mysqli $conn, int $instructionId): array
{
    return fxFundRequestLifecycleLinkedRequestsForInstruction($conn, $instructionId, true);
}

/**
 * Reopen directly-created AcctLab FX Fund Requests by detaching them from an
 * FX payment. A partial grouped-payment detach is supported: the remaining
 * instruction amount is recalculated from the remaining request allocations.
 *
 * @return array{reopened:array<int,int>,deleted_instruction_ids:array<int,int>,adjusted_instruction_ids:array<int,int>}
 */
function fxFundRequestLifecycleReopenManualRequests(
    mysqli $conn,
    array $requestIds,
    int $actorUserId,
    string $actorEmail,
    string $reason = '',
    bool $allowPaidCorrection = false
): array {
    $ids = fxFundRequestLifecycleNormalizeRequestIds($requestIds);
    $rows = fxFundRequestLifecycleLockRequests($conn, $ids);

    $byInstruction = [];
    $unlinkedIds = [];
    foreach ($ids as $requestId) {
        fxFundRequestLifecycleAssertManualRequest($conn, $requestId);
        $instructionId = (int) ($rows[$requestId]['fx_instruction_letter_id'] ?? 0);
        if ($instructionId > 0) {
            $byInstruction[$instructionId][] = $requestId;
        } else {
            $unlinkedIds[] = $requestId;
        }
    }

    $reopened = [];
    $deletedInstructions = [];
    $adjustedInstructions = [];
    $normalizedReason = trim($reason);

    foreach ($byInstruction as $instructionId => $selectedIds) {
        $instructionStmt = $conn->prepare(
            'SELECT id, payment_status, currency_table, amount_figure FROM fx_instruction_letter_table WHERE id = ? FOR UPDATE'
        );
        $instructionStmt->bind_param('i', $instructionId);
        $instructionStmt->execute();
        $instruction = $instructionStmt->get_result()->fetch_assoc();
        $instructionStmt->close();
        if (!$instruction) {
            throw new Exception('Linked FX Instruction #' . $instructionId . ' was not found.', 409);
        }

        $instructionStatus = trim((string) ($instruction['payment_status'] ?? ''));
        if (strcasecmp($instructionStatus, 'Paid') === 0) {
            if (!$allowPaidCorrection || $normalizedReason === '') {
                throw new Exception(
                    'FX Instruction #' . $instructionId
                        . ' is Paid. Enter a correction reason before reopening the linked Fund Request(s).',
                    409
                );
            }
        }

        $allLinked = fxFundRequestLifecycleLinkedRequestsByInstruction($conn, (int) $instructionId);
        $allLinkedIds = array_map(static fn(array $row): int => (int) $row['id'], $allLinked);

        if (strcasecmp($instructionStatus, 'Paid') === 0) {
            $selectedSorted = $selectedIds;
            sort($selectedSorted, SORT_NUMERIC);
            $allSorted = $allLinkedIds;
            sort($allSorted, SORT_NUMERIC);
            if ($selectedSorted !== $allSorted) {
                throw new Exception(
                    'A Paid grouped FX payment can only be corrected by reopening every Fund Request linked to that payment.',
                    409
                );
            }
        }

        $selectedLookup = array_fill_keys($selectedIds, true);
        $remaining = array_values(array_filter(
            $allLinked,
            static fn(array $row): bool => !isset($selectedLookup[(int) $row['id']])
        ));

        $resetStmt = $conn->prepare(
            "UPDATE fx_fund_request_table
             SET payment_status = 'Pending', fx_instruction_letter_id = NULL,
                 payment_currency = NULL, payment_amount = NULL, exchange_rate = NULL,
                 processed_by = NULL, processed_at = NULL, updated_by = ?, updated_at = NOW()
             WHERE id = ? AND fx_instruction_letter_id = ?"
        );
        foreach ($selectedIds as $requestId) {
            $resetStmt->bind_param('iii', $actorUserId, $requestId, $instructionId);
            $resetStmt->execute();
            if ($resetStmt->affected_rows !== 1) {
                throw new Exception('FX Fund Request #' . $requestId . ' changed before it could be reopened.', 409);
            }
            $reopened[] = $requestId;
            fxFundRequestInsertLog(
                $conn,
                $actorUserId,
                $actorEmail,
                $actorEmail . ' reopened direct AcctLab FX Fund Request #' . $requestId
                    . ' from FX Instruction #' . $instructionId . ' back to Pending.'
                    . ($normalizedReason !== '' ? ' Reason: ' . $normalizedReason : '')
            );
        }
        $resetStmt->close();

        if ($remaining === []) {
            $delete = $conn->prepare('DELETE FROM fx_instruction_letter_table WHERE id = ?');
            $delete->bind_param('i', $instructionId);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                throw new Exception('FX Instruction #' . $instructionId . ' could not be removed while reopening its Fund Request(s).', 409);
            }
            $delete->close();
            $deletedInstructions[] = (int) $instructionId;
        } else {
            $remainingAmount = 0.0;
            foreach ($remaining as $remainingRequest) {
                if ($remainingRequest['payment_amount'] === null) {
                    throw new Exception(
                        'FX Instruction #' . $instructionId . ' has a linked request without a payment allocation.',
                        409
                    );
                }
                $remainingAmount = round($remainingAmount + (float) $remainingRequest['payment_amount'], 2);
            }
            if ($remainingAmount <= 0) {
                throw new Exception('Remaining grouped FX payment amount must be greater than zero.', 409);
            }
            $paymentCurrency = trim((string) ($instruction['currency_table'] ?? ''));
            if ($paymentCurrency === '') {
                throw new Exception('FX Instruction #' . $instructionId . ' is missing its payment currency.', 409);
            }
            $amountWords = fxFundRequestAmountWords($remainingAmount, $paymentCurrency);
            $amountFigure = number_format($remainingAmount, 2, '.', '');
            $updateInstruction = $conn->prepare(
                'UPDATE fx_instruction_letter_table SET amount_figure = ?, amount_words = ?, updated_at = NOW() WHERE id = ?'
            );
            $updateInstruction->bind_param('ssi', $amountFigure, $amountWords, $instructionId);
            $updateInstruction->execute();
            $updateInstruction->close();
            $adjustedInstructions[] = (int) $instructionId;
        }
    }

    if ($unlinkedIds !== []) {
        $pending = $conn->prepare(
            "UPDATE fx_fund_request_table
             SET payment_status = 'Pending', payment_currency = NULL, payment_amount = NULL, exchange_rate = NULL,
                 processed_by = NULL, processed_at = NULL, updated_by = ?, updated_at = NOW()
             WHERE id = ?"
        );
        foreach ($unlinkedIds as $requestId) {
            $pending->bind_param('ii', $actorUserId, $requestId);
            $pending->execute();
            $reopened[] = $requestId;
        }
        $pending->close();
    }

    sort($reopened, SORT_NUMERIC);
    sort($deletedInstructions, SORT_NUMERIC);
    sort($adjustedInstructions, SORT_NUMERIC);
    return [
        'reopened' => array_values(array_unique($reopened)),
        'deleted_instruction_ids' => array_values(array_unique($deletedInstructions)),
        'adjusted_instruction_ids' => array_values(array_unique($adjustedInstructions)),
    ];
}

/**
 * Apply a request-facing FX lifecycle status. Pending reopens direct AcctLab
 * requests. Other statuses stay payment-level and therefore require every
 * request linked to a grouped payment to be selected together.
 *
 * @return array<string,mixed>
 */
function fxFundRequestLifecycleApplyRequestStatus(
    mysqli $conn,
    array $requestIds,
    string $targetStatus,
    int $actorUserId,
    string $actorEmail,
    string $reason = ''
): array {
    $ids = fxFundRequestLifecycleNormalizeRequestIds($requestIds);
    if ($ids === []) {
        throw new Exception('Please select at least one FX Fund Request.', 400);
    }

    $target = trim($targetStatus);
    $allowed = ['Pending', 'Processing', 'Paid', 'Unconfirmed'];
    if (!in_array($target, $allowed, true)) {
        throw new Exception('Invalid FX Fund Request status. Allowed: Pending, Processing, Paid, Unconfirmed.', 400);
    }

    if ($target === 'Pending') {
        return [
            'target_status' => 'Pending',
            'reopen' => fxFundRequestLifecycleReopenManualRequests(
                $conn,
                $ids,
                $actorUserId,
                $actorEmail,
                $reason,
                trim($reason) !== ''
            ),
            'linked_fund_requests_synchronized' => count($ids),
        ];
    }

    $rows = fxFundRequestLifecycleLockRequests($conn, $ids);
    $selectedLookup = array_fill_keys($ids, true);
    $instructionIds = [];
    foreach ($ids as $requestId) {
        $instructionId = (int) ($rows[$requestId]['fx_instruction_letter_id'] ?? 0);
        if ($instructionId <= 0) {
            throw new Exception(
                'FX Fund Request #' . $requestId . ' is not linked to a payment. Use Create Payment before moving it to Processing.',
                409
            );
        }
        $instructionIds[$instructionId] = true;
    }

    foreach (array_keys($instructionIds) as $instructionId) {
        $linkedRows = fxFundRequestLifecycleLinkedRequestsByInstruction($conn, (int) $instructionId);
        foreach ($linkedRows as $linkedRow) {
            if (!isset($selectedLookup[(int) $linkedRow['id']])) {
                throw new Exception(
                    'FX Instruction #' . $instructionId
                        . ' contains multiple Fund Requests. Select every request linked to that payment before changing its shared status.',
                    409
                );
            }
        }
    }

    $instructionStatuses = fxFundRequestLifecycleLockInstructionStatuses($conn, array_keys($instructionIds));
    $reason = trim($reason);
    foreach ($instructionStatuses as $instructionId => $currentStatus) {
        if (strcasecmp((string) $currentStatus, 'Paid') === 0 && $target !== 'Paid' && $reason === '') {
            throw new Exception(
                'A reason is required when correcting a Paid FX payment to ' . $target . '.',
                409
            );
        }
    }

    // Processing is the Fund Request state while its payment instruction is
    // still Pending in the FX payment queue.
    $instructionTarget = $target === 'Processing' ? 'Pending' : $target;
    $placeholders = implode(',', array_fill(0, count($instructionIds), '?'));
    $types = 's' . str_repeat('i', count($instructionIds));
    $instructionIdList = array_map('intval', array_keys($instructionIds));
    sort($instructionIdList, SORT_NUMERIC);
    $stmt = $conn->prepare(
        "UPDATE fx_instruction_letter_table SET payment_status = ?, updated_at = NOW() WHERE id IN ($placeholders)"
    );
    $params = array_merge([$instructionTarget], $instructionIdList);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    $targets = [];
    foreach ($instructionIdList as $instructionId) {
        $targets[$instructionId] = $instructionTarget;
    }
    $synced = fxFundRequestLifecycleSyncMany(
        $conn,
        $targets,
        $actorUserId,
        $actorEmail,
        'FX Fund Request status update'
    );

    fxFundRequestInsertLog(
        $conn,
        $actorUserId,
        $actorEmail,
        $actorEmail . ' updated FX Fund Request ID(s) ' . implode(', ', $ids)
            . ' to ' . $target . ' through linked FX payment status synchronization.'
            . ($reason !== '' ? ' Reason: ' . $reason : '')
    );

    return [
        'target_status' => $target,
        'instruction_status' => $instructionTarget,
        'instruction_ids' => $instructionIdList,
        'linked_fund_requests_synchronized' => count($synced),
    ];
}
