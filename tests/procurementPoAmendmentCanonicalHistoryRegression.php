<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/includes/workflowEventCanonicalReadService.php');

$checks = [
    'procurement_request_history_remains_canonical' => str_contains(
        $service,
        'if ($sourceTable === WORKFLOW_EVENT_SOURCE_PROCUREMENT_REQUEST)'
    ),
    'retired_event_source_falls_back_to_canonical_projection' => str_contains(
        $service,
        'workflowEventObjectType($conn, $sourceTable) === null'
    ) && substr_count($service, 'return workflowEventCanonicalProjection($sourceTable);') >= 2,
    'legacy_compatibility_is_preserved_when_source_exists' => str_contains(
        $service,
        'workflowEventCanonicalReadsEnabled($conn)'
    ) && str_contains($service, ': $sourceTable;'),
    'po_event_projection_is_supported' => str_contains(
        $service,
        'WORKFLOW_EVENT_SOURCE_ADVANCE_PO =>'
    ),
    'reconciliation_event_projection_is_supported' => str_contains(
        $service,
        'WORKFLOW_EVENT_SOURCE_ADVANCE_RECONCILIATION =>'
    ),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
