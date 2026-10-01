<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) throw new RuntimeException("Missing file: {$path}");
    return (string)file_get_contents($full);
};

$helpers = $read('routes/bank-recon/reconMatchingHelpers.php');
$autoRules = $read('routes/bank-recon/autoRules.php');
$profiles = $read('routes/bank-recon/ruleProfiles.php');
$create = $read('routes/bank-recon/createReconciliation.php');
$update = $read('routes/bank-recon/updateReconciliation.php');
$get = $read('routes/bank-recon/getReconciliation.php');
$router = $read('index.php');
$migration = $read('database/20260818_bank_recon_rule_profiles_foundation.sql');
$classification = $read('routes/bank-recon/reconAutoClassification.php');

$checks = [
    'profile_table_foundation_present' =>
        str_contains($helpers, 'CREATE TABLE IF NOT EXISTS bank_recon_rule_profiles')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `bank_recon_rule_profiles`'),
    'existing_reconciliations_default_to_legacy' =>
        str_contains($helpers, "DEFAULT 'legacy'")
        && str_contains($migration, "DEFAULT 'legacy'")
        && str_contains($create, "'legacy'), 'legacy'")
        && !str_contains($migration, "SET `auto_rule_mode` = 'profile'"),
    'existing_rules_remain_unscoped' =>
        str_contains($migration, '`profile_id` int DEFAULT NULL')
        && !preg_match('/UPDATE\s+`?bank_recon_auto_rules`?\s+SET\s+`?profile_id`?/i', $migration),
    'rule_lookup_is_reconciliation_scoped' =>
        str_contains($helpers, 'int $reconId = 0')
        && str_contains($helpers, 'brReconResolveRuleScope($conn, $reconId)')
        && str_contains($helpers, "\$profileWhere = 'profile_id IS NULL'")
        && str_contains($helpers, "\$profileWhere = 'profile_id=' . \$profileId"),
    'invalid_profile_fails_closed_not_global' =>
        str_contains($helpers, "return ['mode' => 'none', 'profile_id' => \$profileId ?: null")
        && str_contains($helpers, "if (\$mode === 'none') return null;"),
    'profile_context_prevents_wrong_bank_or_currency' =>
        str_contains($helpers, 'brReconRuleProfileCompatible')
        && str_contains($helpers, "Selected rule profile does not match this reconciliation company, bank account or currency."),
    'create_persists_scope_before_auto_classification' =>
        str_contains($create, 'auto_rule_mode, auto_rule_profile_id')
        && strpos($create, 'brReconValidateRuleScopeSelection') < strpos($create, 'brAutoApplyClassifications($conn, $reconId'),
    'update_preserves_scope_and_append_only_behaviour' =>
        str_contains($update, "body['auto_rule_mode'] ?? \$recon['auto_rule_mode']")
        && str_contains($update, 'auto_rule_mode=?, auto_rule_profile_id=?')
        && str_contains($update, 'Edit re-upload is append-only')
        && !str_contains($update, 'DELETE FROM bank_recon_bank_lines'),
    'profile_api_is_routed_and_reports_rule_counts' =>
        str_contains($router, "'/bank-recon/rule-profiles' => 'routes/bank-recon/ruleProfiles.php'")
        && str_contains($profiles, 'active_rule_count')
        && str_contains($profiles, 'brProfileMatchScore'),
    'rule_crud_supports_profile_assignment_without_breaking_legacy' =>
        str_contains($autoRules, 'profile_id=?')
        && str_contains($autoRules, 'LEFT JOIN bank_recon_rule_profiles')
        && str_contains($autoRules, "\$profileIdValue = \$profileId > 0 ? \$profileId : null"),
    'auto_classification_passes_recon_id_into_rule_lookup' =>
        str_contains($classification, 'brReconFindAutoRule($GLOBALS[\'conn\'], $source, $description, $direction, $reference, $reconId)'),
    'single_reconciliation_read_initializes_new_schema' =>
        strpos($get, 'brReconEnsureSmartSchema($conn);') < strpos($get, 'SELECT * FROM bank_recons'),
    'migration_is_non_destructive' =>
        !preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM)\b/i', $migration),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => !$failed, 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed ? 1 : 0);
