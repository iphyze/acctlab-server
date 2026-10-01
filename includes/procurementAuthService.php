<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

use Firebase\JWT\JWT;

const PROCUREMENT_ROLES = ['super_admin', 'supervisor', 'officer'];
const PROCUREMENT_DEPARTMENTS = ['account', 'procurement'];
const PROCUREMENT_ADMIN_PERMISSION_CODES = [
    'access.users.view',
    'access.users.manage',
    'access.users.grant_admin',
];

function procurementEnsureColumn(
    mysqli $conn,
    string $table,
    string $column,
    string $definition
): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new RuntimeException('Invalid procurement database identifier.', 500);
    }

    // Use the database selected on this connection directly. Relying on a
    // session variable here can incorrectly report an existing column as
    // missing when that variable was not initialised on a production request.
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect database structure.', 500);
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    if ($exists) {
        return;
    }

    try {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    } catch (mysqli_sql_exception $error) {
        // A second concurrent request may add the same column after our check.
        // MySQL/MariaDB error 1060 means the desired end state already exists.
        if ((int) $error->getCode() === 1060) {
            return;
        }

        throw new RuntimeException(
            'Unable to update procurement database structure.',
            500,
            $error
        );
    }
}

function procurementEnsureDepartmentColumn(mysqli $conn): void
{
    procurementEnsureColumn(
        $conn,
        'user_table',
        'department',
        "VARCHAR(50) NOT NULL DEFAULT 'account' AFTER `integrity`"
    );
    $conn->query("UPDATE user_table SET department = 'account' WHERE department IS NULL OR TRIM(department) = ''");
}

function procurementUserCanAccessApp(array $user): bool
{
    return (string) ($user['role'] ?? '') === 'super_admin'
        || strtolower(trim((string) ($user['department'] ?? ''))) === 'procurement';
}

function procurementUserHasAdminRights(array $permissions, string $role = ''): bool
{
    return $role === 'super_admin'
        || in_array('access.users.manage', $permissions, true);
}

const PROCUREMENT_AUTH_RUNTIME_SENTINEL_PERMISSION = 'payments.fx_advance.resolve_po_reconciliation';

/**
 * Cheap healthy-runtime probe used by normal authenticated requests.
 *
 * The former path replayed table creation, column inspection and permission
 * seeding for every HTTP request. A healthy deployment now validates the
 * required auth schema in two lightweight queries and falls back to the
 * existing full bootstrap only when the probe fails.
 */
function procurementAuthenticationRuntimeReady(mysqli $conn): bool
{
    static $ready = [];
    $key = spl_object_id($conn);
    if (($ready[$key] ?? false) === true) {
        return true;
    }

    try {
        $probe = $conn->prepare(
            "SELECT u.department, pua.role, pua.is_active,
                    p.is_delegable, p.is_role_assignable,
                    prp.is_enabled AS role_permission_enabled,
                    pup.is_enabled AS user_permission_enabled,
                    rt.token_hash, rt.family_id, la.email_hash
             FROM user_table u
             LEFT JOIN procurement_user_access pua ON 1 = 0
             LEFT JOIN procurement_permissions p ON 1 = 0
             LEFT JOIN procurement_role_permissions prp ON 1 = 0
             LEFT JOIN procurement_user_permissions pup ON 1 = 0
             LEFT JOIN procurement_auth_refresh_tokens rt ON 1 = 0
             LEFT JOIN procurement_auth_login_attempts la ON 1 = 0
             WHERE 1 = 0"
        );
        if (!$probe) {
            return false;
        }
        $probe->execute();
        $probe->close();

        $sentinel = PROCUREMENT_AUTH_RUNTIME_SENTINEL_PERMISSION;
        $permission = $conn->prepare(
            'SELECT 1 FROM procurement_permissions WHERE code = ? AND is_active = 1 LIMIT 1'
        );
        if (!$permission) {
            return false;
        }
        $permission->bind_param('s', $sentinel);
        $permission->execute();
        $exists = $permission->get_result()->num_rows === 1;
        $permission->close();

        if ($exists) {
            $ready[$key] = true;
        }
        return $exists;
    } catch (Throwable) {
        return false;
    }
}

