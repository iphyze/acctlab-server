<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migrationPath = $root . '/database/20260809_fx_fund_request_grouped_payment.sql';
$servicePath = $root . '/includes/fxFundRequestPaymentService.php';
$lifecyclePath = $root . '/includes/fxFundRequestPaymentLifecycleService.php';
$routePath = $root . '/routes/request/fx/processGroupedForPayment.php';
$indexPath = $root . '/index.php';
$directCreatePath = $root . '/routes/fx/payment/createPayment.php';
$updateStatusPath = $root . '/routes/fx/payment/updateStatus.php';

$migration = file_get_contents($migrationPath) ?: '';
$service = file_get_contents($servicePath) ?: '';
$lifecycle = file_get_contents($lifecyclePath) ?: '';
$route = file_get_contents($routePath) ?: '';
$index = file_get_contents($indexPath) ?: '';
$directCreate = file_get_contents($directCreatePath) ?: '';
$updateStatus = file_get_contents($updateStatusPath) ?: '';

require_once $servicePath;

$checks = [];
$checks['migration_removes_obsolete_unique_instruction_link'] = str_contains($migration, 'DROP INDEX `uq_fx_fund_request_instruction`')
    && str_contains($migration, '`lambert2_acctlab_db`.`fx_fund_request_table`')
    && !str_contains($migration, 'lambert2_acctlab_archive')
    && !str_contains($migration, 'lambert2_acctlab_read');
$checks['migration_adds_many_to_one_instruction_index'] = str_contains($migration, 'ADD KEY `idx_fx_fund_request_instruction` (`fx_instruction_letter_id`)');
$checks['migration_does_not_alter_instruction_table'] = !preg_match('/ALTER\s+TABLE\s+[^;]*fx_instruction_letter_table/i', $migration);
$checks['grouped_processing_route_registered'] = str_contains($index, "'/request/fx/processGroupedForPayment' => 'routes/request/fx/processGroupedForPayment.php'");
$checks['grouped_processing_requires_admin'] = str_contains($route, '$user = requireAdmin();');
$checks['grouped_processing_uses_active_connection'] = str_contains($route, '$writeConn = databaseActiveConnection($conn);');
$checks['grouped_processing_is_transactional'] = str_contains($route, 'begin_transaction()')
    && str_contains($route, 'commit()')
    && str_contains($route, 'rollback()');
$checks['grouped_requests_are_locked_in_deterministic_order'] = str_contains($service, 'ORDER BY id FOR UPDATE')
    && str_contains($route, 'fxFundRequestLockManyForProcessing');
$checks['grouped_payment_requires_same_supplier'] = str_contains($service, 'Grouped FX payments can only contain Fund Requests for the same supplier.');
$checks['different_po_and_purchase_numbers_are_not_blocked'] = !str_contains($service, 'same PO')
    && !str_contains($service, 'same purchase');
$checks['line_conversion_uses_existing_currency_rules'] = str_contains($service, 'fxFundRequestResolvePaymentConversion($requestById[$requestId]');
$checks['grouped_instruction_amount_is_sum_of_line_allocations'] = str_contains($service, '$total = round($total + (float) $conversion')
    && str_contains($service, "['payment_amount'], 2);")
    && str_contains($route, '\'payment_amount\' => $groupedConversion[\'payment_amount\']');
$checks['one_instruction_is_created_for_group'] = substr_count($route, 'fxFundRequestInsertInstructionForProcessing(') === 1;
$checks['same_instruction_id_is_written_to_each_request'] = str_contains($route, 'foreach ($groupedConversion[\'lines\'] as $line)')
    && str_contains($route, 'SET fx_instruction_letter_id = NULLIF(?, 0)');
$checks['per_request_payment_audit_is_persisted'] = str_contains($route, 'payment_currency = ?, payment_amount = ?, exchange_rate = ?');
$checks['lifecycle_resolves_all_requests_for_instruction'] = str_contains($lifecycle, 'fxFundRequestLifecycleLinkedRequestsForInstruction')
    && str_contains($lifecycle, 'ORDER BY id')
    && !str_contains($lifecycle, 'WHERE fx_instruction_letter_id = ?\n            LIMIT 1');
$checks['lifecycle_syncs_every_linked_request'] = str_contains($lifecycle, 'foreach ($linkedRequests as $linked)');
$checks['existing_fx_bulk_status_route_remains_authoritative'] = str_contains($updateStatus, "'/fx/payment/updateStatus'") === false
    && str_contains($updateStatus, 'fxFundRequestLifecycleSyncMany');
$checks['direct_fx_payment_creation_is_unchanged'] = str_contains($directCreate, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($directCreate, 'processGroupedForPayment')
    && !str_contains($directCreate, 'fx_fund_request_table');

try {
    $payload = fxFundRequestNormalizeGroupedProcessingPayload([
        'requestItems' => [
            ['requestId' => 18],
            ['requestId' => 7, 'paymentAmount' => '1550000.00'],
        ],
        'beneficiaryDetailsId' => 5,
        'paymentBankId' => 2,
        'reference' => 'TY-FX-GROUP-01',
        'payment_purpose' => 'Grouped FX settlement',
        'paymentCurrency' => 'ngn',
        'payment_date' => '2026-08-09',
    ]);
    $checks['grouped_payload_normalizes_and_sorts_request_lines'] = $payload['payment_currency'] === 'NGN'
        && $payload['request_items'][0]['request_id'] === 7
        && $payload['request_items'][0]['payment_amount'] === 1550000.0
        && $payload['request_items'][1]['request_id'] === 18;
} catch (Throwable) {
    $checks['grouped_payload_normalizes_and_sorts_request_lines'] = false;
}

try {
    $result = fxFundRequestResolveGroupedConversions(
        [
            ['id' => 7, 'currency' => 'USD', 'payable_amount' => 1000, 'request_type' => 'Final', 'po_number' => 'PO-1'],
            ['id' => 18, 'currency' => 'NGN', 'payable_amount' => 500000, 'request_type' => 'Advance', 'po_number' => 'PO-2'],
        ],
        [
            'payment_currency' => 'NGN',
            'request_items' => [
                ['request_id' => 7, 'payment_amount' => 1550000],
                ['request_id' => 18, 'payment_amount' => null],
            ],
        ]
    );
    $checks['mixed_request_currencies_keep_line_level_rates'] = count($result['lines']) === 2
        && abs((float) $result['lines'][0]['exchange_rate'] - 1550.0) < 0.0000001
        && (float) $result['lines'][1]['exchange_rate'] === 1.0
        && (float) $result['payment_amount'] === 2050000.0;
} catch (Throwable) {
    $checks['mixed_request_currencies_keep_line_level_rates'] = false;
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
