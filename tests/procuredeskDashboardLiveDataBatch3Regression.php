<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$route = (string) file_get_contents($root . '/routes/procurement/dashboard/overview.php');

$checks = [
    'recent_activity_resolves_advance_po_identity' => str_contains($route, 'LEFT JOIN procurement_local_advance_pos ap')
        && str_contains($route, 'LEFT JOIN procurement_local_advance_po_revisions apr')
        && str_contains($route, 'r.po_id')
        && str_contains($route, 'r.po_revision_id'),
    'recent_activity_prefers_full_po_number_over_internal_request_number' => str_contains($route, 'AS resolved_po_number')
        && str_contains($route, "\$reference = trim((string) (\$row['resolved_po_number'] ?? ''))")
        && strpos($route, "\$row['resolved_po_number']") < strpos($route, "\$row['request_number']"),
    'advance_supplier_is_resolved_from_po_or_revision' => str_contains($route, 'AS resolved_supplier_name')
        && str_contains($route, "NULLIF(TRIM(apr.supplier_name), '')")
        && str_contains($route, "NULLIF(TRIM(ap.supplier_name), '')")
        && str_contains($route, "'vendor' => trim((string) (\$row['resolved_supplier_name'] ?? ''))"),
    'final_purchase_rows_keep_canonical_po_and_supplier_fallbacks' => str_contains($route, "NULLIF(TRIM(r.po_number), '')")
        && str_contains($route, "NULLIF(TRIM(r.supplier_name), '')"),
    'recent_amount_and_currency_logic_is_unchanged' => str_contains($route, "'amount' => (float) (\$isAdvance")
        && str_contains($route, "'currency' => \$isLocal ? 'NGN'"),
    'batch3_is_read_only_and_requires_no_schema_change' => !str_contains($route, 'CREATE TABLE')
        && !str_contains($route, 'ALTER TABLE')
        && !str_contains($route, 'DROP TABLE')
        && !str_contains($route, 'INSERT INTO')
        && !str_contains($route, 'UPDATE procurement_requests')
        && !str_contains($route, 'DELETE FROM'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
