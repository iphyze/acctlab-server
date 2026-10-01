<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/procurementRequestCanonicalRuntimeService.php';

$checks = [
    'local_final_request_type_defined' => defined('PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL'),
    'local_advance_request_type_defined' => defined('PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE'),
    'local_final_request_type_is_canonical' => defined('PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL')
        && PROCUREMENT_REQUEST_TYPE_LOCAL_FINAL === PROCUREMENT_REQUEST_CANONICAL_LOCAL_FINAL_TYPE,
    'local_advance_request_type_is_canonical' => defined('PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE')
        && PROCUREMENT_REQUEST_TYPE_LOCAL_ADVANCE === 'local_advance_purchase',
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
$result = [
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
