<?php

declare(strict_types=1);

require_once __DIR__ . '/procurementAuthService.php';
require_once __DIR__ . '/accountNotificationService.php';
require_once __DIR__ . '/procurementRequestCanonicalRuntimeService.php';

/**
 * Shared ProcureDesk notification storage and publishing helpers.
 *
 * Module-specific services publish into this common inbox so procurement,
 * approval, access, and Account-originated activities share one delivery and
 * read-state model without restricting notifications to a single workflow.
 */

function procurementNotificationTableExists(mysqli $conn, string $table): bool
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

function procurementNotificationColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function procurementNotificationEnsureColumn(
    mysqli $conn,
    string $table,
    string $column,
    string $definition
): void {
    if (!procurementNotificationColumnExists($conn, $table, $column)
        && !$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
        throw new RuntimeException('Unable to update ProcureDesk notification storage.', 500);
    }
}

function procurementNotificationIndexExists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function procurementNotificationEnsureStorage(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }

    accountNotificationEnsureStorage($conn);
    if (!userNotificationCanonicalRuntimeEnabled($conn)) {
        throw new RuntimeException(
            'Canonical ProcureDesk notification runtime is not healthy.',
            500
        );
    }

    $ensured = true;
}

function procurementNotificationText(mixed $value, int $max, string $fallback = ''): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return $fallback;
    }
    return strlen($text) > $max ? substr($text, 0, $max) : $text;
}

function procurementNotificationActor(array $actor): array
{
    return [
        'id' => max(0, (int) ($actor['id'] ?? 0)),
        'email' => procurementNotificationText($actor['email'] ?? 'system', 255, 'system'),
    ];
}

/**
 * Account payment synchronization historically supplied only the Account user
 * ID to the ProcureDesk audit service. Resolve the email here so mirrored
 * notifications identify the real actor without changing every existing sync
 * call. Scheduler actions remain clearly labelled as system actions.
 */
