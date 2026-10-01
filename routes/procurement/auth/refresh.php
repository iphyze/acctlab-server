<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequireCsrfToken();
    $user = procurementRotateRefreshSession($conn);
    $permissions = procurementPermissionCodes($conn, (string) $user['role'], (int) $user['id']);
    $access = procurementIssueAccessToken($user, $permissions);
    $csrfToken = procurementIssueCsrfCookie();

    jsonResponse([
        'status' => 'Success',
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
        error_log('Procurement refresh error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to restore procurement session.' : $error->getMessage(),
    ], $status);
}
