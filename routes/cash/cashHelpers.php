<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

function cashRequireMethod(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== strtoupper($method)) {
        throw new RuntimeException('Route not found.', 405);
    }
}

function cashReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);

    if (!is_array($data)) {
        throw new InvalidArgumentException('Invalid request format. Expected a JSON object.', 400);
    }

    return $data;
}

function cashRequireSchema(mysqli $conn): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    $requiredTables = [
        'cash_accounts',
        'cash_account_users',
        'cash_categories',
        'cash_transactions',
        'cash_ious',
        'cash_iou_retirements',
        'cash_receipts',
        'cash_daily_closures',
        'cash_mutilated_cash',
        'cash_transaction_edits',
        'cash_settings',
    ];

    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $sql = "SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ({$placeholders})";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to verify Cash Desk storage.', 500);
    }

    $types = str_repeat('s', count($requiredTables));
    $schemaParams = $requiredTables;
    cashBindParams($stmt, $types, $schemaParams);
    $stmt->execute();
    $found = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'table_name');
    $stmt->close();

    $missing = array_values(array_diff($requiredTables, $found));
    if ($missing !== []) {
        throw new RuntimeException(
            'Cash Desk database migrations are incomplete. Apply the Cash Desk migrations through 20260716_004_cash_entry_edits_and_receipt_linked_mutilated_cash.sql.',
            503
        );
    }

    $verified = true;
}

function cashNullableText(mixed $value, int $maxLength = 0): ?string
{
    if ($value === null) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
        throw new InvalidArgumentException("A supplied text value exceeds {$maxLength} characters.", 422);
    }

    return $text;
}

function cashRequiredText(array $data, string $field, string $label, int $maxLength = 160): string
{
    $value = cashNullableText($data[$field] ?? null, $maxLength);
    if ($value === null) {
        throw new InvalidArgumentException("{$label} is required.", 422);
    }

    return $value;
}

function cashParseAmount(mixed $value, string $label = 'Amount'): float
{
    if (is_string($value)) {
        $value = str_replace([',', '₦', 'NGN', ' '], '', strtoupper(trim($value)));
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException("{$label} must be a valid number.", 422);
    }

    $amount = round((float) $value, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException("{$label} must be greater than zero.", 422);
    }

    if ($amount > 9999999999999999.99) {
        throw new InvalidArgumentException("{$label} is too large.", 422);
    }

    return $amount;
}

function cashParseIsoDate(mixed $value, string $label = 'Date', bool $allowFuture = false): string
{
    $date = trim((string) $value);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException("{$label} must use the YYYY-MM-DD format.", 422);
    }

    if (!$allowFuture && $parsed > new DateTimeImmutable('today')) {
        throw new InvalidArgumentException("{$label} cannot be in the future.", 422);
    }

    return $date;
}

function cashParseDate(mixed $value, string $label = 'Date'): string
{
    return cashParseIsoDate($value, $label, false);
}

function cashAssertAccountingPeriod(array $user, string $transactionDate): int
{
    $year = (int) substr($transactionDate, 0, 4);
    $accountingPeriod = (int) ($user['accounting_period'] ?? $year);

    if ($accountingPeriod > 0 && $year !== $accountingPeriod) {
        throw new RuntimeException(
            "The transaction date must fall within the active {$accountingPeriod} accounting year.",
            422
        );
    }

    return $year;
}

function cashCurrentUser(): array
{
    $user = authenticateUser();
    $user['id'] = (int) ($user['id'] ?? 0);
    $user['email'] = strtolower(trim((string) ($user['email'] ?? '')));
    $user['integrity'] = (string) ($user['integrity'] ?? '');

    if ($user['id'] <= 0 || $user['email'] === '') {
        throw new RuntimeException('Authentication required.', 401);
    }

    return $user;
}

function cashResolveAccount(mysqli $conn, array $user, ?int $requestedAccountId = null): array
{
    cashRequireSchema($conn);

    $isAdmin = in_array($user['integrity'], ['Admin', 'Super_Admin'], true);
    $accountId = (int) ($requestedAccountId ?? 0);

    if ($accountId > 0) {
        $sql = "SELECT ca.*, cau.access_level, cau.is_active AS assignment_active
                FROM cash_accounts ca
                LEFT JOIN cash_account_users cau
                  ON cau.account_id = ca.id AND cau.user_id = ?
                WHERE ca.id = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to resolve the cash account.', 500);
        }
        $userId = (int) $user['id'];
        $stmt->bind_param('ii', $userId, $accountId);
    } elseif ($isAdmin) {
        $sql = "SELECT ca.*, 'MANAGER' AS access_level, 1 AS assignment_active
                FROM cash_accounts ca
                WHERE ca.status = 'ACTIVE'
                ORDER BY (ca.account_code = 'MAIN-NGN') DESC, ca.id ASC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to resolve the cash account.', 500);
        }
    } else {
        $sql = "SELECT ca.*, cau.access_level, cau.is_active AS assignment_active
                FROM cash_account_users cau
                INNER JOIN cash_accounts ca ON ca.id = cau.account_id
                WHERE cau.user_id = ?
                  AND cau.is_active = 1
                  AND ca.status = 'ACTIVE'
                ORDER BY ca.id ASC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to resolve the cash account.', 500);
        }
        $userId = (int) $user['id'];
        $stmt->bind_param('i', $userId);
    }

    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account || strtoupper((string) ($account['status'] ?? '')) !== 'ACTIVE') {
        throw new RuntimeException('No active Cash Desk account is available.', 404);
    }

    if (!$isAdmin) {
        if ((int) ($account['assignment_active'] ?? 0) !== 1 || empty($account['access_level'])) {
            throw new RuntimeException('You do not have access to this Cash Desk account.', 403);
        }
    } else {
        $account['access_level'] = 'MANAGER';
    }

    $account['id'] = (int) $account['id'];
    $account['allow_negative_balance'] = (int) ($account['allow_negative_balance'] ?? 0);
    $account['access_level'] = strtoupper((string) ($account['access_level'] ?? 'REVIEWER'));

    return $account;
}

