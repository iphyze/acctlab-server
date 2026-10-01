<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';

try {
    procurementEnsureAuthenticationTables($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        procurementRequireSuperAdmin($conn);
        $permissions = procurementPermissionCatalogue($conn);

        $matrix = [];
        foreach (PROCUREMENT_ROLES as $role) {
            $matrix[$role] = procurementRolePermissionCodes($conn, $role);
        }

        jsonResponse([
            'status' => 'Success',
            'data' => [
                'roles' => PROCUREMENT_ROLES,
                'permissions' => $permissions,
                'matrix' => $matrix,
                'editable_roles' => ['supervisor', 'officer'],
                'note' => 'Delegated administrator rights are assigned per user, not to every member of a role.',
            ],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequireSuperAdmin($conn);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid request body.', 400);
        }

        $role = strtolower(trim((string) ($data['role'] ?? '')));
        $selectedPermissions = $data['permissions'] ?? null;
        if (!in_array($role, ['supervisor', 'officer'], true)) {
            throw new RuntimeException('Permissions can only be edited for supervisor or officer roles.', 400);
        }
        if (!is_array($selectedPermissions)) {
            throw new RuntimeException('Permissions must be supplied as an array.', 400);
        }

        $selectedPermissions = procurementNormalizePermissionSelection($selectedPermissions);
        $catalogue = procurementPermissionCatalogue($conn);
        $validCodes = array_column($catalogue, 'code');
        $roleAssignableCodes = array_values(array_map(
            static fn(array $permission): string => (string) $permission['code'],
            array_filter($catalogue, static fn(array $permission): bool => (bool) $permission['is_role_assignable'])
        ));
        $invalidCodes = array_values(array_diff($selectedPermissions, $validCodes));
        if ($invalidCodes !== []) {
            throw new RuntimeException('One or more permission codes are invalid.', 400);
        }

        $selectedAssignable = array_values(array_intersect($selectedPermissions, $roleAssignableCodes));
        $currentRolePermissions = procurementRolePermissionCodes($conn, $role);
        $immutablePermissions = array_values(array_diff($currentRolePermissions, $roleAssignableCodes));
        $finalPermissions = array_values(array_unique(array_merge($immutablePermissions, $selectedAssignable)));

        $conn->begin_transaction();
        try {
            $actorId = (int) $actor['id'];
            if ($roleAssignableCodes !== []) {
                $placeholders = implode(',', array_fill(0, count($roleAssignableCodes), '?'));
                $disableSql = "UPDATE procurement_role_permissions
                               SET is_enabled = 0, updated_by = ?
                               WHERE role = ? AND permission_code IN ($placeholders)";
                $disable = $conn->prepare($disableSql);
                $types = 'is' . str_repeat('s', count($roleAssignableCodes));
                $params = array_merge([$actorId, $role], $roleAssignableCodes);
                $disable->bind_param($types, ...$params);
                $disable->execute();
                $disable->close();
            }

            $upsert = $conn->prepare(
                'INSERT INTO procurement_role_permissions (role, permission_code, is_enabled, updated_by)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE is_enabled = 1, updated_by = VALUES(updated_by)'
            );
            foreach ($selectedAssignable as $permissionCode) {
                $upsert->bind_param('ssi', $role, $permissionCode, $actorId);
                $upsert->execute();
            }
            $upsert->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        procurementWriteAuditLog(
            $conn,
            (int) $actor['id'],
            (string) $actor['email'],
            (string) $actor['email'] . ' updated procurement role permissions for ' . $role
        );

        jsonResponse([
            'status' => 'Success',
            'message' => 'Role permissions updated successfully.',
            'data' => ['role' => $role, 'permissions' => $finalPermissions],
        ]);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement role permissions error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process role permissions.' : $error->getMessage(),
    ], $status);
}
