<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertManageAccess($user, $account);
    $accountId = (int) $account['id'];
    $assignedUserId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($assignedUserId === false || $assignedUserId === null || $assignedUserId <= 0) {
        throw new InvalidArgumentException('user_id must be a positive integer.', 422);
    }
    $accessLevel = strtoupper(trim((string) ($data['access_level'] ?? 'CASHIER')));
    if (!in_array($accessLevel, ['MANAGER', 'CASHIER', 'REVIEWER'], true)) {
        throw new InvalidArgumentException('access_level must be MANAGER, CASHIER or REVIEWER.', 422);
    }
    $isActive = cashParseBoolean($data['is_active'] ?? true, true) ? 1 : 0;

    $userStmt = $conn->prepare('SELECT id, fname, lname, email FROM user_table WHERE id = ? LIMIT 1');
    if (!$userStmt) {
        throw new RuntimeException('Unable to verify the selected user.', 500);
    }
    $userStmt->bind_param('i', $assignedUserId);
    $userStmt->execute();
    $assignedUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$assignedUser) {
        throw new InvalidArgumentException('The selected user is unavailable.', 422);
    }

    $existingStmt = $conn->prepare('SELECT access_level, is_active FROM cash_account_users WHERE account_id = ? AND user_id = ? LIMIT 1');
    if (!$existingStmt) {
        throw new RuntimeException('Unable to verify the existing Cash Desk assignment.', 500);
    }
    $existingStmt->bind_param('ii', $accountId, $assignedUserId);
    $existingStmt->execute();
    $existingAssignment = $existingStmt->get_result()->fetch_assoc() ?: null;
    $existingStmt->close();

    if (
        $existingAssignment
        && strtoupper((string) $existingAssignment['access_level']) === 'MANAGER'
        && (int) $existingAssignment['is_active'] === 1
        && ($accessLevel !== 'MANAGER' || $isActive !== 1)
    ) {
        $managerStmt = $conn->prepare("SELECT COUNT(*) AS total
                                       FROM cash_account_users
                                       WHERE account_id = ?
                                         AND access_level = 'MANAGER'
                                         AND is_active = 1
                                         AND user_id <> ?");
        if (!$managerStmt) {
            throw new RuntimeException('Unable to verify the remaining Cash Desk managers.', 500);
        }
        $managerStmt->bind_param('ii', $accountId, $assignedUserId);
        $managerStmt->execute();
        $remainingManagers = (int) ($managerStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $managerStmt->close();
        if ($remainingManagers === 0) {
            throw new RuntimeException('Assign another active Cash Desk manager before removing the final manager.', 409);
        }
    }

    $email = (string) $user['email'];
    $stmt = $conn->prepare("INSERT INTO cash_account_users (account_id, user_id, access_level, is_active, assigned_by)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), is_active = VALUES(is_active), assigned_by = VALUES(assigned_by)");
    if (!$stmt) {
        throw new RuntimeException('Unable to save the Cash Desk assignment.', 500);
    }
    $stmt->bind_param('iisis', $accountId, $assignedUserId, $accessLevel, $isActive, $email);
    $stmt->execute();
    $stmt->close();

    $name = trim((string) $assignedUser['fname'] . ' ' . (string) $assignedUser['lname']);
    cashLogAction($conn, $user, sprintf('%s assigned %s as %s on %s (%s).', $email, $name, $accessLevel, $account['account_name'], $isActive ? 'active' : 'inactive'));

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk user assignment saved successfully.',
        'data' => [
            'account_id' => $accountId,
            'user_id' => (int) $assignedUserId,
            'access_level' => $accessLevel,
            'is_active' => (bool) $isActive,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to save the Cash Desk user assignment.');
}
