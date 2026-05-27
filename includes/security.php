<?php

declare(strict_types=1);

use Dotenv\Dotenv;

/**
 * Central HTTP security, environment and cookie helpers.
 * This file contains no route-specific business logic.
 */
function loadAppEnvironment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $root = dirname(__DIR__);
    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    $loaded = true;
}

loadAppEnvironment();

function envValue(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

function envBoolean(string $key, bool $default = false): bool
{
    $value = envValue($key, null);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function appEnvironment(): string
{
    return strtolower((string) envValue('APP_ENV', 'local'));
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function allowedOrigins(): array
{
    $configured = (string) envValue(
        'ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173'
    );

    return array_values(array_filter(array_map('trim', explode(',', $configured))));
}

/**
 * CORS is intentionally allow-list based because refresh cookies are credentialed.
 */
function applyApiSecurityHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (appEnvironment() === 'production' && envBoolean('COOKIE_SECURE', true)) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $trustedOrigin = $origin !== '' && in_array($origin, allowedOrigins(), true);

    if ($trustedOrigin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 600');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($origin !== '' && !$trustedOrigin) {
            jsonResponse(['status' => 'Failed', 'message' => 'Origin is not allowed.'], 403);
        }

        http_response_code(204);
        exit;
    }
}

function refreshCookieName(): string
{
    return (string) envValue('REFRESH_COOKIE_NAME', 'acctlab_refresh');
}

function csrfCookieName(): string
{
    return (string) envValue('CSRF_COOKIE_NAME', 'acctlab_csrf');
}

function authCookiePath(): string
{
    return (string) envValue('AUTH_COOKIE_PATH', '/acctlab-server/api/auth');
}

function cookieSameSite(): string
{
    $sameSite = ucfirst(strtolower((string) envValue('COOKIE_SAMESITE', 'Strict')));
    return in_array($sameSite, ['Strict', 'Lax', 'None'], true) ? $sameSite : 'Strict';
}

function cookieOptions(bool $httpOnly, ?int $expires = null): array
{
    $options = [
        'expires' => $expires ?? 0,
        'path' => authCookiePath(),
        'secure' => envBoolean('COOKIE_SECURE', false),
        'httponly' => $httpOnly,
        'samesite' => cookieSameSite(),
    ];

    $domain = trim((string) envValue('COOKIE_DOMAIN', ''));
    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    return $options;
}

function setRefreshCookie(string $refreshToken, int $expiresAt): void
{
    setcookie(refreshCookieName(), $refreshToken, cookieOptions(true, $expiresAt));
}

/**
 * Returns a token to put in the JSON response. The JavaScript client keeps this
 * value only in memory and returns it in X-CSRF-Token for cookie-auth operations.
 */
function issueCsrfCookie(): string
{
    $csrfToken = bin2hex(random_bytes(32));
    $expiresAt = time() + (int) envValue('REFRESH_TOKEN_EXPIRES_IN', 2592000);
    setcookie(csrfCookieName(), $csrfToken, cookieOptions(false, $expiresAt));
    return $csrfToken;
}

function clearAuthCookies(): void
{
    setcookie(refreshCookieName(), '', cookieOptions(true, time() - 3600));
    setcookie(csrfCookieName(), '', cookieOptions(false, time() - 3600));
}

function requireTrustedRequestOrigin(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && !in_array($origin, allowedOrigins(), true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Origin is not allowed.'], 403);
    }
}

function requireCsrfToken(): void
{
    requireTrustedRequestOrigin();

    $cookieToken = (string) ($_COOKIE[csrfCookieName()] ?? '');
    $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Security validation failed. Refresh the page and try again.'], 403);
    }
}

function clientIpAddress(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function requestUserAgent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);
}
