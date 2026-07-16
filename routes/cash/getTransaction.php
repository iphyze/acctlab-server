<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    $transactionId = cashTransactionId();
    $transaction = cashFetchTransaction($conn, $transactionId);

    if ((int) $transaction['account_id'] !== (int) $account['id']) {
        throw new RuntimeException('Cash transaction not found.', 404);
    }

    $receiptStmt = $conn->prepare("SELECT id, transaction_id, iou_id, document_type, original_filename, mime_type, file_size, status, uploaded_by_email, created_at
                                  FROM cash_receipts
                                  WHERE transaction_id = ? AND status = 'ACTIVE'
                                  ORDER BY created_at DESC, id DESC");
    if (!$receiptStmt) {
        throw new RuntimeException('Unable to load transaction receipts.', 500);
    }
    $receiptStmt->bind_param('i', $transactionId);
    $receiptStmt->execute();
    $receipts = $receiptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $receiptStmt->close();

    foreach ($receipts as &$receipt) {
        foreach (['id', 'transaction_id', 'iou_id', 'file_size'] as $field) {
            $receipt[$field] = $receipt[$field] !== null ? (int) $receipt[$field] : null;
        }
    }
    unset($receipt);

    $reversalStmt = $conn->prepare("SELECT id, transaction_reference, transaction_date, direction, amount, reason, description, created_by_email, created_at
                                    FROM cash_transactions
                                    WHERE reversal_of_transaction_id = ?
                                    ORDER BY id DESC
                                    LIMIT 1");
    if (!$reversalStmt) {
        throw new RuntimeException('Unable to load transaction reversal details.', 500);
    }
    $reversalStmt->bind_param('i', $transactionId);
    $reversalStmt->execute();
    $reversal = $reversalStmt->get_result()->fetch_assoc() ?: null;
    $reversalStmt->close();
    if ($reversal) {
        $reversal['id'] = (int) $reversal['id'];
        $reversal['amount'] = round((float) $reversal['amount'], 2);
    }

    $mutilatedStmt = $conn->prepare("SELECT id
                                    FROM cash_mutilated_cash
                                    WHERE account_id = ?
                                      AND (
                                        source_receipt_transaction_id = ?
                                        OR linked_disbursement_transaction_id = ?
                                        OR (source_receipt_transaction_id IS NULL AND source_transaction_id = ?)
                                      )
                                    ORDER BY discovered_date DESC, id DESC");
    if (!$mutilatedStmt) {
        throw new RuntimeException('Unable to load linked mutilated cash records.', 500);
    }
    $accountId = (int) $account['id'];
    $mutilatedStmt->bind_param('iiii', $accountId, $transactionId, $transactionId, $transactionId);
    $mutilatedStmt->execute();
    $mutilatedIds = $mutilatedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mutilatedStmt->close();
    $mutilatedCash = array_map(
        static fn (array $row): array => cashFetchMutilatedCash($conn, (int) $row['id']),
        $mutilatedIds
    );

    $transaction['affects_balance'] = cashTransactionAffectsBalance((string) $transaction['transaction_type']);
    $editHistory = cashFetchTransactionEditHistory($conn, $transactionId);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash transaction loaded successfully.',
        'data' => [
            'transaction' => $transaction,
            'receipts' => $receipts,
            'reversal' => $reversal,
            'mutilated_cash' => $mutilatedCash,
            'edit_history' => $editHistory,
            'cash_balance' => cashGetBalance($conn, (int) $account['id']),
            'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, (int) $account['id']),
            'available_balance' => cashGetUsableBalance($conn, (int) $account['id']),
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load the cash transaction.');
}
