<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function workspaceSearchTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    if (!$stmt) {
        return $cache[$table] = false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $cache[$table] = $exists;
}

/** @param array<int, mixed> $params */
function workspaceSearchBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $bind = [$types];
    foreach ($params as $index => $value) {
        $params[$index] = $value;
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}


function workspaceSearchScore(string $query, array $row): int
{
    $lower = static function (string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    };
    $needle = $lower(trim($query));
    if ($needle === '') {
        return 0;
    }

    $searchValue = $lower(trim((string) ($row['search_value'] ?? '')));
    $title = $lower(trim((string) ($row['title'] ?? '')));
    $subtitle = $lower(trim((string) ($row['subtitle'] ?? '')));
    $score = 0;

    if ($searchValue === $needle || $title === $needle) $score += 120;
    if ($searchValue !== '' && str_starts_with($searchValue, $needle)) $score += 80;
    if ($title !== '' && str_starts_with($title, $needle)) $score += 70;
    if ($searchValue !== '' && str_contains($searchValue, $needle)) $score += 55;
    if ($title !== '' && str_contains($title, $needle)) $score += 45;
    if ($subtitle !== '' && str_contains($subtitle, $needle)) $score += 25;

    return $score;
}

function workspaceSearchResultPath(array $source, array $row, string $query): string
{
    if (!empty($source['path_prefix'])) {
        return (string) $source['path_prefix'] . (int) ($row['id'] ?? 0);
    }

    $path = (string) ($source['path'] ?? '/');
    if (!empty($source['filterable'])) {
        $searchValue = trim((string) ($row['search_value'] ?? '')) ?: $query;
        $path .= (str_contains($path, '?') ? '&' : '?') . 'search=' . rawurlencode($searchValue);
    }
    return $path;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Route not found', 400);
    }

    $userData = authenticateUser();
    $integrity = (string) ($userData['integrity'] ?? '');
    $userId = (int) ($userData['id'] ?? 0);
    $isAdmin = in_array($integrity, ['Admin', 'Super_Admin'], true);
    $isSuperAdmin = $integrity === 'Super_Admin';
    $accountingYear = (int) ($userData['accounting_period'] ?? date('Y'));

    if ($userId <= 0) {
        throw new RuntimeException('Authentication required.', 401);
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $limit = min(max((int) ($_GET['limit'] ?? 28), 1), 40);
    $scope = strtolower(trim((string) ($_GET['scope'] ?? 'all')));
    $allowedScopes = ['all', 'fund_requests', 'payments', 'schedules', 'cash', 'master_data', 'reports', 'administration'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'all';
    }

    if (!$isAdmin && !in_array($scope, ['all', 'cash'], true)) {
        $scope = 'all';
    }

    $queryLength = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    if ($queryLength < 2) {
        echo json_encode([
            'status' => 'Success',
            'data' => [],
            'meta' => [
                'query' => $query,
                'total' => 0,
                'scope' => $scope,
                'accounting_year' => $accountingYear,
                'group_counts' => [],
                'available_scopes' => $isAdmin
                    ? $allowedScopes
                    : ['all', 'cash'],
            ],
        ]);
        exit;
    }

    $like = '%' . $query . '%';
    $sources = [];

    if ($isAdmin) {
        $sources = [
            [
                'table' => 'supplier_fund_request_table', 'type' => 'Supplier Request', 'module' => 'Supplier Fund Requests',
                'scope' => 'fund_requests', 'category' => 'Fund Requests', 'path' => '/payments/fund-request/supplier', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(invoice_number,''), NULLIF(project_code,'')) AS subtitle,
                                 payment_status AS status, amount, 'NGN' AS currency, COALESCE(NULLIF(invoice_number,''), suppliers_name) AS search_value
                          FROM supplier_fund_request_table
                          WHERE YEAR(created_at) = ? AND (suppliers_name LIKE ? OR invoice_number LIKE ? OR purchase_number LIKE ? OR po_number LIKE ? OR project_code LIKE ? OR description LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'issssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'advance_payment_request', 'type' => 'Advance Request', 'module' => 'Advance Fund Requests',
                'scope' => 'fund_requests', 'category' => 'Fund Requests', 'path' => '/payments/fund-request/advance', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(po_number,''), NULLIF(site,'')) AS subtitle,
                                 payment_status AS status, amount_payable AS amount, 'NGN' AS currency, COALESCE(NULLIF(po_number,''), suppliers_name) AS search_value
                          FROM advance_payment_request
                          WHERE YEAR(created_at) = ? AND (suppliers_name LIKE ? OR po_number LIKE ? OR site LIKE ? OR note LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'issss', 'params' => [$accountingYear, $like, $like, $like, $like],
            ],
            [
                'table' => 'expense_fund_request_table', 'type' => 'Expense Request', 'module' => 'Expense Fund Requests',
                'scope' => 'fund_requests', 'category' => 'Fund Requests', 'path' => '/payments/fund-request/expense', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(invoice_number,''), NULLIF(project_code,'')) AS subtitle,
                                 payment_status AS status, amount, 'NGN' AS currency, COALESCE(NULLIF(invoice_number,''), suppliers_name) AS search_value
                          FROM expense_fund_request_table
                          WHERE YEAR(created_at) = ? AND (suppliers_name LIKE ? OR invoice_number LIKE ? OR project_code LIKE ? OR description LIKE ? OR classification LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'compass_fund_request_table', 'type' => 'Compass Request', 'module' => 'Compass Fund Requests',
                'scope' => 'fund_requests', 'category' => 'Fund Requests', 'path' => '/payments/fund-request/compass', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(invoice_number,''), NULLIF(project_code,'')) AS subtitle,
                                 payment_status AS status, amount, 'NGN' AS currency, COALESCE(NULLIF(invoice_number,''), suppliers_name) AS search_value
                          FROM compass_fund_request_table
                          WHERE YEAR(created_at) = ? AND (suppliers_name LIKE ? OR invoice_number LIKE ? OR project_code LIKE ? OR description LIKE ? OR classification LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'fx_fund_request_table', 'type' => 'FX Fund Request', 'module' => 'FX Fund Requests',
                'scope' => 'fund_requests', 'category' => 'Fund Requests', 'path' => '/payments/fund-request/fx', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title,
                                 CONCAT_WS(' • ', request_type, currency, COALESCE(NULLIF(invoice_number,''), NULLIF(po_number,''), NULLIF(purchase_number,''), NULLIF(project_code,''))) AS subtitle,
                                 payment_status AS status, payable_amount AS amount, currency,
                                 COALESCE(NULLIF(invoice_number,''), NULLIF(po_number,''), NULLIF(purchase_number,''), suppliers_name) AS search_value
                          FROM fx_fund_request_table
                          WHERE YEAR(created_at) = ? AND (suppliers_name LIKE ? OR contact_person LIKE ? OR phone_number LIKE ? OR invoice_number LIKE ? OR purchase_number LIKE ? OR po_number LIKE ? OR project_code LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'fx_instruction_letter_table', 'type' => 'FX Payment', 'module' => 'FX Payments',
                'scope' => 'payments', 'category' => 'Payments', 'path' => '/payments/fx-payments/payments', 'filterable' => true,
                'sql' => "SELECT id, beneficiary_name AS title, CONCAT_WS(' • ', NULLIF(reference,''), NULLIF(payment_purpose,''), NULLIF(beneficiary_bank,'')) AS subtitle,
                                 payment_status AS status, amount_figure AS amount, currency, COALESCE(NULLIF(reference,''), beneficiary_name) AS search_value
                          FROM fx_instruction_letter_table
                          WHERE YEAR(created_at) = ? AND (beneficiary_name LIKE ? OR reference LIKE ? OR beneficiary_bank LIKE ? OR beneficiary_account_number LIKE ? OR payment_purpose LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'local_transfer', 'type' => 'Supplier Bank Instruction', 'module' => 'Supplier Bank Instructions',
                'scope' => 'payments', 'category' => 'Payments', 'path' => '/payments/letters/suppliers', 'filterable' => true,
                'sql' => "SELECT id, beneficiary_name AS title, CONCAT_WS(' • ', NULLIF(ben_bank_name,''), NULLIF(account_number,''), CONCAT('Batch ', batch)) AS subtitle,
                                 'Prepared' AS status, amount, 'NGN' AS currency, COALESCE(NULLIF(account_number,''), beneficiary_name) AS search_value
                          FROM local_transfer
                          WHERE YEAR(created_at) = ? AND (beneficiary_name LIKE ? OR account_number LIKE ? OR ben_bank_name LIKE ? OR payment_category LIKE ?)
                          ORDER BY id DESC LIMIT 5",
                'types' => 'issss', 'params' => [$accountingYear, $like, $like, $like, $like],
            ],
            [
                'table' => 'instruction_letter', 'type' => 'Inter-bank Instruction', 'module' => 'Inter-bank Instructions',
                'scope' => 'payments', 'category' => 'Payments', 'path' => '/payments/letters/inter-bank', 'filterable' => true,
                'sql' => "SELECT id, COALESCE(NULLIF(payment_to,''), letter_heading) AS title,
                                 CONCAT_WS(' • ', NULLIF(instruction_type,''), NULLIF(payment_bank_name,''), NULLIF(payment_date,'')) AS subtitle,
                                 'Prepared' AS status, payment_amount AS amount, 'NGN' AS currency, COALESCE(NULLIF(payment_to,''), NULLIF(letter_heading,'')) AS search_value
                          FROM instruction_letter
                          WHERE YEAR(payment_date) = ? AND (letter_heading LIKE ? OR instruction_type LIKE ? OR payment_to LIKE ? OR payment_bank_name LIKE ? OR tax_beneficiary LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'payment_schedule_tab', 'type' => 'Supplier GAPS Schedule', 'module' => 'Supplier GAPS Schedule',
                'scope' => 'schedules', 'category' => 'Schedules', 'path' => '/payments/gaps/suppliers', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(invoice_numbers,''), NULLIF(po_numbers,''), NULLIF(payment_date,'')) AS subtitle,
                                 'Scheduled' AS status, payment_amount AS amount, 'NGN' AS currency, COALESCE(NULLIF(invoice_numbers,''), NULLIF(po_numbers,''), suppliers_name) AS search_value
                          FROM payment_schedule_tab
                          WHERE YEAR(payment_date) = ? AND (suppliers_name LIKE ? OR invoice_numbers LIKE ? OR po_numbers LIKE ? OR account_number LIKE ? OR bank_name LIKE ? OR remark LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'issssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'advance_payment_schedule_tab', 'type' => 'Advance GAPS Schedule', 'module' => 'Advance GAPS Schedule',
                'scope' => 'schedules', 'category' => 'Schedules', 'path' => '/payments/gaps/advance', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(po_numbers,''), NULLIF(payment_date,''), CONCAT('Batch ', batch)) AS subtitle,
                                 'Scheduled' AS status, payment_amount AS amount, 'NGN' AS currency, COALESCE(NULLIF(po_numbers,''), suppliers_name) AS search_value
                          FROM advance_payment_schedule_tab
                          WHERE YEAR(payment_date) = ? AND (suppliers_name LIKE ? OR po_numbers LIKE ? OR account_number LIKE ? OR bank_name LIKE ? OR remark LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'other_payment_schedule', 'type' => 'Expense GAPS Schedule', 'module' => 'Expense GAPS Schedule',
                'scope' => 'schedules', 'category' => 'Schedules', 'path' => '/payments/gaps/expense', 'filterable' => true,
                'sql' => "SELECT id, suppliers_name AS title, CONCAT_WS(' • ', NULLIF(invoice_numbers,''), NULLIF(payment_date,''), CONCAT('Batch ', batch)) AS subtitle,
                                 'Scheduled' AS status, payment_amount AS amount, 'NGN' AS currency, COALESCE(NULLIF(invoice_numbers,''), suppliers_name) AS search_value
                          FROM other_payment_schedule
                          WHERE YEAR(payment_date) = ? AND (suppliers_name LIKE ? OR invoice_numbers LIKE ? OR account_number LIKE ? OR bank_name LIKE ? OR remark LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'isssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'union_payment_schedule', 'type' => 'Union Bank Schedule', 'module' => 'Union Bank Schedule',
                'scope' => 'schedules', 'category' => 'Schedules', 'path' => '/payments/union-bank-schedule', 'filterable' => true,
                'sql' => "SELECT id, supplier_name AS title, CONCAT_WS(' • ', NULLIF(invoice_number,''), NULLIF(po_number,''), NULLIF(payment_date,'')) AS subtitle,
                                 'Scheduled' AS status, payment_amount AS amount, 'NGN' AS currency, COALESCE(NULLIF(invoice_number,''), NULLIF(po_number,''), supplier_name) AS search_value
                          FROM union_payment_schedule
                          WHERE YEAR(payment_date) = ? AND (supplier_name LIKE ? OR invoice_number LIKE ? OR po_number LIKE ? OR account_number LIKE ? OR bank_name LIKE ? OR narration LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'issssss', 'params' => [$accountingYear, $like, $like, $like, $like, $like, $like],
            ],
            [
                'table' => 'location_table', 'type' => 'Project', 'module' => 'Projects',
                'scope' => 'master_data', 'category' => 'Master Data', 'path' => '/projects', 'filterable' => true,
                'sql' => "SELECT id, location AS title, CONCAT('Project code • ', code) AS subtitle, 'Active' AS status, NULL AS amount, NULL AS currency, code AS search_value
                          FROM location_table WHERE location LIKE ? OR code LIKE ? ORDER BY location ASC LIMIT 5",
                'types' => 'ss', 'params' => [$like, $like],
            ],
            [
                'table' => 'suppliers_table', 'type' => 'Ledger / Supplier', 'module' => 'Ledgers',
                'scope' => 'master_data', 'category' => 'Master Data', 'path' => '/ledgers', 'filterable' => true,
                'sql' => "SELECT id, supplier_name AS title, CONCAT('Supplier no. • ', supplier_number) AS subtitle, 'Active' AS status, NULL AS amount, NULL AS currency, supplier_number AS search_value
                          FROM suppliers_table WHERE supplier_name LIKE ? OR CAST(supplier_number AS CHAR) LIKE ? ORDER BY supplier_name ASC LIMIT 5",
                'types' => 'ss', 'params' => [$like, $like],
            ],
            [
                'table' => 'suppliers_account_details', 'type' => 'Bank Account', 'module' => 'Account Details',
                'scope' => 'master_data', 'category' => 'Master Data', 'path' => '/bank/account-details', 'filterable' => true,
                'sql' => "SELECT id, account_name AS title, CONCAT_WS(' • ', NULLIF(bank_name,''), NULLIF(account_number,'')) AS subtitle, 'Active' AS status, NULL AS amount, NULL AS currency, account_number AS search_value
                          FROM suppliers_account_details WHERE account_name LIKE ? OR account_number LIKE ? OR bank_name LIKE ? ORDER BY account_name ASC LIMIT 5",
                'types' => 'sss', 'params' => [$like, $like, $like],
            ],
            [
                'table' => 'bank_sortcode_tab', 'type' => 'Sort Code', 'module' => 'Sort Codes',
                'scope' => 'master_data', 'category' => 'Master Data', 'path' => '/bank/sortcodes', 'filterable' => true,
                'sql' => "SELECT id, bank_name AS title, CONCAT_WS(' • ', NULLIF(code_name,''), NULLIF(sort_code,'')) AS subtitle, 'Active' AS status, NULL AS amount, NULL AS currency, sort_code AS search_value
                          FROM bank_sortcode_tab WHERE bank_name LIKE ? OR code_name LIKE ? OR sort_code LIKE ? ORDER BY bank_name ASC LIMIT 5",
                'types' => 'sss', 'params' => [$like, $like, $like],
            ],
            [
                'table' => 'bank_beneficiary_details_table', 'type' => 'FX Beneficiary', 'module' => 'FX Beneficiaries',
                'scope' => 'master_data', 'category' => 'Master Data', 'path' => '/payments/fx-payments/beneficiaries', 'filterable' => true,
                'sql' => "SELECT id, beneficiary_name AS title, CONCAT_WS(' • ', NULLIF(beneficiary_bank,''), NULLIF(beneficiary_account_number,''), NULLIF(swift_code,'')) AS subtitle,
                                 'Active' AS status, NULL AS amount, NULL AS currency, COALESCE(NULLIF(beneficiary_account_number,''), beneficiary_name) AS search_value
                          FROM bank_beneficiary_details_table
                          WHERE beneficiary_name LIKE ? OR beneficiary_bank LIKE ? OR beneficiary_account_number LIKE ? OR swift_code LIKE ? OR intermediary_bank LIKE ?
                          ORDER BY beneficiary_name ASC LIMIT 5",
                'types' => 'sssss', 'params' => [$like, $like, $like, $like, $like],
            ],
            [
                'table' => 'bank_recons', 'type' => 'Bank Reconciliation', 'module' => 'Bank Reconciliations',
                'scope' => 'reports', 'category' => 'Reports', 'path_prefix' => '/reports/bank-reconciliation/workspace/',
                'sql' => "SELECT id, recon_number AS title, CONCAT_WS(' • ', NULLIF(company_name,''), NULLIF(bank_name,''), NULLIF(account_number,'')) AS subtitle,
                                 status, unreconciled_difference AS amount, currency, recon_number AS search_value
                          FROM bank_recons
                          WHERE (YEAR(period_from) = ? OR YEAR(period_to) = ?) AND (recon_number LIKE ? OR company_name LIKE ? OR bank_name LIKE ? OR account_number LIKE ? OR notes LIKE ?)
                          ORDER BY created_at DESC LIMIT 5",
                'types' => 'iisssss', 'params' => [$accountingYear, $accountingYear, $like, $like, $like, $like, $like],
            ],
        ];
    }

    // Cash Desk is available to every authenticated role, but non-admin users
    // are restricted to active account assignments to avoid cross-till leakage.
    if (workspaceSearchTableExists($conn, 'cash_transactions')) {
        $cashAccessSql = $isAdmin
            ? ''
            : " AND EXISTS (SELECT 1 FROM cash_account_users cau WHERE cau.account_id = ct.account_id AND cau.user_id = ? AND cau.is_active = 1)";
        $cashParams = [$accountingYear, $like, $like, $like, $like, $like];
        $cashTypes = 'isssss';
        if (!$isAdmin) {
            $cashParams[] = $userId;
            $cashTypes .= 'i';
        }
        $sources[] = [
            'table' => 'cash_transactions', 'type' => 'Cash Transaction', 'module' => 'Cashbook',
            'scope' => 'cash', 'category' => 'Cash Desk', 'path' => '/payments/cash/transactions', 'filterable' => true,
            'sql' => "SELECT ct.id, ct.person_name AS title,
                             CONCAT_WS(' • ', NULLIF(ct.transaction_reference,''), NULLIF(ct.transaction_type,''), NULLIF(ct.reason,'')) AS subtitle,
                             ct.status, ct.amount, ca.currency, ct.transaction_reference AS search_value
                      FROM cash_transactions ct
                      INNER JOIN cash_accounts ca ON ca.id = ct.account_id
                      WHERE ct.accounting_year = ? AND (ct.person_name LIKE ? OR ct.transaction_reference LIKE ? OR ct.external_reference LIKE ? OR ct.reason LIKE ? OR ct.description LIKE ?){$cashAccessSql}
                      ORDER BY ct.transaction_date DESC, ct.id DESC LIMIT 5",
            'types' => $cashTypes, 'params' => $cashParams,
        ];
    }

    if (workspaceSearchTableExists($conn, 'cash_ious')) {
        $iouAccessSql = $isAdmin
            ? ''
            : " AND EXISTS (SELECT 1 FROM cash_account_users cau WHERE cau.account_id = ci.account_id AND cau.user_id = ? AND cau.is_active = 1)";
        $iouParams = [$accountingYear, $like, $like, $like, $like];
        $iouTypes = 'issss';
        if (!$isAdmin) {
            $iouParams[] = $userId;
            $iouTypes .= 'i';
        }
        $sources[] = [
            'table' => 'cash_ious', 'type' => 'Cash IOU', 'module' => 'Cash IOUs',
            'scope' => 'cash', 'category' => 'Cash Desk', 'path' => '/payments/cash/ious', 'filterable' => true,
            'sql' => "SELECT ci.id, ci.recipient_name AS title,
                             CONCAT_WS(' • ', NULLIF(ci.iou_reference,''), NULLIF(ci.reason,''), NULLIF(ci.expected_retirement_date,'')) AS subtitle,
                             ci.status, ci.outstanding_amount AS amount, ca.currency, ci.iou_reference AS search_value
                      FROM cash_ious ci
                      INNER JOIN cash_accounts ca ON ca.id = ci.account_id
                      WHERE ci.accounting_year = ? AND (ci.recipient_name LIKE ? OR ci.iou_reference LIKE ? OR ci.reason LIKE ? OR ci.description LIKE ?){$iouAccessSql}
                      ORDER BY ci.created_at DESC LIMIT 5",
            'types' => $iouTypes, 'params' => $iouParams,
        ];
    }

    if ($isSuperAdmin) {
        $sources[] = [
            'table' => 'user_table', 'type' => 'User', 'module' => 'Users',
            'scope' => 'administration', 'category' => 'Administration', 'path' => '/users', 'filterable' => true,
            'sql' => "SELECT id, CONCAT_WS(' ', fname, lname) AS title, CONCAT_WS(' • ', email, integrity, status) AS subtitle,
                             status, NULL AS amount, NULL AS currency, email AS search_value
                      FROM user_table WHERE fname LIKE ? OR lname LIKE ? OR email LIKE ? OR integrity LIKE ? OR status LIKE ? ORDER BY fname ASC LIMIT 5",
            'types' => 'sssss', 'params' => [$like, $like, $like, $like, $like],
        ];
        $sources[] = [
            'table' => 'logs', 'type' => 'Audit Log', 'module' => 'Audit Logs',
            'scope' => 'administration', 'category' => 'Administration', 'path' => '/logs', 'filterable' => true,
            'sql' => "SELECT id, action AS title, CONCAT_WS(' • ', created_by, DATE_FORMAT(created_at, '%d %b %Y %H:%i')) AS subtitle,
                             'Recorded' AS status, NULL AS amount, NULL AS currency, COALESCE(NULLIF(created_by,''), action) AS search_value
                      FROM logs WHERE YEAR(created_at) = ? AND (action LIKE ? OR created_by LIKE ?) ORDER BY created_at DESC LIMIT 5",
            'types' => 'iss', 'params' => [$accountingYear, $like, $like],
        ];
    }

    $results = [];
    $groupCounts = [];
    $searchedModules = [];

    foreach ($sources as $source) {
        if ($scope !== 'all' && ($source['scope'] ?? '') !== $scope) {
            continue;
        }
        if (!workspaceSearchTableExists($conn, (string) $source['table'])) {
            continue;
        }

        $stmt = $conn->prepare((string) $source['sql']);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare global search.', 500);
        }
        workspaceSearchBind($stmt, (string) ($source['types'] ?? ''), (array) ($source['params'] ?? []));
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $searchedModules[(string) $source['module']] = true;
        foreach ($rows as $row) {
            $category = (string) ($source['category'] ?? 'Records');
            $groupCounts[$category] = ($groupCounts[$category] ?? 0) + 1;
            $results[] = [
                'id' => (int) ($row['id'] ?? 0),
                'type' => (string) $source['type'],
                'module' => (string) $source['module'],
                'scope' => (string) $source['scope'],
                'category' => $category,
                'path' => workspaceSearchResultPath($source, $row, $query),
                'title' => trim((string) ($row['title'] ?? '')) ?: (string) $source['type'],
                'subtitle' => trim((string) ($row['subtitle'] ?? '')),
                'status' => trim((string) ($row['status'] ?? '')),
                'amount' => $row['amount'] ?? null,
                'currency' => trim((string) ($row['currency'] ?? '')),
                'search_value' => trim((string) ($row['search_value'] ?? '')),
                '_score' => workspaceSearchScore($query, $row),
            ];
        }
    }

    usort($results, static function (array $left, array $right): int {
        $scoreCompare = ((int) ($right['_score'] ?? 0)) <=> ((int) ($left['_score'] ?? 0));
        if ($scoreCompare !== 0) return $scoreCompare;
        return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    });
    $results = array_slice($results, 0, $limit);
    foreach ($results as &$result) {
        unset($result['_score']);
    }
    unset($result);
    // Recalculate counts after the global result cap so UI totals always match
    // what the user can actually see in the modal.
    $groupCounts = [];
    foreach ($results as $result) {
        $category = (string) ($result['category'] ?? 'Records');
        $groupCounts[$category] = ($groupCounts[$category] ?? 0) + 1;
    }

    echo json_encode([
        'status' => 'Success',
        'data' => $results,
        'meta' => [
            'query' => $query,
            'total' => count($results),
            'scope' => $scope,
            'accounting_year' => $accountingYear,
            'group_counts' => $groupCounts,
            'searched_modules' => array_keys($searchedModules),
            'available_scopes' => $isAdmin
                ? $allowedScopes
                : ['all', 'cash'],
        ],
    ]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
