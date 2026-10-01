<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$allocationService = $read('includes/accountReceivablesAllocationService.php');
$allocationRoute = $read('routes/receivables/allocations.php');
$invoiceService = $read('includes/accountReceivablesService.php');

$checks = [
    'allocation_history_includes_creator_identity' =>
        str_contains($allocationService, 'LEFT JOIN user_table creator ON creator.id = a.created_by')
        && str_contains($allocationService, 'creator.fname AS created_by_fname')
        && str_contains($allocationService, "\$row['created_by_name']"),
    'allocation_history_includes_reversal_identity' =>
        str_contains($allocationService, 'LEFT JOIN user_table reverser ON reverser.id = a.reversed_by')
        && str_contains($allocationService, 'reverser.fname AS reversed_by_fname')
        && str_contains($allocationService, "\$row['reversed_by_name']"),
    'reversals_remain_auditable_instead_of_deleting_rows' =>
        str_contains($allocationService, 'SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ?')
        && !str_contains($allocationService, 'DELETE FROM account_receivable_allocations'),
    'allocation_list_keeps_active_and_reversed_rows_by_default' =>
        str_contains($allocationService, "if (isset(\$query['active_only'])")
        && str_contains($allocationService, 'ORDER BY a.allocation_date DESC, a.id DESC'),
    'rate_snapshot_and_override_reason_remain_on_audit_rows' =>
        str_contains($allocationService, "\$row['fx_rate_variance']")
        && str_contains($allocationService, 'rate_override_reason'),
    'reverse_endpoint_remains_available' =>
        str_contains($allocationRoute, "(\$payload['action'] ?? '') === 'reverse'")
        && str_contains($allocationRoute, 'accountReceivablesReverseAllocation'),
    'existing_receivables_delete_protection_remains_in_place' =>
        str_contains($invoiceService, 'active allocation')
        || str_contains($invoiceService, 'allocations'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
