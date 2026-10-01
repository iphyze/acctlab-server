<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';

function procurementAccessTarget(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT u.id, u.fname, u.lname, u.email, u.department,
                u.status AS account_status, pua.role, pua.is_active
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
        $actor = procurementRequirePermission($conn, 'access.users.view');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim((string) ($_GET['search'] ?? ''));
        $role = strtolower(trim((string) ($_GET['role'] ?? '')));
        $activeFilter = trim((string) ($_GET['is_active'] ?? ''));

        if ($role !== '' && !in_array($role, PROCUREMENT_ROLES, true)) {
            throw new RuntimeException('Invalid role filter.', 400);
        }
        if ($activeFilter !== '' && !in_array($activeFilter, ['0', '1'], true)) {
            throw new RuntimeException('Invalid access-status filter.', 400);
        }

        $where = ["u.department = 'procurement'"];
        $params = [];
        $types = '';
        if ((string) $actor['role'] !== 'super_admin') {
            $where[] = "pua.role <> 'super_admin'";
        }
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

        $stmt = $conn->prepare(
            "SELECT u.id, u.fname, u.lname, u.email, u.status AS account_status,
                    pua.role, pua.is_active
             FROM procurement_user_access pua
             INNER JOIN user_table u ON u.id = pua.user_id
             WHERE $whereSql
             ORDER BY u.fname, u.lname, u.id
             LIMIT ? OFFSET ?"
        );
        $dataParams = $params;
        $dataTypes = $types . 'ii';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $stmt->bind_param($dataTypes, ...$dataParams);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($users as &$user) {
            $user['id'] = (int) $user['id'];
            $user['is_active'] = (bool) $user['is_active'];
            $user['full_name'] = trim((string) $user['fname'] . ' ' . (string) $user['lname']);
            $user['permissions'] = procurementPermissionCodes(
                $conn,
                (string) $user['role'],
                (int) $user['id']
            );
            $user['is_admin'] = procurementUserHasAdminRights(
                $user['permissions'],
                (string) $user['role']
            );
        }
        unset($user);

        $catalogue = array_values(array_filter(
            procurementPermissionCatalogue($conn),
            static fn(array $permission): bool => (bool) $permission['is_delegable']
        ));
        if ((string) $actor['role'] !== 'super_admin') {
            $actorPermissions = $actor['permissions'];
            $catalogue = array_values(array_filter(
                $catalogue,
                static fn(array $permission): bool => in_array(
                    (string) $permission['code'],
                    $actorPermissions,
                    true
                )
            ));
        }

        jsonResponse([
            'status' => 'Success',
            'data' => [
                'users' => $users,
                'permissions' => $catalogue,
                'roles' => (string) $actor['role'] === 'super_admin'
                    ? PROCUREMENT_ROLES
                    : ['supervisor', 'officer'],
                'can_grant_admin' => (string) $actor['role'] === 'super_admin'
                    || in_array('access.users.grant_admin', $actor['permissions'], true),
            ],
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        procurementRequireCsrfToken();
        $actor = procurementRequirePermission($conn, 'access.users.manage');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }

        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('A valid user ID is required.', 400);
        }
        if ($userId === (int) $actor['id']) {
            throw new RuntimeException('You cannot modify your own access.', 400);
        }

        $target = procurementAccessTarget($conn, $userId);
        if (!$target) {
            throw new RuntimeException('Procurement user not found.', 404);
        }
        if ((string) $target['role'] === 'super_admin' && (string) $actor['role'] !== 'super_admin') {
            throw new RuntimeException('Only a super admin can modify a super-admin account.', 403);
        }

        $role = array_key_exists('role', $data)
            ? strtolower(trim((string) $data['role']))
            : (string) $target['role'];
        $isActive = array_key_exists('is_active', $data)
            ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : (bool) $target['is_active'];
        $permissionsSupplied = array_key_exists('permissions', $data);
        $permissions = $data['permissions'] ?? [];

        if (!in_array($role, PROCUREMENT_ROLES, true)) {
            throw new RuntimeException('Invalid procurement role.', 400);
        }
        if ((string) $actor['role'] !== 'super_admin' && $role === 'super_admin') {
            throw new RuntimeException('Only a super admin can assign the super-admin role.', 403);
        }
        if ($isActive === null) {
            throw new RuntimeException('is_active must be true or false.', 400);
        }
        if ($permissionsSupplied && !is_array($permissions)) {
            throw new RuntimeException('Permissions must be supplied as an array.', 400);
        }

        procurementAssertLastActiveSuperAdminIsPreserved($conn, $target, $role, $isActive);

        $conn->begin_transaction();
        try {
            $activeFlag = $isActive ? 1 : 0;
            $actorId = (int) $actor['id'];
            $update = $conn->prepare(
                'UPDATE procurement_user_access
                 SET role = ?, is_active = ?, updated_by = ?
                 WHERE user_id = ?'
            );
            $update->bind_param('siii', $role, $activeFlag, $actorId, $userId);
            $update->execute();
            $update->close();

            if ($permissionsSupplied) {
                $effectivePermissions = procurementSetUserPermissionSelection(
                    $conn,
                    $userId,
                    $role,
                    $permissions,
                    $actor
                );
            } elseif ($role === 'super_admin') {
                $clear = $conn->prepare('DELETE FROM procurement_user_permissions WHERE user_id = ?');
                $clear->bind_param('i', $userId);
                $clear->execute();
                $clear->close();
                $effectivePermissions = procurementPermissionCodes($conn, $role, $userId);
            } elseif ($role !== (string) $target['role'] && $role !== 'supervisor') {
                $currentPermissions = procurementPermissionCodes(
                    $conn,
                    (string) $target['role'],
                    $userId
                );
                $permissionsWithoutAdmin = array_values(array_diff(
                    $currentPermissions,
                    PROCUREMENT_ADMIN_PERMISSION_CODES
                ));
                $effectivePermissions = procurementSetUserPermissionSelection(
                    $conn,
                    $userId,
                    $role,
                    $permissionsWithoutAdmin,
                    $actor
                );
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
            (string) $actor['email'] . ' updated procurement access for ' . (string) $target['email']
        );

        jsonResponse([
            'status' => 'Success',
            'message' => 'Procurement access updated successfully.',
            'data' => [
                'id' => $userId,
                'fname' => (string) $target['fname'],
                'lname' => (string) $target['lname'],
                'email' => (string) $target['email'],
                'role' => $role,
                'is_active' => $isActive,
                'is_admin' => procurementUserHasAdminRights($effectivePermissions, $role),
                'permissions' => $effectivePermissions,
            ],
        ]);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement access users error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process procurement access.' : $error->getMessage(),
    ], $status);
}
