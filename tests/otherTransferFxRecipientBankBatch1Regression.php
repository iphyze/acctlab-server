<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/interBankLetterRecipientService.php';

$create = file_get_contents($root . '/routes/letter/inter-bank/createRequest.php') ?: '';
$edit = file_get_contents($root . '/routes/letter/inter-bank/editRequest.php') ?: '';
$list = file_get_contents($root . '/routes/letter/inter-bank/getFilteredRequest.php') ?: '';
$fetchFx = file_get_contents($root . '/routes/data/fetchFxBanks.php') ?: '';
$fetchLocal = file_get_contents($root . '/routes/data/fetchLocalBanks.php') ?: '';
$migration = file_get_contents($root . '/database/20261001_other_transfer_fx_recipient_bank_source.sql') ?: '';

$checks = [];
$checks['other_transfer_defaults_to_local'] = interBankLetterRecipientBankType([], 'other_transfers') === 'LOCAL';
$checks['other_transfer_accepts_fx'] = interBankLetterRecipientBankType(['recipient_bank_type' => 'fx'], 'other_transfers') === 'FX';
$checks['non_other_transfer_is_forced_local'] = interBankLetterRecipientBankType(['recipient_bank_type' => 'FX'], 'tax_payment') === 'LOCAL'
    && interBankLetterRecipientBankType(['recipient_bank_type' => 'FX'], 'inter_bank_transfer') === 'LOCAL';

$invalidRejected = false;
try {
    interBankLetterRecipientBankType(['recipient_bank_type' => 'OFFSHORE'], 'other_transfers');
} catch (Exception $e) {
    $invalidRejected = $e->getCode() === 400;
}
$checks['invalid_source_is_rejected'] = $invalidRejected;

$checks['migration_adds_backward_compatible_source'] = str_contains($migration, 'recipient_bank_type')
    && str_contains($migration, "DEFAULT 'LOCAL'")
    && str_contains($migration, "payment_account_number` = '0622025683'")
    && str_contains($migration, "bank_code` = 'WEM'")
    && str_contains($migration, "recipient_bank_type` = 'FX'");

$checks['create_persists_and_returns_source'] = str_contains($create, 'interBankLetterRecipientBankType($data, $normalizedType)')
    && str_contains($create, 'recipient_bank_type,')
    && str_contains($create, '"recipient_bank_type"    => $recipient_bank_type')
    && str_contains($create, 'interBankLetterAssertFxRecipientExists');

$checks['edit_preserves_existing_source_when_payload_omits_it'] = str_contains($edit, 'SELECT id, recipient_bank_type FROM instruction_letter')
    && str_contains($edit, "existingLetter['recipient_bank_type']")
    && str_contains($edit, "?? 'LOCAL'")
    && str_contains($edit, 'recipient_bank_type   = ?')
    && str_contains($edit, 'interBankLetterAssertFxRecipientExists');

$checks['list_exposes_saved_source'] = str_contains($list, 'recipient_bank_type,');

$checks['fx_lookup_exposes_other_transfer_letter_metadata'] = str_contains($fetchFx, 'letter_header')
    && str_contains($fetchFx, 'salutation')
    && str_contains($fetchFx, 'attention')
    && str_contains($fetchFx, 'letter_title')
    && str_contains($fetchFx, "'FX' AS recipient_bank_type");

$checks['local_lookup_is_source_aware_without_behaviour_change'] = str_contains($fetchLocal, "'LOCAL' AS recipient_bank_type")
    && str_contains($fetchLocal, 'FROM local_banks');

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
