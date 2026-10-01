<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementNotificationService.php';
require_once __DIR__ . '/workflowEventCanonicalReadService.php';
require_once __DIR__ . '/workflowEventCanonicalWriteService.php';

function procurementLocalAdvancePoAuditTimestamp(mysqli $conn): string
{
    $row = $conn->query('SELECT NOW() AS action_at')->fetch_assoc();
    return (string) ($row['action_at'] ?? date('Y-m-d H:i:s'));
}

function procurementLocalAdvancePoAuditJson(array $details): ?string
{
    if ($details === []) {
        return null;
    }
    $json = json_encode(
        $details,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($json === false) {
        throw new RuntimeException('Unable to encode the PO amendment audit details.', 500);
    }
    return $json;
}

function procurementLocalAdvancePoAuditContext(
    mysqli $conn,
    int $poId,
    ?int $revisionId = null,
    ?int $reconciliationId = null
): array {
    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT po.id AS po_id, po.po_number, po.supplier_name, po.created_by AS po_created_by,
                po.current_revision_id, po.current_revision_number, po.amendment_status,
                revision.id AS revision_id, revision.revision_number,
                revision.revision_reference, revision.revision_status,
                revision.amendment_type, revision.amendment_reason,
                revision.created_by AS revision_created_by,
                revision.submitted_by AS revision_submitted_by,
                reconciliation.id AS reconciliation_id,
                reconciliation.reconciliation_direction,
                reconciliation.reconciliation_status,
                reconciliation.reconciliation_amount,
                reconciliation.previous_committed_amount,
                reconciliation.revised_committed_amount,
                reconciliation.total_paid_at_revision,
                reconciliation.total_processing_at_revision,
                reconciliation.total_pending_at_revision,
                reconciliation.supplementary_purchase_id,
                reconciliation.supplementary_advance_payment_request_id,
                reconciliation.account_reconciliation_id,
                reconciliation.resolution_type,
                reconciliation.recovery_reference,
                reconciliation.resolution_notes,
                (SELECT purchase.id
                 FROM {$localAdvanceRelation} purchase
                 WHERE purchase.po_id = po.id AND purchase.deleted_at IS NULL
                 ORDER BY purchase.id ASC LIMIT 1) AS representative_purchase_id
         FROM procurement_local_advance_pos po
         LEFT JOIN procurement_local_advance_po_revisions revision
           ON revision.id = COALESCE(NULLIF(?, 0), po.current_revision_id)
         LEFT JOIN procurement_local_advance_po_revision_reconciliations reconciliation
           ON reconciliation.id = NULLIF(?, 0)
         WHERE po.id = ? LIMIT 1"
    );
    $revisionValue = max(0, (int) ($revisionId ?? 0));
    $reconciliationValue = max(0, (int) ($reconciliationId ?? 0));
    $stmt->bind_param('iii', $revisionValue, $reconciliationValue, $poId);
    $stmt->execute();
    $context = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$context) {
        throw new RuntimeException('The PO amendment audit context could not be resolved.', 409);
    }
    return $context;
}

function procurementLocalAdvancePoAuditRecipients(array $context): array
{
    return array_values(array_unique(array_filter([
        (int) ($context['po_created_by'] ?? 0),
        (int) ($context['revision_created_by'] ?? 0),
        (int) ($context['revision_submitted_by'] ?? 0),
    ], static fn(int $id): bool => $id > 0)));
}

function procurementLocalAdvancePoAuditMoney(mixed $value): string
{
    return number_format((float) ($value ?? 0), 2, '.', ',');
}

