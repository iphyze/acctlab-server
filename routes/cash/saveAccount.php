<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    if (!in_array($user['integrity'], ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Only an Admin or Super Admin can manage Cash Desk accounts.', 403);
    }
    cashRequireSchema($conn);
    $data = cashReadJsonBody();
    $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : 0;
    $accountCode = strtoupper(cashRequiredText($data, 'account_code', 'Account code', 40));
    if (!preg_match('/^[A-Z0-9_-]+$/', $accountCode)) {
        throw new InvalidArgumentException('Account code may only contain letters, numbers, hyphens and underscores.', 422);
    }
    $accountName = cashRequiredText($data, 'account_name', 'Account name', 160);
    $currency = strtoupper(trim((string) ($data['currency'] ?? 'NGN')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new InvalidArgumentException('Currency must be a three-letter code.', 422);
    }
    $custodianUserId = isset($data['custodian_user_id']) && $data['custodian_user_id'] !== '' ? (int) $data['custodian_user_id'] : null;
    $allowNegative = cashParseBoolean($data['allow_negative_balance'] ?? false) ? 1 : 0;
    $status = strtoupper(trim((string) ($data['status'] ?? 'ACTIVE')));
    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        throw new InvalidArgumentException('Account status must be ACTIVE or INACTIVE.', 422);
    }

    if ($custodianUserId !== null) {
        $userStmt = $conn->prepare('SELECT id FROM user_table WHERE id = ? LIMIT 1');
        if (!$userStmt) {
            throw new RuntimeException('Unable to verify the selected custodian.', 500);
        }
        $userStmt->bind_param('i', $custodianUserId);
        $userStmt->execute();
        $exists = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if (!$exists) {
            throw new InvalidArgumentException('The selected custodian is unavailable.', 422);
        }
    }

    $conn->begin_transaction();
    try {
        $email = (string) $user['email'];
        if ($id > 0) {
            $currentStmt = $conn->prepare('SELECT id, currency, status FROM cash_accounts WHERE id = ? FOR UPDATE');
            if (!$currentStmt) {
                throw new RuntimeException('Unable to lock the Cash Desk account.', 500);
            }
            $currentStmt->bind_param('i', $id);
            $currentStmt->execute();
            $currentAccount = $currentStmt->get_result()->fetch_assoc();
            $currentStmt->close();
            if (!$currentAccount) {
                throw new RuntimeException('Cash Desk account not found.', 404);
            }

            $activityStmt = $conn->prepare("SELECT
                    (SELECT COUNT(*) FROM cash_transactions WHERE account_id = ?) AS transaction_count,
                    (SELECT COUNT(*) FROM cash_ious WHERE account_id = ? AND status NOT IN ('CLOSED', 'REVERSED')) AS open_iou_count");
            if (!$activityStmt) {
                throw new RuntimeException('Unable to verify Cash Desk account activity.', 500);
            }
            $activityStmt->bind_param('ii', $id, $id);
            $activityStmt->execute();
            $activity = $activityStmt->get_result()->fetch_assoc() ?: [];
            $activityStmt->close();

            if ((int) ($activity['transaction_count'] ?? 0) > 0 && strtoupper((string) $currentAccount['currency']) !== $currency) {
                throw new RuntimeException('The currency cannot be changed after cash transactions have been posted.', 409);
            }

            if ($status === 'INACTIVE' && strtoupper((string) $currentAccount['status']) !== 'INACTIVE') {
                $balance = cashGetBalance($conn, $id);
                if (abs($balance) >= 0.01) {
                    throw new RuntimeException('Bring the Cash Desk balance to zero before deactivating this account.', 409);
                }
                if ((int) ($activity['open_iou_count'] ?? 0) > 0) {
                    throw new RuntimeException('Close or reverse all outstanding IOUs before deactivating this account.', 409);
                }
            }

            $stmt = $conn->prepare("UPDATE cash_accounts
                                    SET account_code = ?, account_name = ?, currency = ?, custodian_user_id = ?,
                                        allow_negative_balance = ?, status = ?, updated_by = ?
                                    WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Unable to update the Cash Desk account.', 500);
            }
            $stmt->bind_param('sssiissi', $accountCode, $accountName, $currency, $custodianUserId, $allowNegative, $status, $email, $id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $checkStmt = $conn->prepare('SELECT id FROM cash_accounts WHERE id = ? LIMIT 1');
                $checkStmt->bind_param('i', $id);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();
                if (!$exists) {
                    $stmt->close();
                    throw new RuntimeException('Cash Desk account not found.', 404);
                }
            }
            $stmt->close();
            $accountId = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO cash_accounts (
                    account_code, account_name, currency, custodian_user_id, allow_negative_balance,
                    status, created_by, updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Unable to create the Cash Desk account.', 500);
            }
            $stmt->bind_param('sssiisss', $accountCode, $accountName, $currency, $custodianUserId, $allowNegative, $status, $email, $email);
            $stmt->execute();
            $accountId = (int) $stmt->insert_id;
            $stmt->close();

            $settingsStmt = $conn->prepare('INSERT IGNORE INTO cash_settings (account_id, updated_by) VALUES (?, ?)');
            if (!$settingsStmt) {
                throw new RuntimeException('Unable to create the Cash Desk account settings.', 500);
            }
            $settingsStmt->bind_param('is', $accountId, $email);
            $settingsStmt->execute();
            $settingsStmt->close();

            $creatorId = (int) $user['id'];
            $assignmentStmt = $conn->prepare("INSERT INTO cash_account_users (account_id, user_id, access_level, is_active, assigned_by)
                                              VALUES (?, ?, 'MANAGER', 1, ?)
                                              ON DUPLICATE KEY UPDATE access_level = 'MANAGER', is_active = 1, assigned_by = VALUES(assigned_by)");
            if (!$assignmentStmt) {
                throw new RuntimeException('Unable to assign the account manager.', 500);
            }
            $assignmentStmt->bind_param('iis', $accountId, $creatorId, $email);
            $assignmentStmt->execute();
            $assignmentStmt->close();
        }

        cashLogAction($conn, $user, sprintf('%s %s Cash Desk account %s.', $email, $id > 0 ? 'updated' : 'created', $accountName));
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => $id > 0 ? 'Cash Desk account updated successfully.' : 'Cash Desk account created successfully.',
            'data' => ['account_id' => $accountId],
        ], $id > 0 ? 200 : 201);
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new InvalidArgumentException('That Cash Desk account code is already in use.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to save the Cash Desk account.');
}
