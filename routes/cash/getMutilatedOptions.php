<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    $accountId = (int) $account['id'];
    $year = (int) ($user['accounting_period'] ?? date('Y'));

    $receiptSql = "SELECT
            ct.id,
            ct.transaction_reference,
            ct.transaction_date,
            ct.transaction_type,
            ct.person_name,
            ct.amount,
            ct.reason,
            ct.external_reference,
            COALESCE((
                SELECT SUM(cmc.amount)
                FROM cash_mutilated_cash cmc
                WHERE cmc.account_id = ct.account_id
                  AND cmc.status <> 'REVERSED'
                  AND COALESCE(cmc.source_receipt_transaction_id,
                      CASE WHEN cmc.linked_disbursement_transaction_id IS NULL THEN cmc.source_transaction_id ELSE NULL END) = ct.id
            ), 0) AS mutilated_amount
        FROM cash_transactions ct
        WHERE ct.account_id = ?
          AND ct.accounting_year = ?
          AND ct.status = 'POSTED'
          AND ct.direction = 'IN'
          AND ct.transaction_type IN ('CASH_RECEIPT', 'OPENING_BALANCE', 'CASH_RETURN')
        ORDER BY ct.transaction_date DESC, ct.id DESC
        LIMIT 500";
    $receiptStmt = $conn->prepare($receiptSql);
    if (!$receiptStmt) {
        throw new RuntimeException('Unable to load cash receipt sources.', 500);
    }
    $receiptStmt->bind_param('ii', $accountId, $year);
    $receiptStmt->execute();
    $receipts = $receiptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $receiptStmt->close();

    $sources = [];
    foreach ($receipts as $receipt) {
        $amount = round((float) $receipt['amount'], 2);
        $mutilatedAmount = round((float) $receipt['mutilated_amount'], 2);
        $remaining = round(max(0, $amount - $mutilatedAmount), 2);
        if ($remaining <= 0) {
            continue;
        }
        $sources[] = [
            'id' => (int) $receipt['id'],
            'transaction_reference' => $receipt['transaction_reference'],
            'transaction_date' => $receipt['transaction_date'],
            'transaction_type' => $receipt['transaction_type'],
            'person_name' => $receipt['person_name'],
            'reason' => $receipt['reason'],
            'external_reference' => $receipt['external_reference'],
            'amount' => $amount,
            'mutilated_amount' => $mutilatedAmount,
            'remaining_amount' => $remaining,
        ];
    }

    $disbursementStmt = $conn->prepare("SELECT id, transaction_reference, transaction_date, transaction_type, person_name, amount, reason, external_reference
                                        FROM cash_transactions
                                        WHERE account_id = ?
                                          AND accounting_year = ?
                                          AND status = 'POSTED'
                                          AND direction = 'OUT'
                                          AND transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT')
                                        ORDER BY transaction_date DESC, id DESC
                                        LIMIT 300");
    if (!$disbursementStmt) {
        throw new RuntimeException('Unable to load disbursement references.', 500);
    }
    $disbursementStmt->bind_param('ii', $accountId, $year);
    $disbursementStmt->execute();
    $disbursements = $disbursementStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $disbursementStmt->close();
    foreach ($disbursements as &$row) {
        $row['id'] = (int) $row['id'];
        $row['amount'] = round((float) $row['amount'], 2);
    }
    unset($row);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Mutilated cash source options loaded successfully.',
        'data' => [
            'receipt_sources' => $sources,
            'disbursements' => $disbursements,
            'cash_balance' => cashGetBalance($conn, $accountId),
            'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, $accountId),
            'usable_balance' => cashGetUsableBalance($conn, $accountId),
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load mutilated cash options.');
}
