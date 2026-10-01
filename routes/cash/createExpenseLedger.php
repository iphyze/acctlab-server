<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertManageAccess($user, $account);

    $name = cashRequiredText($data, 'name', 'Ledger name', 255);
    $code = cashRequiredText($data, 'code', 'Ledger code', 255);
    $summary = cashNullableText($data['summary'] ?? null, 255) ?? '';

    $duplicate = $conn->prepare("SELECT id FROM expense_ledger WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?)) OR TRIM(supplier_number) = TRIM(?) LIMIT 1");
    if (!$duplicate) {
        throw new RuntimeException('Unable to validate the expense ledger.', 500);
    }
    $duplicate->bind_param('ss', $name, $code);
    $duplicate->execute();
    $exists = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($exists) {
        throw new InvalidArgumentException('An expense ledger with that name or code already exists.', 409);
    }

    $stmt = $conn->prepare('INSERT INTO expense_ledger (supplier_name, summary, supplier_number) VALUES (?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Unable to create the expense ledger.', 500);
    }
    $stmt->bind_param('sss', $name, $summary, $code);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    cashLogAction($conn, $user, sprintf('%s created expense ledger %s (%s) from Cash Desk.', $user['email'], $name, $code));
    jsonResponse([
        'status' => 'Success',
        'message' => 'Expense ledger created successfully.',
        'data' => [
            'ledger' => [
                'id' => $id,
                'supplier_name' => $name,
                'supplier_number' => $code,
                'summary' => $summary,
            ],
        ],
    ], 201);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to create the expense ledger.');
}