function cashAssertWriteAccess(array $user, array $account): void
{
    if (in_array($user['integrity'], ['Admin', 'Super_Admin'], true)) {
        return;
    }

    if (!in_array($account['access_level'], ['MANAGER', 'CASHIER'], true)) {
        throw new RuntimeException('You have read-only access to this Cash Desk account.', 403);
    }
}

function cashGetSettings(mysqli $conn, int $accountId): array
{
    $stmt = $conn->prepare('SELECT * FROM cash_settings WHERE account_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to read Cash Desk settings.', 500);
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $settings ?: [
        'account_id' => $accountId,
        'low_balance_threshold' => '100000.00',
        'default_iou_due_days' => 7,
        'require_receipt_for_direct_expense' => 0,
        'allow_backdated_entries' => 1,
    ];
}

function cashAssertDateIsOpen(mysqli $conn, int $accountId, string $transactionDate): void
{
    $stmt = $conn->prepare("SELECT id FROM cash_daily_closures WHERE account_id = ? AND closure_date = ? AND status = 'CLOSED' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the cash day.', 500);
    }
    $stmt->bind_param('is', $accountId, $transactionDate);
    $stmt->execute();
    $closed = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($closed) {
        throw new RuntimeException('This cash day has already been closed. Ask an administrator to reopen it before posting another entry.', 409);
    }
}

function cashTransactionAffectsBalance(string $transactionType): bool
{
    return !in_array(strtoupper($transactionType), [
        'MUTILATED_CASH_SET_ASIDE',
        'MUTILATED_CASH_REPLACEMENT',
    ], true);
}

function cashGetBalance(mysqli $conn, int $accountId, ?string $asOfDate = null): float
{
    $sql = "SELECT COALESCE(SUM(
                CASE
                    WHEN transaction_type IN ('MUTILATED_CASH_SET_ASIDE', 'MUTILATED_CASH_REPLACEMENT') THEN 0
                    WHEN direction = 'IN' THEN amount
                    ELSE -amount
                END
            ), 0) AS balance
            FROM cash_transactions
            WHERE account_id = ?
              AND status IN ('POSTED', 'REVERSED')";

    if ($asOfDate !== null) {
        $sql .= ' AND transaction_date <= ?';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate the Cash Desk balance.', 500);
    }

    if ($asOfDate !== null) {
        $stmt->bind_param('is', $accountId, $asOfDate);
    } else {
        $stmt->bind_param('i', $accountId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return round((float) ($row['balance'] ?? 0), 2);
}

function cashGetPendingMutilatedAmount(mysqli $conn, int $accountId, ?string $asOfDate = null): float
{
    if ($asOfDate !== null) {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS amount
                FROM cash_mutilated_cash
                WHERE account_id = ?
                  AND discovered_date <= ?
                  AND (return_date IS NULL OR return_date > ?)";
    } else {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS amount
                FROM cash_mutilated_cash
                WHERE account_id = ?
                  AND status = 'PENDING_RETURN'";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate pending mutilated cash.', 500);
    }
    if ($asOfDate !== null) {
        $stmt->bind_param('iss', $accountId, $asOfDate, $asOfDate);
    } else {
        $stmt->bind_param('i', $accountId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return round((float) ($row['amount'] ?? 0), 2);
}

function cashGetUsableBalance(mysqli $conn, int $accountId, ?string $asOfDate = null): float
{
    return round(cashGetBalance($conn, $accountId, $asOfDate) - cashGetPendingMutilatedAmount($conn, $accountId, $asOfDate), 2);
}

function cashAssertAdmin(array $user, string $message = 'Only an administrator can perform this action.'): void
{
    if (!in_array((string) ($user['integrity'] ?? ''), ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException($message, 403);
    }
}

function cashLockAccount(mysqli $conn, int $accountId): void
{
    $stmt = $conn->prepare("SELECT id FROM cash_accounts WHERE id = ? AND status = 'ACTIVE' FOR UPDATE");
    if (!$stmt) {
        throw new RuntimeException('Unable to lock the Cash Desk account.', 500);
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account) {
        throw new RuntimeException('The Cash Desk account is unavailable.', 409);
    }
}

function cashGenerateReference(mysqli $conn, string $prefix, string $date): string
{
    $datePart = str_replace('-', '', $date);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $reference = strtoupper($prefix . '-' . $datePart . '-' . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare('SELECT id FROM cash_transactions WHERE transaction_reference = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the cash transaction reference.', 500);
        }
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $reference;
        }
    }

    throw new RuntimeException('Unable to generate a unique cash transaction reference.', 500);
}

function cashGenerateIouReference(mysqli $conn, string $date): string
{
    $datePart = str_replace('-', '', $date);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $reference = strtoupper('IOU-' . $datePart . '-' . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare('SELECT id FROM cash_ious WHERE iou_reference = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the IOU reference.', 500);
        }
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $reference;
        }
    }

    throw new RuntimeException('Unable to generate a unique IOU reference.', 500);
}

function cashFindIdempotentTransaction(mysqli $conn, int $accountId, ?string $idempotencyKey): ?array
{
    if ($idempotencyKey === null) {
        return null;
    }

    $stmt = $conn->prepare('SELECT * FROM cash_transactions WHERE account_id = ? AND idempotency_key = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the request idempotency key.', 500);
    }
    $stmt->bind_param('is', $accountId, $idempotencyKey);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $transaction ?: null;
}

