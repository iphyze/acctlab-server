<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) throw new RuntimeException("Missing file: {$path}");
    return (string)file_get_contents($full);
};

$helpers = $read('routes/bank-recon/reconMatchingHelpers.php');
$migration = $read('database/20260916_bank_recon_named_profiles.sql');

$checks = [
    'canonical_bank_family_matching_present' =>
        str_contains($helpers, 'brReconCanonicalBankFamily')
        && str_contains($helpers, "return 'gtb';")
        && str_contains($helpers, "return 'fcmb';")
        && str_contains($helpers, "return 'access';")
        && str_contains($helpers, "return 'stanbic';"),
    'bank_scope_uses_canonical_family' =>
        str_contains($helpers, "if (\$key === 'bank_name')")
        && str_contains($helpers, 'brReconCanonicalBankFamily($scope) !== brReconCanonicalBankFamily($value)'),
    'migration_creates_four_named_profiles' =>
        str_contains($migration, "SELECT 'Stanbic IBTC Bank'")
        && str_contains($migration, "SELECT 'GTB'")
        && str_contains($migration, "SELECT 'FCMB'")
        && str_contains($migration, "SELECT 'Access Bank'"),
    'migration_moves_legacy_rules_by_bank' =>
        str_contains($migration, "LOWER(rule_name) LIKE 'stanbic%'")
        && str_contains($migration, "LOWER(rule_name) LIKE 'gtb%'")
        && str_contains($migration, "LOWER(rule_name) LIKE 'fcmb%'")
        && str_contains($migration, "LOWER(rule_name) LIKE 'abn%'"),
    'migration_preserves_rule_active_state' =>
        !str_contains($migration, 'UPDATE bank_recon_auto_rules\nSET is_active=')
        && !str_contains($migration, 'UPDATE bank_recon_auto_rules\r\nSET is_active='),
    'migration_moves_historical_scopes_off_legacy' =>
        str_contains($migration, "SET auto_rule_mode='profile', auto_rule_profile_id=@gtb_profile_id")
        && str_contains($migration, "SET auto_rule_mode='profile', auto_rule_profile_id=@fcmb_profile_id"),
    'migration_is_repeat_safe_for_profile_creation' =>
        substr_count($migration, 'WHERE NOT EXISTS') >= 4,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => !$failed, 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed ? 1 : 0);
