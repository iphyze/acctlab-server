<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$finalRoute = file_get_contents($root . '/routes/procurement/payments/foreign/finalPurchases.php') ?: '';
$advanceRoute = file_get_contents($root . '/routes/procurement/payments/foreign/advancePurchases.php') ?: '';
$finalService = file_get_contents($root . '/includes/procurementFxFinalPurchaseService.php') ?: '';
$advanceService = file_get_contents($root . '/includes/procurementFxAdvancePurchaseService.php') ?: '';

$checks = [];
$checks['fx_final_details_can_request_events'] = str_contains($finalRoute, "include_events")
    && str_contains($finalRoute, 'procurementFxFinalEvents($conn, $id)');
$checks['fx_advance_details_can_request_events'] = str_contains($advanceRoute, "include_events")
    && str_contains($advanceRoute, 'procurementFxAdvanceEvents($conn, $id)');
$checks['fx_final_events_use_canonical_workflow_history'] = str_contains($finalService, 'function procurementFxFinalEvents')
    && str_contains($finalService, 'workflowEventReadSource')
    && str_contains($finalService, 'PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_TYPE')
    && str_contains($finalService, 'PROCUREMENT_REQUEST_CANONICAL_FX_FINAL_SOURCE');
$checks['fx_advance_events_use_canonical_workflow_history'] = str_contains($advanceService, 'function procurementFxAdvanceEvents')
    && str_contains($advanceService, 'workflowEventReadSource')
    && str_contains($advanceService, 'PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_TYPE')
    && str_contains($advanceService, 'PROCUREMENT_REQUEST_CANONICAL_FX_ADVANCE_SOURCE');
$checks['event_details_are_decoded_for_reversal_reason'] = str_contains($finalService, "json_decode((string) (\$event['details_json'] ?? ''), true)")
    && str_contains($advanceService, "json_decode((string) (\$event['details_json'] ?? ''), true)")
    && str_contains($finalService, "\$event['details'] = is_array(\$decoded) ? \$decoded : null")
    && str_contains($advanceService, "\$event['details'] = is_array(\$decoded) ? \$decoded : null");
$checks['event_history_is_sorted_latest_first'] = str_contains($finalService, 'ORDER BY e.created_at DESC, e.id DESC')
    && str_contains($advanceService, 'ORDER BY e.created_at DESC, e.id DESC');
$checks['no_schema_expansion_for_visibility_batch'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $finalRoute . $advanceRoute . $finalService . $advanceService);

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