function cashInsertTransaction(mysqli $conn, array $payload): int
{
    $sql = "INSERT INTO cash_transactions (
                account_id,
                transaction_reference,
                transaction_date,
                transaction_type,
                direction,
                person_name,
                amount,
                reason,
                description,
                category_id,
                external_reference,
                disbursement_type,
                receipt_status,
                status,
                idempotency_key,
                accounting_year,
                created_by_user_id,
                created_by_email,
                metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'POSTED', ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the cash transaction.', 500);
    }

    $accountId = (int) $payload['account_id'];
    $transactionReference = (string) $payload['transaction_reference'];
    $transactionDate = (string) $payload['transaction_date'];
    $transactionType = (string) $payload['transaction_type'];
    $direction = (string) $payload['direction'];
    $personName = (string) $payload['person_name'];
    $amount = (float) $payload['amount'];
    $reason = $payload['reason'] ?? null;
    $description = $payload['description'] ?? null;
    $categoryId = isset($payload['category_id']) ? (int) $payload['category_id'] : null;
    $externalReference = $payload['external_reference'] ?? null;
    $disbursementType = $payload['disbursement_type'] ?? null;
    $receiptStatus = (string) $payload['receipt_status'];
    $idempotencyKey = $payload['idempotency_key'] ?? null;
    $accountingYear = (int) $payload['accounting_year'];
    $createdByUserId = (int) $payload['created_by_user_id'];
    $createdByEmail = (string) $payload['created_by_email'];
    $metadata = isset($payload['metadata'])
        ? json_encode($payload['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;

    $stmt->bind_param(
        'isssssdssissssiiss',
        $accountId,
        $transactionReference,
        $transactionDate,
        $transactionType,
        $direction,
        $personName,
        $amount,
        $reason,
        $description,
        $categoryId,
        $externalReference,
        $disbursementType,
        $receiptStatus,
        $idempotencyKey,
        $accountingYear,
        $createdByUserId,
        $createdByEmail,
        $metadata
    );

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to post the cash transaction: ' . $message, 500);
    }

    $transactionId = (int) $stmt->insert_id;
    $stmt->close();

    return $transactionId;
}

function cashFetchTransaction(mysqli $conn, int $transactionId): array
{
    $sql = "SELECT
                ct.*,
                ca.account_code,
                ca.account_name,
                ca.currency,
                cc.category_name,
                ci.id AS iou_id,
                ci.iou_reference,
                ci.status AS iou_status,
                ci.outstanding_amount AS iou_outstanding_amount,
                ci.expected_retirement_date
            FROM cash_transactions ct
            INNER JOIN cash_accounts ca ON ca.id = ct.account_id
            LEFT JOIN cash_categories cc ON cc.id = ct.category_id
            LEFT JOIN cash_ious ci ON ci.source_transaction_id = ct.id
            WHERE ct.id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to read the cash transaction.', 500);
    }
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$transaction) {
        throw new RuntimeException('Cash transaction not found.', 404);
    }

    foreach (['amount', 'iou_outstanding_amount'] as $field) {
        if (array_key_exists($field, $transaction) && $transaction[$field] !== null) {
            $transaction[$field] = round((float) $transaction[$field], 2);
        }
    }

    $transaction['id'] = (int) $transaction['id'];
    $transaction['account_id'] = (int) $transaction['account_id'];
    $transaction['category_id'] = $transaction['category_id'] !== null ? (int) $transaction['category_id'] : null;
    $transaction['iou_id'] = $transaction['iou_id'] !== null ? (int) $transaction['iou_id'] : null;

    return $transaction;
}

function cashLogAction(mysqli $conn, array $user, string $action): void
{
    $stmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Unable to write the audit log.', 500);
    }

    $userId = (int) $user['id'];
    $userEmail = (string) $user['email'];
    $stmt->bind_param('iss', $userId, $action, $userEmail);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to write the audit log.', 500);
    }
    $stmt->close();
}

function cashRequestAccountId(array $data = []): ?int
{
    $value = $data['account_id'] ?? $_GET['account_id'] ?? null;
    if ($value === null || $value === '') {
        return null;
    }

    $accountId = filter_var($value, FILTER_VALIDATE_INT);
    if ($accountId === false || $accountId <= 0) {
        throw new InvalidArgumentException('account_id must be a positive integer.', 422);
    }

    return (int) $accountId;
}

function cashIdempotencyKey(array $data): ?string
{
    $headerKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $bodyKey = trim((string) ($data['idempotency_key'] ?? ''));
    $key = $headerKey !== '' ? $headerKey : $bodyKey;

    if ($key === '') {
        return null;
    }

    if (mb_strlen($key) < 8 || mb_strlen($key) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
        throw new InvalidArgumentException('The idempotency key must be 8-100 letters, numbers, dots, underscores, colons or hyphens.', 422);
    }

    return $key;
}

function cashBindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $references = [];
    foreach ($params as $index => &$value) {
        $references[$index] = &$value;
    }
    unset($value);

    $stmt->bind_param($types, ...$references);
}

function cashHandleError(Throwable $error, string $publicFallback): never
{
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    if ($status >= 500) {
        error_log('Cash Desk error: ' . $error->getMessage());
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? $publicFallback : $error->getMessage(),
    ], $status);
}

function cashRequireIouActionsSchema(mysqli $conn): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    $stmt = $conn->prepare("SELECT 1
                            FROM information_schema.tables
                            WHERE table_schema = DATABASE()
                              AND table_name = 'cash_iou_actions'
                            LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the Cash Desk IOU activity storage.', 500);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
        throw new RuntimeException(
            'The Cash Desk IOU migration has not been applied. Import database/migrations/20260715_002_add_cash_iou_actions.sql.',
            503
        );
    }

    $verified = true;
}

function cashParseNonNegativeAmount(mixed $value, string $label): float
{
    if (is_string($value)) {
        $value = str_replace([',', '₦', 'NGN', ' '], '', strtoupper(trim($value)));
    }

    if ($value === null || $value === '' || !is_numeric($value)) {
        throw new InvalidArgumentException("{$label} must be a valid number.", 422);
    }

    $amount = round((float) $value, 2);
    if ($amount < 0) {
        throw new InvalidArgumentException("{$label} cannot be negative.", 422);
    }

    if ($amount > 9999999999999999.99) {
        throw new InvalidArgumentException("{$label} is too large.", 422);
    }

    return $amount;
}

