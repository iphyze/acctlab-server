<?php

declare(strict_types=1);

require_once __DIR__ . '/../routes/bank-recon/reconMatchingHelpers.php';

$checks = [];
$checks['exact_same_amount_matches'] = brReconAmountsMatchExactly(500.00, 500.00);
$checks['one_cent_difference_does_not_match'] = !brReconAmountsMatchExactly(500.01, 500.00);
$checks['one_cent_difference_is_not_normalized_to_zero'] = brReconNormalizeDifference(0.01) === 0.01;

$bankLines = [[
    'id' => 1,
    'amount' => 500.01,
    'matched_amount' => 0,
    'direction' => 'OUT',
    'txn_date' => '2026-09-01',
]];
$ledgerLines = [[
    'id' => 2,
    'amount' => 500.00,
    'matched_amount' => 0,
    'direction' => 'OUT',
    'txn_date' => '2026-09-01',
]];

$fullMatchRejected = false;
try {
    brReconBuildAllocations($bankLines, $ledgerLines, false, 999.00);
} catch (Throwable $e) {
    $fullMatchRejected = $e->getCode() === 422;
}
$checks['full_match_rejects_one_cent_even_with_saved_tolerance'] = $fullMatchRejected;

$partialPlan = brReconBuildAllocations($bankLines, $ledgerLines, true, 999.00);
$checks['partial_match_leaves_one_cent_outstanding'] =
    ($partialPlan['is_partial'] ?? false) === true
    && ($partialPlan['difference'] ?? 0) === 0.01
    && ($partialPlan['matched_total'] ?? 0) === 500.00;

$sourceFiles = [
    __DIR__ . '/../routes/bank-recon/createReconciliation.php',
    __DIR__ . '/../routes/bank-recon/updateReconciliation.php',
    __DIR__ . '/../routes/bank-recon/appendLines.php',
    __DIR__ . '/../routes/bank-recon/matchLines.php',
    __DIR__ . '/../routes/bank-recon/getReconciliation.php',
];
$source = implode("\n", array_map(static fn($file) => file_get_contents($file) ?: '', $sourceFiles));
$checks['auto_and_manual_paths_use_exact_amount_helper'] = substr_count($source, 'brReconAmountsMatchExactly') >= 7;
$checks['legacy_one_cent_tolerance_gate_removed'] = !str_contains($source, 'max($tolAmt, 0.01)')
    && !str_contains($source, 'max((float)($r[\'tolerance_amount\'] ?? 0), 0.01)');

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
echo json_encode([
    'healthy' => count($failed) === 0,
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit(count($failed) === 0 ? 0 : 1);
