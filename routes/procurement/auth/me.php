<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
}

$user = procurementAuthenticateUser($conn);
jsonResponse([
    'status' => 'Success',
    'data' => procurementPublicUserPayload($user, $user['permissions']),
]);
