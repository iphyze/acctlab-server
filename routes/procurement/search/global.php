<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementRequestCanonicalRuntimeService.php';

function procurementGlobalSearchHasPermission(array $user, string $permission): bool
{
    return (string) ($user['role'] ?? '') === 'super_admin'
        || in_array($permission, array_map('strval', $user['permissions'] ?? []), true);
}

function procurementGlobalSearchHasAnyPermission(array $user, array $permissions): bool
{
    if ((string) ($user['role'] ?? '') === 'super_admin') {
        return true;
    }

    $granted = array_flip(array_map('strval', $user['permissions'] ?? []));
    foreach ($permissions as $permission) {
        if (isset($granted[(string) $permission])) {
            return true;
        }
    }
    return false;
}

function procurementGlobalSearchVisibleTypes(array $user, string $scope): array
{
    $permissionMap = [
        'local_final_purchase' => 'payments.local_final.view',
        'local_advance_purchase' => 'payments.local_advance.view',
        'fx_final_purchase' => 'payments.fx_final.view',
        'fx_advance_purchase' => 'payments.fx_advance.view',
    ];

    $types = [];
    foreach ($permissionMap as $requestType => $permission) {
        if (!procurementGlobalSearchHasPermission($user, $permission)) {
            continue;
        }
        if ($scope === 'local' && !str_starts_with($requestType, 'local_')) {
            continue;
        }
        if ($scope === 'fx' && !str_starts_with($requestType, 'fx_')) {
            continue;
        }
        if ($scope === 'users') {
            continue;
        }
        $types[] = $requestType;
    }

    return $types;
}

function procurementGlobalSearchRequestLabel(string $requestType): string
{
    return match ($requestType) {
        'local_final_purchase' => 'Local Final Purchase',
        'local_advance_purchase' => 'Local Advance Purchase',
        'fx_final_purchase' => 'FX Final Purchase',
        'fx_advance_purchase' => 'FX Advance Purchase',
        default => 'Purchase',
    };
}

function procurementGlobalSearchRequestCategory(string $requestType): string
{
    return str_starts_with($requestType, 'local_') ? 'Local Purchase' : 'FX Purchase';
}

function procurementGlobalSearchRequestRoute(string $requestType, int $publicId): ?string
{
    if ($publicId <= 0) {
        return null;
    }

    return match ($requestType) {
        'local_final_purchase' => "/payments/local/final/{$publicId}",
        'local_advance_purchase' => "/payments/local/advance/{$publicId}",
        'fx_final_purchase' => "/payments/foreign/final/{$publicId}",
        'fx_advance_purchase' => "/payments/foreign/advance/{$publicId}",
        default => null,
    };
}

function procurementGlobalSearchStatus(array $row): string
{
    $payment = trim((string) ($row['payment_status'] ?? ''));
    $approval = trim((string) ($row['approval_status'] ?? ''));
    $handoff = trim((string) ($row['handoff_status'] ?? ''));
    $poStatus = trim((string) ($row['po_status'] ?? ''));

    if (strcasecmp($payment, 'Cancelled') === 0 || strcasecmp($poStatus, 'Cancelled') === 0) {
        return 'Cancelled';
    }
    if (strcasecmp($payment, 'Failed') === 0) {
        return 'Failed';
    }
    if (strcasecmp($payment, 'Paid') === 0) {
        return 'Paid';
    }
    if (strcasecmp($handoff, 'Retrieved') === 0) {
        return 'Returned';
    }
    if (strcasecmp($approval, 'Unapproved') === 0) {
        return 'Pending approval';
    }
    if (strcasecmp($handoff, 'In Account') === 0) {
        return 'With accounts';
    }
    if (strcasecmp($approval, 'Approved') === 0) {
        return 'Approved';
    }

    return $payment !== '' ? $payment : ($poStatus !== '' ? $poStatus : 'Open');
}

function procurementGlobalSearchScore(string $query, array $row): int
{
    $needle = strtolower(trim($query));
    if ($needle === '') {
        return 0;
    }

    $values = [
        (string) ($row['reference'] ?? ''),
        (string) ($row['resolved_supplier_name'] ?? ''),
        (string) ($row['project_code'] ?? ''),
        (string) ($row['project_name'] ?? ''),
        (string) ($row['invoice_number'] ?? ''),
        (string) ($row['request_number'] ?? ''),
    ];

    $score = 0;
    foreach ($values as $index => $value) {
        $haystack = strtolower(trim($value));
        if ($haystack === '') {
            continue;
        }
        if ($haystack === $needle) {
            $score += $index === 0 ? 150 : 110;
        } elseif (str_starts_with($haystack, $needle)) {
            $score += $index === 0 ? 100 : 70;
        } elseif (str_contains($haystack, $needle)) {
            $score += $index === 0 ? 70 : 40;
        }
    }
    return $score;
}

function procurementGlobalSearchFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare ProcureDesk search.', 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }

    $user = procurementAuthenticateUser($conn);
    $query = trim((string) ($_GET['q'] ?? ''));
    $scope = strtolower(trim((string) ($_GET['scope'] ?? 'all')));
    $limit = min(32, max(1, (int) ($_GET['limit'] ?? 24)));
    $allowedScopes = ['all', 'local', 'fx', 'users'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'all';
    }

    $canSearchUsers = procurementGlobalSearchHasAnyPermission($user, ['users.view', 'access.users.view']);
    if ($scope === 'users' && !$canSearchUsers) {
        $scope = 'all';
    }

    $queryLength = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    if ($queryLength < 2) {
        jsonResponse([
            'status' => 'Success',
            'data' => [],
            'meta' => [
                'query' => $query,
                'scope' => $scope,
                'total' => 0,
                'group_counts' => [],
                'available_scopes' => array_values(array_filter(
                    ['all', 'local', 'fx', $canSearchUsers ? 'users' : null]
                )),
            ],
        ]);
    }

    $results = [];
    $visibleTypes = procurementGlobalSearchVisibleTypes($user, $scope);

    if ($visibleTypes !== []) {
        $requiredColumns = [
            'id', 'legacy_source_id', 'request_type', 'request_number', 'purchase_number', 'po_number',
            'invoice_number', 'grn_ref', 'material_type', 'project_code', 'project_name', 'supplier_name',
            'supplier_ledger', 'currency', 'purchase_value', 'expected_payment', 'approval_status',
            'handoff_status', 'payment_status', 'po_status', 'remark', 'created_by', 'created_at', 'deleted_at',
            'po_id', 'po_revision_id',
        ];
        if (!procurementRequestCanonicalRuntimeColumnsReady($conn, 'procurement_requests', $requiredColumns)) {
            throw new RuntimeException('Procurement search storage is unavailable.', 500);
        }

        $typePlaceholders = implode(', ', array_fill(0, count($visibleTypes), '?'));
        $like = '%' . $query . '%';
        $searchFields = [
            'r.request_number', 'r.purchase_number', 'r.po_number', 'r.invoice_number', 'r.grn_ref',
            'r.material_type', 'r.project_code', 'r.project_name', 'r.supplier_name', 'r.supplier_ledger',
            'r.remark', 'r.payment_status', 'r.approval_status', 'r.handoff_status', 'r.po_status', 'r.currency',
            'ap.po_number', 'apr.po_number', 'ap.supplier_name', 'apr.supplier_name',
            "CONCAT_WS(' ', COALESCE(u.fname,''), COALESCE(u.lname,''), COALESCE(u.email,''))",
        ];
        $searchSql = implode(' OR ', array_map(static fn(string $field): string => "{$field} LIKE ?", $searchFields));
        $types = str_repeat('s', count($visibleTypes) + count($searchFields));
        $params = array_merge($visibleTypes, array_fill(0, count($searchFields), $like));
        $candidateLimit = min(80, max($limit * 3, 36));
        $types .= 'i';
        $params[] = $candidateLimit;

        $rows = procurementGlobalSearchFetchAll(
            $conn,
            "SELECT
                r.id AS canonical_id,
                r.legacy_source_id AS public_id,
                r.request_type,
                r.request_number,
                r.purchase_number,
                r.invoice_number,
                r.project_code,
                r.project_name,
                r.currency,
                r.purchase_value,
                r.expected_payment,
                r.approval_status,
                r.handoff_status,
                r.payment_status,
                r.po_status,
                r.created_at,
                COALESCE(NULLIF(TRIM(apr.po_number), ''), NULLIF(TRIM(ap.po_number), ''), NULLIF(TRIM(r.po_number), ''), NULLIF(TRIM(r.purchase_number), ''), NULLIF(TRIM(r.request_number), '')) AS reference,
                COALESCE(NULLIF(TRIM(apr.supplier_name), ''), NULLIF(TRIM(ap.supplier_name), ''), NULLIF(TRIM(r.supplier_name), ''), NULLIF(TRIM(r.supplier_ledger), '')) AS resolved_supplier_name,
                CONCAT(TRIM(COALESCE(u.fname, '')), CASE WHEN COALESCE(u.fname, '') <> '' AND COALESCE(u.lname, '') <> '' THEN ' ' ELSE '' END, TRIM(COALESCE(u.lname, ''))) AS officer_name
             FROM procurement_requests r
             LEFT JOIN procurement_local_advance_pos ap
               ON ap.id = r.po_id
              AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
             LEFT JOIN procurement_local_advance_po_revisions apr
               ON apr.id = r.po_revision_id
              AND r.request_type IN ('local_advance_purchase', 'fx_advance_purchase')
             LEFT JOIN user_table u ON u.id = r.created_by
             WHERE r.deleted_at IS NULL
               AND r.request_type IN ({$typePlaceholders})
               AND ({$searchSql})
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ?",
            $types,
            $params
        );

        foreach ($rows as $row) {
            $requestType = (string) ($row['request_type'] ?? '');
            $publicId = (int) ($row['public_id'] ?? 0);
            $path = procurementGlobalSearchRequestRoute($requestType, $publicId);
            if ($path === null) {
                continue;
            }

            $isAdvance = in_array($requestType, ['local_advance_purchase', 'fx_advance_purchase'], true);
            $isLocal = str_starts_with($requestType, 'local_');
            $reference = trim((string) ($row['reference'] ?? '')) ?: 'Request #' . $publicId;
            $supplier = trim((string) ($row['resolved_supplier_name'] ?? '')) ?: 'Supplier not recorded';
            $project = trim((string) ($row['project_name'] ?? ''));
            $projectCode = trim((string) ($row['project_code'] ?? ''));
            $invoice = trim((string) ($row['invoice_number'] ?? ''));
            $subtitleParts = array_values(array_filter([$supplier, $projectCode ?: $project, $invoice]));

            $normalized = $row;
            $normalized['reference'] = $reference;
            $normalized['resolved_supplier_name'] = $supplier;
            $results[] = [
                'id' => $requestType . ':' . ($publicId > 0 ? $publicId : (int) ($row['canonical_id'] ?? 0)),
                'record_id' => $publicId,
                'type' => $requestType,
                'type_label' => procurementGlobalSearchRequestLabel($requestType),
                'category' => procurementGlobalSearchRequestCategory($requestType),
                'title' => $reference,
                'subtitle' => implode(' • ', $subtitleParts),
                'supplier' => $supplier,
                'project' => $projectCode ?: $project,
                'invoice_number' => $invoice,
                'officer' => trim((string) ($row['officer_name'] ?? '')),
                'status' => procurementGlobalSearchStatus($row),
                'amount' => (float) ($isAdvance ? ($row['expected_payment'] ?? 0) : ($row['purchase_value'] ?? 0)),
                'currency' => $isLocal ? 'NGN' : (strtoupper(trim((string) ($row['currency'] ?? ''))) ?: 'UNKNOWN'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'path' => $path,
                'score' => procurementGlobalSearchScore($query, $normalized),
            ];
        }
    }

    if (($scope === 'all' || $scope === 'users') && $canSearchUsers) {
        $like = '%' . $query . '%';
        $canManageUsers = procurementGlobalSearchHasAnyPermission($user, ['users.update', 'access.users.manage']);
        $userRows = procurementGlobalSearchFetchAll(
            $conn,
            "SELECT u.id, u.fname, u.lname, u.email, u.department, u.status, pua.role
             FROM user_table u
             INNER JOIN procurement_user_access pua ON pua.user_id = u.id AND pua.is_active = 1
             WHERE u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR pua.role LIKE ?
             ORDER BY u.fname ASC, u.lname ASC
             LIMIT 8",
            'sssss',
            [$like, $like, $like, $like, $like]
        );

        foreach ($userRows as $row) {
            $name = trim(implode(' ', array_filter([
                trim((string) ($row['fname'] ?? '')),
                trim((string) ($row['lname'] ?? '')),
            ]))) ?: (string) ($row['email'] ?? 'ProcureDesk user');
            $userId = (int) ($row['id'] ?? 0);
            $results[] = [
                'id' => 'user:' . $userId,
                'record_id' => $userId,
                'type' => 'procurement_user',
                'type_label' => 'ProcureDesk User',
                'category' => 'People & Access',
                'title' => $name,
                'subtitle' => implode(' • ', array_values(array_filter([
                    trim((string) ($row['email'] ?? '')),
                    trim((string) ($row['department'] ?? '')),
                    trim((string) ($row['role'] ?? '')),
                ]))),
                'status' => (string) ($row['status'] ?? 'Active'),
                'amount' => null,
                'currency' => null,
                'created_at' => null,
                'path' => $canManageUsers && $userId > 0 ? "/settings/users/{$userId}/edit" : '/settings/users',
                'score' => procurementGlobalSearchScore($query, [
                    'reference' => (string) ($row['email'] ?? ''),
                    'resolved_supplier_name' => $name,
                ]),
            ];
        }
    }

    usort($results, static function (array $left, array $right): int {
        $scoreCompare = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });

    $results = array_slice($results, 0, $limit);
    $groupCounts = [];
    foreach ($results as &$result) {
        unset($result['score']);
        $category = (string) ($result['category'] ?? 'Records');
        $groupCounts[$category] = ($groupCounts[$category] ?? 0) + 1;
    }
    unset($result);

    jsonResponse([
        'status' => 'Success',
        'data' => $results,
        'meta' => [
            'query' => $query,
            'scope' => $scope,
            'total' => count($results),
            'group_counts' => $groupCounts,
            'available_scopes' => array_values(array_filter(
                ['all', 'local', 'fx', $canSearchUsers ? 'users' : null]
            )),
        ],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('ProcureDesk global search error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to search ProcureDesk.' : $error->getMessage(),
    ], $status);
}