function cashParseBoolean(mixed $value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    throw new InvalidArgumentException('A supplied boolean value is invalid.', 422);
}

function cashIouId(array $data = []): int
{
    $value = $data['iou_id'] ?? $data['id'] ?? $_GET['iou_id'] ?? $_GET['id'] ?? null;
    $iouId = filter_var($value, FILTER_VALIDATE_INT);

    if ($iouId === false || $iouId === null || $iouId <= 0) {
        throw new InvalidArgumentException('iou_id must be a positive integer.', 422);
    }

    return (int) $iouId;
}

function cashValidateIouReceiptStatus(mixed $value, ?string $fallback = null): string
{
    $status = strtoupper(trim((string) ($value ?? $fallback ?? 'PENDING')));
    if (!in_array($status, ['PENDING', 'PARTIAL', 'RECEIVED'], true)) {
        throw new InvalidArgumentException('receipt_status must be PENDING, PARTIAL or RECEIVED.', 422);
    }

    return $status;
}

function cashIouIsFinalizedStatus(string $status): bool
{
    return in_array(strtoupper($status), ['PENDING_CASH_RETURN', 'PENDING_REIMBURSEMENT', 'CLOSED'], true);
}

function cashNormalizeIouRow(array $iou): array
{
    foreach (['id', 'account_id', 'source_transaction_id', 'accounting_year', 'created_by_user_id'] as $field) {
        if (array_key_exists($field, $iou) && $iou[$field] !== null) {
            $iou[$field] = (int) $iou[$field];
        }
    }

    foreach (['closed_by_user_id', 'category_id', 'receipt_count'] as $field) {
        if (array_key_exists($field, $iou)) {
            $iou[$field] = $iou[$field] !== null ? (int) $iou[$field] : null;
        }
    }

    foreach (['amount_advanced', 'actual_amount_spent', 'amount_returned', 'reimbursement_paid', 'outstanding_amount'] as $field) {
        if (array_key_exists($field, $iou) && $iou[$field] !== null) {
            $iou[$field] = round((float) $iou[$field], 2);
        }
    }

    $advanced = (float) ($iou['amount_advanced'] ?? 0);
    $spent = (float) ($iou['actual_amount_spent'] ?? 0);
    $returned = (float) ($iou['amount_returned'] ?? 0);
    $reimbursed = (float) ($iou['reimbursement_paid'] ?? 0);
    $netCashRetained = round($advanced + $reimbursed - $returned, 2);
    $difference = round($spent - $netCashRetained, 2);
    $status = strtoupper((string) ($iou['status'] ?? 'OPEN'));
    $isFinalized = cashIouIsFinalizedStatus($status);

    $iou['status'] = $status;
    $iou['display_status'] = $status;
    if (
        !$isFinalized
        && !in_array($status, ['CLOSED', 'REVERSED'], true)
        && !empty($iou['expected_retirement_date'])
        && (string) $iou['expected_retirement_date'] < date('Y-m-d')
    ) {
        $iou['display_status'] = 'OVERDUE';
    }

    $iou['is_finalized'] = $isFinalized;
    $iou['net_cash_retained'] = $netCashRetained;
    $iou['settlement_difference'] = $difference;
    $iou['cash_return_due'] = round(max(0, -$difference), 2);
    $iou['reimbursement_due'] = round(max(0, $difference), 2);
    $iou['remaining_unretired'] = $isFinalized
        ? 0.0
        : round(max(0, $netCashRetained - $spent), 2);

    return $iou;
}

function cashFetchIou(mysqli $conn, int $iouId, int $accountId): array
{
    $sql = "SELECT
                ci.*,
                ct.transaction_reference AS source_transaction_reference,
                ct.transaction_date AS source_transaction_date,
                ct.amount AS source_transaction_amount,
                ct.category_id,
                ct.external_reference,
                ct.created_at AS disbursed_at,
                cc.category_name,
                ca.account_code,
                ca.account_name,
                ca.currency,
                (
                    SELECT COUNT(*)
                    FROM cash_receipts cr
                    WHERE cr.iou_id = ci.id AND cr.status = 'ACTIVE'
                ) AS receipt_count
            FROM cash_ious ci
            INNER JOIN cash_transactions ct ON ct.id = ci.source_transaction_id
            INNER JOIN cash_accounts ca ON ca.id = ci.account_id
            LEFT JOIN cash_categories cc ON cc.id = ct.category_id
            WHERE ci.id = ? AND ci.account_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to read the IOU.', 500);
    }
    $stmt->bind_param('ii', $iouId, $accountId);
    $stmt->execute();
    $iou = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$iou) {
        throw new RuntimeException('Cash IOU not found.', 404);
    }

    return cashNormalizeIouRow($iou);
}

function cashLockIou(mysqli $conn, int $iouId, int $accountId): array
{
    $sql = "SELECT
                ci.*,
                ct.transaction_date AS source_transaction_date,
                ct.transaction_reference AS source_transaction_reference,
                ct.category_id
            FROM cash_ious ci
            INNER JOIN cash_transactions ct ON ct.id = ci.source_transaction_id
            WHERE ci.id = ? AND ci.account_id = ?
            FOR UPDATE";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to lock the IOU.', 500);
    }
    $stmt->bind_param('ii', $iouId, $accountId);
    $stmt->execute();
    $iou = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$iou) {
        throw new RuntimeException('Cash IOU not found.', 404);
    }

    return cashNormalizeIouRow($iou);
}

function cashAssertIouActionDate(array $iou, string $actionDate): void
{
    $sourceDate = (string) ($iou['source_transaction_date'] ?? '');
    if ($sourceDate !== '' && $actionDate < $sourceDate) {
        throw new InvalidArgumentException('The IOU action date cannot be earlier than the original disbursement date.', 422);
    }
}

