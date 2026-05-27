<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

use Firebase\JWT\JWT;

function ensureAuthenticationTables(mysqli $conn): void
{
    $refreshSql = "CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        family_id CHAR(64) NOT NULL,
        accounting_period INT NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        replaced_by_hash CHAR(64) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        INDEX idx_refresh_user (user_id),
        INDEX idx_refresh_expiry (expires_at),
        INDEX idx_refresh_family (family_id, revoked_at),
        INDEX idx_refresh_active (token_hash, revoked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $loginSql = "CREATE TABLE IF NOT EXISTS auth_login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email_hash CHAR(64) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        successful TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_login_guard (email_hash, ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($refreshSql) || !$conn->query($loginSql)) {
        throw new RuntimeException('Authentication storage could not be initialized. Apply database/security_auth_migration.sql.', 500);
    }
}

function jwtSecret(): string
{
    $secret = (string) envValue('JWT_SECRET', '');
    if (strlen($secret) < 32 || in_array(strtolower($secret), ['xxx', 'replace_me', 'your_default_secret'], true)) {
        throw new RuntimeException('JWT_SECRET must be configured with at least 32 random characters.', 500);
    }
    return $secret;
}

function jwtIssuer(): string
{
    return (string) envValue('JWT_ISSUER', 'acctlab-api');
}

function jwtAudience(): string
{
    return (string) envValue('JWT_AUDIENCE', 'acctlab-web');
}

function issueAccessToken(array $user, int $accountingPeriod): array
{
    $issuedAt = time();
    $expiresIn = (int) envValue('JWT_ACCESS_EXPIRES_IN', 900);
    $expiresAt = $issuedAt + max(300, $expiresIn);

    $payload = [
        'iss' => jwtIssuer(),
        'aud' => jwtAudience(),
        'iat' => $issuedAt,
        'nbf' => $issuedAt - 5,
        'exp' => $expiresAt,
        'jti' => bin2hex(random_bytes(16)),
        'type' => 'access',
        'sub' => (string) $user['id'],
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'integrity' => (string) $user['integrity'],
        'accounting_period' => $accountingPeriod,
    ];

    return [
        'token' => JWT::encode($payload, jwtSecret(), 'HS256'),
        'expires_at' => $expiresAt,
    ];
}

function issueRefreshSession(mysqli $conn, int $userId, int $accountingPeriod, bool $ensureTables = true, ?string $familyId = null, bool $writeCookie = true): array
{
    if ($ensureTables) {
        ensureAuthenticationTables($conn);
    }

    $token = bin2hex(random_bytes(64));
    $hash = hash('sha256', $token);
    $familyId = $familyId ?: $hash;
    $expiresAtUnix = time() + (int) envValue('REFRESH_TOKEN_EXPIRES_IN', 2592000);
    $expiresAt = date('Y-m-d H:i:s', $expiresAtUnix);
    $ipAddress = clientIpAddress();
    $userAgent = requestUserAgent();

    $stmt = $conn->prepare('INSERT INTO auth_refresh_tokens (user_id, token_hash, family_id, accounting_period, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Unable to create secure session.', 500);
    }
    $stmt->bind_param('ississs', $userId, $hash, $familyId, $accountingPeriod, $expiresAt, $ipAddress, $userAgent);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to create secure session.', 500);
    }
    $stmt->close();

    if ($writeCookie) {
        setRefreshCookie($token, $expiresAtUnix);
    }

    return [
        'hash' => $hash,
        'token' => $token,
        'expires_at' => $expiresAtUnix,
    ];
}

function currentRefreshCookie(): string
{
    return trim((string) ($_COOKIE[refreshCookieName()] ?? ''));
}