function procurementNotificationResolveActor(mysqli $conn, array $actor): array
{
    $resolved = procurementNotificationActor($actor);
    $genericEmails = ['account-user', 'system', ''];
    if (!in_array(strtolower($resolved['email']), $genericEmails, true)) {
        return $resolved;
    }

    if ($resolved['id'] <= 0) {
        $resolved['email'] = 'AcctLab scheduler';
        return $resolved;
    }

    $stmt = $conn->prepare('SELECT email FROM user_table WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return $resolved;
    }
    $stmt->bind_param('i', $resolved['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $email = procurementNotificationText($row['email'] ?? '', 255);
    if ($email !== '') {
        $resolved['email'] = $email;
    }
    return $resolved;
}

function procurementNotificationPaymentMethodLabel(array $details): string
{
    $method = procurementNotificationText($details['processing_method'] ?? '', 60);
    $normalized = strtolower(preg_replace('/\s+/', ' ', trim($method)) ?? trim($method));

    if ($normalized === '') {
        return 'Payment';
    }
    if (in_array($normalized, ['bank instruction', 'instruction', 'prepare instruction'], true)) {
        return 'Bank Instruction';
    }
    if (in_array($normalized, ['gaps', 'prepare gaps'], true)) {
        return 'GAPS';
    }
    if (in_array($normalized, [
        'union bank schedule',
        'union schedule',
        'union bank',
        'union bank instruction',
        'union',
    ], true)) {
        return 'Union Bank Schedule';
    }
    if (in_array($normalized, ['manual', 'manual payment'], true)) {
        return 'Manual Payment';
    }

    return $method;
}

/**
 * Present only Account events that materially affect a ProcureDesk-linked
 * purchase. Internal synchronization-only events are deliberately ignored so
 * one business action does not generate duplicate inbox entries.
 */
function procurementNotificationWhtAdjustmentMessage(
    array $details,
    string $poNumber,
    array $actor
): string {
    $reference = procurementNotificationText($poNumber, 120, 'Unknown');
    $previousStatus = procurementNotificationText($details['previous_wht_status'] ?? '', 40, 'Unknown');
    $newStatus = procurementNotificationText($details['new_wht_status'] ?? '', 40, 'Unknown');
    $previousAmount = procurementNotificationText($details['previous_wht_amount'] ?? '', 40, '0.00');
    $newAmount = procurementNotificationText($details['new_wht_amount'] ?? '', 40, '0.00');
    $previousExpected = procurementNotificationText($details['previous_expected_amount'] ?? '', 40, '0.00');
    $newExpected = procurementNotificationText($details['new_expected_amount'] ?? '', 40, '0.00');
    $reason = procurementNotificationText($details['reason'] ?? '', 600, 'Not provided');
    $adjustedAt = procurementNotificationText($details['adjusted_at'] ?? '', 40, date('Y-m-d H:i:s'));
    $actorEmail = procurementNotificationText(
        $details['adjusted_by_email'] ?? $actor['email'] ?? '',
        180,
        'system'
    );

    return 'PO ' . $reference . ' WHT adjusted from ' . $previousStatus . ' (' . $previousAmount
        . ') to ' . $newStatus . ' (' . $newAmount . '). Expected amount changed from '
        . $previousExpected . ' to ' . $newExpected . '. Reason: ' . $reason
        . '. User: ' . $actorEmail . '. Timestamp: ' . $adjustedAt . ' WAT.';
}

function procurementNotificationAccountEventPresentation(string $eventType, array $details): ?array
{
    $paymentStatus = procurementNotificationText($details['payment_status'] ?? '', 40);
    $confirmationStatus = procurementNotificationText($details['payment_confirmation_status'] ?? '', 40);
    $methodLabel = procurementNotificationPaymentMethodLabel($details);

    if ($eventType === 'account_updated_sent_purchase_payment_details') {
        return null;
    }

    switch ($eventType) {
        case 'account_processing_started':
            return ["$methodLabel processing started", "started $methodLabel processing for", 'info', 'account_processing_started'];
        case 'account_payment_completed_immediately':
            return ["$methodLabel payment marked Paid", 'marked', 'success', 'account_payment_completed'];
        case 'account_payment_auto_completed':
            return ["$methodLabel payment completed automatically", "automatically completed the $methodLabel payment for", 'success', 'account_payment_auto_completed'];
        case 'account_payment_confirmation_due':
            return ["$methodLabel confirmation is due", "requires $methodLabel confirmation for", 'warning', 'account_payment_confirmation_due'];
        case 'account_paid_status_reversed':
            return ['Paid status corrected by Account', 'corrected the Paid status for', 'warning', 'account_paid_status_reversed'];
        case 'account_updated_sent_purchase':
            return ['Purchase updated by Account', 'updated the Account-owned details for', 'info', 'account_updated_sent_purchase'];
        case 'account_wht_adjusted':
            return ['WHT adjusted by Account', 'adjusted WHT for', 'warning', 'account_wht_adjusted'];
        case 'account_gaps_schedule_updated':
            return ['GAPS schedule updated by Account', 'updated the linked GAPS schedule for', 'info', 'account_gaps_schedule_updated'];
        case 'account_gaps_schedule_removed_after_exception':
            return ['GAPS exception removed from schedule', 'removed an exception-linked GAPS row from the downloadable schedule for', 'warning', 'account_gaps_schedule_removed_after_exception'];
        case 'account_union_bank_schedule_updated':
            return ['Union Bank schedule updated by Account', 'updated the linked Union Bank schedule for', 'info', 'account_union_bank_schedule_updated'];
        case 'account_union_bank_schedule_removed_after_exception':
            return ['Union Bank exception removed from schedule', 'removed an exception-linked Union Bank row from the downloadable schedule for', 'warning', 'account_union_bank_schedule_removed_after_exception'];
        case 'account_payment_reviewed':
            if ($paymentStatus === 'Paid') {
                return ["$methodLabel payment marked Paid", 'marked', 'success', 'account_payment_paid'];
            }
            if ($paymentStatus === 'Failed') {
                return ["$methodLabel payment marked Failed", "marked the $methodLabel payment as Failed for", 'error', 'account_payment_failed'];
            }
            if ($paymentStatus === 'Cancelled') {
                return ["$methodLabel processing cancelled", "cancelled $methodLabel processing for", 'warning', 'account_payment_cancelled'];
            }
            if ($confirmationStatus === 'Delayed') {
                return ["$methodLabel processing delayed", "delayed $methodLabel processing for", 'warning', 'account_payment_delayed'];
            }
            return ["$methodLabel processing reviewed", "reviewed $methodLabel processing for", 'info', 'account_payment_reviewed'];
        case 'account_payment_status_updated':
        case 'account_payment_updated':
            $severity = 'info';
            if ($paymentStatus === 'Paid') {
                $severity = 'success';
            } elseif ($paymentStatus === 'Failed') {
                $severity = 'error';
            } elseif (in_array($paymentStatus, ['Cancelled', 'Delayed'], true)) {
                $severity = 'warning';
            }
            return ["$methodLabel status updated", "updated the $methodLabel status for", $severity, 'account_payment_status_updated'];
        default:
            return null;
    }
}

/**
 * Mirror committed ProcureDesk Local Final Purchase actions into the existing
 * AcctLab notification inbox. Account-originated synchronization events are
 * intentionally excluded so a single action is never echoed back to its source.
 */
function procurementNotificationMirrorLocalFinalEventToAccount(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => [
            'Local Final Purchase created in ProcureDesk',
            'created',
            'info',
            'procuredesk_local_final_created',
        ],
        'updated' => [
            'Local Final Purchase updated in ProcureDesk',
            'updated',
            'info',
            'procuredesk_local_final_updated',
        ],
        'deleted' => [
            'Local Final Purchase deleted in ProcureDesk',
            'deleted',
            'warning',
            'procuredesk_local_final_deleted',
        ],
        'approved' => [
            'Purchase sent to Account',
            'approved and sent',
            'success',
            'procuredesk_purchase_sent_to_account',
        ],
        'resubmitted_to_account' => [
            'Purchase resubmitted to Account',
            'updated and resubmitted',
            'success',
            'procuredesk_purchase_resubmitted',
        ],
        'approval_reversed' => [
            'Procurement approval reversed',
            'reversed the approval for',
            'warning',
            'procuredesk_approval_reversed',
        ],
        'retrieved_from_account' => [
            'Purchase retrieved from Account',
            'retrieved',
            'warning',
            'procuredesk_purchase_retrieved',
        ],
        'po_status_updated' => [
            'ProcureDesk PO status updated',
            'updated the PO status for',
            'info',
            'procuredesk_po_status_updated',
        ],
    ];

    if (!isset($definitions[$eventType])) {
        return 0;
    }

    procurementRequestCanonicalLocalFinalAssertReady($conn);
    $localFinalRelation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id, purchase_number, po_number, supplier_name, created_by,
                approval_status, payment_status, po_status, handoff_status,
                supplier_fund_request_id, previous_supplier_fund_request_id,
                deleted_at
         FROM {$localFinalRelation} purchase
         WHERE purchase.id = ? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the ProcureDesk notification mirror.', 500);
    }
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        return 0;
    }

    [$title, $verb, $severity, $actionKey] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $purchaseNumber = procurementNotificationText(
        $purchase['purchase_number'] ?? '',
        120,
        'Local Final Purchase #' . $purchaseId
    );
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $requestId = max(0, (int) ($purchase['supplier_fund_request_id'] ?? 0));
    $previousRequestId = max(0, (int) ($purchase['previous_supplier_fund_request_id'] ?? 0));
    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));

    $message = $actorInfo['email'] . ' ' . $verb . ' ' . $purchaseNumber;
    if ($supplierName !== '') {
        $message .= ' for ' . $supplierName;
    }

    if (in_array($eventType, ['approved', 'resubmitted_to_account'], true)) {
        $message .= ' to Account';
        if ($requestId > 0) {
            $message .= ' as Supplier Fund Request #' . $requestId;
        }
    } elseif ($eventType === 'approval_reversed') {
        $message .= ' and removed its pending Supplier Fund Request from Account';
    } elseif ($eventType === 'retrieved_from_account') {
        $message .= ' from Account before payment processing started';
    } elseif ($eventType === 'po_status_updated') {
        $newStatus = procurementNotificationText($details['po_status'] ?? $purchase['po_status'] ?? '', 40);
        $previousStatus = procurementNotificationText($details['previous_status'] ?? '', 40);
        if ($newStatus !== '') {
            $message .= $previousStatus !== ''
                ? ' from ' . $previousStatus . ' to ' . $newStatus
                : ' to ' . $newStatus;
        }
        if ($newStatus === 'Cancelled') {
            $severity = 'warning';
        }
    }

    $reason = procurementNotificationText($details['reason'] ?? '', 600);
    if ($reason !== '' && in_array($eventType, [
        'deleted',
        'approval_reversed',
        'retrieved_from_account',
        'po_status_updated',
    ], true)) {
        $message .= '. Reason: ' . $reason;
    }
    $message .= '.';

    $route = '/payments/fund-request/supplier';
    if ($requestId > 0 && in_array($eventType, ['approved', 'resubmitted_to_account', 'po_status_updated'], true)) {
        $route .= '?search=' . rawurlencode($purchaseNumber);
        $paymentStatus = procurementNotificationText($purchase['payment_status'] ?? '', 40);
        if ($paymentStatus !== '') {
            $route .= '&payment_status=' . rawurlencode($paymentStatus);
        }
    }

    $dedupeKey = $eventId > 0
        ? 'acctlab:procuredesk-event:' . $eventId
        : 'acctlab:procuredesk-event:' . $eventType . ':' . $purchaseId . ':' . date('YmdHis');

    return accountNotificationPublish($conn, [
        'type' => $actionKey,
        'action_key' => $actionKey,
        'source_app' => 'procuredesk',
        'category' => 'local_final_purchase',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'entity_type' => 'local_final_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => $route,
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
        'dedupe_key' => $dedupeKey,
        'payload' => array_merge($details, [
            'origin' => 'procuredesk_action',
            'purchase_id' => $purchaseId,
            'purchase_number' => $purchaseNumber,
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'supplier_name' => $supplierName,
            'supplier_fund_request_id' => $requestId > 0 ? $requestId : null,
            'previous_supplier_fund_request_id' => $previousRequestId > 0 ? $previousRequestId : null,
            'approval_status' => (string) ($purchase['approval_status'] ?? ''),
            'payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'po_status' => (string) ($purchase['po_status'] ?? ''),
            'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
            'procuredesk_route' => empty($purchase['deleted_at'])
                ? '/payments/local/final/' . $purchaseId
                : '/payments/local/final',
        ]),
    ]);
}


/**
 * Mirror ProcureDesk-originated Local Advance Purchase actions to AcctLab.
 * Account-originated synchronization events are excluded to avoid echo loops.
 */
