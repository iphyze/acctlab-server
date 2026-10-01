<?php

/**
 * Recipient-bank source helpers for inter-bank instruction letters.
 *
 * Only Other Transfers may use FX recipient banks. Every other instruction
 * type is deliberately pinned to LOCAL so existing Inter Bank Transfer and
 * Tax Payment behaviour cannot be changed by a client payload.
 */
function interBankLetterRecipientBankType(
    array $payload,
    string $normalizedInstructionType,
    string $fallback = 'LOCAL'
): string {
    if ($normalizedInstructionType !== 'other_transfers') {
        return 'LOCAL';
    }

    $rawType = array_key_exists('recipient_bank_type', $payload)
        ? (string) $payload['recipient_bank_type']
        : $fallback;

    $recipientBankType = strtoupper(trim($rawType));
    if ($recipientBankType === '') {
        $recipientBankType = 'LOCAL';
    }

    if (!in_array($recipientBankType, ['LOCAL', 'FX'], true)) {
        throw new Exception('Recipient bank type must be LOCAL or FX', 400);
    }

    return $recipientBankType;
}

/**
 * FX recipient selections must point to a real configured FX bank account.
 * Local recipients retain the legacy behaviour so existing letters are not
 * made dependent on stricter historical bank master-data matching.
 */
function interBankLetterAssertFxRecipientExists(
    mysqli $conn,
    string $recipientBankType,
    string $accountNumber,
    string $bankCode
): void {
    if ($recipientBankType !== 'FX') {
        return;
    }

    $stmt = $conn->prepare(
        'SELECT id FROM fx_banks_table WHERE account_number = ? AND bank_code = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new Exception('Database error: Unable to validate FX letter recipient', 500);
    }

    $stmt->bind_param('ss', $accountNumber, $bankCode);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if (!$exists) {
        throw new Exception('Selected FX letter recipient could not be found', 400);
    }
}
