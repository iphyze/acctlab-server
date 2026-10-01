<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$service = $read('includes/procurementFxAdvancePurchaseService.php');
$route = $read('routes/procurement/payments/foreign/advancePurchaseAmendments.php');
$index = $read('index.php');
$notifications = $read('includes/procurementNotificationService.php');
$fxService = $read('includes/fxFundRequestService.php');
$fxCreate = $read('routes/request/fx/create.php');
$fxEdit = $read('routes/request/fx/edit.php');

$checks = [
    'amendment_route_registered' => str_contains($index, "/procurement/payments/foreign/advance-purchases/amendments")
        && str_contains($index, 'routes/procurement/payments/foreign/advancePurchaseAmendments.php'),
    'amendment_route_uses_fx_permissions' => str_contains($route, 'payments.fx_advance.amend_paid_po')
        && str_contains($route, 'payments.fx_advance.approve_po_amendment')
        && str_contains($route, 'payments.fx_advance.resolve_po_reconciliation'),
    'local_revision_and_reconciliation_tables_are_reused' => str_contains($service, 'procurement_local_advance_po_revisions')
        && str_contains($service, 'procurement_local_advance_po_revision_reconciliations')
        && !str_contains($service . $route, 'procurement_fx_advance_po_revisions')
        && !str_contains($service . $route, 'procurement_fx_advance_po_revision_reconciliations'),
    'amendment_reads_and_writes_are_scope_currency_safe' => str_contains($service, 'PROCUREMENT_FX_ADVANCE_SCOPE')
        && str_contains($service, 'request_scope = ?')
        && str_contains($service, 'currency = ?'),
    'fx_revision_reference_is_namespaced' => str_contains($service, "'FX-ADV:' . trim(\$poNumber) . '/REV-'")
        && str_contains($service, 'procurementFxAdvancePoRevisionReference'),
    'currency_is_immutable_after_payment_activity' => str_contains($service, 'The currency cannot be changed after FX Advance payment activity has started'),
    'amendment_requires_locked_paid_revision_context' => str_contains($service, 'has no locked payment revision')
        && str_contains($service, 'No Account payment activity has been recorded for this FX PO'),
    'approval_creates_reconciliation_and_supersedes_previous_revision' => str_contains($service, 'function procurementFxAdvanceApprovePoAmendment')
        && str_contains($service, "revision_status = 'Superseded'")
        && str_contains($service, 'procurement_local_advance_po_revision_reconciliations'),
    'pending_fx_requests_reallocate_to_current_revision' => str_contains($service, 'function procurementFxAdvanceUpdatePendingAllocationToRevision')
        && str_contains($service, 'UPDATE fx_fund_request_table')
        && str_contains($service, 'procurementFxAdvanceCreateHandoff'),
    'supplementary_increase_creates_fx_procurement_and_account_requests' => str_contains($service, 'function procurementFxAdvanceCreateSupplementaryPurchaseAndRequest')
        && str_contains($service, "request_variant = 'Supplementary'")
        && str_contains($service, 'INSERT INTO fx_fund_request_table')
        && str_contains($service, "'fx_fund_request'"),
    'decrease_supports_recovery_resolution' => str_contains($service, "'Recovery Required'")
        && str_contains($service, 'function procurementFxAdvanceResolveRecoveryReconciliation')
        && str_contains($service, 'PROCUREMENT_LOCAL_ADVANCE_RECOVERY_RESOLUTION_TYPES'),
    'allocation_snapshot_is_currency_tagged' => str_contains($service, "'allocation_snapshot_json'")
        || (str_contains($service, 'allocation_snapshot_json') && str_contains($service, "'currency' => (string) \$revision['currency']")),
    'amendment_notifications_reach_both_apps' => str_contains($notifications, "'po_amendment_submitted'")
        && str_contains($notifications, "'po_amendment_approved'")
        && str_contains($notifications, "'po_reconciliation_resolved'")
        && str_contains($service, 'procurementNotificationPublishFxAdvanceEvent')
        && str_contains($service, 'procurementNotificationMirrorFxAdvanceEventToAccount'),
    'fx_percentage_guard_is_currency_aware' => str_contains($fxService, '?string $currency = null')
        && str_contains($fxService, 'f.currency = ?')
        && str_contains($fxCreate, "\$request['currency']")
        && str_contains($fxEdit, "\$request['currency']")
        && str_contains($service, "(string) \$payload['currency']"),
    'supplementary_amendments_do_not_consume_original_100_percent_guard' => str_contains($fxService, "pr.request_variant = 'Supplementary'")
        && str_contains($fxService, 'NOT EXISTS')
        && str_contains($fxService, "f.payment_status <> 'Cancelled'"),
    'no_schema_expansion_in_batch3' => !str_contains(strtoupper($service . $route . $fxService), 'CREATE TABLE')
        && !str_contains(strtoupper($service . $route . $fxService), 'ALTER TABLE'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