function procurementNotificationMirrorLocalAdvanceEventToAccount(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['Local Advance Purchase created in ProcureDesk', 'created', 'info', 'procuredesk_local_advance_created'],
        'updated' => ['Local Advance Purchase updated in ProcureDesk', 'updated', 'info', 'procuredesk_local_advance_updated'],
        'deleted' => ['Local Advance Purchase deleted in ProcureDesk', 'deleted', 'warning', 'procuredesk_local_advance_deleted'],
        'approved' => ['Advance request sent to Account', 'approved and sent', 'success', 'procuredesk_advance_sent_to_account'],
        'resubmitted_to_account' => ['Advance request resubmitted to Account', 'updated and resubmitted', 'success', 'procuredesk_advance_resubmitted'],
        'approval_reversed' => ['Local Advance approval reversed', 'reversed the approval for', 'warning', 'procuredesk_advance_approval_reversed'],
        'retrieved_from_account' => ['Advance request retrieved from Account', 'retrieved', 'warning', 'procuredesk_advance_retrieved'],
        'po_status_updated' => ['Local Advance PO status updated', 'updated the PO status for', 'info', 'procuredesk_advance_po_status_updated'],
    ];
    if (!isset($definitions[$eventType])) {
        return 0;
    }

    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT r.id, r.request_number, r.purchase_number, r.created_by,
                r.approval_status, r.payment_status, r.handoff_status,
                r.advance_payment_request_id, r.previous_advance_payment_request_id, r.deleted_at,
                p.po_number, p.supplier_name, p.po_status
         FROM {$localAdvanceRelation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE r.id = ? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Local Advance Account notification mirror.', 500);
    }
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        return 0;
    }

    [$title, $verb, $severity, $actionKey] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $reference = procurementNotificationText(
        $purchase['po_number'] ?? $purchase['request_number'] ?? $purchase['purchase_number'] ?? '',
        120,
        'Local Advance Purchase #' . $purchaseId
    );
    $requestReference = procurementNotificationText($purchase['request_number'] ?? '', 120);
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $requestId = max(0, (int) ($purchase['advance_payment_request_id'] ?? 0));
    $previousRequestId = max(0, (int) ($purchase['previous_advance_payment_request_id'] ?? 0));
    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));

    $message = $actorInfo['email'] . ' ' . $verb . ' PO ' . $reference;
    if ($requestReference !== '' && $requestReference !== $reference) {
        $message .= ' (Request ' . $requestReference . ')';
    }
    if ($supplierName !== '') {
        $message .= ' for ' . $supplierName;
    }
    if (in_array($eventType, ['approved', 'resubmitted_to_account'], true)) {
        $message .= ' to Account';
        if ($requestId > 0) {
            $message .= ' as Advance Fund Request #' . $requestId;
        }
    } elseif ($eventType === 'approval_reversed') {
        $message .= ' and removed its pending Advance Fund Request from Account';
    } elseif ($eventType === 'retrieved_from_account') {
        $message .= ' from Account before payment processing started';
    } elseif ($eventType === 'po_status_updated') {
        $newStatus = procurementNotificationText($details['po_status'] ?? $purchase['po_status'] ?? '', 40);
        $previousStatus = procurementNotificationText($details['previous_status'] ?? '', 40);
        if ($newStatus !== '') {
            $message .= $previousStatus !== ''
                ? ' from ' . $previousStatus . ' to ' . $newStatus
                : ' to ' . $newStatus;
        }
        if ($newStatus === 'Cancelled') {
            $severity = 'warning';
        }
    }

    $reason = procurementNotificationText($details['reason'] ?? '', 600);
    if ($reason !== '' && in_array($eventType, [
        'deleted', 'approval_reversed', 'retrieved_from_account', 'po_status_updated',
    ], true)) {
        $message .= '. Reason: ' . $reason;
    }
    $message .= '.';

    $route = '/payments/fund-request/advance';
    if ($requestId > 0 && in_array($eventType, ['approved', 'resubmitted_to_account', 'po_status_updated'], true)) {
        $route .= '?search=' . rawurlencode((string) ($purchase['po_number'] ?? $reference));
        $paymentStatus = procurementNotificationText($purchase['payment_status'] ?? '', 40);
        if ($paymentStatus !== '') {
            $route .= '&payment_status=' . rawurlencode($paymentStatus);
        }
    }

    $dedupeKey = $eventId > 0
        ? 'acctlab:procuredesk-local-advance-event:' . $eventId
        : 'acctlab:procuredesk-local-advance-event:' . $eventType . ':' . $purchaseId . ':' . date('YmdHis');

    return accountNotificationPublish($conn, [
        'type' => $actionKey,
        'action_key' => $actionKey,
        'source_app' => 'procuredesk',
        'category' => 'local_advance_purchase',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'entity_type' => 'local_advance_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => $route,
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
        'dedupe_key' => $dedupeKey,
        'payload' => array_merge($details, [
            'origin' => 'procuredesk_action',
            'purchase_id' => $purchaseId,
            'request_number' => (string) ($purchase['request_number'] ?? ''),
            'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'supplier_name' => $supplierName,
            'advance_payment_request_id' => $requestId > 0 ? $requestId : null,
            'previous_advance_payment_request_id' => $previousRequestId > 0 ? $previousRequestId : null,
            'approval_status' => (string) ($purchase['approval_status'] ?? ''),
            'payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'po_status' => (string) ($purchase['po_status'] ?? ''),
            'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
            'procuredesk_route' => empty($purchase['deleted_at'])
                ? '/payments/local/advance/' . $purchaseId
                : '/payments/local/advance',
        ]),
    ]);
}

/**
 * Resolve active ProcureDesk recipients as a union of explicit users, roles,
 * and effective permissions. The acting user remains included whenever they
 * are an eligible recipient, so an action performed by the only authorized
 * ProcureDesk user still produces a visible notification.
 */
