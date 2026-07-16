<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertWriteAccess($user, $account);

    $recordId = cashMutilatedCashId($data);
    $resolutionDate = cashParseDate($data['date'] ?? $data['return_date'] ?? null, 'Bank return date');
    $accountingYear = cashAssertAccountingPeriod($user, $resolutionDate);
    $resolutionType = strtoupper(trim((string) ($data['resolution_type'] ?? $data['type'] ?? 'RETURNED')));
    if (!in_array($resolutionType, ['RETURNED', 'REPLACED'], true)) {
        throw new InvalidArgumentException('resolution_type must be RETURNED or REPLACED.', 422);
    }

    $bankReference = cashNullableText($data['bank_reference'] ?? $data['reference'] ?? null, 120);
    $resolutionNote = cashNullableText($data['note'] ?? $data['resolution_note'] ?? null, 1000);
    $idempotencyKey = cashIdempotencyKey($data);
    $accountId = (int) $account['id'];

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $resolutionDate);
        cashAssertEntryDateAllowed($conn, $accountId, $resolutionDate);

        $lockStmt = $conn->prepare('SELECT * FROM cash_mutilated_cash WHERE id = ? AND account_id = ? FOR UPDATE');
        if (!$lockStmt) {
            throw new RuntimeException('Unable to lock the mutilated cash record.', 500);
        }
        $lockStmt->bind_param('ii', $recordId, $accountId);
        $lockStmt->execute();
        $record = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if (!$record) {
            throw new RuntimeException('Mutilated cash record not found.', 404);
        }

        $currentStatus = strtoupper((string) ($record['status'] ?? 'PENDING_RETURN'));
        if ($currentStatus !== 'PENDING_RETURN') {
            $resolved = cashFetchMutilatedCash($conn, $recordId);
            $conn->commit();
            jsonResponse([
                'status' => 'Success',
                'message' => 'This mutilated cash record has already been resolved.',
                'data' => [
                    'mutilated_cash' => $resolved,
                    'cash_balance' => cashGetBalance($conn, $accountId),
                    'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, $accountId),
                    'available_balance' => cashGetUsableBalance($conn, $accountId),
                    'idempotent_replay' => true,
                ],
            ]);
        }

        if ($resolutionDate < (string) $record['discovered_date']) {
            throw new InvalidArgumentException('The bank return date cannot be earlier than the date the mutilated cash was discovered.', 422);
        }

        $expectedTransactionType = $resolutionType === 'REPLACED'
            ? 'MUTILATED_CASH_REPLACEMENT'
            : 'MUTILATED_CASH_BANK_RETURN';
        $resolutionTransactionId = null;
        $existing = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($existing) {
            if (strtoupper((string) ($existing['transaction_type'] ?? '')) !== $expectedTransactionType) {
                throw new RuntimeException('This idempotency key has already been used for another cash transaction.', 409);
            }
            $resolutionTransactionId = (int) $existing['id'];
        } else {
            $isReplacement = $resolutionType === 'REPLACED';
            $transactionReference = cashGenerateReference(
                $conn,
                $isReplacement ? 'CASH-MUT-RPL' : 'CASH-MUT-RTN',
                $resolutionDate
            );
            $resolutionTransactionId = cashInsertTransaction($conn, [
                'account_id' => $accountId,
                'transaction_reference' => $transactionReference,
                'transaction_date' => $resolutionDate,
                'transaction_type' => $expectedTransactionType,
                'direction' => $isReplacement ? 'IN' : 'OUT',
                'person_name' => $isReplacement ? 'Bank replacement' : 'Returned to bank',
                'amount' => round((float) $record['amount'], 2),
                'reason' => $isReplacement
                    ? 'Clean cash exchanged for mutilated notes'
                    : 'Mutilated notes returned to the bank without immediate replacement',
                'description' => $resolutionNote,
                'category_id' => null,
                'external_reference' => $bankReference,
                'disbursement_type' => $isReplacement ? 'MUTILATED_REPLACEMENT' : 'MUTILATED_RETURN',
                'receipt_status' => 'NOT_REQUIRED',
                'idempotency_key' => $idempotencyKey,
                'accounting_year' => $accountingYear,
                'created_by_user_id' => $user['id'],
                'created_by_email' => $user['email'],
                'metadata' => [
                    'entry_source' => 'cash_desk_mutilated_cash_resolution',
                    'mutilated_cash_id' => $recordId,
                    'set_aside_transaction_id' => (int) $record['set_aside_transaction_id'],
                    'affects_cash_balance' => !$isReplacement,
                    'client_ip' => clientIpAddress(),
                ],
            ]);
        }

        $replacementTransactionId = $resolutionType === 'REPLACED' ? $resolutionTransactionId : null;
        $updateStmt = $conn->prepare("UPDATE cash_mutilated_cash
            SET status = ?, resolution_type = ?, return_date = ?, bank_reference = ?, resolution_note = ?,
                replacement_transaction_id = ?, resolution_transaction_id = ?, resolved_by_user_id = ?,
                resolved_by_email = ?, resolved_at = NOW()
            WHERE id = ? AND account_id = ?");
        if (!$updateStmt) {
            throw new RuntimeException('Unable to update the mutilated cash record.', 500);
        }
        $status = $resolutionType;
        $resolvedByUserId = (int) $user['id'];
        $resolvedByEmail = (string) $user['email'];
        $updateStmt->bind_param(
            'sssssiiisii',
            $status,
            $resolutionType,
            $resolutionDate,
            $bankReference,
            $resolutionNote,
            $replacementTransactionId,
            $resolutionTransactionId,
            $resolvedByUserId,
            $resolvedByEmail,
            $recordId,
            $accountId
        );
        if (!$updateStmt->execute()) {
            $message = $updateStmt->error;
            $updateStmt->close();
            throw new RuntimeException('Unable to resolve the mutilated cash record: ' . $message, 500);
        }
        $updateStmt->close();

        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s marked mutilated cash of NGN %s as %s for %s (%s).',
                $user['email'],
                number_format((float) $record['amount'], 2, '.', ','),
                $resolutionType,
                $account['account_name'],
                $bankReference ?: 'no bank reference'
            )
        );

        $resolvedRecord = cashFetchMutilatedCash($conn, $recordId);
        $cashBalance = cashGetBalance($conn, $accountId);
        $pendingMutilatedCash = cashGetPendingMutilatedAmount($conn, $accountId);
        $usableBalance = cashGetUsableBalance($conn, $accountId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => $resolutionType === 'REPLACED'
                ? 'The mutilated notes were exchanged for clean cash. The total cash balance is unchanged.'
                : 'The mutilated notes were returned to the bank without an immediate replacement.',
            'data' => [
                'mutilated_cash' => $resolvedRecord,
                'resolution_transaction' => cashFetchTransaction($conn, $resolutionTransactionId),
                'cash_balance' => $cashBalance,
                'pending_mutilated_cash' => $pendingMutilatedCash,
                'available_balance' => $usableBalance,
                'idempotent_replay' => false,
            ],
        ]);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to resolve the mutilated cash record.');
}
