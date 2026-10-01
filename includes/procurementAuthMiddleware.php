<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/procurementAuthService.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function procurementBearerTokenFromRequest(): string
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }

    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Authentication required.'], 401);
    }

    return $matches[1];
}

function procurementAuthenticateUser(mysqli $conn): array
{
    // Archive-mode reads must still validate the session and permissions
    // against the current operational database.
    $authConn = ($GLOBALS['activeConn'] ?? null) instanceof mysqli
        ? $GLOBALS['activeConn']
        : $conn;

    procurementEnsureAuthenticationTables($authConn);

    try {
        $decoded = (array) JWT::decode(
            procurementBearerTokenFromRequest(),
            new Key(procurementJwtSecret(), 'HS256')
        );

        if (($decoded['type'] ?? '') !== 'access' || ($decoded['app'] ?? '') !== 'procurement') {
            throw new UnexpectedValueException('Invalid token type.');
        }
        if (($decoded['iss'] ?? '') !== procurementJwtIssuer() || ($decoded['aud'] ?? '') !== procurementJwtAudience()) {
            throw new UnexpectedValueException('Invalid token scope.');
        }
        if (empty($decoded['id']) || empty($decoded['email']) || empty($decoded['role'])) {
            throw new UnexpectedValueException('Incomplete token claims.');
        }

        $userId = (int) $decoded['id'];
        $stmt = $authConn->prepare(
            "SELECT u.id, u.fname, u.lname, u.email, u.department, u.status,
                    pua.role, pua.is_active
             FROM user_table u
             INNER JOIN procurement_user_access pua ON pua.user_id = u.id
             WHERE u.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to validate procurement session.', 500);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || strcasecmp((string) $user['status'], 'Active') !== 0 || (int) $user['is_active'] !== 1) {
            jsonResponse(['status' => 'Failed', 'message' => 'Invalid, expired, or inactive procurement session.'], 401);
        }
        if (!in_array((string) $user['role'], PROCUREMENT_ROLES, true)) {
            jsonResponse(['status' => 'Failed', 'message' => 'Your procurement role is invalid.'], 403);
        }
        if (!procurementUserCanAccessApp($user)) {
            procurementRevokeAllUserSessions($authConn, (int) $user['id']);
            procurementClearAuthCookies();
            jsonResponse([
                'status' => 'Failed',
                'message' => 'ProcureDesk is restricted to the procurement department.',
            ], 403);
        }

        $user['permissions'] = procurementPermissionCodes(
            $authConn,
            (string) $user['role'],
            (int) $user['id']
        );
        $user['is_admin'] = procurementUserHasAdminRights(
            $user['permissions'],
            (string) $user['role']
        );
        return $user;
    } catch (Throwable $error) {
        if ((int) $error->getCode() >= 500) {
            error_log('Procurement authentication middleware error: ' . $error->getMessage());
        }
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid or expired procurement session.'], 401);
    }
}

function procurementRequireRoles(mysqli $conn, array $allowedRoles): array
{
    $user = procurementAuthenticateUser($conn);
    if (!in_array((string) $user['role'], $allowedRoles, true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'You are not permitted to perform this action.'], 403);
    }

    return $user;
}

function procurementRequireSuperAdmin(mysqli $conn): array
{
    return procurementRequireRoles($conn, ['super_admin']);
}

function procurementRequirePermission(mysqli $conn, string $permission): array
{
    $user = procurementAuthenticateUser($conn);
    if ((string) $user['role'] !== 'super_admin' && !in_array($permission, $user['permissions'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'You are not permitted to perform this action.'], 403);
    }

    return $user;
}

function procurementRequireAnyPermission(mysqli $conn, array $permissions): array
{
    $user = procurementAuthenticateUser($conn);
    if ((string) $user['role'] === 'super_admin') {
        return $user;
    }

    foreach ($permissions as $permission) {
        if (in_array((string) $permission, $user['permissions'], true)) {
            return $user;
        }
    }

    jsonResponse(['status' => 'Failed', 'message' => 'You are not permitted to perform this action.'], 403);
}