function procurementNotificationRecipientIds(mysqli $conn, array $options = []): array
{
    $explicit = array_values(array_unique(array_filter(
        array_map('intval', (array) ($options['recipient_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));
    $explicitMap = array_fill_keys($explicit, true);

    $roles = array_values(array_unique(array_filter(
        array_map('strval', (array) ($options['roles'] ?? [])),
        static fn(string $role): bool => in_array($role, PROCUREMENT_ROLES, true)
    )));
    $roleMap = array_fill_keys($roles, true);

    $permissions = array_values(array_unique(array_filter(
        array_map('strval', (array) ($options['permission_codes'] ?? [])),
        static fn(string $permission): bool => trim($permission) !== ''
    )));

    $stmt = $conn->prepare(
        "SELECT u.id, u.department, pua.role
         FROM user_table u
         INNER JOIN procurement_user_access pua ON pua.user_id = u.id
         WHERE u.status = 'Active' AND pua.is_active = 1
         ORDER BY u.id ASC"
    );
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $excluded = array_fill_keys(array_map('intval', (array) ($options['exclude_user_ids'] ?? [])), true);
    $resolved = [];

    foreach ($users as $user) {
        $id = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        if ($id <= 0 || isset($excluded[$id]) || !procurementUserCanAccessApp($user)) {
            continue;
        }

        $include = isset($explicitMap[$id]) || isset($roleMap[$role]);
        if (!$include && $permissions !== []) {
            $effective = procurementPermissionCodes($conn, $role, $id);
            $include = count(array_intersect($permissions, $effective)) > 0;
        }

        if ($include) {
            $resolved[$id] = true;
        }
    }

    return array_map('intval', array_keys($resolved));
}

function procurementNotificationPublish(mysqli $conn, array $notification): int
{
    procurementNotificationEnsureStorage($conn);

    $type = procurementNotificationText($notification['type'] ?? '', 60);
    $title = procurementNotificationText($notification['title'] ?? '', 180);
    $message = trim((string) ($notification['message'] ?? ''));
    if ($type === '' || $title === '' || $message === '') {
        throw new RuntimeException('Notification type, title and message are required.', 500);
    }

    $actor = procurementNotificationActor((array) ($notification['actor'] ?? []));
    $sourceApp = procurementNotificationText($notification['source_app'] ?? 'procuredesk', 30, 'procuredesk');
    $category = procurementNotificationText($notification['category'] ?? 'workflow', 60, 'workflow');
    $severity = strtolower(procurementNotificationText($notification['severity'] ?? 'info', 20, 'info'));
    if (!in_array($severity, ['info', 'success', 'warning', 'error'], true)) {
        $severity = 'info';
    }
    $actionKey = procurementNotificationText($notification['action_key'] ?? $type, 80, $type);
    $entityType = procurementNotificationText($notification['entity_type'] ?? '', 80);
    $entityId = procurementNotificationText($notification['entity_id'] ?? '', 120);
    $route = procurementNotificationText($notification['route'] ?? '', 255);
    $dedupePrefix = procurementNotificationText($notification['dedupe_key'] ?? '', 150);

    $payload = is_array($notification['payload'] ?? null) ? $notification['payload'] : [];
    $payload = array_merge($payload, [
        'source_app' => $sourceApp,
        'category' => $category,
        'severity' => $severity,
        'action_key' => $actionKey,
        'actor' => $actor,
        'entity_type' => $entityType !== '' ? $entityType : null,
        'entity_id' => $entityId !== '' ? $entityId : null,
        'route' => $route !== '' ? $route : ($payload['route'] ?? null),
    ]);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        throw new RuntimeException('Unable to encode ProcureDesk notification context.', 500);
    }

    $recipients = procurementNotificationRecipientIds($conn, $notification);
    if ($recipients === []) {
        return 0;
    }

    if (!userNotificationCanonicalRuntimeEnabled($conn)) {
        throw new RuntimeException('Canonical ProcureDesk notification runtime is not healthy.', 500);
    }
    $managesTransaction = false;
    $publicIdLock = null;
    $stmt = null;
    $created = 0;

    try {
        if (!accountNotificationConnectionInTransaction($conn)) {
            $conn->begin_transaction();
            $managesTransaction = true;
        }
        $publicIdLock = userNotificationCanonicalAcquirePublicIdLock(
            $conn,
            'procuredesk'
        );
        $nextPublicId = userNotificationCanonicalNextPublicId(
            $conn,
            'procuredesk'
        );
        $storageTable = userNotificationCanonicalRuntimeStorageTable($conn);
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO `{$storageTable}`
                (inbox_app, inbox_notification_id, recipient_user_id, source_app,
                 notification_type, category, severity, action_key, title, message,
                 actor_user_id, actor_email, entity_type, entity_id, route,
                 payload_json, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare ProcureDesk notification.', 500);
        }

        foreach ($recipients as $recipientId) {
            $dedupeKey = $dedupePrefix !== '' ? $dedupePrefix . ':' . $recipientId : null;
            $inboxApp = 'procuredesk';
            $publicId = $nextPublicId++;
            $stmt->bind_param(
                'siisssssssissssss',
                $inboxApp,
                $publicId,
                $recipientId,
                $sourceApp,
                $type,
                $category,
                $severity,
                $actionKey,
                $title,
                $message,
                $actor['id'],
                $actor['email'],
                $entityType,
                $entityId,
                $route,
                $payloadJson,
                $dedupeKey
            );
            $stmt->execute();
            $created += max(0, $stmt->affected_rows);
        }

        if ($managesTransaction) {
            $conn->commit();
        }
    } catch (Throwable $error) {
        if ($managesTransaction) {
            $conn->rollback();
        }
        throw $error;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        if (is_string($publicIdLock) && $publicIdLock !== '') {
            userNotificationCanonicalReleasePublicIdLock($conn, $publicIdLock);
        }
    }

    return $created;
}

/**
 * Convert committed Local Final Purchase events into ProcureDesk notifications.
 * ProcureDesk actions keep their existing wording, while material Account
 * payment actions are mirrored into the same inbox with source_app = acctlab.
 */
function procurementNotificationPublishLocalFinalEvent(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['Local Final Purchase created', 'created', 'info'],
        'updated' => ['Local Final Purchase updated', 'updated', 'info'],
        'deleted' => ['Local Final Purchase deleted', 'deleted', 'warning'],
        'approved' => ['Local Final Purchase approved', 'approved and sent to Account', 'success'],
        'resubmitted_to_account' => ['Local Final Purchase resubmitted', 'resubmitted to Account', 'success'],
        'approval_reversed' => ['Local Final Purchase approval reversed', 'reversed the approval for', 'warning'],
        'retrieved_from_account' => ['Local Final Purchase retrieved', 'retrieved from Account', 'warning'],
        'po_status_updated' => ['Local Final Purchase PO status updated', 'updated the PO status for', 'info'],
    ];
    $accountPresentation = procurementNotificationAccountEventPresentation($eventType, $details);
    if (!isset($definitions[$eventType]) && $accountPresentation === null) {
        return 0;
    }

    procurementRequestCanonicalLocalFinalAssertReady($conn);
    $localFinalRelation = procurementRequestCanonicalLocalFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id, purchase_number, po_number, supplier_name, created_by, approval_status,
                payment_status, po_status, handoff_status, deleted_at
         FROM {$localFinalRelation} purchase WHERE purchase.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        return 0;
    }

    $purchaseNumber = procurementNotificationText($purchase['purchase_number'] ?? '', 120, 'Local Final Purchase');
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $route = empty($purchase['deleted_at'])
        ? '/payments/local/final/' . $purchaseId
        : '/payments/local/final';

    if ($accountPresentation !== null) {
        [$title, $verb, $severity, $actionKey] = $accountPresentation;
        if ($eventType === 'account_wht_adjusted') {
            $message = procurementNotificationWhtAdjustmentMessage(
                $details,
                (string) ($purchase['po_number'] ?? $purchaseNumber),
                $actorInfo
            );
        } else {
            $message = $actorInfo['email'] . ' ' . $verb . ' ' . $purchaseNumber;
            if ($supplierName !== '') {
                $message .= ' for ' . $supplierName;
            }
        }

        $paymentStatus = procurementNotificationText($details['payment_status'] ?? '', 40);
        $confirmationStatus = procurementNotificationText($details['payment_confirmation_status'] ?? '', 40);
        $processingReference = procurementNotificationText($details['processing_reference'] ?? '', 160);
        $expectedCompletion = procurementNotificationText($details['expected_completion_at'] ?? '', 40);
        $reason = procurementNotificationText(
            $details['reason'] ?? $details['account_remarks'] ?? '',
            600
        );

        if ($eventType !== 'account_wht_adjusted') {
            if ($eventType === 'account_payment_completed_immediately'
                || $eventType === 'account_payment_auto_completed'
                || ($eventType === 'account_payment_reviewed' && $paymentStatus === 'Paid')) {
                $message .= ' as Paid';
            } elseif (in_array($eventType, ['account_payment_status_updated', 'account_payment_updated'], true)
                && $paymentStatus !== '') {
                $message .= ' to ' . $paymentStatus;
            } elseif ($eventType === 'account_paid_status_reversed' && $paymentStatus !== '') {
                $message .= ' to ' . $paymentStatus;
            }

            if ($confirmationStatus === 'Delayed' && $expectedCompletion !== '') {
                $message .= '. New expected completion: ' . $expectedCompletion;
            } elseif ($eventType === 'account_processing_started' && $expectedCompletion !== '') {
                $message .= '. Expected completion: ' . $expectedCompletion;
            }
            if ($processingReference !== '' && in_array($eventType, [
                'account_processing_started',
                'account_payment_completed_immediately',
                'account_payment_auto_completed',
            ], true)) {
                $message .= '. Reference: ' . $processingReference;
            }
            if ($reason !== '' && in_array($eventType, [
                'account_payment_reviewed',
                'account_paid_status_reversed',
                'account_payment_status_updated',
            ], true)) {
                $message .= '. Reason: ' . $reason;
            }
            $message .= '.';
        }

        $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));
        $dedupeKey = $eventId > 0
            ? 'procuredesk:account-event:' . $eventId
            : 'procuredesk:account-event:' . $eventType . ':' . $purchaseId . ':' . date('YmdHis');
        if ($eventType === 'account_updated_sent_purchase') {
            // A commercial Account edit currently writes both a domain event and
            // a payment-detail synchronization event in the same transaction.
            // Keep one user-facing notification while preserving both audits.
            $dedupeKey = 'procuredesk:account-purchase-update:' . $purchaseId . ':'
                . $actorInfo['id'] . ':' . date('YmdHis');
        }

        return procurementNotificationPublish($conn, [
            'type' => 'local_final_' . $actionKey,
            'title' => $title,
            'message' => $message,
            'actor' => $actorInfo,
            'source_app' => 'acctlab',
            'category' => 'local_final_purchase',
            'severity' => $severity,
            'action_key' => $actionKey,
            'entity_type' => 'local_final_purchase',
            'entity_id' => (string) $purchaseId,
            'route' => $route,
            'recipient_ids' => [(int) ($purchase['created_by'] ?? 0)],
            'permission_codes' => ['payments.local_final.view'],
                'dedupe_key' => $dedupeKey,
            'payload' => array_merge($details, [
                'purchase_id' => $purchaseId,
                'purchase_number' => $purchaseNumber,
                'po_number' => (string) ($purchase['po_number'] ?? ''),
                'supplier_name' => $supplierName,
                'approval_status' => (string) ($purchase['approval_status'] ?? ''),
                'payment_status' => (string) ($purchase['payment_status'] ?? ''),
                'po_status' => (string) ($purchase['po_status'] ?? ''),
                'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
                'origin' => 'account_sync',
            ]),
        ]);
    }

    [$title, $verb, $severity] = $definitions[$eventType];
    if ($eventType === 'po_status_updated' && (string) ($details['po_status'] ?? '') === 'Cancelled') {
        $severity = 'warning';
    }
    if (in_array($eventType, ['account_payment_updated', 'account_payment_status_updated'], true)) {
        $syncedPaymentStatus = trim((string) ($details['payment_status'] ?? $purchase['payment_status'] ?? ''));
        if ($syncedPaymentStatus === 'Paid') {
            $severity = 'success';
        } elseif (in_array($syncedPaymentStatus, ['Failed', 'Cancelled'], true)) {
            $severity = 'warning';
        }
    }

    $message = $actorInfo['email'] . ' ' . $verb . ' ' . $purchaseNumber;
    if ($supplierName !== '') {
        $message .= ' for ' . $supplierName;
    }
    if ($eventType === 'po_status_updated' && !empty($details['po_status'])) {
        $message .= ' to ' . (string) $details['po_status'];
    } elseif (in_array($eventType, ['account_payment_updated', 'account_payment_status_updated'], true)) {
        $paymentStatus = procurementNotificationText($details['payment_status'] ?? $purchase['payment_status'] ?? '', 40);
        if ($paymentStatus !== '') {
            $message .= ' to ' . $paymentStatus;
        }
        if ($paymentStatus === 'Paid') {
            $severity = 'success';
        } elseif ($paymentStatus === 'Failed') {
            $severity = 'error';
        } elseif ($paymentStatus === 'Cancelled') {
            $severity = 'warning';
        }
    }
    if (in_array($eventType, ['approval_reversed', 'retrieved_from_account', 'returned_by_account', 'deleted'], true)
        && trim((string) ($details['reason'] ?? '')) !== '') {
        $message .= '. Reason: ' . trim((string) $details['reason']);
    }
    $message .= '.';

    return procurementNotificationPublish($conn, [
        'type' => 'local_final_' . $eventType,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'source_app' => 'procuredesk',
        'category' => 'local_final_purchase',
        'severity' => $severity,
        'action_key' => $eventType,
        'entity_type' => 'local_final_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => $route,
        'recipient_ids' => [(int) ($purchase['created_by'] ?? 0)],
        'permission_codes' => ['payments.local_final.view'],
        'payload' => array_merge($details, [
            'purchase_id' => $purchaseId,
            'purchase_number' => $purchaseNumber,
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'supplier_name' => $supplierName,
            'approval_status' => (string) ($purchase['approval_status'] ?? ''),
            'payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'po_status' => (string) ($purchase['po_status'] ?? ''),
            'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
        ]),
    ]);
}

/**
 * Convert Local Advance Purchase and Account synchronization events into
 * ProcureDesk notifications.
 */
function procurementNotificationPublishLocalAdvanceEvent(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['Local Advance Purchase created', 'created', 'info'],
        'updated' => ['Local Advance Purchase updated', 'updated', 'info'],
        'deleted' => ['Local Advance Purchase deleted', 'deleted', 'warning'],
        'approved' => ['Local Advance Purchase approved', 'approved and sent to Account', 'success'],
        'resubmitted_to_account' => ['Local Advance Purchase resubmitted', 'resubmitted to Account', 'success'],
        'approval_reversed' => ['Local Advance Purchase approval reversed', 'reversed the approval for', 'warning'],
        'retrieved_from_account' => ['Local Advance Purchase retrieved', 'retrieved from Account', 'warning'],
        'returned_by_account' => ['Local Advance Purchase returned', 'returned to Procurement', 'warning'],
        'account_payment_updated' => ['Advance payment updated', 'updated payment details for', 'info'],
        'account_payment_status_updated' => ['Advance payment status updated', 'updated the payment status for', 'info'],
        'account_payment_status_corrected' => ['Advance payment status corrected', 'corrected the payment status for', 'warning'],
        'account_wht_adjusted' => ['Advance WHT adjusted by Account', 'adjusted WHT for', 'warning'],
        'po_status_updated' => ['Local Advance Purchase PO status updated', 'updated the PO status for', 'info'],
    ];
    if (!isset($definitions[$eventType])) {
        return 0;
    }

    procurementRequestCanonicalLocalAdvanceAssertReady($conn);
    $localAdvanceRelation = procurementRequestCanonicalLocalAdvanceReadRelation();
    $stmt = $conn->prepare(
        "SELECT r.id, r.request_number, r.purchase_number, r.created_by, r.approval_status,
                r.payment_status, r.handoff_status, r.deleted_at,
                p.po_number, p.supplier_name, p.po_status
         FROM {$localAdvanceRelation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE r.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        return 0;
    }

    [$title, $verb, $severity] = $definitions[$eventType];
    if ($eventType === 'po_status_updated' && (string) ($details['po_status'] ?? '') === 'Cancelled') {
        $severity = 'warning';
    }
    if (in_array($eventType, ['account_payment_updated', 'account_payment_status_updated'], true)) {
        $syncedPaymentStatus = trim((string) ($details['payment_status'] ?? $purchase['payment_status'] ?? ''));
        if ($syncedPaymentStatus === 'Paid') {
            $severity = 'success';
        } elseif (in_array($syncedPaymentStatus, ['Failed', 'Cancelled'], true)) {
            $severity = 'warning';
        }
    }

    $reference = procurementNotificationText(
        $purchase['po_number'] ?? $purchase['request_number'] ?? $purchase['purchase_number'] ?? '',
        120,
        'Local Advance Purchase'
    );
    $requestReference = procurementNotificationText($purchase['request_number'] ?? '', 120);
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $route = empty($purchase['deleted_at'])
        ? '/payments/local/advance/' . $purchaseId
        : '/payments/local/advance';

    if ($eventType === 'account_wht_adjusted') {
        $message = procurementNotificationWhtAdjustmentMessage(
            $details,
            (string) ($purchase['po_number'] ?? $reference),
            $actorInfo
        );
    } elseif ($eventType === 'account_payment_status_corrected') {
        $previousStatus = procurementNotificationText(
            $details['previous_status'] ?? $details['previous_payment_status'] ?? '',
            40,
            'Unknown'
        );
        $newStatus = procurementNotificationText(
            $details['new_status'] ?? $details['requested_status'] ?? $details['payment_status'] ?? '',
            40,
            'Unknown'
        );
        $reason = procurementNotificationText($details['reason'] ?? '', 600, 'Not provided');
        $correctedAt = procurementNotificationText($details['corrected_at'] ?? '', 40, date('Y-m-d H:i:s'));
        $message = 'PO ' . $reference . ' payment status corrected from ' . $previousStatus
            . ' to ' . $newStatus . '. Reason: ' . $reason
            . '. User: ' . $actorInfo['email'] . '. Timestamp: ' . $correctedAt . ' WAT.';
    } else {
        $message = $actorInfo['email'] . ' ' . $verb . ' PO ' . $reference;
        if ($requestReference !== '' && $requestReference !== $reference) {
            $message .= ' (Request ' . $requestReference . ')';
        }
        if ($supplierName !== '') {
            $message .= ' for ' . $supplierName;
        }
        if ($eventType === 'po_status_updated' && !empty($details['po_status'])) {
            $message .= ' to ' . (string) $details['po_status'];
        } elseif (in_array($eventType, ['account_payment_updated', 'account_payment_status_updated'], true)
            && !empty($details['payment_status'])) {
            $message .= ' to ' . (string) $details['payment_status'];
        }
        if (in_array($eventType, ['approval_reversed', 'retrieved_from_account', 'returned_by_account', 'deleted'], true)
            && trim((string) ($details['reason'] ?? '')) !== '') {
            $message .= '. Reason: ' . trim((string) $details['reason']);
        }
        $message .= '.';
    }

    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));
    $dedupeKey = $eventId > 0
        ? 'procuredesk:local-advance-event:' . $eventId
        : 'procuredesk:local-advance-event:' . $eventType . ':' . $purchaseId . ':' . date('YmdHis');

    return procurementNotificationPublish($conn, [
        'type' => 'local_advance_' . $eventType,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'source_app' => $eventType === 'account_wht_adjusted' ? 'acctlab' : 'procuredesk',
        'category' => 'local_advance_purchase',
        'severity' => $severity,
        'action_key' => $eventType,
        'entity_type' => 'local_advance_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => $route,
        'recipient_ids' => [(int) ($purchase['created_by'] ?? 0)],
        'permission_codes' => ['payments.local_advance.view'],
        'dedupe_key' => $dedupeKey,
        'payload' => array_merge($details, [
            'purchase_id' => $purchaseId,
            'request_number' => (string) ($purchase['request_number'] ?? ''),
            'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'supplier_name' => $supplierName,
            'approval_status' => (string) ($purchase['approval_status'] ?? ''),
            'payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'po_status' => (string) ($purchase['po_status'] ?? ''),
            'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
        ]),
    ]);
}

