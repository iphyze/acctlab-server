<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$verifier = $read('cron/verifyProcuredeskRegisterPerformance.php');
$runner = $read('tests/runProcuredeskRegisterPerformanceRegression.php');

$checks = [
    'verifier_covers_all_four_registers' =>
        str_contains($verifier, "'local_final' =>")
        && str_contains($verifier, "'local_advance' =>")
        && str_contains($verifier, "'fx_final' =>")
        && str_contains($verifier, "'fx_advance' =>"),
    'verifier_measures_fetch_all_and_year_filtered_reads' =>
        str_contains($verifier, "'all_page_20'")
        && str_contains($verifier, "'year_page_20'")
        && str_contains($verifier, '{$dateColumn} >= ? AND {$dateColumn} < ?'),
    'verifier_is_read_only' =>
        !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\s+/i', $verifier)
        && str_contains($verifier, "'read_only' => true"),
    'latency_threshold_is_warning_not_failure' =>
        str_contains($verifier, 'PROCUREDESK_REGISTER_WARN_MS')
        && str_contains($verifier, '$warnings[] = sprintf(')
        && !str_contains($verifier, "'latency_within_threshold'"),
    'required_performance_indexes_are_verified' =>
        str_contains($verifier, 'idx_proc_req_register_created')
        && str_contains($verifier, 'idx_proc_req_register_purchase_date')
        && str_contains($verifier, 'idx_proc_req_register_transaction_date')
        && str_contains($verifier, 'idx_proc_req_advance_allocation'),
    'runner_includes_batches_1_to_3_and_safety_regressions' =>
        str_contains($runner, 'procuredeskRegisterHotPathBootstrapPerformanceRegression.php')
        && str_contains($runner, 'procuredeskLeanRegisterApiPerformanceRegression.php')
        && str_contains($runner, 'procuredeskRegisterQueryIndexPerformanceRegression.php')
        && str_contains($runner, 'procuredeskUnifiedReadEnsureSafetyRegression.php')
        && str_contains($runner, 'procurementFxFinalPurchaseFoundationRegression.php')
        && str_contains($runner, 'procurementFxAdvancePurchaseFoundationRegression.php'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
