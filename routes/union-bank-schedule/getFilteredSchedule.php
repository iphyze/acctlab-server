<?php

declare(strict_types=1);

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/accountSupplierPaymentService.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('Route not found.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserIntegrity = (string) ($userData['integrity'] ?? '');

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Super_Admin'], true)) {
        throw new RuntimeException('Unauthorized: Only Admins can access Union Bank schedules.', 401);
    }

    accountSupplierEnsurePaymentStorage($conn);

    if (!isset($_GET['limit'], $_GET['page'])) {
        throw new RuntimeException("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    if ($limit <= 0 || $page <= 0) {
        throw new RuntimeException("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int) $_GET['year'] : null;
    $date = isset($_GET['date']) ? trim((string) $_GET['date']) : null;
    $requestedUserId = isset($_GET['userId']) ? (string) $_GET['userId'] : (string) $loggedInUserId;
    $batch = isset($_GET['batch']) ? (string) $_GET['batch'] : 'all';
    $batchParam = $batch !== 'all' && is_numeric($batch) ? (int) $batch : null;
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : null;

    $scheduleIds = [];
    if (isset($_GET['schedule_ids']) && trim((string) $_GET['schedule_ids']) !== '') {
        $scheduleIds = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $_GET['schedule_ids'])),
            static fn(int $id): bool => $id > 0
        )));
        if (count($scheduleIds) > 100) {
            throw new RuntimeException('A maximum of 100 Union Bank schedule records can be filtered at once.', 400);
        }
    }

    $offset = ($page - 1) * $limit;
    $allowedSortFields = [
        'payment_amount',
        'payment_date',
        'supplier_name',
        'invoice_number',
        'po_number',
        'created_at',
        'batch',
    ];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $allowedSortFields, true)
        ? (string) $_GET['sortBy']
        : 'payment_date';
    $sortOrder = isset($_GET['sortOrder']) && strtoupper((string) $_GET['sortOrder']) === 'ASC' ? 'ASC' : 'DESC';

    $paymentSources = accountPaymentStorageReadSources($conn, ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL);
    $artifactSource = $paymentSources['artifacts'];
    $batchSource = $paymentSources['batches'];
    $baseQuery = "FROM union_payment_schedule ups
        LEFT JOIN {$artifactSource} artifact
          ON artifact.id = (
              SELECT MAX(a2.id)
              FROM {$artifactSource} a2
              WHERE a2.artifact_type = 'Union Bank Schedule'
                AND a2.artifact_id = ups.id
          )
        LEFT JOIN {$batchSource} payment_batch
          ON payment_batch.id = artifact.batch_id
        WHERE 1=1";
    $params = [];
    $types = '';

    if ($scheduleIds !== []) {
        $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
        $baseQuery .= " AND ups.id IN ($placeholders)";
        foreach ($scheduleIds as $scheduleId) {
            $params[] = $scheduleId;
            $types .= 'i';
        }
    }

    if ($scheduleIds === [] && $requestedUserId !== 'all') {
        $baseQuery .= ' AND ups.user_id = ?';
        $params[] = (int) $requestedUserId;
        $types .= 'i';
    }

    if ($scheduleIds === [] && $year !== null) {
        $baseQuery .= ' AND YEAR(ups.payment_date) = ?';
        $params[] = $year;
        $types .= 'i';
    }

    if ($scheduleIds === [] && $date !== null && $date !== '') {
        $baseQuery .= ' AND DATE(ups.payment_date) = ?';
        $params[] = $date;
        $types .= 's';
    }

    if ($scheduleIds === [] && $batchParam !== null) {
        $baseQuery .= ' AND ups.batch = ?';
        $params[] = $batchParam;
        $types .= 'i';
    }

    if ($scheduleIds === [] && $search !== null && $search !== '') {
        $baseQuery .= " AND (
            ups.supplier_name LIKE ?
            OR ups.payment_amount LIKE ?
            OR ups.payment_date LIKE ?
            OR ups.invoice_number LIKE ?
            OR ups.po_number LIKE ?
            OR ups.narration LIKE ?
            OR ups.bank_name LIKE ?
            OR ups.account_name LIKE ?
            OR ups.account_number LIKE ?
            OR ups.sort_code LIKE ?
            OR payment_batch.batch_reference LIKE ?
            OR payment_batch.processing_reference LIKE ?
        )";
        $likeSearch = '%' . $search . '%';
        for ($index = 0; $index < 12; $index++) {
            $params[] = $likeSearch;
            $types .= 's';
        }
    }

    $countStmt = $conn->prepare("SELECT COUNT(DISTINCT ups.id) AS total $baseQuery");
    if (!$countStmt) {
        throw new RuntimeException('Failed to prepare Union Bank schedule count query.', 500);
    }
    if ($params !== []) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $query = "SELECT ups.*,
                     artifact.batch_id AS payment_operation_id,
                     artifact.artifact_reference AS payment_artifact_reference,
                     artifact.artifact_route AS payment_artifact_route,
                     payment_batch.batch_reference AS payment_operation_reference,
                     payment_batch.processing_method AS payment_processing_method,
                     payment_batch.processing_reference AS payment_processing_reference,
                     payment_batch.status AS payment_operation_status,
                     payment_batch.completion_mode AS payment_completion_mode
              $baseQuery
              ORDER BY ups.$sortBy $sortOrder
              LIMIT ? OFFSET ?";
    $getStmt = $conn->prepare($query);
    if (!$getStmt) {
        throw new RuntimeException('Failed to prepare Union Bank schedule query.', 500);
    }

    $queryParams = $params;
    $queryTypes = $types . 'ii';
    $queryParams[] = $limit;
    $queryParams[] = $offset;
    $getStmt->bind_param($queryTypes, ...$queryParams);
    $getStmt->execute();
    $payments = $getStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $getStmt->close();

    $linkedScheduleIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int) ($row['payment_operation_id'] ?? 0) > 0
            ? (int) ($row['id'] ?? 0)
            : 0,
        $payments
    ), static fn(int $id): bool => $id > 0)));
    $removableByScheduleId = [];
    if ($linkedScheduleIds !== []) {
        foreach (accountSupplierFetchArtifactLinks($conn, 'Union Bank Schedule', $linkedScheduleIds) as $artifactLink) {
            $artifactId = (int) $artifactLink['artifact_id'];
            $isRemovable = !empty($artifactLink['removable_after_exception']);
            $removableByScheduleId[$artifactId] = array_key_exists($artifactId, $removableByScheduleId)
                ? $removableByScheduleId[$artifactId] && $isRemovable
                : $isRemovable;
        }
    }
    foreach ($payments as &$payment) {
        $payment['payment_artifact_removable'] = (int) ($payment['payment_operation_id'] ?? 0) <= 0
            || !empty($removableByScheduleId[(int) ($payment['id'] ?? 0)]);
    }
    unset($payment);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Payments fetched successfully.',
        'data' => $payments,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'page' => $page,
            'year' => $year,
            'date' => $date,
            'userId' => $requestedUserId,
            'batch' => $batch,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'search' => $search,
            'schedule_ids' => $scheduleIds,
        ],
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Union Bank schedule fetch error: ' . $error->getMessage());
    }
    http_response_code($status);
    echo json_encode([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ]);
}