/**
 * Publish Foreign/FX Final Purchase events into the ProcureDesk inbox.
 */
function procurementNotificationPublishFxFinalEvent(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['FX Final Purchase created', 'created', 'info'],
        'updated' => ['FX Final Purchase updated', 'updated', 'info'],
        'deleted' => ['FX Final Purchase deleted', 'deleted', 'warning'],
        'approved' => ['FX Final Purchase approved', 'approved and sent to Account', 'success'],
        'resubmitted_to_account' => ['FX Final Purchase resubmitted', 'resubmitted to Account', 'success'],
        'approval_reversed' => ['FX Final Purchase approval reversed', 'reversed the approval for', 'warning'],
        'retrieved_from_account' => ['FX Final Purchase retrieved', 'retrieved from Account', 'warning'],
        'returned_by_account' => ['FX Final Purchase returned', 'returned to Procurement', 'warning'],
        'po_status_updated' => ['FX Final Purchase PO status updated', 'updated the PO status for', 'info'],
        'account_processing_started' => ['FX payment processing started', 'started payment processing for', 'info'],
        'account_payment_status_updated' => ['FX payment status updated', 'updated payment status for', 'info'],
    ];
    if (!isset($definitions[$eventType])) {
        return 0;
    }

    procurementRequestCanonicalFxFinalAssertReady($conn);
    $relation = procurementRequestCanonicalFxFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id, purchase_number, po_number, supplier_name, currency, created_by,
                approval_status, payment_status, po_status, handoff_status, deleted_at
         FROM {$relation} p WHERE p.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        return 0;
    }

    [$title, $verb, $severity] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $purchaseNumber = procurementNotificationText($purchase['purchase_number'] ?? '', 120, 'FX Final Purchase');
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $message = $actorInfo['email'] . ' ' . $verb . ' ' . $purchaseNumber;
    if ($supplierName !== '') {
        $message .= ' for ' . $supplierName;
    }
    if (in_array($eventType, ['account_processing_started', 'account_payment_status_updated'], true)) {
        $paymentStatus = procurementNotificationText($details['payment_status'] ?? $purchase['payment_status'] ?? '', 40);
        if ($paymentStatus !== '') {
            $message .= ' to ' . $paymentStatus;
        }
        if ($paymentStatus === 'Paid') {
            $severity = 'success';
        }
    }
    $reason = procurementNotificationText($details['reason'] ?? '', 600);
    if ($reason !== '' && in_array($eventType, ['approval_reversed', 'retrieved_from_account', 'returned_by_account', 'deleted'], true)) {
        $message .= '. Reason: ' . $reason;
    }
    $message .= '.';

    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));
    $dedupeKey = $eventId > 0
        ? 'procuredesk:fx-final-event:' . $eventId
        : 'procuredesk:fx-final-event:' . $eventType . ':' . $purchaseId . ':' . date('YmdHis');

    return procurementNotificationPublish($conn, [
        'type' => 'fx_final_' . $eventType,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'source_app' => in_array($eventType, ['account_processing_started', 'account_payment_status_updated', 'returned_by_account'], true)
            ? 'acctlab' : 'procuredesk',
        'category' => 'foreign_final_purchase',
        'severity' => $severity,
        'action_key' => $eventType,
        'entity_type' => 'foreign_final_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => '/payments/foreign/final',
        'recipient_ids' => [(int) ($purchase['created_by'] ?? 0)],
        'permission_codes' => ['payments.fx_final.view'],
        'dedupe_key' => $dedupeKey,
        'payload' => array_merge($details, [
            'purchase_id' => $purchaseId,
            'purchase_number' => $purchaseNumber,
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'supplier_name' => $supplierName,
            'currency' => (string) ($purchase['currency'] ?? ''),
            'approval_status' => (string) ($purchase['approval_status'] ?? ''),
            'payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'po_status' => (string) ($purchase['po_status'] ?? ''),
            'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
        ]),
    ]);
}

