<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = file_get_contents($root . '/includes/procurementRequestCanonicalRuntimeService.php');
$localFinal = file_get_contents($root . '/includes/procurementLocalFinalPurchaseService.php');
$localAdvance = file_get_contents($root . '/includes/procurementLocalAdvancePurchaseService.php');

function relationBody(string $source, string $functionName): string
{
    $start = strpos($source, 'function ' . $functionName . '()');
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\nfunction ", $start + 10);
    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
}

$localFinalRelation = relationBody($runtime, 'procurementRequestCanonicalLocalFinalReadRelation');
$localAdvanceRelation = relationBody($runtime, 'procurementRequestCanonicalLocalAdvanceReadRelation');
$fxFinalRelation = relationBody($runtime, 'procurementRequestCanonicalFxFinalReadRelation');
$fxAdvanceRelation = relationBody($runtime, 'procurementRequestCanonicalFxAdvanceReadRelation');

$checks = [
    'local_final_preserves_public_and_canonical_identity' =>
        str_contains($localFinalRelation, 'legacy_source_id AS id')
        && str_contains($localFinalRelation, 'id AS canonical_request_id')
        && str_contains($localFinalRelation, 'id AS procurement_request_id'),
    'local_advance_preserves_public_and_canonical_identity' =>
        str_contains($localAdvanceRelation, 'legacy_source_id AS id')
        && str_contains($localAdvanceRelation, 'id AS canonical_request_id')
        && str_contains($localAdvanceRelation, 'id AS procurement_request_id'),
    'local_serializers_keep_document_anchor_integer_safe' =>
        str_contains($localFinal, "'canonical_request_id', 'procurement_request_id'")
        && str_contains($localAdvance, "'canonical_request_id', 'procurement_request_id'"),
    'fx_document_anchor_behavior_unchanged' =>
        str_contains($fxFinalRelation, 'id AS canonical_request_id')
        && str_contains($fxAdvanceRelation, 'id AS canonical_request_id'),
    'no_public_id_document_fallback_introduced' =>
        !str_contains($runtime, 'legacy_source_id AS procurement_request_id'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode([
    'healthy' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
