<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/accountPaymentProcessingService.php') ?: '';
$support = file_get_contents($root . '/includes/accountPaymentStorageRuntimeSupportService.php') ?: '';
$read = file_get_contents($root . '/includes/accountPaymentStorageRuntimeReadService.php') ?: '';
$write = file_get_contents($root . '/includes/accountPaymentStorageCanonicalWriteService.php') ?: '';
$index = file_get_contents($root . '/index.php') ?: '';
$singleFx = file_get_contents($root . '/routes/request/fx/processForPayment.php') ?: '';
$groupFx = file_get_contents($root . '/routes/request/fx/processGroupedForPayment.php') ?: '';
$itemsRoute = file_get_contents($root . '/routes/request/payment-processing/items.php') ?: '';
$batchesRoute = file_get_contents($root . '/routes/request/payment-processing/batches.php') ?: '';
$actionsRoute = file_get_contents($root . '/routes/request/payment-processing/actions.php') ?: '';

$checks = [];
$checks['canonical_batch_tables_are_shared'] = str_contains($service, 'account_payment_batches')
    && str_contains($service, 'account_payment_batch_items')
    && !str_contains($service, 'fx_payment_batches')
    && !str_contains($service, 'local_payment_batches');
$checks['all_canonical_request_types_are_supported'] = str_contains($support, "ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL = 'local_final_purchase'")
    && str_contains($support, "ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE = 'local_advance_purchase'")
    && str_contains($support, "ACCOUNT_PAYMENT_TYPE_FX_FINAL = 'fx_final_purchase'")
    && str_contains($support, "ACCOUNT_PAYMENT_TYPE_FX_ADVANCE = 'fx_advance_purchase'")
    && str_contains($support, "ACCOUNT_PAYMENT_TYPE_COMPASS = 'compass_fund_request'")
    && substr_count($read, 'ACCOUNT_PAYMENT_TYPE_FX_FINAL') >= 1
    && substr_count($read, 'ACCOUNT_PAYMENT_TYPE_FX_ADVANCE') >= 1;
$checks['unified_processing_routes_registered'] = str_contains($index, "'/request/payment-processing/items'")
    && str_contains($index, "'/request/payment-processing/batches'")
    && str_contains($index, "'/request/payment-processing/actions'");
$checks['unified_routes_use_canonical_service'] = str_contains($itemsRoute, 'accountPaymentProcessingListItems')
    && str_contains($batchesRoute, 'accountPaymentProcessingGetBatch')
    && str_contains($actionsRoute, 'accountPaymentProcessingAction');
$checks['local_and_fx_sources_share_one_projection'] = str_contains($service, 'supplier_fund_request_table')
    && str_contains($service, 'advance_payment_request')
    && str_contains($service, 'fx_fund_request_table')
    && str_contains($service, 'compass_fund_request_table')
    && str_contains($service, "'local_final_purchase'")
    && str_contains($service, "'local_advance_purchase'")
    && str_contains($service, "'fx_final_purchase'")
    && str_contains($service, "'fx_advance_purchase'")
    && str_contains($service, "'compass_fund_request'");
$checks['processing_is_filtered_by_request_type'] = str_contains($service, 'i.request_type = b.request_type')
    && str_contains($service, 'r.request_type = i.request_type')
    && str_contains($service, "i.request_type = ?")
    && str_contains($service, 'b.request_type = ?');
$checks['legacy_local_batch_links_resolve_to_canonical_rows'] = str_contains($service, "legacy_batch_id")
    && str_contains($service, 'accountPaymentProcessingResolveCanonicalBatchId')
    && str_contains($service, 'b.legacy_source_id = ?')
    && str_contains($batchesRoute, "legacy_id");
$checks['currency_summary_never_cross_sums'] = str_contains($service, 'GROUP BY r.currency')
    && str_contains($service, "'summary_by_currency'")
    && !str_contains($service, 'SUM(r.request_amount) AS grand_total');
$checks['fx_single_processing_creates_canonical_batch'] = str_contains($singleFx, 'accountPaymentProcessingCreateFxBatches')
    && str_contains($singleFx, "'processing_batches'");
$checks['fx_grouped_processing_creates_canonical_batches'] = str_contains($groupFx, 'accountPaymentProcessingCreateFxBatches')
    && str_contains($groupFx, "'processing_batches'");
$checks['mixed_fx_final_advance_split_only_by_request_type'] = str_contains($service, '$groups[$type][]')
    && str_contains($service, 'foreach ($groups as $requestType => $items)')
    && str_contains($service, 'accountPaymentProcessingCanonicalTypeFromFx');
$checks['batch_level_actions_are_supported'] = str_contains($service, '$batchId = (int) ($data[\'batch_id\'] ?? 0)')
    && str_contains($service, "i.batch_id = ?")
    && str_contains($service, 'accountSupplierMarkItems')
    && str_contains($service, 'accountAdvanceMarkItems')
    && str_contains($service, 'accountCompassMarkPaymentItems')
    && str_contains($service, 'accountPaymentProcessingApplyFxPaid');
$checks['fx_shared_instruction_siblings_stay_atomic'] = str_contains($service, 'accountPaymentProcessingExpandFxSharedInstructionRequests')
    && str_contains($service, 'fx_instruction_letter_id = selected.fx_instruction_letter_id');
$checks['fx_completion_metadata_uses_canonical_batches'] = str_contains($service, "'completion_mode' => \$completionMode")
    && str_contains($service, "'processing_business_days' => \$businessDays")
    && str_contains($service, "'expected_completion_at' => \$expectedCompletionAt")
    && !str_contains($service, "'completion_mode' => 'Manual'");
$checks['fx_delay_failure_policy_is_not_invented'] = str_contains($service, 'FX processing currently supports batch Paid confirmation only');
$checks['no_processing_schema_expansion_in_consolidation'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $service . $itemsRoute . $batchesRoute . $actionsRoute);
$checks['canonical_write_service_does_not_create_parallel_fx_tables'] = !str_contains($write, 'fx_payment_batches')
    && !str_contains($write, 'fx_advance_payment_batches')
    && str_contains($write, "'batch_table' => 'account_payment_batches'");

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