/**
 * Mirror ProcureDesk-originated FX Final Purchase actions to AcctLab.
 */
function procurementNotificationMirrorFxFinalEventToAccount(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['FX Final Purchase created in ProcureDesk', 'created', 'info'],
        'updated' => ['FX Final Purchase updated in ProcureDesk', 'updated', 'info'],
        'deleted' => ['FX Final Purchase deleted in ProcureDesk', 'deleted', 'warning'],
        'approved' => ['FX Final request sent to Account', 'approved and sent', 'success'],
        'resubmitted_to_account' => ['FX Final request resubmitted to Account', 'updated and resubmitted', 'success'],
        'approval_reversed' => ['FX Final approval reversed', 'reversed the approval for', 'warning'],
        'retrieved_from_account' => ['FX Final request retrieved from Account', 'retrieved', 'warning'],
        'po_status_updated' => ['FX Final PO status updated', 'updated the PO status for', 'info'],
    ];
    if (!isset($definitions[$eventType])) {
        return 0;
    }

    $relation = procurementRequestCanonicalFxFinalReadRelation();
    $stmt = $conn->prepare(
        "SELECT id, purchase_number, po_number, supplier_name, currency, payment_status,
                account_request_id AS fx_fund_request_id
         FROM procurement_requests
         WHERE request_type = ? AND legacy_source_table = ? AND legacy_source_id = ?
           AND deleted_at IS NULL LIMIT 1"
    );
    $type = PROCUREMENT_REQUEST_TYPE_FX_FINAL;
    $source = PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE;
    $stmt->bind_param('ssi', $type, $source, $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) {
        return 0;
    }

    [$title, $verb, $severity] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $purchaseNumber = procurementNotificationText($purchase['purchase_number'] ?? '', 120, 'FX Final Purchase');
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $message = $actorInfo['email'] . ' ' . $verb . ' ' . $purchaseNumber;
    if ($supplierName !== '') {
        $message .= ' for ' . $supplierName;
    }
    if (in_array($eventType, ['approved', 'resubmitted_to_account'], true)) {
        $requestId = (int) ($purchase['fx_fund_request_id'] ?? 0);
        if ($requestId > 0) {
            $message .= ' as FX Fund Request #' . $requestId;
        }
    }
    $reason = procurementNotificationText($details['reason'] ?? '', 600);
    if ($reason !== '' && in_array($eventType, ['approval_reversed', 'retrieved_from_account', 'deleted'], true)) {
        $message .= '. Reason: ' . $reason;
    }
    $message .= '.';

    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));
    return accountNotificationPublish($conn, [
        'type' => 'procuredesk_fx_final_' . $eventType,
        'action_key' => 'procuredesk_fx_final_' . $eventType,
        'source_app' => 'procuredesk',
        'category' => 'foreign_final_purchase',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'entity_type' => 'foreign_final_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => '/payments/fund-request/fx',
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
        'dedupe_key' => $eventId > 0 ? 'acctlab:procuredesk-fx-final-event:' . $eventId : null,
        'payload' => array_merge($details, [
            'purchase_id' => $purchaseId,
            'purchase_number' => $purchaseNumber,
            'po_number' => (string) ($purchase['po_number'] ?? ''),
            'supplier_name' => $supplierName,
            'currency' => (string) ($purchase['currency'] ?? ''),
            'fx_fund_request_id' => (int) ($purchase['fx_fund_request_id'] ?? 0) ?: null,
        ]),
    ]);
}

