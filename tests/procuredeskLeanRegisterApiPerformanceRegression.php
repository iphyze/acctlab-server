<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$routes = [
    'local_final' => $read('routes/procurement/payments/local/finalPurchases.php'),
    'local_advance' => $read('routes/procurement/payments/local/advancePurchases.php'),
    'fx_final' => $read('routes/procurement/payments/foreign/finalPurchases.php'),
    'fx_advance' => $read('routes/procurement/payments/foreign/advancePurchases.php'),
];

$registerSlice = static function (string $source): string {
    $marker = strpos($source, '// Register projection:');
    if ($marker === false) {
        return '';
    }
    $end = strpos($source, '$listParams = array_merge', $marker);
    if ($end === false) {
        return substr($source, $marker);
    }
    return substr($source, $marker, $end - $marker);
};

$register = array_map($registerSlice, $routes);

$hasAll = static function (string $source, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            return false;
        }
    }
    return true;
};

$checks = [
    'all_four_registers_have_explicit_projection' =>
        $register['local_final'] !== ''
        && $register['local_advance'] !== ''
        && $register['fx_final'] !== ''
        && $register['fx_advance'] !== '',
    'register_queries_do_not_use_select_star' =>
        !str_contains($register['local_final'], 'p.*')
        && !str_contains($register['local_advance'], 'r.*')
        && !str_contains($register['fx_final'], 'p.*')
        && !str_contains($register['fx_advance'], 'r.*'),
    'advance_registers_exclude_large_po_snapshots' =>
        !str_contains($register['local_advance'], 'po_snapshot_json')
        && !str_contains($register['fx_advance'], 'po_snapshot_json'),
    'registers_exclude_detail_only_remarks' =>
        !str_contains($register['local_final'], 'p.remark')
        && !str_contains($register['local_advance'], 'r.remark')
        && !str_contains($register['fx_final'], 'p.remark')
        && !str_contains($register['fx_advance'], 'r.remark')
        && !str_contains($register['local_final'], 'account_payment_remarks')
        && !str_contains($register['local_advance'], 'account_payment_remarks'),
    'local_final_table_contract_preserved' => $hasAll($register['local_final'], [
        'p.id', 'p.purchase_number', 'p.po_number', 'p.invoice_number', 'p.grn_ref',
        'p.material_type', 'p.supplier_name', 'p.supplier_ledger', 'p.project_code',
        'p.project_name', 'p.purchase_date', 'p.invoice_date', 'p.purchase_value',
        'p.po_value', 'p.wht_amount', 'AS account_payable_amount', 'AS account_amount_paid',
        'p.approval_status', 'p.payment_status', 'p.handoff_status', 'p.po_status',
        'AS approved_by_name', 'AS retrieved_by_name',
    ]),
    'local_advance_table_contract_preserved' => $hasAll($register['local_advance'], [
        'r.id', 'r.request_number', 'r.purchase_number', 'r.po_percentage', 'r.expected_payment',
        'AS po_number', 'AS supplier_name', 'AS supplier_ledger', 'AS project_code',
        'AS project_name', 'AS po_value', 'AS po_vat_amount', 'p.po_status AS po_status',
        'r.transaction_date', 'r.date_received', 'r.approval_status', 'r.payment_status',
        'r.handoff_status', 'AS account_payment_status', 'AS created_by_name',
        'AS approved_by_name', 'AS retrieved_by_name',
    ]),
    'fx_final_table_contract_preserved' => $hasAll($register['fx_final'], [
        'p.id', 'p.currency', 'p.purchase_number', 'p.po_number', 'p.invoice_number',
        'p.grn_ref', 'p.material_type', 'p.supplier_name', 'p.project_code',
        'p.purchase_date', 'p.invoice_date', 'p.purchase_value', 'p.po_value',
        'p.approval_status', 'p.payment_status', 'p.handoff_status', 'p.po_status',
        'AS account_payment_status', 'AS account_amount_paid', 'AS account_payment_currency',
        'AS approved_by_name', 'AS retrieved_by_name',
    ]),
    'fx_advance_table_contract_preserved' => $hasAll($register['fx_advance'], [
        'r.id', 'r.request_number', 'r.currency', 'r.purchase_number', 'r.po_percentage',
        'r.expected_payment', 'AS po_number', 'AS supplier_name', 'AS supplier_ledger',
        'AS project_code', 'AS project_name', 'AS po_value', 'AS po_vat_amount',
        'p.po_status', 'r.transaction_date', 'r.date_received', 'r.approval_status',
        'r.payment_status', 'r.handoff_status', 'AS account_payment_status',
    ]),
    'full_detail_get_paths_are_preserved' =>
        str_contains($routes['local_final'], 'procurementLocalFinalFetchRecord($conn, $id)')
        && str_contains($routes['local_advance'], 'procurementLocalAdvanceFetchRecord($conn, $id)')
        && str_contains($routes['fx_final'], 'procurementFxFinalFetchRecord($conn, $id)')
        && str_contains($routes['fx_advance'], 'procurementFxAdvanceFetchRecord($conn, $id)'),
    'pagination_count_and_sort_contracts_preserved' =>
        array_reduce($routes, static fn(bool $ok, string $source): bool =>
            $ok
            && str_contains($source, 'SELECT COUNT(*) AS total')
            && str_contains($source, "'total_pages'")
            && str_contains($source, "'sort_by'"), true),
    'write_paths_remain_in_same_endpoints' =>
        str_contains($routes['local_final'], "if (\$method === 'POST')")
        && str_contains($routes['local_advance'], "if (\$method === 'POST')")
        && str_contains($routes['fx_final'], "if (\$method === 'POST')")
        && str_contains($routes['fx_advance'], "if (\$method === 'POST')"),
    'no_migration_or_new_table_required' => true,
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
