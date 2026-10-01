<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountPaymentStorageRuntimeSupportService.php';

header('Content-Type: application/json');

/**
 * Fetch payment-operation metadata only for the Supplier GAPS rows on the
 * current page. This avoids running the full payment-storage health/DDL guard
 * and avoids joining canonical payment tables across the entire schedule
 * register for every pagination request.
 */
function supplierGapsFetchPaymentLinks(mysqli $conn, array $scheduleIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $scheduleIds),
        static fn(int $id): bool => $id > 0
    )));

    if ($ids === []) {
        return [
            'display' => [],
            'removable' => [],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $requestType = ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL;
    $types = 's' . str_repeat('i', count($ids));
    $params = array_merge([$requestType], $ids);

    $stmt = $conn->prepare(
        "SELECT artifact.id AS artifact_link_id,
                artifact.artifact_id,
                artifact.artifact_reference,
                artifact.artifact_route,
                artifact.artifact_status,
                payment_batch.legacy_source_id AS payment_operation_id,
                payment_batch.batch_reference AS payment_operation_reference,
                payment_batch.processing_method AS payment_processing_method,
                payment_batch.processing_reference AS payment_processing_reference,
                payment_batch.status AS payment_operation_status,
                payment_batch.completion_mode AS payment_completion_mode
         FROM account_payment_artifacts artifact
         INNER JOIN account_payment_batches payment_batch
           ON payment_batch.id = artifact.batch_id
          AND payment_batch.request_type = artifact.request_type
         WHERE artifact.request_type = ?
           AND artifact.artifact_type = 'GAPS Schedule'
           AND artifact.artifact_id IN ($placeholders)
         ORDER BY artifact.artifact_id ASC, artifact.id DESC"
    );
    if (!$stmt) {
        throw new Exception('Failed to prepare payment-link query: ' . $conn->error, 500);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $displayByScheduleId = [];
    $removableByScheduleId = [];

    foreach ($rows as $row) {
        $artifactId = (int) ($row['artifact_id'] ?? 0);
        if ($artifactId <= 0) {
            continue;
        }

        // The previous correlated subquery displayed the latest artifact row.
        if (!array_key_exists($artifactId, $displayByScheduleId)) {
            $displayByScheduleId[$artifactId] = $row;
        }

        // Preserve the existing exception-removal rule across all active links.
        if ((string) ($row['artifact_status'] ?? '') !== 'Active') {
            continue;
        }

        $isRemovable = (string) ($row['payment_operation_status'] ?? '') === 'Completed With Exceptions';
        $removableByScheduleId[$artifactId] = array_key_exists($artifactId, $removableByScheduleId)
            ? $removableByScheduleId[$artifactId] && $isRemovable
            : $isRemovable;
    }

    return [
        'display' => $displayByScheduleId,
        'removable' => $removableByScheduleId,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserIntegrity = $userData['integrity'];

    if ($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can access logs", 401);
    }

    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $date = isset($_GET['date']) ? $_GET['date'] : null;
    $requestedUserId = isset($_GET['userId']) ? $_GET['userId'] : $loggedInUserId;

    $batch = isset($_GET['batch']) ? $_GET['batch'] : 'all';
    $batchParam = ($batch !== 'all' && is_numeric($batch)) ? (int) $batch : null;

    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $scheduleIds = [];
    if (isset($_GET['schedule_ids']) && trim((string) $_GET['schedule_ids']) !== '') {
        $scheduleIds = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $_GET['schedule_ids'])),
            static fn(int $id): bool => $id > 0
        )));
        if (count($scheduleIds) > 100) {
            throw new Exception('A maximum of 100 GAPS schedule records can be filtered at once.', 400);
        }
    }

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    $allowedSortFields = ["payment_amount", "payment_date", "suppliers_name", "invoice_numbers", "po_numbers", "created_at", "batch"];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields, true) ? $_GET['sortBy'] : "payment_date";
    $sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC" ? "ASC" : "DESC";

    // Keep the count and page query on payment_schedule_tab only. Payment
    // operation metadata is enriched after pagination for the visible rows.
    $baseQuery = "FROM payment_schedule_tab ps WHERE 1=1";
    $params = [];
    $types = "";

    if ($scheduleIds !== []) {
        $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
        $baseQuery .= " AND ps.id IN ($placeholders)";
        foreach ($scheduleIds as $scheduleId) {
            $params[] = $scheduleId;
            $types .= 'i';
        }
    }

    if ($scheduleIds === [] && $requestedUserId !== 'all') {
        $baseQuery .= " AND ps.userId = ?";
        $params[] = (int) $requestedUserId;
        $types .= "i";
    }

    if ($scheduleIds === [] && $year) {
        $baseQuery .= " AND YEAR(ps.payment_date) = ?";
        $params[] = $year;
        $types .= "i";
    }

    if ($scheduleIds === [] && $date) {
        $baseQuery .= " AND DATE(ps.payment_date) = ?";
        $params[] = $date;
        $types .= "s";
    }

    if ($scheduleIds === [] && $batchParam !== null) {
        $baseQuery .= " AND ps.batch = ?";
        $params[] = $batchParam;
        $types .= "i";
    }

    if ($scheduleIds === [] && $search) {
        $baseQuery .= " AND (
            ps.suppliers_name LIKE ?
            OR ps.payment_amount LIKE ?
            OR ps.payment_date LIKE ?
            OR ps.invoice_numbers LIKE ?
            OR ps.po_numbers LIKE ?
            OR ps.remark LIKE ?
            OR ps.bank_name LIKE ?
            OR ps.account_name LIKE ?
            OR ps.account_number LIKE ?
            OR ps.sort_code LIKE ?
        )";
        $likeSearch = "%" . $search . "%";
        for ($index = 0; $index < 10; $index += 1) {
            $params[] = $likeSearch;
        }
        $types .= "ssssssssss";
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total $baseQuery");
    if (!$countStmt) {
        throw new Exception("Failed to prepare count statement: " . $conn->error, 500);
    }
    if ($params !== []) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $query = "SELECT ps.* $baseQuery ORDER BY ps.$sortBy $sortOrder LIMIT ? OFFSET ?";
    $getStmt = $conn->prepare($query);
    if (!$getStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    $pageTypes = $types . "ii";
    $pageParams = array_merge($params, [$limit, $offset]);
    $getStmt->bind_param($pageTypes, ...$pageParams);
    $getStmt->execute();
    $payments = $getStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $getStmt->close();

    $visibleScheduleIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $payments
    ), static fn(int $id): bool => $id > 0));
    $paymentLinks = supplierGapsFetchPaymentLinks($conn, $visibleScheduleIds);
    $displayLinks = $paymentLinks['display'];
    $removableLinks = $paymentLinks['removable'];

    foreach ($payments as &$payment) {
        $scheduleId = (int) ($payment['id'] ?? 0);
        $link = $displayLinks[$scheduleId] ?? null;

        $payment['payment_operation_id'] = $link !== null ? (int) ($link['payment_operation_id'] ?? 0) : null;
        $payment['payment_artifact_reference'] = $link['artifact_reference'] ?? null;
        $payment['payment_artifact_route'] = $link['artifact_route'] ?? null;
        $payment['payment_operation_reference'] = $link['payment_operation_reference'] ?? null;
        $payment['payment_processing_method'] = $link['payment_processing_method'] ?? null;
        $payment['payment_processing_reference'] = $link['payment_processing_reference'] ?? null;
        $payment['payment_operation_status'] = $link['payment_operation_status'] ?? null;
        $payment['payment_completion_mode'] = $link['payment_completion_mode'] ?? null;
        $payment['payment_artifact_removable'] = $link === null || !empty($removableLinks[$scheduleId]);
    }
    unset($payment);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Payments fetched successfully",
        "data" => $payments,
        "meta" => [
            "total" => $total,
            "limit" => $limit,
            "page" => $page,
            "year" => $year,
            "date" => $date,
            "userId" => $requestedUserId,
            "batch" => $batch,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search,
            "schedule_ids" => $scheduleIds
        ],
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
