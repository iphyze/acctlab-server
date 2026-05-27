<?php

declare(strict_types=1);

/**
 * Backward-compatible secured user creation endpoint.
 * Kept for existing clients, but it now follows the user_table model and is
 * restricted to Super_Admin. New UI requests use /users/createUsers.
 */
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    $actor = requireSuperAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid request body.', 400);
    }

    $fname = trim((string) ($data['fname'] ?? $data['firstName'] ?? ''));
    $lname = trim((string) ($data['lname'] ?? $data['lastName'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $integrity = trim((string) ($data['integrity'] ?? $data['role'] ?? 'Admin'));

    if ($fname === '' || $lname === '' || !v::email()->validate($email) || strlen($password) < 8) {
        throw new RuntimeException('First name, last name, valid email and password of at least 8 characters are required.', 400);
    }
    if (!in_array($integrity, ['User', 'Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Invalid user role.', 400);
    }

    $dup = $conn->prepare('SELECT id FROM user_table WHERE email = ? LIMIT 1');
    $dup->bind_param('s', $email);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        throw new RuntimeException('A user with this email already exists.', 409);
    }
    $dup->close();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $createdBy = (string) $actor['email'];
    $stmt = $conn->prepare('INSERT INTO user_table (fname, lname, email, password, integrity, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssss', $fname, $lname, $email, $hashedPassword, $integrity, $createdBy, $createdBy);
    $stmt->execute();
    $userId = $stmt->insert_id;
    $stmt->close();

    $log = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    $actorId = (int) $actor['id'];
    $action = $createdBy . ' created user ' . $email . ' with role ' . $integrity;
    $log->bind_param('iss', $actorId, $action, $createdBy);
    $log->execute();
    $log->close();

    jsonResponse([
        'status' => 'Success',
        'message' => 'User created successfully.',
        'data' => [
            'id' => $userId,
            'fname' => $fname,
            'lname' => $lname,
            'email' => $email,
            'integrity' => $integrity,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ],
    ], 201);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('User registration error: ' . $error->getMessage());
    }
    jsonResponse(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to create user.' : $error->getMessage()], $status);
}
