<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';

use Respect\Validation\Validator as v;

function procurementValidatedUserStatus(mixed $value, string $default = 'Active'): string
{
    $status = trim((string) ($value ?? $default));
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        throw new RuntimeException('Status must be Active or Inactive.', 400);
    }
    return $status;
}

function procurementUserManagementRecord(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT u.id, u.fname, u.lname, u.email, u.department, u.integrity,
                u.status AS account_status, pua.role, pua.is_active,
                pua.created_at, pua.updated_at
         FROM user_table u
         INNER JOIN procurement_user_access pua ON pua.user_id = u.id
         WHERE u.id = ? AND u.department = 'procurement'
         LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

try {
    procurementEnsureAuthenticationTables($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        procurementRequireSuperAdmin($conn);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim((string) ($_GET['search'] ?? ''));
        $role = strtolower(trim((string) ($_GET['role'] ?? '')));
        $activeFilter = trim((string) ($_GET['is_active'] ?? ''));
        $statusFilter = trim((string) ($_GET['status'] ?? ''));

        if ($role !== '' && !in_array($role, PROCUREMENT_ROLES, true)) {
            throw new RuntimeException('Invalid role filter.', 400);
        }
        if ($activeFilter !== '' && !in_array($activeFilter, ['0', '1'], true)) {
            throw new RuntimeException('Invalid access-status filter.', 400);
        }
        if ($statusFilter !== '' && !in_array($statusFilter, ['Active', 'Inactive'], true)) {
            throw new RuntimeException('Invalid account-status filter.', 400);
        }

        $where = ["u.department = 'procurement'"];
        $params = [];
        $types = '';
        if ($search !== '') {
            $where[] = '(u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
            $types .= 'sss';
        }
        if ($role !== '') {
            $where[] = 'pua.role = ?';
            $params[] = $role;
            $types .= 's';
        }
        if ($activeFilter !== '') {
            $where[] = 'pua.is_active = ?';
            $params[] = (int) $activeFilter;
            $types .= 'i';
        }
        if ($statusFilter !== '') {
            $where[] = 'u.status = ?';
            $params[] = $statusFilter;
            $types .= 's';
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM procurement_user_access pua
             INNER JOIN user_table u ON u.id = pua.user_id
             WHERE $whereSql"
        );
        if ($params !== []) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $dataStmt = $conn->prepare(
            "SELECT u.id, u.fname, u.lname, u.email, u.department, u.integrity,
                    u.status AS account_status, pua.role, pua.is_active,
                    pua.created_at, pua.updated_at
             FROM procurement_user_access pua
             INNER JOIN user_table u ON u.id = pua.user_id
             WHERE $whereSql
             ORDER BY u.fname ASC, u.lname ASC, u.id ASC
             LIMIT ? OFFSET ?"
        );
        $dataParams = $params;
        $dataTypes = $types . 'ii';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $dataStmt->bind_param($dataTypes, ...$dataParams);
        $dataStmt->execute();
        $users = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $dataStmt->close();

        foreach ($users as &$listedUser) {
            $listedUser['id'] = (int) $listedUser['id'];
            $listedUser['is_active'] = (bool) $listedUser['is_active'];
            $listedUser['permissions'] = procurementPermissionCodes(
                $conn,
                (string) $listedUser['role'],
                (int) $listedUser['id']
            );
            $listedUser['is_admin'] = procurementUserHasAdminRights(
                $listedUser['permissions'],
                (string) $listedUser['role']
            );
        }
        unset($listedUser);

        jsonResponse([
            'status' => 'Success',
            'data' => $users,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        procurementRequireCsrfToken();
        $actor = procurementRequireSuperAdmin($conn);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }

        $fname = trim((string) ($data['fname'] ?? ''));
        $lname = trim((string) ($data['lname'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $role = strtolower(trim((string) ($data['role'] ?? 'officer')));
        $status = procurementValidatedUserStatus($data['status'] ?? 'Active');
        $isActive = array_key_exists('is_active', $data)
            ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : true;
        $permissions = $data['permissions'] ?? [];

        if ($fname === '' || $lname === '') {
            throw new RuntimeException('First name and last name are required.', 400);
        }
        if (!v::email()->validate($email)) {
            throw new RuntimeException('A valid email address is required.', 400);
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must contain at least 8 characters.', 400);
        }
        if (!in_array($role, PROCUREMENT_ROLES, true)) {
            throw new RuntimeException('Invalid procurement role.', 400);
        }
        if ($isActive === null) {
            throw new RuntimeException('is_active must be true or false.', 400);
        }
        if (!is_array($permissions)) {
            throw new RuntimeException('Permissions must be supplied as an array.', 400);
        }

        $existingStmt = $conn->prepare('SELECT id FROM user_table WHERE email = ? LIMIT 1');
        $existingStmt->bind_param('s', $email);
        $existingStmt->execute();
        $emailExists = $existingStmt->get_result()->num_rows > 0;
        $existingStmt->close();
        if ($emailExists) {
            throw new RuntimeException('A user with this email address already exists.', 409);
        }

        $conn->begin_transaction();
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $integrity = 'Procurement_Only';
            $department = 'procurement';
            $actorEmail = (string) $actor['email'];
            $createUser = $conn->prepare(
                'INSERT INTO user_table
                    (fname, lname, email, password, integrity, department, status, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $createUser->bind_param(
                'sssssssss',
                $fname,
                $lname,
                $email,
                $passwordHash,
                $integrity,
                $department,
                $status,
                $actorEmail,
                $actorEmail
            );
            $createUser->execute();
            $userId = (int) $createUser->insert_id;
            $createUser->close();

            $activeFlag = $isActive ? 1 : 0;
            $actorId = (int) $actor['id'];
            $createAccess = $conn->prepare(
                'INSERT INTO procurement_user_access (user_id, role, is_active, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $createAccess->bind_param('isiii', $userId, $role, $activeFlag, $actorId, $actorId);
            $createAccess->execute();
            $createAccess->close();

            $effectivePermissions = procurementSetUserPermissionSelection(
                $conn,
                $userId,
                $role,
                $permissions,
                $actor
            );
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        procurementWriteAuditLog(
            $conn,
            (int) $actor['id'],
            (string) $actor['email'],
            (string) $actor['email'] . ' created procurement user ' . $email
        );

        jsonResponse([
            'status' => 'Success',
            'message' => 'Procurement user created successfully.',
            'data' => [
                'id' => $userId,
                'fname' => $fname,
                'lname' => $lname,
                'email' => $email,
                'department' => 'procurement',
                'account_status' => $status,
                'role' => $role,
                'is_active' => $isActive,
                'is_admin' => procurementUserHasAdminRights($effectivePermissions, $role),
                'permissions' => $effectivePermissions,
            ],
        ], 201);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequireSuperAdmin($conn);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }

        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('A valid user ID is required.', 400);
        }
        $target = procurementUserManagementRecord($conn, $userId);
        if (!$target) {
            throw new RuntimeException('Procurement user not found.', 404);
        }

        $fname = array_key_exists('fname', $data) ? trim((string) $data['fname']) : (string) $target['fname'];
        $lname = array_key_exists('lname', $data) ? trim((string) $data['lname']) : (string) $target['lname'];
        $email = array_key_exists('email', $data)
            ? strtolower(trim((string) $data['email']))
            : (string) $target['email'];
        $role = array_key_exists('role', $data)
            ? strtolower(trim((string) $data['role']))
            : (string) $target['role'];
        $status = array_key_exists('status', $data)
            ? procurementValidatedUserStatus($data['status'])
            : (string) $target['account_status'];
        $isActive = array_key_exists('is_active', $data)
            ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : (bool) $target['is_active'];
        $newPassword = (string) ($data['password'] ?? '');
        $permissionsSupplied = array_key_exists('permissions', $data);
        $permissions = $data['permissions'] ?? [];

        if ($fname === '' || $lname === '') {
            throw new RuntimeException('First name and last name are required.', 400);
        }
        if (!v::email()->validate($email)) {
            throw new RuntimeException('A valid email address is required.', 400);
        }
        if (!in_array($role, PROCUREMENT_ROLES, true)) {
            throw new RuntimeException('Invalid procurement role.', 400);
        }
        if ($isActive === null) {
            throw new RuntimeException('is_active must be true or false.', 400);
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            throw new RuntimeException('Password must contain at least 8 characters.', 400);
        }
        if ($permissionsSupplied && !is_array($permissions)) {
            throw new RuntimeException('Permissions must be supplied as an array.', 400);
        }
        if ($userId === (int) $actor['id'] && ($role !== 'super_admin' || !$isActive || $status !== 'Active')) {
            throw new RuntimeException('You cannot remove or deactivate your own super-admin access.', 400);
        }

        $emailStmt = $conn->prepare('SELECT id FROM user_table WHERE email = ? AND id <> ? LIMIT 1');
        $emailStmt->bind_param('si', $email, $userId);
        $emailStmt->execute();
        $duplicateEmail = $emailStmt->get_result()->num_rows > 0;
        $emailStmt->close();
        if ($duplicateEmail) {
            throw new RuntimeException('A user with this email address already exists.', 409);
        }

        procurementAssertLastActiveSuperAdminIsPreserved(
            $conn,
            $target,
            $role,
            $isActive && $status === 'Active'
        );

        $conn->begin_transaction();
        try {
            $actorEmail = (string) $actor['email'];
            if ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateUser = $conn->prepare(
                    "UPDATE user_table
                     SET fname = ?, lname = ?, email = ?, password = ?, department = 'procurement',
                         status = ?, updated_by = ?
                     WHERE id = ?"
                );
                $updateUser->bind_param(
                    'ssssssi',
                    $fname,
                    $lname,
                    $email,
                    $passwordHash,
                    $status,
                    $actorEmail,
                    $userId
                );
            } else {
                $updateUser = $conn->prepare(
                    "UPDATE user_table
                     SET fname = ?, lname = ?, email = ?, department = 'procurement',
                         status = ?, updated_by = ?
                     WHERE id = ?"
                );
                $updateUser->bind_param('sssssi', $fname, $lname, $email, $status, $actorEmail, $userId);
            }
            $updateUser->execute();
            $updateUser->close();

            $activeFlag = $isActive ? 1 : 0;
            $actorId = (int) $actor['id'];
            $updateAccess = $conn->prepare(
                'UPDATE procurement_user_access
                 SET role = ?, is_active = ?, updated_by = ?
                 WHERE user_id = ?'
            );
            $updateAccess->bind_param('siii', $role, $activeFlag, $actorId, $userId);
            $updateAccess->execute();
            $updateAccess->close();

            if ($permissionsSupplied) {
                $effectivePermissions = procurementSetUserPermissionSelection(
                    $conn,
                    $userId,
                    $role,
                    $permissions,
                    $actor
                );
            } elseif ($role !== (string) $target['role']) {
                $clearOverrides = $conn->prepare('DELETE FROM procurement_user_permissions WHERE user_id = ?');
                $clearOverrides->bind_param('i', $userId);
                $clearOverrides->execute();
                $clearOverrides->close();
                $effectivePermissions = procurementPermissionCodes($conn, $role, $userId);
            } else {
                $effectivePermissions = procurementPermissionCodes($conn, $role, $userId);
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        procurementRevokeAllUserSessions($conn, $userId);
        procurementWriteAuditLog(
            $conn,
            (int) $actor['id'],
            (string) $actor['email'],
            (string) $actor['email'] . ' updated procurement user ' . $email
        );

        jsonResponse([
            'status' => 'Success',
            'message' => 'Procurement user updated successfully.',
            'data' => [
                'id' => $userId,
                'fname' => $fname,
                'lname' => $lname,
                'email' => $email,
                'department' => 'procurement',
                'account_status' => $status,
                'role' => $role,
                'is_active' => $isActive,
                'is_admin' => procurementUserHasAdminRights($effectivePermissions, $role),
                'permissions' => $effectivePermissions,
            ],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        procurementRequireCsrfToken();
        $actor = procurementRequireSuperAdmin($conn);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_GET;
        }
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('A valid user ID is required.', 400);
        }
        if ($userId === (int) $actor['id']) {
            throw new RuntimeException('You cannot delete your own account.', 400);
        }

        $target = procurementUserManagementRecord($conn, $userId);
        if (!$target) {
            throw new RuntimeException('Procurement user not found.', 404);
        }
        procurementAssertLastActiveSuperAdminIsPreserved($conn, $target, null, null, true);

        $conn->begin_transaction();
        try {
            $actorEmail = (string) $actor['email'];
            $inactive = 'Inactive';
            $updateUser = $conn->prepare(
                'UPDATE user_table SET status = ?, updated_by = ? WHERE id = ?'
            );
            $updateUser->bind_param('ssi', $inactive, $actorEmail, $userId);
            $updateUser->execute();
            $updateUser->close();

            $actorId = (int) $actor['id'];
            $updateAccess = $conn->prepare(
                'UPDATE procurement_user_access SET is_active = 0, updated_by = ? WHERE user_id = ?'
            );
            $updateAccess->bind_param('ii', $actorId, $userId);
            $updateAccess->execute();
            $updateAccess->close();

            $deletePermissions = $conn->prepare('DELETE FROM procurement_user_permissions WHERE user_id = ?');
            $deletePermissions->bind_param('i', $userId);
            $deletePermissions->execute();
            $deletePermissions->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        procurementRevokeAllUserSessions($conn, $userId);
        procurementWriteAuditLog(
            $conn,
            (int) $actor['id'],
            (string) $actor['email'],
            (string) $actor['email'] . ' deleted procurement user ' . (string) $target['email']
        );

        jsonResponse([
            'status' => 'Success',
            'message' => 'Procurement user deleted successfully.',
        ]);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement user management error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process procurement users.' : $error->getMessage(),
    ], $status);
}
