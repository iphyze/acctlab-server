<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/20260809_fx_fund_request_foundation.sql') ?: '';
$directFxCreate = file_get_contents($root . '/routes/fx/payment/createPayment.php') ?: '';

$checks = [
    'migration_creates_fx_fund_request_table' => str_contains(
        $migration,
        'CREATE TABLE IF NOT EXISTS `lambert2_acctlab_db`.`fx_fund_request_table`'
    ),
    'migration_does_not_alter_fx_instruction_table' => !preg_match(
        '/\b(?:ALTER|DROP|RENAME|TRUNCATE)\s+TABLE\s+`?(?:lambert2_acctlab_db`?\.)?`?fx_instruction_letter_table`?/i',
        $migration
    ),
    'direct_fx_create_route_still_targets_instruction_table' => str_contains(
        $directFxCreate,
        'INSERT INTO fx_instruction_letter_table'
    ),
    'request_types_are_final_and_advance' => str_contains(
        $migration,
        "ENUM('Final', 'Advance')"
    ),
    'final_duplicate_guard_uses_purchase_number' => str_contains(
        $migration,
        'UNIQUE KEY `uq_fx_fund_request_final_purchase` (`purchase_number`)'
    ),
    'advance_po_percentage_index_present' => str_contains(
        $migration,
        'KEY `idx_fx_fund_request_advance_percentage` (`po_number`, `request_type`, `percentage`)'
    ),
    'advance_percentage_is_bounded' => str_contains($migration, '`percentage` > 0.00')
        && str_contains($migration, '`percentage` <= 100.00'),
    'final_only_fields_are_enforced' => str_contains($migration, "`request_type` = 'Final'")
        && str_contains($migration, '`purchase_number` IS NOT NULL')
        && str_contains($migration, '`invoice_date` IS NOT NULL')
        && str_contains($migration, '`purchase_date` IS NOT NULL'),
    'advance_hides_final_only_fields_at_storage_boundary' => str_contains($migration, "`request_type` = 'Advance'")
        && str_contains($migration, '`invoice_number` IS NULL')
        && str_contains($migration, '`purchase_number` IS NULL'),
    'vat_rate_domain_is_enforced' => str_contains(
        $migration,
        'CHECK (`vat_rate` IN (0.000000, 0.075000))'
    ),
    'wht_rate_domain_is_enforced' => str_contains(
        $migration,
        'CHECK (`wht_rate` IN (0.000000, 0.020000, 0.050000))'
    ),
    'future_instruction_link_is_nullable_and_unique' => str_contains(
        $migration,
        '`fx_instruction_letter_id` INT NULL'
    ) && str_contains(
        $migration,
        'UNIQUE KEY `uq_fx_fund_request_instruction` (`fx_instruction_letter_id`)'
    ),
    'foundation_targets_single_canonical_database' => !str_contains($migration, 'lambert2_acctlab_archive')
        && !str_contains($migration, 'lambert2_acctlab_read'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
