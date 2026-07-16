<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertWriteAccess($user, $account);

    $closureDate = cashParseDate($data['date'] ?? $data['closure_date'] ?? null, 'Closure date');
    cashAssertAccountingPeriod($user, $closureDate);
    $physicalCash = cashParseNonNegativeAmount($data['physical_cash_counted'] ?? $data['physical_cash'] ?? null, 'Usable cash counted');
    $differenceNote = cashNullableText($data['difference_note'] ?? $data['note'] ?? null, 5000);
    $accountId = (int) $account['id'];

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);

        $laterStmt = $conn->prepare("SELECT closure_date
                                     FROM cash_daily_closures
                                     WHERE account_id = ? AND status = 'CLOSED' AND closure_date > ?
                                     ORDER BY closure_date ASC LIMIT 1");
        if (!$laterStmt) {
            throw new RuntimeException('Unable to validate daily-close order.', 500);
        }
        $laterStmt->bind_param('is', $accountId, $closureDate);
        $laterStmt->execute();
        $later = $laterStmt->get_result()->fetch_assoc();
        $laterStmt->close();
        if ($later) {
            throw new RuntimeException('A later cash day is already closed. Reopen later closed days before closing this date.', 409);
        }

        $existingStmt = $conn->prepare('SELECT * FROM cash_daily_closures WHERE account_id = ? AND closure_date = ? FOR UPDATE');
        if (!$existingStmt) {
            throw new RuntimeException('Unable to verify the daily close.', 500);
        }
        $existingStmt->bind_param('is', $accountId, $closureDate);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc() ?: null;
        $existingStmt->close();
        if ($existing && strtoupper((string) $existing['status']) === 'CLOSED') {
            throw new RuntimeException('This cash day has already been closed.', 409);
        }

        $dayBefore = (new DateTimeImmutable($closureDate))->modify('-1 day')->format('Y-m-d');
        $openingBalance = cashGetUsableBalance($conn, $accountId, $dayBefore);
        $systemClosingBalance = cashGetUsableBalance($conn, $accountId, $closureDate);
        $movements = cashGetDayMovements($conn, $accountId, $closureDate);
        $difference = round($physicalCash - $systemClosingBalance, 2);
        if (abs($difference) >= 0.01 && $differenceNote === null) {
            throw new InvalidArgumentException('An explanation is required when the usable cash counted differs from the system balance.', 422);
        }

        $userId = (int) $user['id'];
        $userEmail = (string) $user['email'];
        if ($existing) {
            $closureId = (int) $existing['id'];
            $stmt = $conn->prepare("UPDATE cash_daily_closures
                    SET opening_balance = ?, cash_received = ?, cash_returned = ?, cash_disbursed = ?,
                        reimbursements_paid = ?, system_closing_balance = ?, physical_cash_counted = ?,
                        difference_amount = ?, difference_note = ?, status = 'CLOSED',
                        closed_by_user_id = ?, closed_by_email = ?, closed_at = NOW()
                    WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Unable to close the cash day.', 500);
            }
            $stmt->bind_param(
                'ddddddddsisi',
                $openingBalance,
                $movements['cash_received'],
                $movements['cash_returned'],
                $movements['cash_disbursed'],
                $movements['reimbursements_paid'],
                $systemClosingBalance,
                $physicalCash,
                $difference,
                $differenceNote,
                $userId,
                $userEmail,
                $closureId
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO cash_daily_closures (
                    account_id, closure_date, opening_balance, cash_received, cash_returned,
                    cash_disbursed, reimbursements_paid, system_closing_balance, physical_cash_counted,
                    difference_amount, difference_note, status, closed_by_user_id, closed_by_email
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CLOSED', ?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Unable to close the cash day.', 500);
            }
            $stmt->bind_param(
                'isddddddddsis',
                $accountId,
                $closureDate,
                $openingBalance,
                $movements['cash_received'],
                $movements['cash_returned'],
                $movements['cash_disbursed'],
                $movements['reimbursements_paid'],
                $systemClosingBalance,
                $physicalCash,
                $difference,
                $differenceNote,
                $userId,
                $userEmail
            );
        }
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to close the cash day: ' . $message, 500);
        }
        $closureId = $existing ? (int) $existing['id'] : (int) $stmt->insert_id;
        $stmt->close();

        cashLogAction(
            $conn,
            $user,
            sprintf('%s closed Cash Desk day %s. System balance: NGN %s; usable cash counted: NGN %s; difference: NGN %s.',
                $user['email'], $closureDate, number_format($systemClosingBalance, 2, '.', ','),
                number_format($physicalCash, 2, '.', ','), number_format($difference, 2, '.', ','))
        );

        $fetchStmt = $conn->prepare('SELECT * FROM cash_daily_closures WHERE id = ? LIMIT 1');
        if (!$fetchStmt) {
            throw new RuntimeException('Unable to read the completed daily close.', 500);
        }
        $fetchStmt->bind_param('i', $closureId);
        $fetchStmt->execute();
        $closure = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => abs($difference) < 0.01 ? 'Cash day closed and balanced successfully.' : 'Cash day closed with a recorded difference.',
            'data' => [
                'closure' => $closure,
                'movements' => $movements,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to close the cash day.');
}
