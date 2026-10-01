<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/fxFundRequestService.php';

$root = dirname(__DIR__);
$checks = [];

$record = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
};

$expectException = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
};

$finalPayload = [
    'request_type' => 'Final',
    'suppliers_name' => 'Example Supplier',
    'suppliers_id' => 12,
    'invoice_number' => 'INV-100',
    'purchase_number' => 'PUR-100',
    'po_number' => 'PO-100',
    'invoice_date' => '2026-08-01',
    'purchase_date' => '2026-08-02',
    'date_received' => '2026-08-03',
    'project_code' => 'PRJ-01',
    'currency' => 'USD',
    'sub_total' => 1000,
    'discount' => 100,
    'other_charges' => 50,
    'vat_rate' => '7.50%',
    'wht_rate' => '5.00%',
];

$advancePayload = [
    'request_type' => 'Advance',
    'suppliers_name' => 'Example Supplier',
    'suppliers_id' => 12,
    'po_number' => 'PO-200',
    'date_received' => '2026-08-03',
    'project_code' => 'PRJ-01',
    'currency' => 'EUR',
    'sub_total' => 1000,
    'discount' => 100,
    'other_charges' => 50,
    'vat_rate' => 7.5,
    'wht_rate' => 2.0,
    'percentage' => 40,
];

$final = fxFundRequestNormalizePayload($finalPayload);
$advance = fxFundRequestNormalizePayload($advancePayload);

$record('final_type_normalized', $final['request_type'] === 'Final');
$record('request_currency_normalized', $final['currency'] === 'USD' && $advance['currency'] === 'EUR');
$record('final_tax_calculation',
    abs($final['vat_amount'] - 67.50) < 0.001
    && abs($final['wht_amount'] - 45.00) < 0.001
    && abs($final['payable_amount'] - 972.50) < 0.001
);
$record('final_percentage_is_null', $final['percentage'] === null);
$record('advance_final_only_fields_cleared',
    $advance['invoice_number'] === null
    && $advance['purchase_number'] === null
    && $advance['invoice_date'] === null
    && $advance['purchase_date'] === null
);
$record('advance_tax_and_percentage_calculation',
    abs($advance['vat_amount'] - 67.50) < 0.001
    && abs($advance['wht_amount'] - 18.00) < 0.001
    && abs($advance['calculation']['base_payable_amount'] - 999.50) < 0.001
    && abs($advance['payable_amount'] - 399.80) < 0.001
);
$record('discount_above_subtotal_rejected', $expectException(static function () use ($finalPayload): void {
    $payload = $finalPayload;
    $payload['discount'] = 1001;
    fxFundRequestNormalizePayload($payload);
}));
$record('invalid_vat_rate_rejected', $expectException(static function () use ($finalPayload): void {
    $payload = $finalPayload;
    $payload['vat_rate'] = '10.00%';
    fxFundRequestNormalizePayload($payload);
}));
$record('invalid_wht_rate_rejected', $expectException(static function () use ($finalPayload): void {
    $payload = $finalPayload;
    $payload['wht_rate'] = '3.00%';
    fxFundRequestNormalizePayload($payload);
}));
$record('advance_above_100_rejected', $expectException(static function () use ($advancePayload): void {
    $payload = $advancePayload;
    $payload['percentage'] = 100.01;
    fxFundRequestNormalizePayload($payload);
}));

$record('missing_request_currency_rejected', $expectException(static function () use ($finalPayload): void {
    $payload = $finalPayload;
    unset($payload['currency']);
    fxFundRequestNormalizePayload($payload);
}));
$record('unsupported_request_currency_rejected', $expectException(static function () use ($finalPayload): void {
    $payload = $finalPayload;
    $payload['currency'] = 'CAD';
    fxFundRequestNormalizePayload($payload);
}));

$record('final_missing_purchase_rejected', $expectException(static function () use ($finalPayload): void {
    $payload = $finalPayload;
    unset($payload['purchase_number']);
    fxFundRequestNormalizePayload($payload);
}));

