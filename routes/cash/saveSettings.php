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

    $thresholdRaw = $data['low_balance_threshold'] ?? 0;
    $threshold = cashParseNonNegativeAmount($thresholdRaw, 'Low-balance threshold');
    $dueDays = isset($data['default_iou_due_days']) ? (int) $data['default_iou_due_days'] : 7;
    if ($dueDays < 1 || $dueDays > 365) {
        throw new InvalidArgumentException('default_iou_due_days must be between 1 and 365.', 422);
    }
    $requireReceipt = cashParseBoolean($data['require_receipt_for_direct_expense'] ?? false) ? 1 : 0;
    $allowBackdated = cashParseBoolean($data['allow_backdated_entries'] ?? true, true) ? 1 : 0;
    $email = (string) $user['email'];

    $stmt = $conn->prepare("INSERT INTO cash_settings (
            account_id, low_balance_threshold, default_iou_due_days,
            require_receipt_for_direct_expense, allow_backdated_entries, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            low_balance_threshold = VALUES(low_balance_threshold),
            default_iou_due_days = VALUES(default_iou_due_days),
            require_receipt_for_direct_expense = VALUES(require_receipt_for_direct_expense),
            allow_backdated_entries = VALUES(allow_backdated_entries),
            updated_by = VALUES(updated_by)");
    if (!$stmt) {
        throw new RuntimeException('Unable to save Cash Desk settings.', 500);
    }
    $stmt->bind_param('idiiis', $accountId, $threshold, $dueDays, $requireReceipt, $allowBackdated, $email);
    $stmt->execute();
    $stmt->close();

    cashLogAction($conn, $user, sprintf('%s updated settings for Cash Desk account %s.', $email, $account['account_name']));
    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk settings updated successfully.',
        'data' => [
            'account_id' => $accountId,
            'low_balance_threshold' => $threshold,
            'default_iou_due_days' => $dueDays,
            'require_receipt_for_direct_expense' => (bool) $requireReceipt,
            'allow_backdated_entries' => (bool) $allowBackdated,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to save Cash Desk settings.');
}
