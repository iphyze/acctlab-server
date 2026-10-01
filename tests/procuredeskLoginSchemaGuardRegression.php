<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auth = file_get_contents($root . '/includes/procurementAuthService.php');
$login = file_get_contents($root . '/routes/procurement/auth/login.php');

$checks = [
    'login_still_uses_procurement_auth_service' =>
        str_contains($login, "require_once 'includes/procurementAuthService.php';"),
    'login_rate_limit_still_bootstraps_auth_storage' =>
        str_contains($login, 'procurementAssertLoginNotRateLimited($conn, $email);'),
    'column_check_uses_selected_database_directly' =>
        str_contains($auth, 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'),
    'column_check_no_longer_depends_on_active_database_session_variable' =>
        !preg_match(
            "/function procurementEnsureColumn\\(.*?@active_database_name.*?^}/ms",
            $auth
        ),
    'existing_column_returns_without_alter' =>
        str_contains($auth, "if (\$exists) {\n        return;\n    }"),
    'duplicate_column_race_is_safe' =>
        str_contains($auth, "if ((int) \$error->getCode() === 1060) {\n            return;\n        }"),
    'non_duplicate_schema_errors_remain_failures' =>
        str_contains($auth, "'Unable to update procurement database structure.'"),
    'schema_identifiers_are_restricted' =>
        str_contains($auth, "preg_match('/^[A-Za-z0-9_]+$/', \$table)")
        && str_contains($auth, "preg_match('/^[A-Za-z0-9_]+$/', \$column)"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
