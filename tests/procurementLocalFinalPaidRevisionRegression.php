<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementLocalFinalPurchaseService.php');
$route = (string) file_get_contents($root . '/routes/procurement/payments/local/finalPurchases.php');
$auth = (string) file_get_contents($root . '/includes/procurementAuthService.php');
$migration = (string) file_get_contents($root . '/database/new/20260917_local_final_paid_purchase_revision.sql');
$editor = (string) file_get_contents($root . '/src/pages/payments/LocalFinalPurchaseEditorPage.jsx');
$details = (string) file_get_contents($root . '/src/pages/payments/LocalFinalPurchaseDetailsPage.jsx');
$helpers = (string) file_get_contents($root . '/src/pages/payments/localFinalPurchaseHelpers.js');
$router = (string) file_get_contents($root . '/src/router/index.jsx');

$checks = [
    'paid_revision_permission_exists' =>
        str_contains($auth, 'payments.local_final.amend_paid_purchase'),

    'paid_revision_has_versioned_storage' =>
        str_contains($service, 'procurement_local_final_paid_revisions')
        && str_contains($migration, 'uq_local_final_paid_revision'),

    'paid_revision_preserves_historical_account_request' =>
        str_contains($service, "'Revised',")
        && str_contains($service, 'previous_account_request_id = ?')
        && !str_contains($service, 'DELETE FROM supplier_fund_request_table WHERE id = ? AND payment_status = \'Paid\''),

    'same_supplier_delta_is_directional' =>
        str_contains($service, '$recoverableCents = max($paidCents - $revisedPayableCents, 0);')
        && str_contains($service, '$additionalPayableCents = max($revisedPayableCents - $paidCents, 0);'),

    'supplier_change_reopens_full_new_supplier_payable' =>
        str_contains($service, '$recoverableCents = $paidCents;')
        && str_contains($service, '$additionalPayableCents = $revisedPayableCents;'),

    'financial_adjustments_are_created' =>
        str_contains($service, "'adjustment_direction' => 'Recoverable'")
        && str_contains($service, "'adjustment_direction' => 'Payable'")
        && str_contains($service, 'procurementSupplierAdjustmentCreate($conn'),

    'additional_payable_creates_new_account_request' =>
        str_contains($service, 'procurementLocalFinalCreateRevisionAccountRequest')
        && str_contains($service, 'procurementLocalFinalCreateHandoff('),

    'compass_handoffs_are_revision_safe' =>
        str_contains($migration, 'uq_compass_procurement_purchase_revision')
        && str_contains($service, 'procurementLocalFinalEnsureCompassRevisionIndex'),

    'paid_revision_requires_reason' =>
        str_contains($service, 'A paid revision reason is required.')
        && str_contains($editor, 'Revision Reason *'),

    'normal_update_and_paid_revision_permissions_are_separated' =>
        str_contains($route, 'procurementRequireAnyPermission')
        && str_contains($route, 'payments.local_final.amend_paid_purchase')
        && str_contains($route, "procurementLocalFinalActorHasPermission(\$actor, 'payments.local_final.update')"),

    'frontend_exposes_paid_revision_only_when_eligible' =>
        str_contains($helpers, 'is_paid_revisable')
        && str_contains($details, 'Revise paid purchase')
        && str_contains($editor, 'paidRevisionMode')
        && str_contains($router, "payments.local_final.amend_paid_purchase"),

    'paid_cancellation_remains_separate' =>
        str_contains($service, 'Use the paid cancellation workflow to cancel a paid PO.'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 2);
