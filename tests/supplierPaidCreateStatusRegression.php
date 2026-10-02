<?php

$root = dirname(__DIR__);
$route = file_get_contents($root . '/routes/request/supplier/create.php');

$checks = [
    'create_starts_from_pending_state' => strpos($route, <<<'TXT'
$insertPaymentStatus = 'Pending';
TXT
    ) !== false,
    'insert_binds_pending_state' => strpos($route, '$insertPaymentStatus,') !== false,
    'requested_status_is_applied_after_insert' => strpos($route, <<<'TXT'
if ($payment_status !== 'Pending')
TXT
    ) !== false
        && strpos($route, 'accountSupplierApplyDirectStatus(') !== false
        && strpos($route, '$payment_status,') !== false,
    'paid_transition_is_not_bypassed' => strpos($route, 'accountSupplierApplyDirectStatus(') !== false,
    'requested_status_is_still_reported' => strpos($route, '"payment_status" => $payment_status') !== false,
];

$failed = array_keys(array_filter($checks, static fn($passed) => !$passed));
echo json_encode([
    'healthy' => count($failed) === 0,
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT) . PHP_EOL;

exit(count($failed) === 0 ? 0 : 1);