function cashAssertIouOpenForRetirement(array $iou): void
{
    $status = strtoupper((string) ($iou['status'] ?? 'OPEN'));
    if (in_array($status, ['CLOSED', 'REVERSED'], true)) {
        throw new RuntimeException('This IOU is already closed and cannot be changed.', 409);
    }
    if (cashIouIsFinalizedStatus($status)) {
        throw new RuntimeException('This IOU has already been finalised. Complete the pending cash return or reimbursement instead.', 409);
    }
}

function cashCalculateIouState(array $iou, bool $finalized): array
{
    $advanced = round((float) ($iou['amount_advanced'] ?? 0), 2);
    $spent = round((float) ($iou['actual_amount_spent'] ?? 0), 2);
    $returned = round((float) ($iou['amount_returned'] ?? 0), 2);
    $reimbursed = round((float) ($iou['reimbursement_paid'] ?? 0), 2);
    $netCashRetained = round($advanced + $reimbursed - $returned, 2);
    $difference = round($spent - $netCashRetained, 2);

    if ($finalized) {
        if (abs($difference) < 0.01) {
            return ['status' => 'CLOSED', 'outstanding_amount' => 0.0, 'difference' => 0.0];
        }

        if ($difference > 0) {
            return [
                'status' => 'PENDING_REIMBURSEMENT',
                'outstanding_amount' => round($difference, 2),
                'difference' => $difference,
            ];
        }

        return [
            'status' => 'PENDING_CASH_RETURN',
            'outstanding_amount' => round(abs($difference), 2),
            'difference' => $difference,
        ];
    }

    $hasActivity = $spent > 0 || $returned > 0 || $reimbursed > 0;
    return [
        'status' => $hasActivity ? 'PARTIALLY_RETIRED' : 'OPEN',
        'outstanding_amount' => round(max(0, $netCashRetained - $spent), 2),
        'difference' => $difference,
    ];
}

function cashUpdateIouState(mysqli $conn, array $iou, array $user, bool $finalized): array
{
    $state = cashCalculateIouState($iou, $finalized);
    $iouId = (int) $iou['id'];
    $spent = round((float) $iou['actual_amount_spent'], 2);
    $returned = round((float) $iou['amount_returned'], 2);
    $reimbursed = round((float) $iou['reimbursement_paid'], 2);
    $outstanding = round((float) $state['outstanding_amount'], 2);
    $status = (string) $state['status'];

    if ($status === 'CLOSED') {
        $sql = "UPDATE cash_ious
                SET actual_amount_spent = ?,
                    amount_returned = ?,
                    reimbursement_paid = ?,
                    outstanding_amount = ?,
                    status = ?,
                    closed_by_user_id = ?,
                    closed_by_email = ?,
                    closed_at = NOW()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to close the IOU.', 500);
        }
        $userId = (int) $user['id'];
        $userEmail = (string) $user['email'];
        $stmt->bind_param('ddddsisi', $spent, $returned, $reimbursed, $outstanding, $status, $userId, $userEmail, $iouId);
    } else {
        $sql = "UPDATE cash_ious
                SET actual_amount_spent = ?,
                    amount_returned = ?,
                    reimbursement_paid = ?,
                    outstanding_amount = ?,
                    status = ?,
                    closed_by_user_id = NULL,
                    closed_by_email = NULL,
                    closed_at = NULL
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to update the IOU.', 500);
        }
        $stmt->bind_param('ddddsi', $spent, $returned, $reimbursed, $outstanding, $status, $iouId);
    }

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to update the IOU: ' . $message, 500);
    }
    $stmt->close();

    return $state;
}

function cashSetIouReceiptStatus(mysqli $conn, array $iou, string $receiptStatus): void
{
    $iouId = (int) $iou['id'];
    $sourceTransactionId = (int) $iou['source_transaction_id'];

    $stmt = $conn->prepare('UPDATE cash_ious SET receipt_status = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to update the IOU receipt status.', 500);
    }
    $stmt->bind_param('si', $receiptStatus, $iouId);
    $stmt->execute();
    $stmt->close();

    $transactionStmt = $conn->prepare('UPDATE cash_transactions SET receipt_status = ? WHERE id = ?');
    if (!$transactionStmt) {
        throw new RuntimeException('Unable to update the transaction receipt status.', 500);
    }
    $transactionStmt->bind_param('si', $receiptStatus, $sourceTransactionId);
    $transactionStmt->execute();
    $transactionStmt->close();
}

function cashGenerateIouActionReference(mysqli $conn, string $prefix, string $date): string
{
    cashRequireIouActionsSchema($conn);
    $datePart = str_replace('-', '', $date);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $reference = strtoupper($prefix . '-' . $datePart . '-' . bin2hex(random_bytes(4)));
        $stmt = $conn->prepare('SELECT id FROM cash_iou_actions WHERE action_reference = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the IOU action reference.', 500);
        }
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $reference;
        }
    }

    throw new RuntimeException('Unable to generate a unique IOU action reference.', 500);
}

function cashFindIouActionByIdempotency(mysqli $conn, int $iouId, ?string $idempotencyKey): ?array
{
    if ($idempotencyKey === null) {
        return null;
    }

    cashRequireIouActionsSchema($conn);
    $stmt = $conn->prepare('SELECT id FROM cash_iou_actions WHERE iou_id = ? AND idempotency_key = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the IOU request idempotency key.', 500);
    }
    $stmt->bind_param('is', $iouId, $idempotencyKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? cashFetchIouAction($conn, (int) $row['id']) : null;
}

