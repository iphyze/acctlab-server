<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$compass = $read('includes/accountCompassFundRequestService.php');
$reminder = $read('includes/accountPaymentReminderService.php');
$storagePlan = $read('includes/paymentReminderUnifiedStoragePlanService.php');
$cycle = $read('cron/processPaymentReminderCycle.php');
$getFiltered = $read('routes/request/compass/getFilteredRequest.php');

$checks = [
    'compass_supports_all_four_completion_modes' => str_contains($compass, "['Manual', 'Notify', 'Automatic', 'Immediate']")
        && str_contains($compass, "completion_mode IN ('Notify','Automatic')"),
    'scheduler_processes_compass_due_batches' => str_contains($cycle, 'accountCompassProcessDueBatches($conn)')
        && str_contains($cycle, "'compass_batches' => \$compassBatches"),
    'automatic_compass_completion_is_due_date_driven' => str_contains($compass, 'function accountCompassProcessDueBatches(')
        && str_contains($compass, "expected_completion_at <= ?")
        && str_contains($compass, "if (\$completionMode === 'Automatic')")
        && str_contains($compass, "payment_confirmation_status = 'Auto Completed'")
        && str_contains($compass, "status = 'Paid', amount_paid = ?"),
    'automatic_compass_syncs_final_and_advance_procuredesk' => str_contains($compass, 'procurementSyncLocalFinalCompassPaymentDetails(')
        && str_contains($compass, 'procurementSyncLocalAdvanceCompassPaymentDetails(')
        && str_contains($compass, 'account_compass_payment_auto_completed')
        && str_contains($compass, 'account_compass_advance_payment_auto_completed'),
    'notify_compass_moves_items_to_confirmation_queue' => str_contains($compass, "SET status = 'Awaiting Confirmation'")
        && str_contains($compass, 'Expected Compass completion time reached; Account confirmation is required.')
        && str_contains($compass, "payment_confirmation_status = 'Due'"),
    'notify_compass_reuses_reminder_lifecycle' => str_contains($reminder, "'compass', 'compass fund request', 'compass_fund_request' => 'Compass'")
        && str_contains($reminder, "'Compass' => 'CMP'")
        && str_contains($reminder, "'request_table' => 'compass_fund_request_table'")
        && str_contains($compass, "accountPaymentReminderSchedule(")
        && str_contains($compass, "accountPaymentReminderInitialDelivery("),
    'compass_reminders_are_backfilled_and_health_checked' => str_contains($reminder, "'Compass', cfr.id")
        && str_contains($reminder, "'compass_backfilled' => \$counts['Compass']")
        && str_contains($storagePlan, "request_type NOT IN ('Advance', 'Supplier', 'Compass', 'FX Final', 'FX Advance')")
        && str_contains($storagePlan, "'reminder_type' => 'Compass'")
        && str_contains($storagePlan, "'canonical_type' => ACCOUNT_PAYMENT_TYPE_COMPASS"),
    'compass_register_receives_reminder_summaries' => str_contains($getFiltered, "accountPaymentReminderAttachSummaries(\$conn, 'Compass', \$data)"),
    'manual_and_immediate_modes_remain_available' => str_contains($compass, "['Manual', 'Notify', 'Automatic', 'Immediate']")
        && str_contains($compass, "accountCompassPaymentCompletionMode(\$data['completion_mode'] ?? 'Manual')")
        && str_contains($compass, "if (\$completionMode === 'Immediate')"),
    'no_schema_expansion_for_completion_parity' => !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $compass . $storagePlan . $cycle . $getFiltered)
        && !preg_match('/CREATE\s+TABLE[^;]*compass|ALTER\s+TABLE[^;]*compass|DROP\s+TABLE[^;]*compass/i', $reminder),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
