<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    cashRequireSchema($conn);

    $isAdmin = in_array($user['integrity'], ['Admin', 'Super_Admin'], true);

    if ($isAdmin) {
        $sql = "SELECT ca.*, 'MANAGER' AS access_level
                FROM cash_accounts ca
                WHERE ca.status = 'ACTIVE'
                ORDER BY (ca.account_code = 'MAIN-NGN') DESC, ca.account_name ASC";
        $stmt = $conn->prepare($sql);
    } else {
        $sql = "SELECT ca.*, cau.access_level
                FROM cash_account_users cau
                INNER JOIN cash_accounts ca ON ca.id = cau.account_id
                WHERE cau.user_id = ?
                  AND cau.is_active = 1
                  AND ca.status = 'ACTIVE'
                ORDER BY ca.account_name ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to load Cash Desk accounts.', 500);
        }
        $userId = (int) $user['id'];
        $stmt->bind_param('i', $userId);
    }

    if (!$stmt) {
        throw new RuntimeException('Unable to load Cash Desk accounts.', 500);
    }

    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($accounts === []) {
        throw new RuntimeException('No Cash Desk account has been assigned to this user.', 403);
    }

    foreach ($accounts as &$account) {
        $account['id'] = (int) $account['id'];
        $account['allow_negative_balance'] = (int) $account['allow_negative_balance'];
        $account['access_level'] = strtoupper((string) ($account['access_level'] ?? 'REVIEWER'));
        $account['cash_balance'] = cashGetBalance($conn, $account['id']);
        $account['pending_mutilated_cash'] = cashGetPendingMutilatedAmount($conn, $account['id']);
        $account['available_balance'] = cashGetUsableBalance($conn, $account['id']);
    }
    unset($account);

    $requestedAccountId = cashRequestAccountId();
    $activeAccount = cashResolveAccount($conn, $user, $requestedAccountId);
    $settings = cashGetSettings($conn, $activeAccount['id']);

    $categoryStmt = $conn->prepare("SELECT id, category_code, category_name, description, sort_order
                                    FROM cash_categories
                                    WHERE is_active = 1
                                    ORDER BY sort_order ASC, category_name ASC");
    if (!$categoryStmt) {
        throw new RuntimeException('Unable to load cash categories.', 500);
    }
    $categoryStmt->execute();
    $categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoryStmt->close();

    foreach ($categories as &$category) {
        $category['id'] = (int) $category['id'];
        $category['sort_order'] = (int) $category['sort_order'];
    }
    unset($category);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk setup loaded successfully.',
        'data' => [
            'accounts' => $accounts,
            'active_account' => [
                'id' => $activeAccount['id'],
                'account_code' => $activeAccount['account_code'],
                'account_name' => $activeAccount['account_name'],
                'currency' => $activeAccount['currency'],
                'access_level' => $activeAccount['access_level'],
                'cash_balance' => cashGetBalance($conn, $activeAccount['id']),
                'pending_mutilated_cash' => cashGetPendingMutilatedAmount($conn, $activeAccount['id']),
                'available_balance' => cashGetUsableBalance($conn, $activeAccount['id']),
            ],
            'categories' => $categories,
            'settings' => [
                'low_balance_threshold' => round((float) ($settings['low_balance_threshold'] ?? 0), 2),
                'default_iou_due_days' => (int) ($settings['default_iou_due_days'] ?? 7),
                'require_receipt_for_direct_expense' => (bool) ($settings['require_receipt_for_direct_expense'] ?? false),
                'allow_backdated_entries' => (bool) ($settings['allow_backdated_entries'] ?? true),
            ],
            'receipt_upload' => [
                'maximum_size_bytes' => 10485760,
                'allowed_mime_types' => array_keys(cashReceiptMimeMap()),
                'allowed_extensions' => array_values(cashReceiptMimeMap()),
            ],
            'permissions' => [
                'can_view' => true,
                'can_post' => $isAdmin || in_array($activeAccount['access_level'], ['MANAGER', 'CASHIER'], true),
                'can_upload_receipts' => $isAdmin || in_array($activeAccount['access_level'], ['MANAGER', 'CASHIER'], true),
                'can_close_day' => $isAdmin || in_array($activeAccount['access_level'], ['MANAGER', 'CASHIER'], true),
                'can_edit' => $isAdmin,
                'can_reverse' => $isAdmin || $activeAccount['access_level'] === 'MANAGER',
                'can_reopen_day' => $isAdmin || $activeAccount['access_level'] === 'MANAGER',
                'can_manage' => $isAdmin || $activeAccount['access_level'] === 'MANAGER',
            ],
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load the Cash Desk setup.');
}
