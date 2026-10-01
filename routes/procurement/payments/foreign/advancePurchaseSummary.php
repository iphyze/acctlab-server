<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementFxAdvancePurchaseService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementEnsureFxAdvancePurchaseStorage($conn);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    procurementRequirePermission($conn, 'payments.fx_advance.view');

    $search = trim((string) ($_GET['search'] ?? ''));
    $year = (int) ($_GET['year'] ?? 0);
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);
    $currency = trim((string) ($_GET['currency'] ?? ''));
    if ($currency !== '') $currency = procurementFxAdvanceNormalizeCurrency($currency);
    if ($year !== 0 && ($year < 2000 || $year > 2100)) throw new RuntimeException('Year filter is invalid.', 400);

    $where = ['r.deleted_at IS NULL', "p.request_scope = 'fx_advance_purchase'"];
    $params = []; $types = '';
    if ($search !== '') { $where[] = '(r.request_number LIKE ? OR r.purchase_number LIKE ? OR p.po_number LIKE ? OR p.project_code LIKE ? OR p.project_name LIKE ? OR p.supplier_name LIKE ? OR p.supplier_ledger LIKE ?)'; $like = '%' . $search . '%'; for ($i=0;$i<7;$i++) $params[]=$like; $types.='sssssss'; }
    if ($year > 0) { $where[] = 'r.transaction_date >= ? AND r.transaction_date < ?'; $params[] = sprintf('%04d-01-01', $year); $params[] = sprintf('%04d-01-01', $year + 1); $types .= 'ss'; }
    if ($projectId > 0) { $where[] = 'p.project_id = ?'; $params[]=$projectId; $types.='i'; }
    if ($supplierId > 0) { $where[] = 'p.supplier_id = ?'; $params[]=$supplierId; $types.='i'; }
    if ($currency !== '') { $where[] = 'r.currency = ?'; $params[]=$currency; $types.='s'; }
    $whereSql = implode(' AND ', $where);
    $relation = procurementRequestCanonicalFxAdvanceReadRelation();

    // Never sum unlike currencies together. Return one metric bucket per request currency.
    $stmt = $conn->prepare(
        "SELECT r.currency,
                COUNT(*) AS total_requests, COUNT(DISTINCT r.po_id) AS total_pos,
                COALESCE(SUM(r.expected_payment), 0.00) AS total_expected_payment,
                COALESCE(SUM(CASE WHEN r.approval_status = 'Unapproved' THEN 1 ELSE 0 END),0) AS unapproved_count,
                COALESCE(SUM(CASE WHEN r.approval_status = 'Approved' THEN 1 ELSE 0 END),0) AS approved_count,
                COALESCE(SUM(CASE WHEN r.payment_status = 'Pending' THEN 1 ELSE 0 END),0) AS pending_count,
                COALESCE(SUM(CASE WHEN r.payment_status = 'Processing' THEN 1 ELSE 0 END),0) AS processing_count,
                COALESCE(SUM(CASE WHEN r.payment_status = 'Paid' THEN 1 ELSE 0 END),0) AS paid_count,
                COALESCE(SUM(CASE WHEN r.handoff_status = 'Retrieved' THEN 1 ELSE 0 END),0) AS retrieved_count
         FROM {$relation} r
         INNER JOIN procurement_local_advance_pos p ON p.id = r.po_id
         WHERE {$whereSql}
         GROUP BY r.currency ORDER BY r.currency ASC"
    );
    if ($params !== []) $stmt->bind_param($types, ...$params);
    $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    foreach ($rows as &$row) {
        foreach (['total_requests','total_pos','unapproved_count','approved_count','pending_count','processing_count','paid_count','retrieved_count'] as $field) $row[$field]=(int)$row[$field];
        $row['total_expected_payment'] = number_format((float)$row['total_expected_payment'],2,'.','');
    } unset($row);

    jsonResponse(['status' => 'Success', 'data' => [
        'by_currency' => $rows,
        'filters' => ['search'=>$search,'year'=>$year?:null,'project_id'=>$projectId?:null,'supplier_id'=>$supplierId?:null,'currency'=>$currency?:null],
    ]]);
} catch (Throwable $error) {
    $status=(int)$error->getCode(); if($status<400||$status>599)$status=500;
    if($status>=500) error_log('FX Advance Purchase summary error: '.$error->getMessage());
    jsonResponse(['status'=>'Failed','message'=>$status>=500?'Unable to load FX Advance Purchase summary.':$error->getMessage()],$status);
}
