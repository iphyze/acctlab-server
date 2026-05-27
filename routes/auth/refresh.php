<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    requireCsrfToken();
    $user = rotateRefreshSession($conn);
    $accountingPeriod = (int) $user['accounting_period'];
    $access = issueAccessToken($user, $accountingPeriod);
    $csrfToken = issueCsrfCookie();

    jsonResponse([
        'status' => 'Success',
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
        error_log('Refresh error: ' . $error->getMessage());
    }
    jsonResponse(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to restore session.' : $error->getMessage()], $status);
}
