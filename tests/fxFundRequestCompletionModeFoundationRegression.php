<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paymentServicePath = $root . '/includes/fxFundRequestPaymentService.php';
$processingServicePath = $root . '/includes/accountPaymentProcessingService.php';
$singleRoutePath = $root . '/routes/request/fx/processForPayment.php';
$groupRoutePath = $root . '/routes/request/fx/processGroupedForPayment.php';
$finalSyncPath = $root . '/includes/procurementFxFinalPurchaseService.php';
$advanceSyncPath = $root . '/includes/procurementFxAdvancePurchaseService.php';

$paymentService = file_get_contents($paymentServicePath) ?: '';
$processingService = file_get_contents($processingServicePath) ?: '';
$singleRoute = file_get_contents($singleRoutePath) ?: '';
$groupRoute = file_get_contents($groupRoutePath) ?: '';
$finalSync = file_get_contents($finalSyncPath) ?: '';
$advanceSync = file_get_contents($advanceSyncPath) ?: '';

require_once $paymentServicePath;

$checks = [];
$checks['fx_uses_local_completion_modes'] = str_contains($paymentService, "FX_FUND_REQUEST_COMPLETION_MODES = ['Notify', 'Immediate', 'Automatic']")
    && str_contains($paymentService, 'Processing period must be between 1 and 5 business days.');
$checks['single_payload_carries_completion_metadata'] = str_contains($paymentService, "'completion_mode' => \$completion['completion_mode']")
    && str_contains($paymentService, "'processing_business_days' => \$completion['processing_business_days']")
    && str_contains($paymentService, "'expected_completion_at' => \$completion['expected_completion_at']");
$checks['grouped_payload_carries_same_completion_metadata'] = substr_count($paymentService, "'completion_mode' => \$completion['completion_mode']") >= 2
    && substr_count($paymentService, "'processing_business_days' => \$completion['processing_business_days']") >= 2;
$checks['immediate_instruction_starts_paid'] = str_contains($paymentService, "!empty(\$processing['is_immediate']) ? 'Paid' : 'Pending'");
$checks['single_fx_request_can_complete_immediately'] = (
        str_contains($singleRoute, "\$fundRequestStatus = !empty(\$processing['is_immediate']) ? 'Paid' : 'Processing'")
        || str_contains($singleRoute, "\$fundRequestStatus = !empty(\$effectiveProcessing['is_immediate']) ? 'Paid' : 'Processing'")
    )
    && str_contains($singleRoute, "'account_payment_completed_immediately'")
    && (str_contains($singleRoute, '$processing') || str_contains($singleRoute, '$effectiveProcessing'));
$checks['grouped_fx_requests_share_completion_mode'] = (
        str_contains($groupRoute, "\$fundRequestStatus = !empty(\$processing['is_immediate']) ? 'Paid' : 'Processing'")
        || str_contains($groupRoute, "\$fundRequestStatus = !empty(\$effectiveProcessing['is_immediate']) ? 'Paid' : 'Processing'")
    )
    && str_contains($groupRoute, "'account_payment_completed_immediately'")
    && (str_contains($groupRoute, '$processing') || str_contains($groupRoute, '$effectiveProcessing'));
$checks['canonical_batches_store_fx_completion_mode'] = str_contains($processingService, "'completion_mode' => \$completionMode")
    && str_contains($processingService, "'processing_business_days' => \$businessDays")
    && str_contains($processingService, "'expected_completion_at' => \$expectedCompletionAt")
    && str_contains($processingService, "'status' => \$isImmediate ? 'Completed' : 'Processing'");
$checks['canonical_items_store_immediate_paid_state'] = str_contains($processingService, "'amount_paid' => \$isImmediate ? (float) \$item['amount'] : 0.0")
    && str_contains($processingService, "'status' => \$isImmediate ? 'Paid' : 'Processing'")
    && str_contains($processingService, "'paid_at' => \$isImmediate ? \$now : null");
