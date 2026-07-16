<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    cashAssertManageAccess($user, $account);
    $accountId = (int) $account['id'];
    $isAdmin = in_array($user['integrity'], ['Admin', 'Super_Admin'], true);

    if ($isAdmin) {
        $accountStmt = $conn->prepare("SELECT ca.*, cs.low_balance_threshold, cs.default_iou_due_days,
                                             cs.require_receipt_for_direct_expense, cs.allow_backdated_entries
                                      FROM cash_accounts ca
                                      LEFT JOIN cash_settings cs ON cs.account_id = ca.id
                                      ORDER BY ca.status = 'ACTIVE' DESC, ca.account_name ASC");
    } else {
        $accountStmt = $conn->prepare("SELECT ca.*, cs.low_balance_threshold, cs.default_iou_due_days,
                                             cs.require_receipt_for_direct_expense, cs.allow_backdated_entries
                                      FROM cash_accounts ca
                                      LEFT JOIN cash_settings cs ON cs.account_id = ca.id
                                      WHERE ca.id = ?");
        if ($accountStmt) {
            $accountStmt->bind_param('i', $accountId);
        }
    }
    if (!$accountStmt) {
        throw new RuntimeException('Unable to load Cash Desk accounts.', 500);
    }
    $accountStmt->execute();
    $accounts = $accountStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $accountStmt->close();
    foreach ($accounts as &$row) {
        $row['id'] = (int) $row['id'];
        $row['custodian_user_id'] = $row['custodian_user_id'] !== null ? (int) $row['custodian_user_id'] : null;
        $row['allow_negative_balance'] = (bool) $row['allow_negative_balance'];
        $row['low_balance_threshold'] = round((float) ($row['low_balance_threshold'] ?? 0), 2);
        $row['default_iou_due_days'] = (int) ($row['default_iou_due_days'] ?? 7);
        $row['require_receipt_for_direct_expense'] = (bool) ($row['require_receipt_for_direct_expense'] ?? false);
        $row['allow_backdated_entries'] = (bool) ($row['allow_backdated_entries'] ?? true);
        $row['cash_balance'] = cashGetBalance($conn, (int) $row['id']);
        $row['pending_mutilated_cash'] = cashGetPendingMutilatedAmount($conn, (int) $row['id']);
        $row['available_balance'] = cashGetUsableBalance($conn, (int) $row['id']);
    }
    unset($row);

    $assignmentStmt = $conn->prepare("SELECT cau.id, cau.account_id, cau.user_id, cau.access_level, cau.is_active,
                                            cau.assigned_by, cau.created_at, cau.updated_at,
                                            CONCAT(u.fname, ' ', u.lname) AS user_name, u.email, u.integrity
                                     FROM cash_account_users cau
                                     INNER JOIN user_table u ON u.id = cau.user_id
                                     WHERE cau.account_id = ?
                                     ORDER BY cau.is_active DESC, user_name ASC");
    if (!$assignmentStmt) {
        throw new RuntimeException('Unable to load Cash Desk assignments.', 500);
    }
    $assignmentStmt->bind_param('i', $accountId);
    $assignmentStmt->execute();
    $assignments = $assignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignmentStmt->close();
    foreach ($assignments as &$row) {
        foreach (['id', 'account_id', 'user_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['is_active'] = (bool) $row['is_active'];
    }
    unset($row);

    $usersStmt = $conn->prepare("SELECT id, fname, lname, email, integrity
                                 FROM user_table
                                 ORDER BY fname ASC, lname ASC, email ASC");
    if (!$usersStmt) {
        throw new RuntimeException('Unable to load users for Cash Desk assignment.', 500);
    }
    $usersStmt->execute();
    $users = $usersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $usersStmt->close();
    foreach ($users as &$row) {
        $row['id'] = (int) $row['id'];
        $row['name'] = trim((string) $row['fname'] . ' ' . (string) $row['lname']);
    }
    unset($row);

    $categoryStmt = $conn->prepare("SELECT * FROM cash_categories ORDER BY is_active DESC, sort_order ASC, category_name ASC");
    if (!$categoryStmt) {
        throw new RuntimeException('Unable to load Cash Desk categories.', 500);
    }
    $categoryStmt->execute();
    $categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoryStmt->close();
    foreach ($categories as &$row) {
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = (bool) $row['is_active'];
    }
    unset($row);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk management setup loaded successfully.',
        'data' => [
            'accounts' => $accounts,
            'active_account_id' => $accountId,
            'assignments' => $assignments,
            'users' => $users,
            'categories' => $categories,
            'permissions' => [
                'can_manage_account' => $isAdmin,
                'can_manage_assignments' => true,
                'can_manage_categories' => true,
                'can_manage_settings' => true,
            ],
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load Cash Desk management setup.');
}