function cashInsertIouAction(mysqli $conn, array $payload): int
{
    cashRequireIouActionsSchema($conn);

    $sql = "INSERT INTO cash_iou_actions (
                iou_id,
                action_reference,
                action_type,
                action_date,
                amount_spent,
                cash_returned,
                reimbursement_paid,
                linked_transaction_id,
                is_final_submission,
                note,
                idempotency_key,
                created_by_user_id,
                created_by_email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the IOU activity.', 500);
    }

    $iouId = (int) $payload['iou_id'];
    $reference = (string) $payload['action_reference'];
    $actionType = (string) $payload['action_type'];
    $actionDate = (string) $payload['action_date'];
    $amountSpent = round((float) ($payload['amount_spent'] ?? 0), 2);
    $cashReturned = round((float) ($payload['cash_returned'] ?? 0), 2);
    $reimbursementPaid = round((float) ($payload['reimbursement_paid'] ?? 0), 2);
    $linkedTransactionId = isset($payload['linked_transaction_id']) ? (int) $payload['linked_transaction_id'] : null;
    $isFinal = !empty($payload['is_final_submission']) ? 1 : 0;
    $note = $payload['note'] ?? null;
    $idempotencyKey = $payload['idempotency_key'] ?? null;
    $createdByUserId = (int) $payload['created_by_user_id'];
    $createdByEmail = (string) $payload['created_by_email'];

    $stmt->bind_param(
        'isssdddiissis',
        $iouId,
        $reference,
        $actionType,
        $actionDate,
        $amountSpent,
        $cashReturned,
        $reimbursementPaid,
        $linkedTransactionId,
        $isFinal,
        $note,
        $idempotencyKey,
        $createdByUserId,
        $createdByEmail
    );

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save the IOU activity: ' . $message, 500);
    }
    $actionId = (int) $stmt->insert_id;
    $stmt->close();

    $historyStmt = $conn->prepare("INSERT INTO cash_iou_retirements (
            iou_id,
            retirement_date,
            actual_amount_spent,
            cash_returned,
            reimbursement_paid,
            note,
            created_by_user_id,
            created_by_email
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$historyStmt) {
        throw new RuntimeException('Unable to save the IOU retirement history.', 500);
    }
    $historyStmt->bind_param(
        'isdddsis',
        $iouId,
        $actionDate,
        $amountSpent,
        $cashReturned,
        $reimbursementPaid,
        $note,
        $createdByUserId,
        $createdByEmail
    );
    if (!$historyStmt->execute()) {
        $message = $historyStmt->error;
        $historyStmt->close();
        throw new RuntimeException('Unable to save the IOU retirement history: ' . $message, 500);
    }
    $historyStmt->close();

    return $actionId;
}

function cashFetchIouAction(mysqli $conn, int $actionId): array
{
    $sql = "SELECT
                cia.*,
                ct.transaction_reference AS linked_transaction_reference,
                ct.direction AS linked_transaction_direction,
                ct.amount AS linked_transaction_amount
            FROM cash_iou_actions cia
            LEFT JOIN cash_transactions ct ON ct.id = cia.linked_transaction_id
            WHERE cia.id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to read the IOU activity.', 500);
    }
    $stmt->bind_param('i', $actionId);
    $stmt->execute();
    $action = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$action) {
        throw new RuntimeException('IOU activity not found.', 404);
    }

    foreach (['id', 'iou_id', 'created_by_user_id'] as $field) {
        $action[$field] = (int) $action[$field];
    }
    $action['linked_transaction_id'] = $action['linked_transaction_id'] !== null
        ? (int) $action['linked_transaction_id']
        : null;
    $action['is_final_submission'] = (bool) $action['is_final_submission'];
    foreach (['amount_spent', 'cash_returned', 'reimbursement_paid', 'linked_transaction_amount'] as $field) {
        if ($action[$field] !== null) {
            $action[$field] = round((float) $action[$field], 2);
        }
    }

    return $action;
}

function cashFetchIouActions(mysqli $conn, int $iouId): array
{
    cashRequireIouActionsSchema($conn);
    $sql = "SELECT
                cia.*,
                ct.transaction_reference AS linked_transaction_reference,
                ct.direction AS linked_transaction_direction,
                ct.amount AS linked_transaction_amount
            FROM cash_iou_actions cia
            LEFT JOIN cash_transactions ct ON ct.id = cia.linked_transaction_id
            WHERE cia.iou_id = ?
            ORDER BY cia.action_date DESC, cia.id DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load the IOU activity.', 500);
    }
    $stmt->bind_param('i', $iouId);
    $stmt->execute();
    $actions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($actions as &$action) {
        foreach (['id', 'iou_id', 'created_by_user_id'] as $field) {
            $action[$field] = (int) $action[$field];
        }
        $action['linked_transaction_id'] = $action['linked_transaction_id'] !== null
            ? (int) $action['linked_transaction_id']
            : null;
        $action['is_final_submission'] = (bool) $action['is_final_submission'];
        foreach (['amount_spent', 'cash_returned', 'reimbursement_paid', 'linked_transaction_amount'] as $field) {
            if ($action[$field] !== null) {
                $action[$field] = round((float) $action[$field], 2);
            }
        }
    }
    unset($action);

    return $actions;
}

function cashAssertManageAccess(array $user, array $account): void
{
    if (in_array($user['integrity'], ['Admin', 'Super_Admin'], true)) {
        return;
    }

    if (($account['access_level'] ?? '') !== 'MANAGER') {
        throw new RuntimeException('Cash Desk manager access is required for this action.', 403);
    }
}

function cashTransactionId(array $data = []): int
{
    $value = $data['transaction_id'] ?? $data['id'] ?? $_GET['transaction_id'] ?? $_GET['id'] ?? null;
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) {
        throw new InvalidArgumentException('transaction_id must be a positive integer.', 422);
    }

    return (int) $id;
}

function cashReceiptId(array $data = []): int
{
    $value = $data['receipt_id'] ?? $data['id'] ?? $_GET['receipt_id'] ?? $_GET['id'] ?? null;
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) {
        throw new InvalidArgumentException('receipt_id must be a positive integer.', 422);
    }

    return (int) $id;
}

function cashClosureId(array $data = []): int
{
    $value = $data['closure_id'] ?? $data['id'] ?? $_GET['closure_id'] ?? $_GET['id'] ?? null;
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) {
        throw new InvalidArgumentException('closure_id must be a positive integer.', 422);
    }

    return (int) $id;
}