function procurementLocalAdvancePublishPoAuditNotification(
    mysqli $conn,
    int $eventId,
    string $eventType,
    array $context,
    array $actor,
    array $details
): int {
    $definitions = [
        'amendment_submitted' => ['Paid PO amendment submitted', 'warning'],
        'amendment_approved' => ['Paid PO amendment approved', 'success'],
        'amendment_rejected' => ['Paid PO amendment rejected', 'warning'],
        'amendment_cancelled' => ['Paid PO amendment cancelled', 'warning'],
        'reconciliation_resolved' => ['PO recovery reconciliation resolved', 'success'],
    ];
    if (!isset($definitions[$eventType])) {
        return 0;
    }

    [$title, $severity] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $poNumber = procurementNotificationText($context['po_number'] ?? '', 120, 'Local Advance PO');
    $revisionNumber = max(1, (int) ($context['revision_number'] ?? 1));
    $actionAt = procurementNotificationText(
        $details['action_at'] ?? '',
        40,
        procurementLocalAdvancePoAuditTimestamp($conn)
    );
    $reason = procurementNotificationText(
        $details['reason'] ?? $context['amendment_reason'] ?? '',
        1000
    );

    if ($eventType === 'amendment_submitted') {
        $message = $actorInfo['email'] . ' submitted PO ' . $poNumber . ' Revision '
            . $revisionNumber . ' for approval.';
        if ($reason !== '') {
            $message .= ' Reason: ' . $reason . '.';
        }
    } elseif ($eventType === 'amendment_approved') {
        $direction = procurementNotificationText(
            $details['reconciliation_direction'] ?? $context['reconciliation_direction'] ?? '',
            20,
            'No Change'
        );
        $amount = procurementLocalAdvancePoAuditMoney(
            $details['reconciliation_amount'] ?? $context['reconciliation_amount'] ?? 0
        );
        $message = $actorInfo['email'] . ' approved PO ' . $poNumber . ' Revision '
            . $revisionNumber . '. Reconciliation: ' . $direction . ' (NGN ' . $amount . ').';
        $supplementaryRequestId = (int) (
            $details['supplementary_advance_payment_request_id']
            ?? $context['supplementary_advance_payment_request_id']
            ?? 0
        );
        if ($supplementaryRequestId > 0) {
            $message .= ' Supplementary Advance Fund Request #' . $supplementaryRequestId . ' was created.';
        }
    } elseif ($eventType === 'amendment_rejected') {
        $message = $actorInfo['email'] . ' rejected PO ' . $poNumber . ' Revision '
            . $revisionNumber . '.';
        if ($reason !== '') {
            $message .= ' Reason: ' . $reason . '.';
        }
    } elseif ($eventType === 'amendment_cancelled') {
        $message = $actorInfo['email'] . ' cancelled PO ' . $poNumber . ' Revision '
            . $revisionNumber . '.';
        if ($reason !== '') {
            $message .= ' Reason: ' . $reason . '.';
        }
    } else {
        $resolutionType = procurementNotificationText(
            $details['resolution_type'] ?? $context['resolution_type'] ?? '',
            60,
            'Resolved'
        );
        $reference = procurementNotificationText(
            $details['recovery_reference'] ?? $context['recovery_reference'] ?? '',
            160
        );
        $message = $actorInfo['email'] . ' resolved the recovery for PO ' . $poNumber
            . ' Revision ' . $revisionNumber . ' as ' . $resolutionType . '.';
        if ($reference !== '') {
            $message .= ' Reference: ' . $reference . '.';
        }
    }
    $message .= ' Timestamp: ' . $actionAt . ' WAT.';

    $purchaseId = max(0, (int) ($context['representative_purchase_id'] ?? 0));
    $route = $purchaseId > 0
        ? '/payments/local/advance/' . $purchaseId
        : '/payments/local/advance';

    return procurementNotificationPublish($conn, [
        'type' => 'local_advance_po_' . $eventType,
        'action_key' => $eventType,
        'source_app' => 'procuredesk',
        'category' => 'local_advance_po_amendment',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'entity_type' => 'local_advance_po_revision',
        'entity_id' => (string) ($context['revision_id'] ?? $context['po_id']),
        'route' => $route,
        'recipient_ids' => procurementLocalAdvancePoAuditRecipients($context),
        'permission_codes' => $eventType === 'amendment_submitted'
            ? ['payments.local_advance.approve_po_amendment', 'payments.local_advance.view']
            : ['payments.local_advance.view'],
        'dedupe_key' => 'procuredesk:local-advance-po-event:' . $eventId,
        'payload' => array_merge($details, [
            'procurement_po_event_id' => $eventId,
            'po_id' => (int) $context['po_id'],
            'po_number' => $poNumber,
            'revision_id' => (int) ($context['revision_id'] ?? 0),
            'revision_number' => $revisionNumber,
            'revision_reference' => (string) ($context['revision_reference'] ?? ''),
            'reconciliation_id' => (int) ($context['reconciliation_id'] ?? 0),
            'supplier_name' => (string) ($context['supplier_name'] ?? ''),
        ]),
    ]);
}

