<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/authService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
}

requireCsrfToken();
revokeRefreshSession($conn);
jsonResponse(['status' => 'Success', 'message' => 'Logged out successfully.']);
