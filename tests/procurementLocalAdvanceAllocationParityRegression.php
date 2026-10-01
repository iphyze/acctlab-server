<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/procurementLocalAdvancePurchaseService.php');
$route = (string) file_get_contents($root . '/routes/procurement/payments/local/advancePurchases.php');
$options = (string) file_get_contents($root . '/routes/procurement/payments/local/advancePurchaseOptions.php');
$fxService = (string) file_get_contents($root . '/includes/procurementFxAdvancePurchaseService.php');

$batchStart = strpos($service, 'function procurementLocalAdvanceAllocatedUnitsForPoIds(');
$singleStart = strpos($service, 'function procurementLocalAdvanceAllocatedUnits(mysqli $conn, int $poId');
$percentStart = strpos($service, 'function procurementLocalAdvancePercentUnitsAllowZero', $singleStart === false ? 0 : $singleStart);
$allocationBlock = ($batchStart !== false && $percentStart !== false)
    ? substr($service, $batchStart, $percentStart - $batchStart)
    : '';

$checks = [
    'local_allocation_helpers_exist' =>
        $batchStart !== false && $singleStart !== false && $percentStart !== false,

    'local_allocation_is_canonical_procuredesk_only' =>
        str_contains($allocationBlock, 'procurementRequestCanonicalLocalAdvanceReadRelation()')
        && str_contains($allocationBlock, "payment_status <> 'Cancelled'")
        && str_contains($allocationBlock, 'deleted_at IS NULL')
        && !str_contains($allocationBlock, 'advance_payment_request')
        && !str_contains($allocationBlock, 'po_number_normalized'),

    'batch_register_allocation_remains_grouped_by_po' =>
        str_contains($allocationBlock, 'GROUP BY source.po_id')
        && str_contains($route, 'procurementLocalAdvanceAllocatedUnitsForPoIds('),

    'create_update_and_approval_still_revalidate_100_percent_guard' =>
        substr_count($route, 'procurementLocalAdvanceAssertAllocationAvailable(') >= 3
        && str_contains($service, 'procurementLocalAdvanceAssertAllocationAvailable(')
        && str_contains($service, 'PROCUREMENT_LOCAL_ADVANCE_PERCENT_MAX'),

    'po_lookup_uses_same_canonical_allocation_helper' =>
        substr_count($options, 'procurementLocalAdvanceAllocatedUnits($conn') >= 2,

    'fx_reference_model_is_canonical_only' =>
        str_contains($fxService, 'function procurementFxAdvanceAllocatedUnits(')
        && str_contains($fxService, 'procurementRequestCanonicalFxAdvanceReadRelation()'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