function procurementLocalAdvanceRecordPoWorkflowEvent(
    mysqli $conn,
    int $poId,
    ?int $revisionId,
    ?int $reconciliationId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $context = procurementLocalAdvancePoAuditContext($conn, $poId, $revisionId, $reconciliationId);
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $actionAt = procurementLocalAdvancePoAuditTimestamp($conn);
    $details = array_merge($details, [
        'po_id' => $poId,
        'po_number' => (string) ($context['po_number'] ?? ''),
        'revision_id' => max(0, (int) ($context['revision_id'] ?? $revisionId ?? 0)),
        'revision_number' => max(1, (int) ($context['revision_number'] ?? 1)),
        'revision_reference' => (string) ($context['revision_reference'] ?? ''),
        'reconciliation_id' => max(0, (int) ($context['reconciliation_id'] ?? $reconciliationId ?? 0)),
        'actor_user_id' => $actorInfo['id'],
        'actor_email' => $actorInfo['email'],
        'action_at' => $actionAt,
    ]);
    $detailsJson = procurementLocalAdvancePoAuditJson($details);
    $resolvedRevisionId = max(0, (int) ($details['revision_id'] ?? 0));
    $resolvedReconciliationId = max(0, (int) ($details['reconciliation_id'] ?? 0));
    $eventKey = 'po:' . $poId . ':revision:' . $resolvedRevisionId
        . ':reconciliation:' . $resolvedReconciliationId
        . ':' . $eventType;

    $eventId = workflowEventRecordAdvancePo(
        $conn,
        $poId,
        $resolvedRevisionId,
        $resolvedReconciliationId,
        $eventType,
        $eventKey,
        $actorInfo['id'],
        $actorInfo['email'],
        $detailsJson,
        $actionAt
    );
    if ($eventId <= 0) {
        throw new RuntimeException('The PO amendment audit event could not be recorded.', 500);
    }

    procurementLocalAdvancePublishPoAuditNotification(
        $conn,
        $eventId,
        $eventType,
        $context,
        $actorInfo,
        $details
    );
    return $eventId;
}

