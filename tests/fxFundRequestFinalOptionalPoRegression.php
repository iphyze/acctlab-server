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

$base = [
    'request_type' => 'Final',
    'suppliers_name' => 'Example Supplier',
    'suppliers_id' => 12,
    'invoice_number' => 'INV-100',
    'purchase_number' => 'PUR-100',
    'invoice_date' => '2026-08-01',
    'purchase_date' => '2026-08-02',
    'date_received' => null,
    'project_code' => 'PRJ-01',
    'currency' => 'USD',
    'sub_total' => 1000,
    'discount' => 0,
    'other_charges' => 0,
    'vat_rate' => 0,
    'wht_rate' => 0,
];

$final = fxFundRequestNormalizePayload($base);
$record('final_without_po_is_valid', $final['request_type'] === 'Final' && $final['po_number'] === null);

$advance = $base;
$advance['request_type'] = 'Advance';
unset($advance['invoice_number'], $advance['purchase_number'], $advance['invoice_date'], $advance['purchase_date']);
$advance['percentage'] = 50;
$record('advance_without_po_is_rejected', $expectException(static fn() => fxFundRequestNormalizePayload($advance)));

$migration = file_get_contents($root . '/database/20260809_fx_fund_request_final_optional_po.sql') ?: '';
$record('migration_makes_active_po_nullable',
    str_contains($migration, 'lambert2_acctlab_db')
    && str_contains($migration, 'MODIFY COLUMN `po_number` VARCHAR(255) NULL')
);
$record('migration_has_no_archive_dependency',
    !str_contains($migration, 'lambert2_acctlab_archive')
    && !str_contains($migration, 'lambert2_acctlab_read')
);
$record('migration_preserves_advance_po_requirement',
    str_contains($migration, 'chk_fx_fund_request_advance_po_required')
    && str_contains($migration, '`request_type`')
    && str_contains($migration, 'Advance')
);

$create = file_get_contents($root . '/routes/request/fx/create.php') ?: '';
$placeholderCount = null;
if (preg_match("/INSERT INTO fx_fund_request_table \\(.+?\\) VALUES \\((.+?)\\)/s", $create, $matches) === 1) {
    $placeholderCount = substr_count($matches[1], '?');
}
$record('create_insert_has_all_placeholders', $placeholderCount === 22);
$record('create_insert_bind_count_is_22', str_contains($create, "'ssissssssssdddddddddii'"));

$directCreate = file_get_contents($root . '/routes/fx/payment/createPayment.php') ?: '';
$record('direct_fx_payment_creation_unchanged',
    str_contains($directCreate, 'INSERT INTO fx_instruction_letter_table')
    && !str_contains($directCreate, 'fx_fund_request_table')
);

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
