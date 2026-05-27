<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/authService.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function bearerTokenFromRequest(): string
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

function authenticateUser(): array
{
    try {
        $decoded = (array) JWT::decode(bearerTokenFromRequest(), new Key(jwtSecret(), 'HS256'));

        if (($decoded['type'] ?? '') !== 'access') {
            throw new UnexpectedValueException('Invalid token type.');
        }
        if (($decoded['iss'] ?? '') !== jwtIssuer() || ($decoded['aud'] ?? '') !== jwtAudience()) {
            throw new UnexpectedValueException('Invalid token scope.');
        }
        if (empty($decoded['id']) || empty($decoded['email']) || empty($decoded['integrity'])) {
            throw new UnexpectedValueException('Incomplete token claims.');
        }

        return $decoded;
    } catch (Throwable $error) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid or expired authentication token.'], 401);
    }
}

function requireRoles(array $allowedRoles): array
{
    $user = authenticateUser();
    if (!in_array((string) $user['integrity'], $allowedRoles, true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'You are not permitted to perform this action.'], 403);
    }

    return $user;
}

function requireAdmin(): array
{
    return requireRoles(['Admin', 'Super_Admin']);
}

function requireSuperAdmin(): array
{
    return requireRoles(['Super_Admin']);
}