$record('final_po_is_optional', (static function () use ($finalPayload): bool {
    $payload = $finalPayload;
    unset($payload['po_number']);
    $normalized = fxFundRequestNormalizePayload($payload);
    return $normalized['request_type'] === 'Final' && $normalized['po_number'] === null;
})());
$record('advance_po_remains_required', $expectException(static function () use ($advancePayload): void {
    $payload = $advancePayload;
    unset($payload['po_number']);
    fxFundRequestNormalizePayload($payload);
}));

$index = file_get_contents($root . '/index.php') ?: '';
$create = file_get_contents($root . '/routes/request/fx/create.php') ?: '';
$edit = file_get_contents($root . '/routes/request/fx/edit.php') ?: '';
$delete = file_get_contents($root . '/routes/request/fx/delete.php') ?: '';
$list = file_get_contents($root . '/routes/request/fx/getFilteredRequest.php') ?: '';
$getSingle = file_get_contents($root . '/routes/request/fx/getSingle.php') ?: '';
$directFxCreate = file_get_contents($root . '/routes/fx/payment/createPayment.php') ?: '';

$record('crud_routes_registered',
    str_contains($index, "'/request/fx/create'")
    && str_contains($index, "'/request/fx/edit'")
    && str_contains($index, "'/request/fx/delete'")
    && str_contains($index, "'/request/fx/getFilteredRequest'")
    && str_contains($index, "'/request/fx/getSingle/(.+)'")
);
$record('writes_explicitly_use_active_connection',
    str_contains($create, 'databaseActiveConnection($conn)')
    && str_contains($edit, 'databaseActiveConnection($conn)')
    && str_contains($delete, 'databaseActiveConnection($conn)')
);
$record('reads_use_unified_runtime_connection',
    str_contains($list, 'FROM fx_fund_request_table')
    && !str_contains($list, 'databaseActiveConnection($conn)')
    && str_contains($getSingle, 'FROM fx_fund_request_table')
    && !str_contains($getSingle, 'databaseActiveConnection($conn)')
);
$record('final_duplicate_guard_present',
    str_contains($create, 'fxFundRequestAssertFinalPurchaseAvailable')
    && str_contains($edit, 'fxFundRequestAssertFinalPurchaseAvailable')
);
$record('advance_transaction_lock_present',
    str_contains($create, 'fxFundRequestAcquireAdvancePoLock')
    && str_contains($create, 'fxFundRequestAssertAdvancePercentageAvailable')
    && str_contains($edit, 'fxFundRequestAcquireAdvancePoLock')
    && str_contains($edit, 'fxFundRequestAssertAdvancePercentageAvailable')
);
$record('processed_record_safety_is_origin_aware',
    str_contains($edit, 'Processed ProcureDesk-linked FX Final Requests cannot be edited in AcctLab.')
    && str_contains($edit, 'Set this direct AcctLab FX Fund Request back to Pending before changing payment values')
    && str_contains($delete, 'fxFundRequestLifecycleReopenManualRequests')
    && str_contains($delete, 'procurementFxFinalAssertFundRequestsCanBeDeleted')
);
$record('direct_fx_payment_creation_preserved',
    str_contains($directFxCreate, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($directFxCreate, 'fx_fund_request_table')
);
$record('currency_is_persisted_on_create_edit',
    str_contains($create, 'project_code, currency')
    && str_contains($edit, 'project_code = ?, currency = ?')
);
$createInsertPlaceholderCount = null;
if (preg_match("/INSERT INTO fx_fund_request_table \(.+?\) VALUES \((.+?)\)/s", $create, $matches) === 1) {
    $createInsertPlaceholderCount = substr_count($matches[1], '?');
}
$record('create_insert_placeholder_count_matches_bindings', $createInsertPlaceholderCount === 22);

$record('batch2_does_not_insert_instruction_letters',
    !str_contains($create, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($edit, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($delete, 'INSERT INTO fx_instruction_letter_table')
);

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