/**
 * Publish Foreign/FX Advance Purchase events into the ProcureDesk inbox.
 */
function procurementNotificationPublishFxAdvanceEvent(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['FX Advance Purchase created', 'created', 'info'],
        'updated' => ['FX Advance Purchase updated', 'updated', 'info'],
        'deleted' => ['FX Advance Purchase deleted', 'deleted', 'warning'],
        'approved' => ['FX Advance Purchase approved', 'approved and sent to Account', 'success'],
        'resubmitted_to_account' => ['FX Advance Purchase resubmitted', 'resubmitted to Account', 'success'],
        'approval_reversed' => ['FX Advance Purchase approval reversed', 'reversed the approval for', 'warning'],
        'retrieved_from_account' => ['FX Advance Purchase retrieved', 'retrieved from Account', 'warning'],
        'returned_by_account' => ['FX Advance Purchase returned', 'returned to Procurement', 'warning'],
        'po_status_updated' => ['FX Advance PO status updated', 'updated the PO status for', 'info'],
        'account_processing_started' => ['FX Advance payment processing started', 'started payment processing for', 'info'],
        'account_payment_status_updated' => ['FX Advance payment status updated', 'updated payment status for', 'info'],
        'po_amendment_submitted' => ['FX Advance PO amendment submitted', 'submitted a PO amendment for', 'info'],
        'po_amendment_approved' => ['FX Advance PO amendment approved', 'approved the PO amendment for', 'success'],
        'po_amendment_rejected' => ['FX Advance PO amendment rejected', 'rejected the PO amendment for', 'warning'],
        'po_amendment_cancelled' => ['FX Advance PO amendment cancelled', 'cancelled the PO amendment for', 'warning'],
        'po_reconciliation_resolved' => ['FX Advance PO reconciliation resolved', 'resolved the PO reconciliation for', 'success'],
    ];
    if (!isset($definitions[$eventType])) return 0;

    $scope = defined('PROCUREMENT_ADVANCE_PO_SCOPE_FX') ? PROCUREMENT_ADVANCE_PO_SCOPE_FX : 'fx_advance_purchase';
    $type = defined('PROCUREMENT_REQUEST_TYPE_FX_ADVANCE') ? PROCUREMENT_REQUEST_TYPE_FX_ADVANCE : 'fx_advance_purchase';
    $stmt = $conn->prepare(
        "SELECT r.legacy_source_id AS id, r.request_number, r.purchase_number, r.po_percentage,
                r.currency, r.created_by, r.approval_status, r.payment_status, r.handoff_status,
                r.account_request_id AS fx_fund_request_id,
                COALESCE(pr.po_number, p.po_number) AS po_number,
                COALESCE(pr.supplier_name, p.supplier_name) AS supplier_name
         FROM procurement_requests r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id AND p.request_scope = ?
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id AND pr.request_scope = ?
         WHERE r.request_type = ? AND r.legacy_source_id = ? AND r.deleted_at IS NULL LIMIT 1"
    );
    $stmt->bind_param('sssi', $scope, $scope, $type, $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) return 0;

    [$title, $verb, $severity] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $poNumber = procurementNotificationText($purchase['po_number'] ?? '', 160, 'FX Advance PO');
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $message = $actorInfo['email'] . ' ' . $verb . ' ' . $poNumber;
    if ($supplierName !== '') $message .= ' for ' . $supplierName;
    if (in_array($eventType, ['account_processing_started', 'account_payment_status_updated'], true)) {
        $paymentStatus = procurementNotificationText($details['payment_status'] ?? $purchase['payment_status'] ?? '', 40);
        if ($paymentStatus !== '') $message .= ' to ' . $paymentStatus;
        if ($paymentStatus === 'Paid') $severity = 'success';
    }
    $reason = procurementNotificationText($details['reason'] ?? '', 600);
    if ($reason !== '' && in_array($eventType, [
        'approval_reversed', 'retrieved_from_account', 'returned_by_account', 'deleted',
        'po_amendment_submitted', 'po_amendment_approved', 'po_amendment_rejected', 'po_amendment_cancelled',
    ], true)) {
        $message .= '. Reason: ' . $reason;
    }
    if (in_array($eventType, ['po_amendment_approved', 'po_reconciliation_resolved'], true)) {
        $amount = procurementNotificationText($details['reconciliation_amount'] ?? '', 60);
        $currency = procurementNotificationText($details['currency'] ?? $purchase['currency'] ?? '', 3);
        if ($amount !== '') {
            $message .= '. Reconciliation: ' . ($currency !== '' ? $currency . ' ' : '') . $amount;
        }
    }
    $message .= '.';

    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));
    $dedupeKey = $eventId > 0
        ? 'procuredesk:fx-advance-event:' . $eventId
        : 'procuredesk:fx-advance-event:' . $eventType . ':' . $purchaseId . ':' . date('YmdHis');

    return procurementNotificationPublish($conn, [
        'type' => 'fx_advance_' . $eventType,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'source_app' => in_array($eventType, ['account_processing_started', 'account_payment_status_updated', 'returned_by_account'], true)
            ? 'acctlab' : 'procuredesk',
        'category' => 'foreign_advance_purchase',
        'severity' => $severity,
        'action_key' => $eventType,
        'entity_type' => 'foreign_advance_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => '/payments/foreign/advance',
        'recipient_ids' => [(int) ($purchase['created_by'] ?? 0)],
        'permission_codes' => ['payments.fx_advance.view'],
        'dedupe_key' => $dedupeKey,
        'payload' => array_merge($details, [
            'purchase_id' => $purchaseId,
            'request_number' => (string) ($purchase['request_number'] ?? ''),
            'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
            'po_number' => $poNumber,
            'po_percentage' => (string) ($purchase['po_percentage'] ?? ''),
            'supplier_name' => $supplierName,
            'currency' => (string) ($purchase['currency'] ?? ''),
            'approval_status' => (string) ($purchase['approval_status'] ?? ''),
            'payment_status' => (string) ($purchase['payment_status'] ?? ''),
            'handoff_status' => (string) ($purchase['handoff_status'] ?? ''),
        ]),
    ]);
}