function procurementLocalAdvancePublishAccountPoAuditNotification(
    mysqli $conn,
    int $eventId,
    string $eventType,
    array $context,
    array $actor,
    array $details
): int {
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $poNumber = procurementNotificationText($context['po_number'] ?? '', 120, 'Local Advance PO');
    $revisionNumber = max(1, (int) ($context['revision_number'] ?? 1));
    $actionAt = procurementNotificationText(
        $details['action_at'] ?? '',
        40,
        procurementLocalAdvancePoAuditTimestamp($conn)
    );

    if ($eventType === 'po_amendment_synchronized') {
        $direction = procurementNotificationText(
            $details['reconciliation_direction'] ?? $context['reconciliation_direction'] ?? '',
            20,
            'No Change'
        );
        $amount = procurementLocalAdvancePoAuditMoney(
            $details['reconciliation_amount'] ?? $context['reconciliation_amount'] ?? 0
        );
        $title = 'Approved PO amendment synchronized';
        $severity = $direction === 'Decrease' ? 'warning' : 'success';
        $message = 'ProcureDesk synchronized PO ' . $poNumber . ' Revision ' . $revisionNumber
            . ' to AcctLab. Reconciliation: ' . $direction . ' (NGN ' . $amount . ').';
        $supplementaryRequestId = (int) (
            $details['supplementary_advance_payment_request_id']
            ?? $context['supplementary_advance_payment_request_id']
            ?? 0
        );
        if ($supplementaryRequestId > 0) {
            $message .= ' Supplementary Advance Fund Request #' . $supplementaryRequestId . ' is Pending.';
        }
    } else {
        $title = 'PO recovery reconciliation resolved';
        $severity = 'success';
        $resolutionType = procurementNotificationText(
            $details['resolution_type'] ?? $context['resolution_type'] ?? '',
            60,
            'Resolved'
        );
        $reference = procurementNotificationText(
            $details['recovery_reference'] ?? $context['recovery_reference'] ?? '',
            160
        );
        $message = $actorInfo['email'] . ' resolved PO ' . $poNumber . ' Revision '
            . $revisionNumber . ' recovery as ' . $resolutionType . '.';
        if ($reference !== '') {
            $message .= ' Reference: ' . $reference . '.';
        }
    }
    $message .= ' Timestamp: ' . $actionAt . ' WAT.';

    return accountNotificationPublish($conn, [
        'type' => 'advance_po_' . $eventType,
        'action_key' => $eventType,
        'source_app' => 'procuredesk',
        'category' => 'advance_po_reconciliation',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'entity_type' => 'advance_po_reconciliation',
        'entity_id' => (string) ($context['account_reconciliation_id'] ?? $context['reconciliation_id'] ?? 0),
        'route' => '/payments/fund-request/advance?search=' . rawurlencode($poNumber),
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
        'dedupe_key' => 'acctlab:local-advance-po-event:' . $eventId,
        'payload' => array_merge($details, [
            'account_po_event_id' => $eventId,
            'po_id' => (int) $context['po_id'],
            'po_number' => $poNumber,
            'revision_id' => (int) ($context['revision_id'] ?? 0),
            'revision_number' => $revisionNumber,
            'reconciliation_id' => (int) ($context['reconciliation_id'] ?? 0),
            'account_reconciliation_id' => (int) ($context['account_reconciliation_id'] ?? 0),
        ]),
    ]);
}

function procurementLocalAdvanceRecordAccountPoWorkflowEvent(
    mysqli $conn,
    int $poId,
    int $revisionId,
    int $reconciliationId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $context = procurementLocalAdvancePoAuditContext($conn, $poId, $revisionId, $reconciliationId);
    $accountReconciliationId = max(0, (int) ($context['account_reconciliation_id'] ?? 0));
    if ($accountReconciliationId <= 0) {
        throw new RuntimeException('The AcctLab PO reconciliation audit link is missing.', 409);
    }
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $actionAt = procurementLocalAdvancePoAuditTimestamp($conn);
    $details = array_merge($details, [
        'po_id' => $poId,
        'po_number' => (string) ($context['po_number'] ?? ''),
        'revision_id' => $revisionId,
        'revision_number' => max(1, (int) ($context['revision_number'] ?? 1)),
        'reconciliation_id' => $reconciliationId,
        'account_reconciliation_id' => $accountReconciliationId,
        'actor_user_id' => $actorInfo['id'],
        'actor_email' => $actorInfo['email'],
        'action_at' => $actionAt,
    ]);
    $detailsJson = procurementLocalAdvancePoAuditJson($details);
    $eventKey = 'account-reconciliation:' . $accountReconciliationId . ':' . $eventType;

    $eventId = workflowEventRecordAdvanceReconciliation(
        $conn,
        $accountReconciliationId,
        $reconciliationId,
        $poId,
        $revisionId,
        $eventType,
        $eventKey,
        $actorInfo['id'],
        $actorInfo['email'],
        $detailsJson,
        $actionAt
    );
    if ($eventId <= 0) {
        throw new RuntimeException('The AcctLab PO reconciliation audit event could not be recorded.', 500);
    }

    procurementLocalAdvancePublishAccountPoAuditNotification(
        $conn,
        $eventId,
        $eventType,
        $context,
        $actorInfo,
        $details
    );
    return $eventId;
}

