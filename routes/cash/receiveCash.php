<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertWriteAccess($user, $account);

    $transactionDate = cashParseDate($data['date'] ?? $data['transaction_date'] ?? null, 'Date received');
    $accountingYear = cashAssertAccountingPeriod($user, $transactionDate);
    $receivedFrom = cashRequiredText($data, 'name', 'Received from', 160);
    $amount = cashParseAmount($data['amount'] ?? null, 'Amount received');
    $reason = cashNullableText($data['reason'] ?? null, 255);
    $description = cashNullableText($data['description'] ?? null, 5000);
    $externalReference = cashNullableText($data['reference'] ?? $data['external_reference'] ?? null, 120);
    $idempotencyKey = cashIdempotencyKey($data);
    $accountId = (int) $account['id'];
    $entryType = strtoupper(trim((string) ($data['entry_type'] ?? $data['transaction_type'] ?? 'CASH_RECEIPT')));
    if (!in_array($entryType, ['CASH_RECEIPT', 'OPENING_BALANCE'], true)) {
        throw new InvalidArgumentException('entry_type must be CASH_RECEIPT or OPENING_BALANCE.', 422);
    }
    if ($entryType === 'OPENING_BALANCE') {
        cashAssertManageAccess($user, $account);
    }

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        cashAssertDateIsOpen($conn, $accountId, $transactionDate);
        cashAssertEntryDateAllowed($conn, $accountId, $transactionDate);

        $existing = cashFindIdempotentTransaction($conn, $accountId, $idempotencyKey);
        if ($existing) {
            $transaction = cashFetchTransaction($conn, (int) $existing['id']);
            $balance = cashGetUsableBalance($conn, $accountId);
            $conn->commit();

            jsonResponse([
                'status' => 'Success',
                'message' => 'This cash receipt was already posted.',
                'data' => [
                    'transaction' => $transaction,
                    'available_balance' => $balance,
                    'idempotent_replay' => true,
                ],
            ]);
        }

        if ($entryType === 'OPENING_BALANCE') {
            $openingStmt = $conn->prepare("SELECT id FROM cash_transactions
                                           WHERE account_id = ? AND accounting_year = ?
                                           LIMIT 1");
            if (!$openingStmt) {
                throw new RuntimeException('Unable to validate the opening balance.', 500);
            }
            $openingStmt->bind_param('ii', $accountId, $accountingYear);
            $openingStmt->execute();
            $existingYearTransaction = $openingStmt->get_result()->fetch_assoc();
            $openingStmt->close();
            if ($existingYearTransaction) {
                throw new RuntimeException('An opening balance can only be posted before the first Cash Desk transaction for the accounting year.', 409);
            }
        }

        $possibleDuplicate = cashFindPossibleDuplicate($conn, $accountId, $transactionDate, $receivedFrom, $amount, 'IN');
        $reference = cashGenerateReference($conn, $entryType === 'OPENING_BALANCE' ? 'CASH-OPEN' : 'CASH-IN', $transactionDate);
        $transactionId = cashInsertTransaction($conn, [
            'account_id' => $accountId,
            'transaction_reference' => $reference,
            'transaction_date' => $transactionDate,
            'transaction_type' => $entryType,
            'direction' => 'IN',
            'person_name' => $receivedFrom,
            'amount' => $amount,
            'reason' => $reason ?? ($entryType === 'OPENING_BALANCE' ? 'Opening balance' : null),
            'description' => $description,
            'category_id' => null,
            'external_reference' => $externalReference,
            'disbursement_type' => null,
            'receipt_status' => 'NOT_REQUIRED',
            'idempotency_key' => $idempotencyKey,
            'accounting_year' => $accountingYear,
            'created_by_user_id' => $user['id'],
            'created_by_email' => $user['email'],
            'metadata' => [
                'entry_source' => 'cash_desk',
                'client_ip' => clientIpAddress(),
            ],
        ]);

        $balance = cashGetUsableBalance($conn, $accountId);
        cashLogAction(
            $conn,
            $user,
            sprintf(
                '%s posted %s of NGN %s from %s to %s (%s).',
                $user['email'],
                $entryType === 'OPENING_BALANCE' ? 'an opening cash balance' : 'cash received',
                number_format($amount, 2, '.', ','),
                $receivedFrom,
                $account['account_name'],
                $reference
            )
        );

        $transaction = cashFetchTransaction($conn, $transactionId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => $entryType === 'OPENING_BALANCE'
                ? 'Opening cash balance posted successfully.'
                : 'Cash received has been posted successfully.',
            'data' => [
                'transaction' => $transaction,
                'available_balance' => $balance,
                'idempotent_replay' => false,
                'possible_duplicate' => $possibleDuplicate,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to post the cash receipt.');
}