function rotateRefreshSession(mysqli $conn): array
{
    ensureAuthenticationTables($conn);
    $token = currentRefreshCookie();
    if ($token === '') {
        throw new RuntimeException('Authentication required.', 401);
    }

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT rt.id AS refresh_id, rt.user_id, rt.family_id, rt.accounting_period, rt.expires_at, rt.revoked_at, rt.replaced_by_hash, u.id, u.fname, u.lname, u.email, u.integrity, u.created_by, u.updated_by
        FROM auth_refresh_tokens rt
        INNER JOIN user_table u ON u.id = rt.user_id
        WHERE rt.token_hash = ?
        LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to verify session.', 500);
    }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session || strtotime((string) $session['expires_at']) <= time()) {
        clearAuthCookies();
        throw new RuntimeException('Session has expired. Please sign in again.', 401);
    }

    if (!empty($session['revoked_at'])) {
        $revokeFamily = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE family_id = ?');
        if ($revokeFamily) {
            $familyId = (string) $session['family_id'];
            $revokeFamily->bind_param('s', $familyId);
            $revokeFamily->execute();
            $revokeFamily->close();
        }
        clearAuthCookies();
        throw new RuntimeException('Session security check failed. Please sign in again.', 401);
    }

    $conn->begin_transaction();
    try {
        $revokeStmt = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = NOW(), last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL');
        $refreshId = (int) $session['refresh_id'];
        $revokeStmt->bind_param('i', $refreshId);
        $revokeStmt->execute();
        if ($revokeStmt->affected_rows !== 1) {
            $revokeStmt->close();
            throw new RuntimeException('Session refresh could not be completed.', 401);
        }
        $revokeStmt->close();

        $newSession = issueRefreshSession($conn, (int) $session['user_id'], (int) $session['accounting_period'], false, (string) $session['family_id'], false);
        $newHash = $newSession['hash'];
        $replaceStmt = $conn->prepare('UPDATE auth_refresh_tokens SET replaced_by_hash = ? WHERE id = ?');
        $replaceStmt->bind_param('si', $newHash, $refreshId);
        $replaceStmt->execute();
        $replaceStmt->close();
        $conn->commit();
        setRefreshCookie($newSession['token'], (int) $newSession['expires_at']);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return $session;
}

function revokeRefreshSession(mysqli $conn): void
{
    ensureAuthenticationTables($conn);
    $token = currentRefreshCookie();
    if ($token !== '') {
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE token_hash = ?');
        if ($stmt) {
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $stmt->close();
        }
    }

    clearAuthCookies();
}

function updateRefreshAccountingPeriod(mysqli $conn, int $userId, int $accountingPeriod): void
{
    $token = currentRefreshCookie();
    if ($token === '') {
        return;
    }
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET accounting_period = ? WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW()');
    if ($stmt) {
        $stmt->bind_param('isi', $accountingPeriod, $hash, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function loginAttemptEmailHash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function assertLoginNotRateLimited(mysqli $conn, string $email): void
{
    ensureAuthenticationTables($conn);
    $hash = loginAttemptEmailHash($email);
    $ip = clientIpAddress();
    $stmt = $conn->prepare("SELECT COUNT(*) AS failures FROM auth_login_attempts WHERE email_hash = ? AND ip_address = ? AND successful = 0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
    $stmt->bind_param('ss', $hash, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int) ($row['failures'] ?? 0) >= (int) envValue('LOGIN_MAX_ATTEMPTS', 5)) {
        throw new RuntimeException('Too many sign-in attempts. Please wait 15 minutes and try again.', 429);
    }
}

function recordLoginAttempt(mysqli $conn, string $email, bool $successful): void
{
    ensureAuthenticationTables($conn);
    $hash = loginAttemptEmailHash($email);
    $ip = clientIpAddress();
    $successFlag = $successful ? 1 : 0;
    $stmt = $conn->prepare('INSERT INTO auth_login_attempts (email_hash, ip_address, successful) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $hash, $ip, $successFlag);
    $stmt->execute();
    $stmt->close();

    if ($successful) {
        $clean = $conn->prepare('DELETE FROM auth_login_attempts WHERE email_hash = ? AND ip_address = ? AND successful = 0');
        $clean->bind_param('ss', $hash, $ip);
        $clean->execute();
        $clean->close();
    }

    $conn->query("DELETE FROM auth_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
}

function publicUserPayload(array $user, int $accountingPeriod): array
{
    return [
        'id' => (int) $user['id'],
        'fname' => (string) ($user['fname'] ?? ''),
        'lname' => (string) ($user['lname'] ?? ''),
        'email' => (string) $user['email'],
        'integrity' => (string) $user['integrity'],
        'accounting_period' => $accountingPeriod,
        'created_by' => $user['created_by'] ?? null,
        'updated_by' => $user['updated_by'] ?? null,
    ];
}