function procurementEnsureAuthenticationTables(mysqli $conn): void
{
    $conn = function_exists('databaseActiveConnection') ? databaseActiveConnection($conn) : $conn;
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (procurementAuthenticationRuntimeReady($conn)) {
        $ensured = true;
        return;
    }

    procurementEnsureDepartmentColumn($conn);

    $queries = [
        "CREATE TABLE IF NOT EXISTS procurement_user_access (
            user_id INT NOT NULL PRIMARY KEY,
            role VARCHAR(30) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_procurement_access_role (role, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_permissions (
            code VARCHAR(100) NOT NULL PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            category VARCHAR(80) NOT NULL DEFAULT 'General',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_procurement_permissions_category (category, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_role_permissions (
            role VARCHAR(30) NOT NULL,
            permission_code VARCHAR(100) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role, permission_code),
            INDEX idx_procurement_role_permission_enabled (role, is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_user_permissions (
            user_id INT NOT NULL,
            permission_code VARCHAR(100) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, permission_code),
            INDEX idx_procurement_user_permission_enabled (user_id, is_enabled),
            INDEX idx_procurement_user_permission_code (permission_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_auth_refresh_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            family_id CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            replaced_by_hash CHAR(64) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            INDEX idx_procurement_refresh_user (user_id),
            INDEX idx_procurement_refresh_expiry (expires_at),
            INDEX idx_procurement_refresh_family (family_id, revoked_at),
            INDEX idx_procurement_refresh_active (token_hash, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS procurement_auth_login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_procurement_login_guard (email_hash, ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        if (!$conn->query($query)) {
            throw new RuntimeException(
                'Procurement authentication storage could not be initialized. Apply database/procurement_auth_migration.sql.',
                500
            );
        }
    }

    procurementEnsureColumn($conn, 'procurement_permissions', 'is_delegable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');
    procurementEnsureColumn($conn, 'procurement_permissions', 'is_role_assignable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_delegable`');

    procurementSeedAccessControl($conn);
    $ensured = true;
}

function procurementSeedAccessControl(mysqli $conn): void
{
    $permissions = [
        ['dashboard.view', 'View dashboard', 'Open the procurement dashboard.', 'Dashboard', 10, 0, 0],
        ['profile.view', 'View own profile', 'View the signed-in user profile.', 'Profile', 20, 0, 0],
        ['profile.change_password', 'Change own password', 'Change the signed-in user password.', 'Profile', 30, 0, 0],

        ['documents.view', 'View purchase documents', 'View, preview and download documents attached to procurement purchases.', 'Purchase Documents', 3010, 1, 1],
        ['documents.manage', 'Manage purchase documents', 'Upload additional documents and create replacement versions for procurement purchases.', 'Purchase Documents', 3020, 1, 1],
        ['documents.configure', 'Configure purchase documents', 'Change purchase-document file type and size configuration.', 'Purchase Documents', 3030, 0, 0],

        ['users.view', 'View full user records', 'View procurement user records in the super-admin user-management area.', 'User management', 40, 0, 0],
        ['users.create', 'Create users', 'Create new procurement users.', 'User management', 50, 0, 0],
        ['users.update', 'Update users', 'Update procurement user identity and account details.', 'User management', 60, 0, 0],
        ['users.delete', 'Delete users', 'Deactivate and remove procurement user access.', 'User management', 70, 0, 0],

        ['access.users.view', 'View procurement access list', 'View limited access details for procurement staff.', 'Access administration', 80, 1, 0],
        ['access.users.manage', 'Manage procurement access', 'Change procurement role, active access and user permission overrides.', 'Access administration', 90, 1, 0],
        ['access.users.grant_admin', 'Delegate admin rights', 'Grant or revoke delegated access-administration permissions.', 'Access administration', 100, 1, 0],

        ['roles_permissions.view', 'View role permissions', 'View the permission catalogue and role matrix.', 'Access control', 110, 0, 0],
        ['roles_permissions.manage', 'Manage role permissions', 'Enable or disable role-level permissions.', 'Access control', 120, 0, 0],

        ['payments.local_final.view', 'View Local Final Purchases', 'View Local Final Purchase records and their approval/payment state.', 'Local Final Purchase', 200, 1, 1],
        ['payments.local_final.create', 'Create Local Final Purchases', 'Create new Local Final Purchase records.', 'Local Final Purchase', 210, 1, 1],
        ['payments.local_final.update', 'Update Local Final Purchases', 'Edit Local Final Purchases before approval.', 'Local Final Purchase', 220, 1, 1],
        ['payments.local_final.delete', 'Delete Local Final Purchases', 'Delete unapproved Local Final Purchase records.', 'Local Final Purchase', 230, 1, 1],
        ['payments.local_final.update_po_status', 'Update Local Final PO status', 'Update PO status individually or in bulk.', 'Local Final Purchase', 240, 1, 1],
        ['payments.local_final.approve', 'Approve Local Final Purchases', 'Approve purchases and create their linked Supplier Fund Requests.', 'Local Final Purchase', 250, 1, 1],
        ['payments.local_final.reverse_approval', 'Reverse Local Final approvals', 'Reverse approval while the linked Supplier Fund Request is still pending.', 'Local Final Purchase', 260, 1, 1],
        ['payments.local_final.retrieve_from_account', 'Retrieve Local Final Purchases from Account', 'Return pending Account handoffs to procurement for correction and reapproval.', 'Local Final Purchase', 270, 1, 1],
        ['payments.local_final.edit_retrieved', 'Edit retrieved Local Final Purchases', 'Correct Local Final Purchases that Account returned to procurement.', 'Local Final Purchase', 280, 1, 1],
        ['payments.local_final.amend_paid_purchase', 'Revise paid Local Final Purchases', 'Revise a paid Local Final Purchase without altering its historical Account payment.', 'Local Final Purchase', 285, 1, 1],

        ['payments.fx_final.view', 'View FX Final Purchases', 'View Foreign/FX Final Purchase records and their approval/payment state.', 'FX Final Purchase', 290, 1, 1],
        ['payments.fx_final.create', 'Create FX Final Purchases', 'Create new Foreign/FX Final Purchase records.', 'FX Final Purchase', 292, 1, 1],
        ['payments.fx_final.update', 'Update FX Final Purchases', 'Edit Foreign/FX Final Purchases before approval.', 'FX Final Purchase', 294, 1, 1],
        ['payments.fx_final.delete', 'Delete FX Final Purchases', 'Delete unapproved Foreign/FX Final Purchase records.', 'FX Final Purchase', 296, 1, 1],
        ['payments.fx_final.update_po_status', 'Update FX Final PO status', 'Update FX Final Purchase PO status individually or in bulk.', 'FX Final Purchase', 298, 1, 1],
        ['payments.fx_final.approve', 'Approve FX Final Purchases', 'Approve FX Final Purchases and create their linked FX Fund Requests.', 'FX Final Purchase', 299, 1, 1],
        ['payments.fx_final.reverse_approval', 'Reverse FX Final approvals', 'Reverse approval while the linked FX Fund Request is still pending.', 'FX Final Purchase', 2991, 1, 1],
        ['payments.fx_final.retrieve_from_account', 'Retrieve FX Final Purchases from Account', 'Return pending FX Fund Request handoffs to Procurement for correction and reapproval.', 'FX Final Purchase', 2992, 1, 1],
        ['payments.fx_final.edit_retrieved', 'Edit retrieved FX Final Purchases', 'Correct FX Final Purchases that Account returned to Procurement.', 'FX Final Purchase', 2993, 1, 1],
        ['payments.fx_final.amend_paid_purchase', 'Revise paid FX Final Purchases', 'Revise a paid FX Final Purchase without altering historical FX payment.', 'FX Final Purchase', 29931, 1, 1],
        ['payments.fx_advance.view', 'View FX Advance Purchases', 'View Foreign/FX Advance Purchase records, PO allocation and payment state.', 'FX Advance Purchase', 2994, 1, 1],
        ['payments.fx_advance.create', 'Create FX Advance Purchases', 'Create Foreign/FX Advance requests against a currency-specific PO.', 'FX Advance Purchase', 2995, 1, 1],
        ['payments.fx_advance.update', 'Update FX Advance Purchases', 'Edit pending Foreign/FX Advance requests before approval.', 'FX Advance Purchase', 2996, 1, 1],
        ['payments.fx_advance.delete', 'Delete FX Advance Purchases', 'Delete unapproved Foreign/FX Advance requests.', 'FX Advance Purchase', 2997, 1, 1],
        ['payments.fx_advance.update_po_status', 'Update FX Advance PO status', 'Update Foreign/FX Advance PO status individually or in bulk.', 'FX Advance Purchase', 2998, 1, 1],
        ['payments.fx_advance.approve', 'Approve FX Advance Purchases', 'Approve Foreign/FX Advance requests and create linked FX Fund Requests.', 'FX Advance Purchase', 2999, 1, 1],
        ['payments.fx_advance.reverse_approval', 'Reverse FX Advance approvals', 'Reverse approval while the linked FX Fund Request remains safely reversible.', 'FX Advance Purchase', 3000, 1, 1],
        ['payments.fx_advance.retrieve_from_account', 'Retrieve FX Advance Purchases from Account', 'Retrieve pending Foreign/FX Advance handoffs for correction and reapproval.', 'FX Advance Purchase', 3001, 1, 1],
        ['payments.fx_advance.edit_retrieved', 'Edit retrieved FX Advance Purchases', 'Correct Foreign/FX Advance requests returned by Account.', 'FX Advance Purchase', 3002, 1, 1],
        ['payments.fx_advance.amend_paid_po', 'Amend paid FX Advance POs', 'Submit versioned amendments when payment activity has started on a Foreign/FX Advance PO.', 'FX Advance Purchase', 3003, 1, 1],
        ['payments.fx_advance.approve_po_amendment', 'Approve FX Advance PO amendments', 'Approve or reject Foreign/FX Advance PO revisions and reconciliation.', 'FX Advance Purchase', 3004, 1, 1],
        ['payments.fx_advance.resolve_po_reconciliation', 'Resolve FX Advance PO recoveries', 'Resolve Foreign/FX Advance PO supplementary/recovery reconciliation.', 'FX Advance Purchase', 3005, 1, 1],

        ['payments.local_advance.view', 'View Local Advance Purchases', 'View Local Advance Purchase records and their approval/payment state.', 'Local Advance Purchase', 300, 1, 1],
        ['payments.local_advance.create', 'Create Local Advance Purchases', 'Create new Local Advance Purchase records.', 'Local Advance Purchase', 310, 1, 1],
        ['payments.local_advance.update', 'Update Local Advance Purchases', 'Edit Local Advance Purchases before approval.', 'Local Advance Purchase', 320, 1, 1],
        ['payments.local_advance.delete', 'Delete Local Advance Purchases', 'Delete unapproved Local Advance Purchase records.', 'Local Advance Purchase', 330, 1, 1],
        ['payments.local_advance.update_po_status', 'Update Local Advance PO status', 'Update Local Advance PO status individually or in bulk.', 'Local Advance Purchase', 340, 1, 1],
        ['payments.local_advance.approve', 'Approve Local Advance Purchases', 'Approve purchases and create their linked Advance Fund Requests.', 'Local Advance Purchase', 350, 1, 1],
        ['payments.local_advance.reverse_approval', 'Reverse Local Advance approvals', 'Reverse approval while the linked Advance Fund Request is still pending.', 'Local Advance Purchase', 360, 1, 1],
        ['payments.local_advance.retrieve_from_account', 'Retrieve Local Advance Purchases from Account', 'Return pending Account handoffs to procurement for correction and reapproval.', 'Local Advance Purchase', 370, 1, 1],
        ['payments.local_advance.edit_retrieved', 'Edit retrieved Local Advance Purchases', 'Correct Local Advance Purchases that Account returned to procurement.', 'Local Advance Purchase', 380, 1, 1],
        ['payments.local_advance.amend_paid_po', 'Amend paid Local Advance POs', 'Submit a versioned amendment when payment has started on a Local Advance PO.', 'Local Advance Purchase', 390, 1, 1],
        ['payments.local_advance.approve_po_amendment', 'Approve Local Advance PO amendments', 'Approve or reject versioned amendments and create their reconciliation.', 'Local Advance Purchase', 400, 1, 1],
        ['payments.local_advance.resolve_po_reconciliation', 'Resolve Local Advance PO recoveries', 'Record supplier credit, refund, recovery or future-payment offset resolutions.', 'Local Advance Purchase', 410, 1, 1],
    ];

    $permissionStmt = $conn->prepare(
        'INSERT INTO procurement_permissions
            (code, name, description, category, sort_order, is_active, is_delegable, is_role_assignable)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            category = VALUES(category),
            sort_order = VALUES(sort_order),
            is_active = 1,
            is_delegable = VALUES(is_delegable),
            is_role_assignable = VALUES(is_role_assignable)'
    );
    if (!$permissionStmt) {
        throw new RuntimeException('Unable to initialize procurement permissions.', 500);
    }
    foreach ($permissions as [$code, $name, $description, $category, $sortOrder, $delegable, $roleAssignable]) {
        $permissionStmt->bind_param(
            'ssssiii',
            $code,
            $name,
            $description,
            $category,
            $sortOrder,
            $delegable,
            $roleAssignable
        );
        $permissionStmt->execute();
    }
    $permissionStmt->close();

    $defaults = [
        'super_admin' => array_column($permissions, 0),
        'supervisor' => [
            'dashboard.view', 'profile.view', 'profile.change_password',
            'documents.view', 'documents.manage',
            'payments.local_final.view', 'payments.local_final.create',
            'payments.local_final.update', 'payments.local_final.delete',
            'payments.local_final.update_po_status', 'payments.local_final.approve',
            'payments.local_final.reverse_approval', 'payments.local_final.retrieve_from_account',
            'payments.local_final.edit_retrieved', 'payments.local_final.amend_paid_purchase',
            'payments.fx_final.view', 'payments.fx_final.create',
            'payments.fx_final.update', 'payments.fx_final.delete',
            'payments.fx_final.update_po_status', 'payments.fx_final.approve',
            'payments.fx_final.reverse_approval', 'payments.fx_final.retrieve_from_account',
            'payments.fx_final.edit_retrieved', 'payments.fx_final.amend_paid_purchase',
            'payments.fx_advance.view', 'payments.fx_advance.create',
            'payments.fx_advance.update', 'payments.fx_advance.delete',
            'payments.fx_advance.update_po_status', 'payments.fx_advance.approve',
            'payments.fx_advance.reverse_approval', 'payments.fx_advance.retrieve_from_account',
            'payments.fx_advance.edit_retrieved', 'payments.fx_advance.amend_paid_po',
            'payments.fx_advance.approve_po_amendment', 'payments.fx_advance.resolve_po_reconciliation',
            'payments.local_advance.view', 'payments.local_advance.create',
            'payments.local_advance.update', 'payments.local_advance.delete',
            'payments.local_advance.update_po_status', 'payments.local_advance.approve',
            'payments.local_advance.reverse_approval', 'payments.local_advance.retrieve_from_account',
            'payments.local_advance.edit_retrieved', 'payments.local_advance.amend_paid_po',
            'payments.local_advance.approve_po_amendment',
            'payments.local_advance.resolve_po_reconciliation',
        ],
        'officer' => [
            'dashboard.view', 'profile.view', 'profile.change_password',
            'documents.view', 'documents.manage',
            'payments.local_final.view', 'payments.local_final.create',
            'payments.local_final.update', 'payments.local_final.amend_paid_purchase',
            'payments.fx_final.view', 'payments.fx_final.create',
            'payments.fx_final.update', 'payments.fx_final.amend_paid_purchase',
            'payments.fx_advance.view', 'payments.fx_advance.create',
            'payments.fx_advance.update',
            'payments.local_advance.view', 'payments.local_advance.create',
            'payments.local_advance.update', 'payments.local_advance.amend_paid_po',
        ],
    ];

    $rolePermissionStmt = $conn->prepare(
        'INSERT INTO procurement_role_permissions (role, permission_code, is_enabled)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)'
    );
    if (!$rolePermissionStmt) {
        throw new RuntimeException('Unable to initialize procurement role permissions.', 500);
    }
    foreach ($defaults as $role => $codes) {
        foreach ($codes as $code) {
            $rolePermissionStmt->bind_param('ss', $role, $code);
            $rolePermissionStmt->execute();
        }
    }
    $rolePermissionStmt->close();

    // Retire the original combined access-update permission in favour of explicit access administration permissions.
    $conn->query("UPDATE procurement_permissions SET is_active = 0 WHERE code = 'users.update_access'");

    // Administrative rights are individual grants. They are never inherited by every supervisor/officer.
    $conn->query(
        "UPDATE procurement_role_permissions
         SET is_enabled = 0
         WHERE role IN ('supervisor', 'officer')
           AND permission_code IN (
               'users.view', 'users.create', 'users.update', 'users.delete',
               'access.users.view', 'access.users.manage', 'access.users.grant_admin',
               'roles_permissions.view', 'roles_permissions.manage'
           )"
    );

    // Existing accounting Super_Admin accounts retain cross-department ProcureDesk access.
    $conn->query(
        "INSERT IGNORE INTO procurement_user_access (user_id, role, is_active, created_by, updated_by)
         SELECT id, 'super_admin', 1, id, id
         FROM user_table
         WHERE integrity = 'Super_Admin' AND status = 'Active'"
    );
}

function procurementJwtSecret(): string
{
    $secret = (string) envValue('PROCUREMENT_JWT_SECRET', envValue('JWT_SECRET', ''));
    if (strlen($secret) < 32 || in_array(strtolower($secret), ['xxx', 'replace_me', 'your_default_secret'], true)) {
        throw new RuntimeException('PROCUREMENT_JWT_SECRET or JWT_SECRET must contain at least 32 random characters.', 500);
    }

    return $secret;
}

function procurementJwtIssuer(): string
{
    return (string) envValue('PROCUREMENT_JWT_ISSUER', 'acctlab-procurement-api');
}

function procurementJwtAudience(): string
{
    return (string) envValue('PROCUREMENT_JWT_AUDIENCE', 'acctlab-procurement-web');
}

function procurementRefreshCookieName(): string
{
    return (string) envValue('PROCUREMENT_REFRESH_COOKIE_NAME', 'acctlab_procurement_refresh');
}

function procurementCsrfCookieName(): string
{
    return (string) envValue('PROCUREMENT_CSRF_COOKIE_NAME', 'acctlab_procurement_csrf');
}

function procurementCookiePath(): string
{
    $default = rtrim((string) envValue('API_BASE_PATH', '/acctlab-server/api'), '/') . '/procurement';
    return (string) envValue('PROCUREMENT_AUTH_COOKIE_PATH', $default);
}

function procurementCookieOptions(bool $httpOnly, ?int $expires = null): array
{
    $options = cookieOptions($httpOnly, $expires);
    $options['path'] = procurementCookiePath();
    return $options;
}

function procurementSetRefreshCookie(string $refreshToken, int $expiresAt): void
{
    setcookie(procurementRefreshCookieName(), $refreshToken, procurementCookieOptions(true, $expiresAt));
}

function procurementIssueCsrfCookie(): string
{
    $csrfToken = bin2hex(random_bytes(32));
    $expiresAt = time() + (int) envValue('PROCUREMENT_REFRESH_TOKEN_EXPIRES_IN', envValue('REFRESH_TOKEN_EXPIRES_IN', 2592000));
    setcookie(procurementCsrfCookieName(), $csrfToken, procurementCookieOptions(false, $expiresAt));
    return $csrfToken;
}

function procurementClearAuthCookies(): void
{
    setcookie(procurementRefreshCookieName(), '', procurementCookieOptions(true, time() - 3600));
    setcookie(procurementCsrfCookieName(), '', procurementCookieOptions(false, time() - 3600));
}

function procurementRequireCsrfToken(): void
{
    requireTrustedRequestOrigin();

    $cookieToken = (string) ($_COOKIE[procurementCsrfCookieName()] ?? '');
    $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Security validation failed. Refresh the page and try again.'], 403);
    }
}

function procurementPermissionCodes(mysqli $conn, string $role, ?int $userId = null): array
{
    if (!in_array($role, PROCUREMENT_ROLES, true)) {
        return [];
    }

    if ($role === 'super_admin') {
        $stmt = $conn->prepare(
            'SELECT code FROM procurement_permissions WHERE is_active = 1 ORDER BY sort_order, code'
        );
        $stmt->execute();
    } else {
        $resolvedUserId = $userId ?? 0;
        $stmt = $conn->prepare(
            'SELECT p.code,
                    COALESCE(up.is_enabled, rp.is_enabled, 0) AS effective_enabled
             FROM procurement_permissions p
             LEFT JOIN procurement_role_permissions rp
                    ON rp.permission_code = p.code AND rp.role = ?
             LEFT JOIN procurement_user_permissions up
                    ON up.permission_code = p.code AND up.user_id = ?
             WHERE p.is_active = 1
             ORDER BY p.sort_order, p.code'
        );
        $stmt->bind_param('si', $role, $resolvedUserId);
        $stmt->execute();
    }

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $codes = [];
    foreach ($rows as $row) {
        if ($role === 'super_admin' || (int) ($row['effective_enabled'] ?? 0) === 1) {
            $codes[] = (string) $row['code'];
        }
    }
    return array_values($codes);
}

function procurementRolePermissionCodes(mysqli $conn, string $role): array
{
    return procurementPermissionCodes($conn, $role, null);
}

function procurementDelegablePermissionCodes(mysqli $conn): array
{
    $stmt = $conn->prepare(
        'SELECT code FROM procurement_permissions
         WHERE is_active = 1 AND is_delegable = 1
         ORDER BY sort_order, code'
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_values(array_map(static fn(array $row): string => (string) $row['code'], $rows));
}

function procurementPublicUserPayload(array $user, array $permissions): array
{
    $role = (string) $user['role'];
    return [
        'id' => (int) $user['id'],
        'fname' => (string) ($user['fname'] ?? ''),
        'lname' => (string) ($user['lname'] ?? ''),
        'full_name' => trim((string) ($user['fname'] ?? '') . ' ' . (string) ($user['lname'] ?? '')),
        'email' => (string) $user['email'],
        'department' => strtolower((string) ($user['department'] ?? 'account')),
        'role' => $role,
        'is_admin' => procurementUserHasAdminRights($permissions, $role),
        'permissions' => array_values($permissions),
    ];
}

function procurementIssueAccessToken(array $user, array $permissions): array
{
    $issuedAt = time();
    $expiresIn = (int) envValue('PROCUREMENT_JWT_ACCESS_EXPIRES_IN', envValue('JWT_ACCESS_EXPIRES_IN', 900));
    $expiresAt = $issuedAt + max(300, $expiresIn);

    $payload = [
        'iss' => procurementJwtIssuer(),
        'aud' => procurementJwtAudience(),
        'iat' => $issuedAt,
        'nbf' => $issuedAt - 5,
        'exp' => $expiresAt,
        'jti' => bin2hex(random_bytes(16)),
        'type' => 'access',
        'app' => 'procurement',
        'sub' => (string) $user['id'],
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'department' => strtolower((string) ($user['department'] ?? 'account')),
        'is_admin' => procurementUserHasAdminRights($permissions, (string) $user['role']),
        'permissions' => array_values($permissions),
    ];

    return [
        'token' => JWT::encode($payload, procurementJwtSecret(), 'HS256'),
        'expires_at' => $expiresAt,
    ];
}

function procurementIssueRefreshSession(
    mysqli $conn,
    int $userId,
    bool $ensureTables = true,
    ?string $familyId = null,
    bool $writeCookie = true
): array {
    if ($ensureTables) {
        procurementEnsureAuthenticationTables($conn);
    }

    $token = bin2hex(random_bytes(64));
    $hash = hash('sha256', $token);
    $familyId = $familyId ?: $hash;
    $expiresAtUnix = time() + (int) envValue('PROCUREMENT_REFRESH_TOKEN_EXPIRES_IN', envValue('REFRESH_TOKEN_EXPIRES_IN', 2592000));
    $expiresAt = date('Y-m-d H:i:s', $expiresAtUnix);
    $ipAddress = clientIpAddress();
    $userAgent = requestUserAgent();

    $stmt = $conn->prepare(
        'INSERT INTO procurement_auth_refresh_tokens (user_id, token_hash, family_id, expires_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to create secure procurement session.', 500);
    }
    $stmt->bind_param('isssss', $userId, $hash, $familyId, $expiresAt, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();

    if ($writeCookie) {
        procurementSetRefreshCookie($token, $expiresAtUnix);
    }

    return ['hash' => $hash, 'token' => $token, 'expires_at' => $expiresAtUnix];
}

function procurementCurrentRefreshCookie(): string
{
    return trim((string) ($_COOKIE[procurementRefreshCookieName()] ?? ''));
}

function procurementRotateRefreshSession(mysqli $conn): array
{
    procurementEnsureAuthenticationTables($conn);
    $token = procurementCurrentRefreshCookie();
    if ($token === '') {
        throw new RuntimeException('Authentication required.', 401);
    }

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT rt.id AS refresh_id, rt.user_id, rt.family_id, rt.expires_at, rt.revoked_at,
                u.id, u.fname, u.lname, u.email, u.department, u.status, pua.role, pua.is_active
         FROM procurement_auth_refresh_tokens rt
         INNER JOIN user_table u ON u.id = rt.user_id
         INNER JOIN procurement_user_access pua ON pua.user_id = u.id
         WHERE rt.token_hash = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session || strtotime((string) $session['expires_at']) <= time()) {
        procurementClearAuthCookies();
        throw new RuntimeException('Session has expired. Please sign in again.', 401);
    }

    if (!empty($session['revoked_at'])) {
        $familyId = (string) $session['family_id'];
        $revokeFamily = $conn->prepare(
            'UPDATE procurement_auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE family_id = ?'
        );
        $revokeFamily->bind_param('s', $familyId);
        $revokeFamily->execute();
        $revokeFamily->close();
        procurementClearAuthCookies();
        throw new RuntimeException('Session security check failed. Please sign in again.', 401);
    }

    if (strcasecmp((string) $session['status'], 'Active') !== 0 || (int) $session['is_active'] !== 1) {
        procurementRevokeAllUserSessions($conn, (int) $session['user_id']);
        procurementClearAuthCookies();
        throw new RuntimeException('Your procurement account is inactive.', 403);
    }

    if (!procurementUserCanAccessApp($session)) {
        procurementRevokeAllUserSessions($conn, (int) $session['user_id']);
        procurementClearAuthCookies();
        throw new RuntimeException('ProcureDesk is restricted to the procurement department.', 403);
    }

    if (!in_array((string) $session['role'], PROCUREMENT_ROLES, true)) {
        procurementClearAuthCookies();
        throw new RuntimeException('Your procurement role is invalid.', 403);
    }

    $conn->begin_transaction();
    try {
        $refreshId = (int) $session['refresh_id'];
        $revokeStmt = $conn->prepare(
            'UPDATE procurement_auth_refresh_tokens SET revoked_at = NOW(), last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL'
        );
        $revokeStmt->bind_param('i', $refreshId);
        $revokeStmt->execute();
        if ($revokeStmt->affected_rows !== 1) {
            $revokeStmt->close();
            throw new RuntimeException('Session refresh could not be completed.', 401);
        }
        $revokeStmt->close();

        $newSession = procurementIssueRefreshSession(
            $conn,
            (int) $session['user_id'],
            false,
            (string) $session['family_id'],
            false
        );
        $newHash = (string) $newSession['hash'];
        $replaceStmt = $conn->prepare('UPDATE procurement_auth_refresh_tokens SET replaced_by_hash = ? WHERE id = ?');
        $replaceStmt->bind_param('si', $newHash, $refreshId);
        $replaceStmt->execute();
        $replaceStmt->close();
        $conn->commit();
        procurementSetRefreshCookie((string) $newSession['token'], (int) $newSession['expires_at']);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return $session;
}

function procurementRevokeRefreshSession(mysqli $conn): void
{
    procurementEnsureAuthenticationTables($conn);
    $token = procurementCurrentRefreshCookie();
    if ($token !== '') {
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare(
            'UPDATE procurement_auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE token_hash = ?'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $stmt->close();
    }

    procurementClearAuthCookies();
}

function procurementRevokeAllUserSessions(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare(
        'UPDATE procurement_auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function procurementLoginAttemptEmailHash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function procurementAssertLoginNotRateLimited(mysqli $conn, string $email): void
{
    procurementEnsureAuthenticationTables($conn);
    $hash = procurementLoginAttemptEmailHash($email);
    $ip = clientIpAddress();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS failures
         FROM procurement_auth_login_attempts
         WHERE email_hash = ? AND ip_address = ? AND successful = 0
           AND attempted_at > (NOW() - INTERVAL 15 MINUTE)"
    );
    $stmt->bind_param('ss', $hash, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $maxAttempts = (int) envValue('PROCUREMENT_LOGIN_MAX_ATTEMPTS', envValue('LOGIN_MAX_ATTEMPTS', 5));
    if ((int) ($row['failures'] ?? 0) >= $maxAttempts) {
        throw new RuntimeException('Too many sign-in attempts. Please wait 15 minutes and try again.', 429);
    }
}

function procurementRecordLoginAttempt(mysqli $conn, string $email, bool $successful): void
{
    $hash = procurementLoginAttemptEmailHash($email);
    $ip = clientIpAddress();
    $successFlag = $successful ? 1 : 0;
    $stmt = $conn->prepare(
        'INSERT INTO procurement_auth_login_attempts (email_hash, ip_address, successful) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('ssi', $hash, $ip, $successFlag);
    $stmt->execute();
    $stmt->close();

    if ($successful) {
        $clean = $conn->prepare(
            'DELETE FROM procurement_auth_login_attempts WHERE email_hash = ? AND ip_address = ? AND successful = 0'
        );
        $clean->bind_param('ss', $hash, $ip);
        $clean->execute();
        $clean->close();
    }

    $conn->query("DELETE FROM procurement_auth_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
}

function procurementPermissionCatalogue(mysqli $conn): array
{
    $stmt = $conn->prepare(
        'SELECT code, name, description, category, sort_order, is_delegable, is_role_assignable
         FROM procurement_permissions
         WHERE is_active = 1
         ORDER BY category, sort_order, name'
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_delegable'] = (bool) $row['is_delegable'];
        $row['is_role_assignable'] = (bool) $row['is_role_assignable'];
    }
    unset($row);
    return $rows;
}

function procurementNormalizePermissionSelection(array $permissions): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn(mixed $code): string => trim((string) $code),
        $permissions
    ))));
}

function procurementSetUserPermissionSelection(
    mysqli $conn,
    int $targetUserId,
    string $targetRole,
    array $selectedPermissions,
    array $actor
): array {
    if ($targetRole === 'super_admin') {
        $delete = $conn->prepare('DELETE FROM procurement_user_permissions WHERE user_id = ?');
        $delete->bind_param('i', $targetUserId);
        $delete->execute();
        $delete->close();
        return procurementPermissionCodes($conn, 'super_admin', $targetUserId);
    }

    $selectedPermissions = procurementNormalizePermissionSelection($selectedPermissions);
    $catalogue = procurementPermissionCatalogue($conn);
    $validCodes = array_column($catalogue, 'code');
    $delegableCodes = array_values(array_map(
        static fn(array $row): string => (string) $row['code'],
        array_filter($catalogue, static fn(array $row): bool => (bool) $row['is_delegable'])
    ));

    $invalid = array_values(array_diff($selectedPermissions, $validCodes));
    if ($invalid !== []) {
        throw new RuntimeException('One or more selected permissions are invalid.', 400);
    }

    $selectedDelegable = array_values(array_intersect($selectedPermissions, $delegableCodes));
    if (array_intersect($selectedDelegable, PROCUREMENT_ADMIN_PERMISSION_CODES) !== []) {
        $selectedDelegable = array_values(array_unique(array_merge(
            $selectedDelegable,
            PROCUREMENT_ADMIN_PERMISSION_CODES
        )));
    } else {
        $selectedDelegable = array_values(array_unique($selectedDelegable));
    }

    if ($targetRole !== 'supervisor'
        && array_intersect($selectedDelegable, PROCUREMENT_ADMIN_PERMISSION_CODES) !== []) {
        throw new RuntimeException('Administrative rights can only be granted to a supervisor.', 400);
    }

    $actorRole = (string) ($actor['role'] ?? '');
    $actorPermissions = array_values($actor['permissions'] ?? []);
    $currentEffectivePermissions = procurementPermissionCodes($conn, $targetRole, $targetUserId);
    $currentDelegable = array_values(array_intersect($currentEffectivePermissions, $delegableCodes));
    if ($actorRole !== 'super_admin') {
        if (!in_array('access.users.manage', $actorPermissions, true)) {
            throw new RuntimeException('You are not permitted to manage user access.', 403);
        }
        $changedPermissions = array_values(array_unique(array_merge(
            array_diff($selectedDelegable, $currentDelegable),
            array_diff($currentDelegable, $selectedDelegable)
        )));
        $changesAdminRights = array_intersect($changedPermissions, PROCUREMENT_ADMIN_PERMISSION_CODES) !== [];
        if ($changesAdminRights && !in_array('access.users.grant_admin', $actorPermissions, true)) {
            throw new RuntimeException('You are not permitted to grant or revoke administrative access.', 403);
        }
        $notOwned = array_values(array_diff($selectedDelegable, $actorPermissions));
        if ($notOwned !== []) {
            throw new RuntimeException('You cannot grant permissions that you do not possess.', 403);
        }
    }

    $basePermissions = procurementRolePermissionCodes($conn, $targetRole);
    $conn->query('DELETE FROM procurement_user_permissions WHERE user_id = ' . (int) $targetUserId);

    $upsert = $conn->prepare(
        'INSERT INTO procurement_user_permissions (user_id, permission_code, is_enabled, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)'
    );
    if (!$upsert) {
        throw new RuntimeException('Unable to update user permissions.', 500);
    }

    $actorId = (int) $actor['id'];
    foreach ($delegableCodes as $permissionCode) {
        $baseEnabled = in_array($permissionCode, $basePermissions, true);
        $selected = in_array($permissionCode, $selectedDelegable, true);
        if ($baseEnabled === $selected) {
            continue;
        }
        $enabled = $selected ? 1 : 0;
        $upsert->bind_param('isii', $targetUserId, $permissionCode, $enabled, $actorId);
        $upsert->execute();
    }
    $upsert->close();

    return procurementPermissionCodes($conn, $targetRole, $targetUserId);
}

function procurementAssertLastActiveSuperAdminIsPreserved(
    mysqli $conn,
    array $target,
    ?string $newRole = null,
    ?bool $newActive = null,
    bool $deleting = false
): void {
    if ((string) ($target['role'] ?? '') !== 'super_admin' || (int) ($target['is_active'] ?? 0) !== 1) {
        return;
    }

    $removesSuperAdmin = $deleting
        || ($newRole !== null && $newRole !== 'super_admin')
        || $newActive === false;
    if (!$removesSuperAdmin) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM procurement_user_access pua
         INNER JOIN user_table u ON u.id = pua.user_id
         WHERE pua.role = 'super_admin' AND pua.is_active = 1 AND u.status = 'Active'"
    );
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    if ($count <= 1) {
        throw new RuntimeException('At least one active procurement super admin is required.', 409);
    }
}

function procurementWriteAuditLog(mysqli $conn, int $actorId, string $actorEmail, string $action): void
{
    $stmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
    if ($stmt) {
        $stmt->bind_param('iss', $actorId, $action, $actorEmail);
        $stmt->execute();
        $stmt->close();
    }
}
