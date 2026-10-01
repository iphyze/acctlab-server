<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$helper = file_get_contents($root . '/routes/bank-recon/reconMatchingHelpers.php');
$get = file_get_contents($root . '/routes/bank-recon/getReconciliation.php');

$checks = [
    'active_connection_helper_exists' => str_contains($helper, 'function brReconActiveConnection(mysqli $conn): mysqli'),
    'schema_ensure_forces_active_connection' => str_contains($helper, 'function brReconEnsureSmartSchema(mysqli $conn): void')
        && str_contains($helper, 'function brReconEnsureMatchingSchema(mysqli $conn): void')
        && substr_count($helper, '$conn = brReconActiveConnection($conn);') >= 7,
    'difference_persistence_can_be_disabled' => str_contains($helper, 'bool $persist = true')
        && str_contains($helper, 'if ($persist) {')
        && str_contains($helper, '$writeConn = brReconActiveConnection($conn);'),
    'get_reads_from_combined_connection' => str_contains($get, '$r = $conn->query("SELECT * FROM bank_recons WHERE id=$id LIMIT 1")')
        && str_contains($get, '$conn->query("SELECT * FROM bank_recon_bank_lines WHERE recon_id=$id'),
    'get_uses_active_connection_for_maintenance' => str_contains($get, '$writeConn = databaseActiveConnection($conn);')
        && str_contains($get, 'brReconEnsureSmartSchema($writeConn);'),
    'archive_only_record_is_detected' => str_contains($get, '$isArchivedOnly = !$activeRecon;'),
    'summary_update_is_active_only' => str_contains($get, 'if (!$isArchivedOnly) {')
        && str_contains($get, '$summaryStmt = $writeConn->prepare(')
        && !str_contains($get, '$summaryStmt = $conn->prepare('),
    'archived_difference_is_not_persisted' => str_contains($get, 'brReconBuildDifferenceExplanation($writeConn, $id, $r, $bank, $ledger, $summary, !$isArchivedOnly)'),
    'response_exposes_record_source' => str_contains($get, "\$r['record_source'] = \$isArchivedOnly ? 'archive' : 'active';")
        && str_contains($get, "\$r['read_only'] = \$isArchivedOnly;"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
