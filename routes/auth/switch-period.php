<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/authService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    $claims = authenticateUser();
    $data = json_decode(file_get_contents('php://input'), true);
    $accountingPeriod = (int) ($data['accounting_period'] ?? 0);
    if ($accountingPeriod < 2000 || $accountingPeriod > ((int) date('Y') + 1)) {
        throw new RuntimeException('Invalid accounting period.', 400);
    }

    $stmt = $conn->prepare('SELECT id, fname, lname, email, integrity, created_by, updated_by FROM user_table WHERE id = ? LIMIT 1');
    $userId = (int) $claims['id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        throw new RuntimeException('User account no longer exists.', 401);
    }

    updateRefreshAccountingPeriod($conn, $userId, $accountingPeriod);
    $access = issueAccessToken($user, $accountingPeriod);
    jsonResponse([
        'status' => 'Success',
        'data' => array_merge(publicUserPayload($user, $accountingPeriod), [
            'token' => $access['token'],
            'token_expires_at' => $access['expires_at'],
        ]),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    jsonResponse(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to switch accounting period.' : $error->getMessage()], $status);
}
