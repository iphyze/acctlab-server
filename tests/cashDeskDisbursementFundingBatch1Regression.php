<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$migration = $read('database/new/20260920_cash_disbursement_mutilated_funding.sql');
$helpers = $read('routes/cash/cashHelpers.php');
$disburse = $read('routes/cash/disburseCash.php');
$recordMutilated = $read('routes/cash/recordMutilatedCash.php');
$resolveMutilated = $read('routes/cash/resolveMutilatedCash.php');
$reverse = $read('routes/cash/reverseTransaction.php');
$update = $read('routes/cash/updateTransaction.php');
$reports = $read('routes/cash/cashReportHelpers.php');

$checks = [
    'migration_tracks_remaining_reserve_and_funding_split' =>
        str_contains($migration, 'remaining_amount DECIMAL(18,2)')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS cash_disbursement_funding')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS cash_mutilated_cash_usages'),
    'mutilated_usage_has_reversal_audit_history' =>
        str_contains($migration, "status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'")
        && str_contains($migration, 'reversal_transaction_id')
        && str_contains($migration, 'reversal_date'),
    'direct_and_iou_disbursement_accept_optional_mutilated_amount' =>
        str_contains($disburse, "cashParseNonNegativeAmount(\$data['mutilated_cash_amount'] ?? 0")
        && str_contains($disburse, "in_array(\$disbursementType, ['DIRECT', 'IOU'], true)")
        && str_contains($disburse, '$regularCashAmount = round($amount - $mutilatedCashAmount, 2)'),
    'regular_and_mutilated_balances_are_validated_separately' =>
        str_contains($disburse, 'cashGetPendingMutilatedAmount($conn, $accountId)')
        && str_contains($disburse, '$mutilatedCashAmount > $pendingMutilatedBefore')
        && str_contains($disburse, '$regularCashAmount > $balanceBefore'),
    'posting_persists_split_and_consumes_mutilated_fifo' =>
        str_contains($disburse, 'cashSaveDisbursementFunding(')
        && str_contains($disburse, 'cashConsumeMutilatedCashForDisbursement(')
        && str_contains($helpers, 'ORDER BY discovered_date ASC, id ASC')
        && str_contains($helpers, 'remaining_amount = ?'),
    'new_mutilated_records_start_with_full_remaining_amount' =>
        str_contains($recordMutilated, 'remaining_amount')
        && str_contains($recordMutilated, '$amount,')
        && str_contains($resolveMutilated, "\$record['remaining_amount'] ?? \$record['amount']"),
    'transaction_reads_include_funding_split' =>
        str_contains($helpers, 'cashFetchDisbursementFunding($conn, $transactionId)')
        && str_contains($helpers, "\$row['regular_cash_amount']")
        && str_contains($helpers, "\$row['mutilated_cash_amount']"),
    'reversal_restores_mutilated_reserve_without_erasing_audit' =>
        str_contains($reverse, 'cashRestoreMutilatedCashForReversedDisbursement($conn, $accountId, $transactionId, $reversalId, $reversalDate)')
        && str_contains($helpers, "SET status = ?, reversal_transaction_id = ?, reversal_date = ?, reversed_at = NOW()")
        && !str_contains($helpers, 'DELETE FROM cash_mutilated_cash_usages'),
    'editing_preserves_existing_mutilated_component' =>
        str_contains($update, 'cashFetchDisbursementFunding($conn, $transactionId)')
        && str_contains($update, '$mutilatedFundingAmount')
        && str_contains($update, 'round($amount - $mutilatedFundingAmount, 2)'),
    'historical_balance_and_existing_reports_understand_usage' =>
        str_contains($helpers, "cmu.reversal_date > ?")
        && str_contains($reports, 'mutilated_cash_used')
        && str_contains($reports, 'mutilated_cash_restored')
        && str_contains($reports, 'cashGetPendingMutilatedAmount($conn, $accountId, $endDate)'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