function cashMutilatedCashId(array $data = []): int
{
    $value = $data['mutilated_cash_id'] ?? $data['id'] ?? $_GET['mutilated_cash_id'] ?? $_GET['id'] ?? null;
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) {
        throw new InvalidArgumentException('mutilated_cash_id must be a positive integer.', 422);
    }

    return (int) $id;
}

function cashNormalizeMutilatedCashRow(array $row): array
{
    foreach ([
        'id',
        'account_id',
        'source_transaction_id',
        'source_receipt_transaction_id',
        'linked_disbursement_transaction_id',
        'set_aside_transaction_id',
        'replacement_transaction_id',
        'resolution_transaction_id',
        'accounting_year',
        'discovered_by_user_id',
        'resolved_by_user_id',
    ] as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
        }
    }

    foreach (['amount', 'source_receipt_amount', 'source_disbursement_amount', 'source_receipt_mutilated_amount', 'source_receipt_remaining_amount'] as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null) {
            $row[$field] = round((float) $row[$field], 2);
        }
    }

    $row['status'] = strtoupper((string) ($row['status'] ?? 'PENDING_RETURN'));
    $row['resolution_type'] = $row['resolution_type'] !== null
        ? strtoupper((string) $row['resolution_type'])
        : null;
    $row['is_legacy_disbursement_link'] = empty($row['source_receipt_transaction_id']);

    return $row;
}

function cashMutilatedCashSelectSql(): string
{
    return "SELECT
            cmc.*,
            source_receipt.transaction_reference AS source_receipt_reference,
            source_receipt.transaction_date AS source_receipt_date,
            source_receipt.person_name AS source_receipt_person,
            source_receipt.amount AS source_receipt_amount,
            source_receipt.transaction_type AS source_receipt_type,
            linked_disbursement.transaction_reference AS linked_disbursement_reference,
            linked_disbursement.transaction_date AS linked_disbursement_date,
            linked_disbursement.person_name AS recipient_name,
            linked_disbursement.amount AS source_disbursement_amount,
            reserve_tx.transaction_reference AS set_aside_reference,
            resolution_tx.transaction_reference AS resolution_reference,
            replacement_tx.transaction_reference AS replacement_reference
        FROM cash_mutilated_cash cmc
        LEFT JOIN cash_transactions source_receipt
          ON source_receipt.id = COALESCE(cmc.source_receipt_transaction_id,
             CASE WHEN cmc.linked_disbursement_transaction_id IS NULL THEN cmc.source_transaction_id ELSE NULL END)
        LEFT JOIN cash_transactions linked_disbursement
          ON linked_disbursement.id = COALESCE(cmc.linked_disbursement_transaction_id,
             CASE WHEN cmc.source_receipt_transaction_id IS NULL THEN cmc.source_transaction_id ELSE NULL END)
        INNER JOIN cash_transactions reserve_tx ON reserve_tx.id = cmc.set_aside_transaction_id
        LEFT JOIN cash_transactions resolution_tx ON resolution_tx.id = cmc.resolution_transaction_id
        LEFT JOIN cash_transactions replacement_tx ON replacement_tx.id = cmc.replacement_transaction_id";
}

function cashFetchMutilatedCash(mysqli $conn, int $recordId): array
{
    $sql = cashMutilatedCashSelectSql() . ' WHERE cmc.id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to read the mutilated cash record.', 500);
    }
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        throw new RuntimeException('Mutilated cash record not found.', 404);
    }

    return cashNormalizeMutilatedCashRow($record);
}

function cashFetchMutilatedCashBySource(mysqli $conn, int $sourceTransactionId): ?array
{
    $stmt = $conn->prepare("SELECT id
                           FROM cash_mutilated_cash
                           WHERE source_receipt_transaction_id = ?
                              OR (source_receipt_transaction_id IS NULL AND source_transaction_id = ?)
                           ORDER BY id DESC
                           LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to read the linked mutilated cash record.', 500);
    }
    $stmt->bind_param('ii', $sourceTransactionId, $sourceTransactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? cashFetchMutilatedCash($conn, (int) $row['id']) : null;
}

function cashFetchLegacyMutilatedCashByDisbursement(mysqli $conn, int $transactionId): ?array
{
    $stmt = $conn->prepare("SELECT id
                           FROM cash_mutilated_cash
                           WHERE source_receipt_transaction_id IS NULL
                             AND linked_disbursement_transaction_id = ?
                           ORDER BY id DESC
                           LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to read the legacy mutilated cash record.', 500);
    }
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? cashFetchMutilatedCash($conn, (int) $row['id']) : null;
}

function cashFetchTransactionEditHistory(mysqli $conn, int $transactionId): array
{
    $stmt = $conn->prepare("SELECT id, correction_reason, old_values, new_values, edited_by_user_id, edited_by_email, edited_at
                           FROM cash_transaction_edits
                           WHERE transaction_id = ?
                           ORDER BY edited_at DESC, id DESC");
    if (!$stmt) {
        throw new RuntimeException('Unable to read the cash transaction edit history.', 500);
    }
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['edited_by_user_id'] = (int) $row['edited_by_user_id'];
        $row['old_values'] = json_decode((string) $row['old_values'], true) ?: [];
        $row['new_values'] = json_decode((string) $row['new_values'], true) ?: [];
    }
    unset($row);

    return $rows;
}

function cashReceiptStorageRoot(): string
{
    $root = dirname(__DIR__, 2) . '/storage/cash-receipts';
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to prepare receipt storage.', 500);
    }

    return $root;
}

function cashReceiptMimeMap(): array
{
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function cashReceiptPublicName(string $originalName): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalName));
    $name = trim((string) $name, '.-_');
    return $name !== '' ? mb_substr($name, 0, 180) : 'receipt';
}

