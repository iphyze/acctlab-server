<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$final = $read('includes/procurementLocalFinalPurchaseService.php');
$runtime = $read('includes/procurementRequestCanonicalRuntimeService.php');
$advance = $read('includes/procurementLocalAdvancePurchaseService.php');
$foundation = $read('database/20260819_procurement_compass_handoff_foundation.sql');
$compassCreate = $read('routes/request/compass/create.php');
$compassEdit = $read('routes/request/compass/edit.php');
$compassDelete = $read('routes/request/compass/delete.php');
$compassStatus = $read('routes/request/compass/updateStatus.php');
$compassReturn = $read('routes/request/compass/returnToProcurement.php');

$checks = [
    'no_new_transaction_table_added' => !preg_match('/CREATE\s+TABLE/i', $foundation),
    'local_final_requires_compass_router' => str_contains($final, "require_once __DIR__ . '/procurementCompassHandoffService.php'"),
    'local_final_approval_resolves_destination_from_supplier' => str_contains($final, 'procurementCompassResolveLocalAccountRequestType(')
        && str_contains($final, '$purchase[\'supplier_id\'] ?? null')
        && str_contains($final, '$purchase[\'supplier_name\'] ?? null'),
    'compass_local_final_is_inserted_into_existing_compass_table' => str_contains($final, 'function procurementLocalFinalCreateCompassFundRequest')
        && str_contains($final, 'INSERT INTO compass_fund_request_table')
        && str_contains($final, "'Procurement'")
        && str_contains($final, "'100'"),
    'normal_supplier_final_path_is_preserved' => str_contains($final, 'function procurementLocalFinalCreateSupplierFundRequest')
        && str_contains($final, 'INSERT INTO supplier_fund_request_table')
        && str_contains($final, 'PROCUREMENT_ACCOUNT_REQUEST_TYPE_SUPPLIER'),
    'account_request_type_is_persisted_on_approval' => str_contains(
        $final,
        'handoff_revision = ?, account_request_type = ?, account_request_id = ?'
    ),
    'canonical_handoff_uses_dynamic_destination' => str_contains($final, 'string $accountRequestType')
        && str_contains($final, '$accountRequestType,')
        && str_contains($final, '$accountRequestId,'),
    'local_final_read_is_destination_aware' => str_contains($runtime, 'account_request_type,')
        && str_contains($final, "p.account_request_type = 'compass_fund_request'")
        && str_contains($final, "p.account_request_type = 'supplier_fund_request'"),
    'compass_invoice_reuse_guard_prevents_duplicate_account_rows' => str_contains($final, 'function procurementLocalFinalFindReusableCompassFundRequest')
        && str_contains($final, 'this Compass invoice already belongs to a different fund request')
        && str_contains($final, 'procurement_purchase_id'),
    'reversal_and_retrieval_are_destination_aware' => str_contains($final, 'function procurementLocalFinalAccountRequestTable')
        && str_contains($final, "PROCUREMENT_ACCOUNT_REQUEST_TYPE_COMPASS => 'compass_fund_request_table'")
        && str_contains($final, 'procurementLocalFinalDeletePendingAccountRequest')
        && str_contains($final, 'account_request_type = NULL, account_request_id = NULL'),
    'account_can_return_pending_compass_request_to_procurement' => str_contains($compassReturn, 'procurementLocalFinalRetrieveCompassRequestOne')
        && str_contains($final, 'function procurementLocalFinalRetrieveCompassRequestOne')
        && str_contains($final, "account_request_type = 'compass_fund_request'"),
    'compass_payment_status_syncs_back_to_procuredesk' => str_contains($compassStatus, 'procurementSyncLocalFinalCompassPaymentDetails')
        && str_contains($final, 'function procurementSyncLocalFinalCompassPaymentDetails')
        && str_contains($final, "payment_status_source = 'account'"),
    'paid_compass_status_records_amount_paid' => str_contains($compassStatus, 'amount_paid = CAST(amount AS DECIMAL(18,2))')
        && str_contains($compassStatus, "payment_confirmation_status = 'Confirmed'"),
    'linked_compass_rows_are_protected_from_direct_edit' => str_contains($compassEdit, 'procurementAssertCompassFundRequestCanBeEdited')
        && str_contains($final, 'function procurementAssertCompassFundRequestCanBeEdited'),
    'linked_compass_rows_are_protected_from_direct_delete' => str_contains($compassDelete, 'procurementAssertCompassFundRequestsCanBeDeleted')
        && str_contains($final, 'function procurementAssertCompassFundRequestsCanBeDeleted'),
    'existing_manual_compass_creation_route_is_not_replaced' => str_contains($compassCreate, 'INSERT INTO compass_fund_request_table')
        && !str_contains($compassCreate, 'procurementCompassResolveLocalAccountRequestType')
        && !str_contains($compassCreate, 'procurementLocalFinalCreateCompassFundRequest'),
    'local_advance_evolution_does_not_remove_normal_advance_storage' => str_contains($advance, 'INSERT INTO advance_payment_request')
        && str_contains($advance, 'PROCUREMENT_ACCOUNT_REQUEST_TYPE_ADVANCE'),
    'fx_remains_outside_batch2' => !str_contains($final, 'fx_fund_request_table')
        && !str_contains($compassStatus, 'fx_fund_request_table')
        && !str_contains($compassReturn, 'fx_fund_request_table'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo json_encode(
    ['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
exit($failed === [] ? 0 : 1);
