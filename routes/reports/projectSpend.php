<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found.', 405);
    }

    requireAdmin();

    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
    $requestedProject = trim((string) ($_GET['project'] ?? ''));

    $recordsSql = "
        SELECT 'supplier' AS category, 'Supplier Requests' AS category_label, project_code AS project_ref,
               payment_status, CAST(REPLACE(COALESCE(amount, '0'), ',', '') AS DECIMAL(20,2)) AS amount, created_at
        FROM supplier_fund_request_table
        UNION ALL
        SELECT 'expense', 'Expense Requests', project_code,
               payment_status, CAST(REPLACE(COALESCE(amount, '0'), ',', '') AS DECIMAL(20,2)), created_at
        FROM expense_fund_request_table
        UNION ALL
        SELECT 'compass', 'Compass Requests', project_code,
               payment_status, CAST(REPLACE(COALESCE(amount, '0'), ',', '') AS DECIMAL(20,2)), created_at
        FROM compass_fund_request_table
        UNION ALL
        SELECT 'advance', 'Advance Requests', site,
               payment_status, CAST(REPLACE(COALESCE(amount_payable, '0'), ',', '') AS DECIMAL(20,2)), created_at
        FROM advance_payment_request
    ";

    $projectsSql = "SELECT r.project_ref, COALESCE(NULLIF(l.location, ''), r.project_ref) AS project_label,
                           COUNT(*) AS request_count, COALESCE(SUM(r.amount), 0) AS overall_total
                    FROM ($recordsSql) r
                    LEFT JOIN location_table l ON l.code = r.project_ref
                    WHERE YEAR(r.created_at) = ? AND r.project_ref IS NOT NULL AND r.project_ref <> ''
                    GROUP BY r.project_ref, l.location
                    ORDER BY overall_total DESC
                    LIMIT 100";
    $projectsStmt = $conn->prepare($projectsSql);
    if (!$projectsStmt) {
        throw new Exception('Unable to prepare project options.', 500);
    }
    $projectsStmt->bind_param('i', $year);
    $projectsStmt->execute();
    $projects = $projectsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $projectsStmt->close();

    foreach ($projects as &$project) {
        $project['request_count'] = (int) $project['request_count'];
        $project['overall_total'] = (float) $project['overall_total'];
    }
    unset($project);

    $selectedProject = $requestedProject !== '' ? $requestedProject : ($projects[0]['project_ref'] ?? '');
    if ($selectedProject === '') {
        echo json_encode(['status' => 'Success', 'data' => ['projects' => [], 'selected' => null]]);
        exit;
    }

    $summarySql = "SELECT r.project_ref, COALESCE(NULLIF(l.location, ''), r.project_ref) AS project_label,
                          COUNT(*) AS request_count,
                          COALESCE(SUM(r.amount), 0) AS overall_total,
                          COALESCE(SUM(CASE WHEN LOWER(r.payment_status) = 'paid' THEN r.amount ELSE 0 END), 0) AS paid_total,
                          COALESCE(SUM(CASE WHEN LOWER(r.payment_status) = 'pending' THEN r.amount ELSE 0 END), 0) AS pending_total,
                          COALESCE(SUM(CASE WHEN LOWER(r.payment_status) = 'unconfirmed' THEN r.amount ELSE 0 END), 0) AS unconfirmed_total
                   FROM ($recordsSql) r
                   LEFT JOIN location_table l ON l.code = r.project_ref
                   WHERE YEAR(r.created_at) = ? AND r.project_ref = ?
                   GROUP BY r.project_ref, l.location";
    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new Exception('Unable to prepare project spend summary.', 500);
    }
    $summaryStmt->bind_param('is', $year, $selectedProject);
    $summaryStmt->execute();
    $selected = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();

    $categorySql = "SELECT r.category, r.category_label, COALESCE(SUM(r.amount), 0) AS amount
                    FROM ($recordsSql) r
                    WHERE YEAR(r.created_at) = ? AND r.project_ref = ?
                    GROUP BY r.category, r.category_label
                    ORDER BY amount DESC";
    $categoryStmt = $conn->prepare($categorySql);
    if (!$categoryStmt) {
        throw new Exception('Unable to prepare project category breakdown.', 500);
    }
    $categoryStmt->bind_param('is', $year, $selectedProject);
    $categoryStmt->execute();
    $categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $categoryStmt->close();

    if ($selected) {
        $selected['request_count'] = (int) $selected['request_count'];
        foreach (['overall_total', 'paid_total', 'pending_total', 'unconfirmed_total'] as $field) {
            $selected[$field] = (float) $selected[$field];
        }
        foreach ($categories as &$category) {
            $category['amount'] = (float) $category['amount'];
        }
        unset($category);
        $selected['categories'] = $categories;
    }

    echo json_encode([
        'status' => 'Success',
        'data' => ['projects' => $projects, 'selected' => $selected],
        'meta' => ['year' => $year],
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