/** Mirror ProcureDesk-originated FX Advance events into AcctLab. */
function procurementNotificationMirrorFxAdvanceEventToAccount(
    mysqli $conn,
    int $purchaseId,
    string $eventType,
    array $actor,
    array $details = []
): int {
    $definitions = [
        'created' => ['FX Advance Purchase created in ProcureDesk', 'created', 'info'],
        'updated' => ['FX Advance Purchase updated in ProcureDesk', 'updated', 'info'],
        'deleted' => ['FX Advance Purchase deleted in ProcureDesk', 'deleted', 'warning'],
        'approved' => ['FX Advance request sent to Account', 'approved and sent', 'success'],
        'resubmitted_to_account' => ['FX Advance request resubmitted to Account', 'updated and resubmitted', 'success'],
        'approval_reversed' => ['FX Advance approval reversed', 'reversed the approval for', 'warning'],
        'retrieved_from_account' => ['FX Advance request retrieved from Account', 'retrieved', 'warning'],
        'po_status_updated' => ['FX Advance PO status updated', 'updated the PO status for', 'info'],
        'po_amendment_submitted' => ['FX Advance PO amendment submitted in ProcureDesk', 'submitted a PO amendment for', 'info'],
        'po_amendment_approved' => ['FX Advance PO amendment approved in ProcureDesk', 'approved the PO amendment for', 'success'],
        'po_amendment_rejected' => ['FX Advance PO amendment rejected in ProcureDesk', 'rejected the PO amendment for', 'warning'],
        'po_amendment_cancelled' => ['FX Advance PO amendment cancelled in ProcureDesk', 'cancelled the PO amendment for', 'warning'],
        'po_reconciliation_resolved' => ['FX Advance PO reconciliation resolved in ProcureDesk', 'resolved the PO reconciliation for', 'success'],
    ];
    if (!isset($definitions[$eventType])) return 0;

    $scope = defined('PROCUREMENT_ADVANCE_PO_SCOPE_FX') ? PROCUREMENT_ADVANCE_PO_SCOPE_FX : 'fx_advance_purchase';
    $type = defined('PROCUREMENT_REQUEST_TYPE_FX_ADVANCE') ? PROCUREMENT_REQUEST_TYPE_FX_ADVANCE : 'fx_advance_purchase';
    $stmt = $conn->prepare(
        "SELECT r.legacy_source_id AS id, r.request_number, r.purchase_number, r.po_percentage,
                r.currency, r.payment_status, r.account_request_id AS fx_fund_request_id,
                COALESCE(pr.po_number, p.po_number) AS po_number,
                COALESCE(pr.supplier_name, p.supplier_name) AS supplier_name
         FROM procurement_requests r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id AND p.request_scope = ?
         LEFT JOIN procurement_local_advance_po_revisions pr ON pr.id = r.po_revision_id AND pr.request_scope = ?
         WHERE r.request_type = ? AND r.legacy_source_id = ? AND r.deleted_at IS NULL LIMIT 1"
    );
    $stmt->bind_param('sssi', $scope, $scope, $type, $purchaseId);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$purchase) return 0;

    [$title, $verb, $severity] = $definitions[$eventType];
    $actorInfo = procurementNotificationResolveActor($conn, $actor);
    $poNumber = procurementNotificationText($purchase['po_number'] ?? '', 160, 'FX Advance PO');
    $supplierName = procurementNotificationText($purchase['supplier_name'] ?? '', 180);
    $message = $actorInfo['email'] . ' ' . $verb . ' ' . $poNumber;
    if ($supplierName !== '') $message .= ' for ' . $supplierName;
    if (in_array($eventType, ['approved', 'resubmitted_to_account'], true)) {
        $requestId = (int) ($purchase['fx_fund_request_id'] ?? 0);
        if ($requestId > 0) $message .= ' as FX Fund Request #' . $requestId;
    }
    $reason = procurementNotificationText($details['reason'] ?? '', 600);
    if ($reason !== '' && in_array($eventType, [
        'approval_reversed', 'retrieved_from_account', 'deleted',
        'po_amendment_submitted', 'po_amendment_approved', 'po_amendment_rejected', 'po_amendment_cancelled',
    ], true)) {
        $message .= '. Reason: ' . $reason;
    }
    if (in_array($eventType, ['po_amendment_approved', 'po_reconciliation_resolved'], true)) {
        $amount = procurementNotificationText($details['reconciliation_amount'] ?? '', 60);
        $currency = procurementNotificationText($details['currency'] ?? $purchase['currency'] ?? '', 3);
        if ($amount !== '') {
            $message .= '. Reconciliation: ' . ($currency !== '' ? $currency . ' ' : '') . $amount;
        }
    }
    $message .= '.';

    $eventId = max(0, (int) ($details['procurement_event_id'] ?? 0));
    return accountNotificationPublish($conn, [
        'type' => 'procuredesk_fx_advance_' . $eventType,
        'action_key' => 'procuredesk_fx_advance_' . $eventType,
        'source_app' => 'procuredesk',
        'category' => 'foreign_advance_purchase',
        'severity' => $severity,
        'title' => $title,
        'message' => $message,
        'actor' => $actorInfo,
        'entity_type' => 'foreign_advance_purchase',
        'entity_id' => (string) $purchaseId,
        'route' => '/payments/fund-request/fx',
        'roles' => ['Admin', 'Super_Admin'],
        'department' => 'account',
        'dedupe_key' => $eventId > 0 ? 'acctlab:procuredesk-fx-advance-event:' . $eventId : null,
        'payload' => array_merge($details, [
            'purchase_id' => $purchaseId,
            'request_number' => (string) ($purchase['request_number'] ?? ''),
            'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
            'po_number' => $poNumber,
            'po_percentage' => (string) ($purchase['po_percentage'] ?? ''),
            'supplier_name' => $supplierName,
            'currency' => (string) ($purchase['currency'] ?? ''),
            'fx_fund_request_id' => (int) ($purchase['fx_fund_request_id'] ?? 0) ?: null,
        ]),
    ]);
}
