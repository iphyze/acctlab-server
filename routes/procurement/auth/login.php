<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthService.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    requireTrustedRequestOrigin();
    $data = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if (!v::email()->validate($email) || $password === '') {
        throw new RuntimeException('Invalid email or password.', 401);
    }

    procurementAssertLoginNotRateLimited($conn, $email);

    $stmt = $conn->prepare(
        "SELECT u.id, u.fname, u.lname, u.email, u.password, u.department, u.status, pua.role, pua.is_active
         FROM user_table u
         INNER JOIN procurement_user_access pua ON pua.user_id = u.id
         WHERE u.email = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $valid = $user
        && password_verify($password, (string) $user['password'])
        && strcasecmp((string) $user['status'], 'Active') === 0
        && (int) $user['is_active'] === 1
        && in_array((string) $user['role'], PROCUREMENT_ROLES, true);

    if (!$valid) {
        procurementRecordLoginAttempt($conn, $email, false);
        throw new RuntimeException('Invalid email or password.', 401);
    }
    if (!procurementUserCanAccessApp($user)) {
        procurementRecordLoginAttempt($conn, $email, false);
        throw new RuntimeException('ProcureDesk is restricted to the procurement department.', 403);
    }

    procurementRecordLoginAttempt($conn, $email, true);
    procurementIssueRefreshSession($conn, (int) $user['id'], false);
    $csrfToken = procurementIssueCsrfCookie();
    $permissions = procurementPermissionCodes($conn, (string) $user['role'], (int) $user['id']);
    $access = procurementIssueAccessToken($user, $permissions);

    $name = trim((string) $user['fname'] . ' ' . (string) $user['lname']);
    procurementWriteAuditLog(
        $conn,
        (int) $user['id'],
        $email,
        ($name !== '' ? $name : $email) . ' logged in to the procurement app'
    );

    jsonResponse([
        'status' => 'Success',
        'message' => 'Login successful.',
        'data' => array_merge(procurementPublicUserPayload($user, $permissions), [
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
        error_log('Procurement login error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to sign in at this time.' : $error->getMessage(),
    ], $status);
}