function cashFetchReceipt(mysqli $conn, int $receiptId, int $accountId, bool $includeArchived = false): array
{
    $statusSql = $includeArchived ? '' : " AND cr.status = 'ACTIVE'";
    $sql = "SELECT
                cr.*,
                ct.account_id AS transaction_account_id,
                ct.transaction_reference,
                ct.transaction_date,
                ct.person_name,
                ct.amount AS transaction_amount,
                ci.account_id AS iou_account_id,
                ci.iou_reference,
                ci.recipient_name
            FROM cash_receipts cr
            LEFT JOIN cash_transactions ct ON ct.id = cr.transaction_id
            LEFT JOIN cash_ious ci ON ci.id = cr.iou_id
            WHERE cr.id = ?
              AND COALESCE(ct.account_id, ci.account_id) = ?{$statusSql}
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to read the receipt.', 500);
    }
    $stmt->bind_param('ii', $receiptId, $accountId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$receipt) {
        throw new RuntimeException('Receipt not found.', 404);
    }

    foreach (['id', 'transaction_id', 'iou_id', 'uploaded_by_user_id', 'file_size'] as $field) {
        if (array_key_exists($field, $receipt)) {
            $receipt[$field] = $receipt[$field] !== null ? (int) $receipt[$field] : null;
        }
    }
    if ($receipt['transaction_amount'] !== null) {
        $receipt['transaction_amount'] = round((float) $receipt['transaction_amount'], 2);
    }

    return $receipt;
}

function cashRefreshReceiptStatus(mysqli $conn, ?int $transactionId, ?int $iouId, ?string $requestedStatus = null): void
{
    $status = $requestedStatus !== null ? cashValidateIouReceiptStatus($requestedStatus) : null;

    if ($iouId !== null) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM cash_receipts WHERE iou_id = ? AND status = 'ACTIVE'");
        if (!$countStmt) {
            throw new RuntimeException('Unable to count IOU receipts.', 500);
        }
        $countStmt->bind_param('i', $iouId);
        $countStmt->execute();
        $count = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $iouStmt = $conn->prepare('SELECT source_transaction_id, status, receipt_status FROM cash_ious WHERE id = ? LIMIT 1');
        if (!$iouStmt) {
            throw new RuntimeException('Unable to read the IOU receipt state.', 500);
        }
        $iouStmt->bind_param('i', $iouId);
        $iouStmt->execute();
        $iou = $iouStmt->get_result()->fetch_assoc();
        $iouStmt->close();
        if (!$iou) {
            throw new RuntimeException('Cash IOU not found.', 404);
        }

        if ($status === null) {
            if ($count === 0) {
                $status = 'PENDING';
            } elseif (strtoupper((string) $iou['status']) === 'CLOSED') {
                $status = 'RECEIVED';
            } else {
                $status = 'PARTIAL';
            }
        }

        cashSetIouReceiptStatus($conn, ['id' => $iouId, 'source_transaction_id' => (int) $iou['source_transaction_id']], $status);
        return;
    }

    if ($transactionId !== null) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM cash_receipts WHERE transaction_id = ? AND status = 'ACTIVE'");
        if (!$countStmt) {
            throw new RuntimeException('Unable to count transaction receipts.', 500);
        }
        $countStmt->bind_param('i', $transactionId);
        $countStmt->execute();
        $count = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $status = $status ?? ($count > 0 ? 'RECEIVED' : 'PENDING');
        $stmt = $conn->prepare('UPDATE cash_transactions SET receipt_status = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to update the transaction receipt status.', 500);
        }
        $stmt->bind_param('si', $status, $transactionId);
        $stmt->execute();
        $stmt->close();
    }
}

function cashGetDayMovements(mysqli $conn, int $accountId, string $date): array
{
    $sql = "SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'CASH_RECEIPT' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS cash_received,
            COALESCE(SUM(CASE WHEN transaction_type = 'CASH_RETURN' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS cash_returned,
            COALESCE(SUM(CASE WHEN transaction_type IN ('DIRECT_DISBURSEMENT', 'IOU_DISBURSEMENT') AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS cash_disbursed,
            COALESCE(SUM(CASE WHEN transaction_type = 'REIMBURSEMENT' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS reimbursements_paid,
            COALESCE(SUM(CASE WHEN transaction_type = 'REVERSAL' AND direction = 'IN' THEN amount ELSE 0 END), 0) AS adjustments_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'REVERSAL' AND direction = 'OUT' THEN amount ELSE 0 END), 0) AS adjustments_out,
            COUNT(*) AS transaction_count
        FROM cash_transactions
        WHERE account_id = ?
          AND transaction_date = ?
          AND status IN ('POSTED', 'REVERSED')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to calculate daily cash movements.', 500);
    }
    $stmt->bind_param('is', $accountId, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach (['cash_received', 'cash_returned', 'cash_disbursed', 'reimbursements_paid', 'adjustments_in', 'adjustments_out'] as $field) {
        $row[$field] = round((float) ($row[$field] ?? 0), 2);
    }
    $row['transaction_count'] = (int) ($row['transaction_count'] ?? 0);

    return $row;
}

function cashAssertEntryDateAllowed(mysqli $conn, int $accountId, string $entryDate): void
{
    $settings = cashGetSettings($conn, $accountId);
    if (!(bool) ($settings['allow_backdated_entries'] ?? true) && $entryDate !== date('Y-m-d')) {
        throw new RuntimeException('Backdated Cash Desk entries are disabled for this account.', 409);
    }
}

function cashFindPossibleDuplicate(mysqli $conn, int $accountId, string $date, string $personName, float $amount, string $direction): ?array
{
    $stmt = $conn->prepare("SELECT id, transaction_reference, transaction_type, transaction_date, person_name, amount, created_at
                            FROM cash_transactions
                            WHERE account_id = ?
                              AND transaction_date = ?
                              AND LOWER(TRIM(person_name)) = LOWER(TRIM(?))
                              AND ABS(amount - ?) < 0.01
                              AND direction = ?
                              AND status = 'POSTED'
                            ORDER BY id DESC
                            LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to check for a possible duplicate cash entry.', 500);
    }
    $stmt->bind_param('issds', $accountId, $date, $personName, $amount, $direction);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        $row['id'] = (int) $row['id'];
        $row['amount'] = round((float) $row['amount'], 2);
    }

    return $row;
}
