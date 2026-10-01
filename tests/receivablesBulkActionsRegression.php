<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$route = $read('routes/receivables/invoices.php');
$service = $read('includes/accountReceivablesService.php');

$checks = [
    'newest_created_is_default_sort' => str_contains($service, "\$query['sort_by'] ?? 'created_at'")
        && str_contains($service, "\$sortBy = 'created_at';"),
    'bulk_update_route_is_available' => str_contains($route, "'bulk_update'")
        && str_contains($route, 'accountReceivablesBulkUpdateInvoices'),
    'bulk_delete_route_is_available' => str_contains($route, "'bulk_delete'")
        && str_contains($route, 'accountReceivablesBulkDeleteInvoices'),
    'bulk_ids_are_bounded' => str_contains($service, 'Bulk actions are limited to 250 records at a time.'),
    'bulk_update_is_restricted_to_safe_fields' => str_contains($service, "array_key_exists('position', \$changes)")
        && str_contains($service, "array_key_exists('line_type', \$changes)")
        && str_contains($service, "array_key_exists('credit_days', \$changes)"),
    'credit_days_recalculates_due_date' => str_contains($service, 'DATE_ADD(invoice_date, INTERVAL ? DAY)'),
    'bulk_delete_remains_soft_delete' => str_contains($service, 'SET deleted_at = NOW(), deleted_by = ?, updated_by = ?'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