function procurementLocalAdvancePoWorkflowEventRows(
    mysqli $conn,
    int $poId,
    string $eventSource
): array {
    $stmt = $conn->prepare(
        "SELECT event_source.id, event_source.po_id, event_source.revision_id,
                event_source.reconciliation_id, event_source.event_type,
                event_source.actor_user_id, event_source.actor_email,
                event_source.details_json, event_source.created_at
         FROM {$eventSource} AS event_source
         WHERE event_source.po_id = ?
         ORDER BY event_source.created_at DESC, event_source.id DESC"
    );
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $events;
}

function procurementLocalAdvanceListPoWorkflowEvents(mysqli $conn, int $poId): array
{
    $eventSource = workflowEventReadSource($conn, WORKFLOW_EVENT_SOURCE_ADVANCE_PO);
    try {
        $events = procurementLocalAdvancePoWorkflowEventRows($conn, $poId, $eventSource);
    } catch (Throwable $error) {
        if ($eventSource === WORKFLOW_EVENT_SOURCE_ADVANCE_PO) {
            throw $error;
        }
        error_log(
            'Canonical Local Advance PO event read failed; using legacy fallback: '
            . $error->getMessage()
        );
        $events = procurementLocalAdvancePoWorkflowEventRows(
            $conn,
            $poId,
            WORKFLOW_EVENT_SOURCE_ADVANCE_PO
        );
    }
    foreach ($events as &$event) {
        foreach (['id', 'po_id', 'revision_id', 'reconciliation_id', 'actor_user_id'] as $field) {
            $event[$field] = $event[$field] === null ? null : (int) $event[$field];
        }
        $details = json_decode((string) ($event['details_json'] ?? ''), true);
        $event['details'] = is_array($details) ? $details : null;
        unset($event['details_json']);
    }
    unset($event);
    return $events;
}

function procurementLocalAdvanceAccountWorkflowEvents(
    mysqli $conn,
    array $accountReconciliationIds
): array {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $accountReconciliationIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $eventSource = workflowEventReadSource(
        $conn,
        WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION
    );
    $read = static function (string $source) use ($conn, $placeholders, $types, $ids): array {
        $stmt = $conn->prepare(
            "SELECT event_source.id, event_source.account_reconciliation_id,
                    event_source.procurement_reconciliation_id, event_source.po_id,
                    event_source.po_revision_id, event_source.event_type,
                    event_source.actor_user_id, event_source.actor_email,
                    event_source.details_json, event_source.created_at
             FROM {$source} AS event_source
             WHERE event_source.account_reconciliation_id IN ($placeholders)
             ORDER BY event_source.created_at DESC, event_source.id DESC"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    };
    try {
        $rows = $read($eventSource);
    } catch (Throwable $error) {
        if ($eventSource === WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION) {
            throw $error;
        }
        error_log(
            'Canonical Local Advance reconciliation event read failed; using legacy fallback: '
            . $error->getMessage()
        );
        $rows = $read(WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION);
    }

    $grouped = [];
    foreach ($rows as $row) {
        foreach ([
            'id', 'account_reconciliation_id', 'procurement_reconciliation_id',
            'po_id', 'po_revision_id', 'actor_user_id',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $details = json_decode((string) ($row['details_json'] ?? ''), true);
        $row['details'] = is_array($details) ? $details : null;
        unset($row['details_json']);
        $grouped[(int) $row['account_reconciliation_id']][] = $row;
    }
    return $grouped;
}