$checks['fx_final_sync_exposes_batch_completion_context'] = str_contains($finalSync, 'procurementFxFinalPaymentProcessingContext')
    && str_contains($finalSync, 'account_expected_completion_at = ?')
    && str_contains($finalSync, 'account_completion_mode = ?')
    && str_contains($finalSync, 'account_confirmation_status = ?')
    && str_contains($finalSync, 'account_payment_batch_id = ?');
$checks['fx_advance_sync_exposes_batch_completion_context'] = str_contains($advanceSync, 'procurementFxAdvancePaymentProcessingContext')
    && str_contains($advanceSync, 'account_expected_completion_at = ?')
    && str_contains($advanceSync, 'account_completion_mode = ?')
    && str_contains($advanceSync, 'account_confirmation_status = ?')
    && str_contains($advanceSync, 'account_payment_batch_id = ?');
$checks['no_parallel_fx_processing_tables'] = !str_contains($processingService, 'fx_payment_batches')
    && !str_contains($processingService, 'fx_advance_payment_batches')
    && str_contains($processingService, 'account_payment_batches')
    && str_contains($processingService, 'account_payment_batch_items');
$checks['no_schema_expansion_in_foundation'] = !preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $paymentService . $processingService . $singleRoute . $groupRoute);

try {
    $legacy = fxFundRequestNormalizeProcessingPayload([
        'requestId' => 1,
        'beneficiaryDetailsId' => 2,
        'paymentBankId' => 3,
        'reference' => 'FX-LEGACY',
        'payment_currency' => 'USD',
        'payment_date' => '2026-08-11',
    ]);
    $checks['legacy_fx_client_defaults_safely_to_notify'] = $legacy['completion_mode'] === 'Notify'
        && $legacy['processing_business_days'] === 1
        && $legacy['is_immediate'] === false
        && trim((string) $legacy['expected_completion_at']) !== '';
} catch (Throwable) {
    $checks['legacy_fx_client_defaults_safely_to_notify'] = false;
}

try {
    $immediate = fxFundRequestNormalizeProcessingPayload([
        'requestId' => 1,
        'beneficiaryDetailsId' => 2,
        'paymentBankId' => 3,
        'reference' => 'FX-NOW',
        'payment_currency' => 'USD',
        'payment_date' => '2026-08-11',
        'completion_mode' => 'Immediate',
    ]);
    $checks['immediate_requires_no_processing_period'] = $immediate['completion_mode'] === 'Immediate'
        && $immediate['processing_business_days'] === 0
        && $immediate['is_immediate'] === true;
} catch (Throwable) {
    $checks['immediate_requires_no_processing_period'] = false;
}

try {
    $automatic = fxFundRequestNormalizeGroupedProcessingPayload([
        'requestItems' => [['requestId' => 5]],
        'beneficiaryDetailsId' => 2,
        'paymentBankId' => 3,
        'reference' => 'FX-AUTO',
        'payment_currency' => 'EUR',
        'payment_date' => '2026-08-11',
        'completion_mode' => 'Automatic',
        'processing_business_days' => 3,
    ]);
    $checks['automatic_grouped_period_is_validated'] = $automatic['completion_mode'] === 'Automatic'
        && $automatic['processing_business_days'] === 3
        && $automatic['is_immediate'] === false;
} catch (Throwable) {
    $checks['automatic_grouped_period_is_validated'] = false;
}

try {
    fxFundRequestNormalizeProcessingPayload([
        'requestId' => 1,
        'beneficiaryDetailsId' => 2,
        'paymentBankId' => 3,
        'reference' => 'FX-BAD',
        'payment_currency' => 'USD',
        'payment_date' => '2026-08-11',
        'completion_mode' => 'Notify',
        'processing_business_days' => 0,
    ]);
    $checks['explicit_invalid_period_is_rejected'] = false;
} catch (Throwable $error) {
    $checks['explicit_invalid_period_is_rejected'] = $error->getCode() === 400;
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
