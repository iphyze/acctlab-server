<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertManageAccess($user, $account);
    $closureId = cashClosureId($data);
    $reason = cashRequiredText($data, 'reason', 'Reopen reason', 1000);
    $accountId = (int) $account['id'];

    $conn->begin_transaction();
    try {
        cashLockAccount($conn, $accountId);
        $stmt = $conn->prepare('SELECT * FROM cash_daily_closures WHERE id = ? AND account_id = ? FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Unable to lock the daily close.', 500);
        }
        $stmt->bind_param('ii', $closureId, $accountId);
        $stmt->execute();
        $closure = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$closure) {
            throw new RuntimeException('Daily cash closure not found.', 404);
        }
        if (strtoupper((string) $closure['status']) !== 'CLOSED') {
            throw new RuntimeException('This cash day is already open.', 409);
        }

        $laterStmt = $conn->prepare("SELECT closure_date
                                     FROM cash_daily_closures
                                     WHERE account_id = ? AND status = 'CLOSED' AND closure_date > ?
                                     ORDER BY closure_date DESC LIMIT 1");
        if (!$laterStmt) {
            throw new RuntimeException('Unable to validate daily-close order.', 500);
        }
        $closureDate = (string) $closure['closure_date'];
        $laterStmt->bind_param('is', $accountId, $closureDate);
        $laterStmt->execute();
        $later = $laterStmt->get_result()->fetch_assoc();
        $laterStmt->close();
        if ($later) {
            throw new RuntimeException('Reopen later closed cash days first, starting with ' . $later['closure_date'] . '.', 409);
        }

        $update = $conn->prepare("UPDATE cash_daily_closures
                                  SET status = 'REOPENED', reopened_by_user_id = ?, reopened_by_email = ?,
                                      reopened_at = NOW(), reopen_reason = ?
                                  WHERE id = ?");
        if (!$update) {
            throw new RuntimeException('Unable to reopen the cash day.', 500);
        }
        $userId = (int) $user['id'];
        $userEmail = (string) $user['email'];
        $update->bind_param('issi', $userId, $userEmail, $reason, $closureId);
        $update->execute();
        $update->close();

        cashLogAction($conn, $user, sprintf('%s reopened Cash Desk day %s. Reason: %s.', $user['email'], $closureDate, $reason));
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'Cash day reopened successfully.',
            'data' => [
                'closure_id' => $closureId,
                'closure_date' => $closureDate,
                'status' => 'REOPENED',
            ],
        ]);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to reopen the cash day.');
}
