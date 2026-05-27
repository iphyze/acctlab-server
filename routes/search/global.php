<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $integrity = $userData['integrity'] ?? '';
    if (!in_array($integrity, ['Admin', 'Super_Admin'], true)) {
        throw new Exception('Forbidden: Only administrators can use workspace search', 403);
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $limit = min(max((int) ($_GET['limit'] ?? 10), 1), 15);
    $scope = trim((string) ($_GET['scope'] ?? 'all'));
    $allowedScopes = ['all', 'projects', 'payments', 'setup', 'administration'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'all';
    }

    $queryLength = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    if ($queryLength < 2) {
        echo json_encode([
            'status' => 'Success',
            'data' => [],
            'meta' => ['query' => $query, 'total' => 0],
        ]);
        exit;
    }

    $like = '%' . $query . '%';
    $sources = [
        [
            'type' => 'Project',
            'scope' => 'projects',
            'path' => '/projects',
            'icon' => 'fa-building',
            'sql' => "SELECT id, location AS title, CONCAT('Code: ', code) AS subtitle FROM location_table WHERE location LIKE ? OR code LIKE ? ORDER BY location ASC LIMIT 4",
            'params' => 2,
        ],
        [
            'type' => 'Ledger / Supplier',
            'scope' => 'setup',
            'path' => '/ledgers',
            'icon' => 'fa-book',
            'sql' => "SELECT id, supplier_name AS title, CONCAT('Supplier no: ', supplier_number) AS subtitle FROM suppliers_table WHERE supplier_name LIKE ? OR supplier_number LIKE ? ORDER BY supplier_name ASC LIMIT 4",
            'params' => 2,
        ],
        [
            'type' => 'Bank Account',
            'scope' => 'setup',
            'path' => '/bank/account-details',
            'icon' => 'fa-credit-card',
            'sql' => "SELECT id, account_name AS title, CONCAT(bank_name, ' • ', account_number) AS subtitle FROM suppliers_account_details WHERE account_name LIKE ? OR account_number LIKE ? OR bank_name LIKE ? ORDER BY account_name ASC LIMIT 4",
            'params' => 3,
        ],
        [
            'type' => 'Sort Code',
            'scope' => 'setup',
            'path' => '/bank/sortcodes',
            'icon' => 'fa-list-ol',
            'sql' => "SELECT id, bank_name AS title, CONCAT(code_name, ' • ', sort_code) AS subtitle FROM bank_sortcode_tab WHERE bank_name LIKE ? OR code_name LIKE ? OR sort_code LIKE ? ORDER BY bank_name ASC LIMIT 4",
            'params' => 3,
        ],
        [
            'type' => 'Supplier Request',
            'scope' => 'payments',
            'path' => '/payments/fund-request/supplier',
            'icon' => 'fa-file-invoice-dollar',
            'sql' => "SELECT id, suppliers_name AS title, CONCAT(invoice_number, ' • ', payment_status) AS subtitle FROM supplier_fund_request_table WHERE suppliers_name LIKE ? OR invoice_number LIKE ? OR project_code LIKE ? OR description LIKE ? ORDER BY created_at DESC LIMIT 4",
            'params' => 4,
        ],
        [
            'type' => 'Advance Request',
            'scope' => 'payments',
            'path' => '/payments/fund-request/advance',
            'icon' => 'fa-hand-holding-usd',
            'sql' => "SELECT id, suppliers_name AS title, CONCAT(po_number, ' • ', payment_status) AS subtitle FROM advance_payment_request WHERE suppliers_name LIKE ? OR po_number LIKE ? OR site LIKE ? OR note LIKE ? ORDER BY created_at DESC LIMIT 4",
            'params' => 4,
        ],
        [
            'type' => 'Expense Request',
            'scope' => 'payments',
            'path' => '/payments/fund-request/expense',
            'icon' => 'fa-receipt',
            'sql' => "SELECT id, suppliers_name AS title, CONCAT(invoice_number, ' • ', payment_status) AS subtitle FROM expense_fund_request_table WHERE suppliers_name LIKE ? OR invoice_number LIKE ? OR project_code LIKE ? OR description LIKE ? ORDER BY created_at DESC LIMIT 4",
            'params' => 4,
        ],
        [
            'type' => 'Compass Request',
            'scope' => 'payments',
            'path' => '/payments/fund-request/compass',
            'icon' => 'fa-compass',
            'sql' => "SELECT id, suppliers_name AS title, CONCAT(invoice_number, ' • ', payment_status) AS subtitle FROM compass_fund_request_table WHERE suppliers_name LIKE ? OR invoice_number LIKE ? OR project_code LIKE ? OR description LIKE ? ORDER BY created_at DESC LIMIT 4",
            'params' => 4,
        ],
        [
            'type' => 'FX Payment',
            'scope' => 'payments',
            'path' => '/payments/fx-payments/payments',
            'icon' => 'fa-exchange-alt',
            'sql' => "SELECT id, beneficiary_name AS title, CONCAT(reference, ' • ', currency, ' ', amount_figure) AS subtitle FROM fx_instruction_letter_table WHERE beneficiary_name LIKE ? OR reference LIKE ? OR beneficiary_bank LIKE ? OR payment_purpose LIKE ? ORDER BY created_at DESC LIMIT 4",
            'params' => 4,
        ],
        [
            'type' => 'Bank Instruction',
            'scope' => 'payments',
            'path' => '/payments/letters/inter-bank',
            'icon' => 'fa-envelope-open-text',
            'sql' => "SELECT id, letter_heading AS title, CONCAT(instruction_type, ' • ', payment_bank_name) AS subtitle FROM instruction_letter WHERE letter_heading LIKE ? OR instruction_type LIKE ? OR payment_to LIKE ? OR payment_bank_name LIKE ? ORDER BY created_at DESC LIMIT 4",
            'params' => 4,
        ],
    ];

    if ($integrity === 'Super_Admin') {
        $sources[] = [
            'type' => 'User',
            'scope' => 'administration',
            'path' => '/users',
            'icon' => 'fa-user-shield',
            'sql' => "SELECT id, CONCAT(fname, ' ', lname) AS title, CONCAT(email, ' • ', integrity) AS subtitle FROM user_table WHERE fname LIKE ? OR lname LIKE ? OR email LIKE ? OR integrity LIKE ? ORDER BY fname ASC LIMIT 4",
            'params' => 4,
        ];
    }

    $results = [];
    foreach ($sources as $source) {
        if ($scope !== 'all' && ($source['scope'] ?? '') !== $scope) {
            continue;
        }
        $stmt = $conn->prepare($source['sql']);
        if (!$stmt) {
            throw new Exception('Unable to prepare global search.', 500);
        }
        switch ($source['params']) {
            case 2:
                $stmt->bind_param('ss', $like, $like);
                break;
            case 3:
                $stmt->bind_param('sss', $like, $like, $like);
                break;
            case 4:
                $stmt->bind_param('ssss', $like, $like, $like, $like);
                break;
            default:
                throw new Exception('Unsupported workspace search definition.', 500);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $row) {
            $results[] = [
                'id' => (int) $row['id'],
                'type' => $source['type'],
                'path' => $source['path'],
                'icon' => $source['icon'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
            ];
        }
    }

    $results = array_slice($results, 0, $limit);
    echo json_encode([
        'status' => 'Success',
        'data' => $results,
        'meta' => [
            'query' => $query,
            'total' => count($results),
            'scope' => $scope,
        ],
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
