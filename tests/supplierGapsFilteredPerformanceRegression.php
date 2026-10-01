<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = file_get_contents($root . '/routes/gaps/supplier/getFilteredGaps.php') ?: '';

$checks = [];
$checks['read_route_avoids_supplier_storage_ensure'] = !str_contains($route, 'accountSupplierEnsurePaymentStorage')
    && !str_contains($route, "includes/accountSupplierPaymentService.php");
$checks['read_route_avoids_full_canonical_runtime_verification'] = !str_contains($route, 'accountPaymentStorageReadSources')
    && !str_contains($route, 'accountPaymentStorageCanonicalRuntimeVerification')
    && str_contains($route, 'accountPaymentStorageRuntimeSupportService.php');
$checks['count_query_stays_on_schedule_table'] = str_contains($route, '$baseQuery = "FROM payment_schedule_tab ps WHERE 1=1"')
    && str_contains($route, 'SELECT COUNT(*) AS total $baseQuery')
    && !str_contains($route, 'COUNT(DISTINCT ps.id)');
$checks['correlated_artifact_subquery_removed'] = !str_contains($route, 'SELECT MAX(a2.id)')
    && !str_contains($route, 'LEFT JOIN {$artifactSource}');
$checks['payment_links_are_enriched_after_pagination'] = str_contains($route, 'SELECT ps.* $baseQuery ORDER BY ps.$sortBy $sortOrder LIMIT ? OFFSET ?')
    && str_contains($route, 'supplierGapsFetchPaymentLinks($conn, $visibleScheduleIds)');
$checks['canonical_payment_tables_are_reused'] = str_contains($route, 'account_payment_artifacts artifact')
    && str_contains($route, 'account_payment_batches payment_batch')
    && str_contains($route, 'artifact.request_type = ?')
    && str_contains($route, "artifact.artifact_type = 'GAPS Schedule'");
$checks['current_page_enrichment_uses_bounded_ids'] = str_contains($route, 'artifact.artifact_id IN ($placeholders)')
    && str_contains($route, '$visibleScheduleIds');
$checks['linked_payment_fields_are_preserved'] = str_contains($route, "payment['payment_operation_id']")
    && str_contains($route, "payment['payment_operation_reference']")
    && str_contains($route, "payment['payment_processing_method']")
    && str_contains($route, "payment['payment_processing_reference']")
    && str_contains($route, "payment['payment_operation_status']")
    && str_contains($route, "payment['payment_completion_mode']");
$checks['exception_removal_guard_is_preserved'] = str_contains($route, "payment_operation_status'] ?? '') === 'Completed With Exceptions'")
    && str_contains($route, "payment['payment_artifact_removable']");
$checks['schedule_ids_focus_filter_is_preserved'] = str_contains($route, "schedule_ids")
    && str_contains($route, 'A maximum of 100 GAPS schedule records can be filtered at once.');
$checks['no_schema_change_in_read_optimization'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $route);

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
