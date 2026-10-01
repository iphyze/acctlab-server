<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException("Missing file: {$path}");
    }
    return (string) file_get_contents($full);
};

$prefixSensitiveRoutes = [
    'routes/data/fetchUsers.php',
    'routes/request/advance/getSingle.php',
    'routes/gaps/supplier/getAllSuppliersGaps.php',
    'routes/gaps/supplier/getSingleSuppliersGaps.php',
    'routes/gaps/expense/getAllExpenseGaps.php',
    'routes/gaps/advance/getSingleSuppliersAdvanceGaps.php',
    'routes/gaps/advance/getAllSuppliersAdvanceGaps.php',
];

$checks = [];
$checks['php_routes_start_at_opening_tag'] = true;
foreach ($prefixSensitiveRoutes as $route) {
    if (!str_starts_with($read($route), '<?php')) {
        $checks['php_routes_start_at_opening_tag'] = false;
        break;
    }
}

$create = $read('routes/bank-recon/createReconciliation.php');
$update = $read('routes/bank-recon/updateReconciliation.php');
$checks['bank_recon_date_separator_regex_is_valid'] =
    str_contains($create, "[-\\/.]")
    && str_contains($update, "[-\\/.]")
    && !str_contains($create, "[\\/-\\.]")
    && !str_contains($update, "[\\/-\\.]");

$checks['bank_recon_date_patterns_compile_and_match'] =
    preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', '2026-09-25') === 1
    && preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', '25/09/2026') === 1;

$summary = $read('routes/analytics/paymentSummary.php');
$checks['payment_schedule_search_uses_real_schema_columns'] =
    str_contains($summary, "'invoice_numbers'")
    && str_contains($summary, "'po_numbers'")
    && str_contains($summary, "'remark'")
    && !str_contains($summary, "'payment_date', 'invoice_number', 'po_number', 'narration'");

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
