<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authService.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    requireTrustedRequestOrigin();
    $data = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $accountingPeriod = (int) ($data['accounting_period'] ?? date('Y'));

    if (!v::email()->validate($email) || $password === '') {
        throw new RuntimeException('Invalid email or password.', 401);
    }
    if ($accountingPeriod < 2000 || $accountingPeriod > ((int) date('Y') + 1)) {
        throw new RuntimeException('Invalid accounting period.', 400);
    }

    assertLoginNotRateLimited($conn, $email);

    $stmt = $conn->prepare('SELECT id, fname, lname, email, password, integrity, created_by, updated_by FROM user_table WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password'])) {
        recordLoginAttempt($conn, $email, false);
        throw new RuntimeException('Invalid email or password.', 401);
    }

    recordLoginAttempt($conn, $email, true);
    issueRefreshSession($conn, (int) $user['id'], $accountingPeriod);
    $csrfToken = issueCsrfCookie();
    $access = issueAccessToken($user, $accountingPeriod);

    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($log) {
        $userId = (int) $user['id'];
        $name = trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? ''));
        $action = ($name !== '' ? $name : $email) . ' logged in successfully';
        $log->bind_param('iss', $userId, $action, $email);
        $log->execute();
        $log->close();
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Login successful',
        'data' => array_merge(publicUserPayload($user, $accountingPeriod), [
            'token' => $access['token'],
            'token_expires_at' => $access['expires_at'],
            'csrf_token' => $csrfToken,
        ]),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Login error: ' . $error->getMessage());
    }
    jsonResponse(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to sign in at this time.' : $error->getMessage()], $status);
}
