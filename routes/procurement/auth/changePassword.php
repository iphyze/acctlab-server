<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    procurementRequireCsrfToken();
    $user = procurementRequirePermission($conn, 'profile.change_password');
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request body.', 400);
    }

    $currentPassword = (string) ($data['current_password'] ?? '');
    $newPassword = (string) ($data['new_password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');

    if ($currentPassword === '' || strlen($newPassword) < 8) {
        throw new RuntimeException('Current password and a new password of at least 8 characters are required.', 400);
    }
    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password confirmation does not match.', 400);
    }
    if ($currentPassword === $newPassword) {
        throw new RuntimeException('New password must be different from the current password.', 400);
    }

    $userId = (int) $user['id'];
    $stmt = $conn->prepare('SELECT password FROM user_table WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !password_verify($currentPassword, (string) $row['password'])) {
        throw new RuntimeException('Current password is incorrect.', 422);
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $email = (string) $user['email'];
    $update = $conn->prepare('UPDATE user_table SET password = ?, updated_by = ? WHERE id = ?');
    $update->bind_param('ssi', $passwordHash, $email, $userId);
    $update->execute();
    $update->close();

    procurementRevokeAllUserSessions($conn, $userId);
    procurementClearAuthCookies();
    procurementWriteAuditLog($conn, $userId, $email, $email . ' changed their account password from the procurement app');

    jsonResponse([
        'status' => 'Success',
        'message' => 'Password changed successfully. Please sign in again.',
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement password change error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to change password.' : $error->getMessage(),
    ], $status);
}
