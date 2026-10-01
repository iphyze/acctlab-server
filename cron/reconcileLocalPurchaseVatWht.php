<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/localPurchaseTaxReconciliation.php';

date_default_timezone_set('Africa/Lagos');

$options = getopt('', ['apply', 'source::', 'limit::']);
$apply = array_key_exists('apply', $options);
$source = strtolower(trim((string) ($options['source'] ?? 'all')));
$limit = max(0, (int) ($options['limit'] ?? 0));

if (!in_array($source, ['all', 'local_final', 'local_advance'], true)) {
    fwrite(STDERR, "Invalid --source. Use all, local_final, or local_advance.\n");
    exit(2);
}

$runId = 'VAT-WHT-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$results = [
    'run_id' => $runId,
    'mode' => $apply ? 'apply' : 'dry-run',
    'source' => $source,
    'summary' => [
        'candidates' => 0,
        'ready' => 0,
        'reconciled' => 0,
        'manual_review' => 0,
        'skipped' => 0,
        'failed' => 0,
    ],
    'local_final' => [],
    'local_advance' => [],
];

try {
    localPurchaseTaxEnsureStorage($conn);

    if (in_array($source, ['all', 'local_final'], true)) {
        $candidates = localPurchaseTaxFetchFinalCandidates($conn, $limit);
        $results['summary']['candidates'] += count($candidates);
        foreach ($candidates as $candidate) {
            try {
                $item = localPurchaseTaxReconcileFinal(
                    $conn,
                    $runId,
                    (int) $candidate['id'],
                    $apply
                );
            } catch (Throwable $error) {
                $item = [
                    'id' => (int) $candidate['id'],
                    'po_number' => $candidate['po_number'] ?? null,
                    'status' => 'failed',
                    'reason' => $error->getMessage(),
                ];
            }
            $results['local_final'][] = $item;
            $status = (string) ($item['status'] ?? 'failed');
            $results['summary'][$status] = ($results['summary'][$status] ?? 0) + 1;
        }
    }

    if (in_array($source, ['all', 'local_advance'], true)) {
        $candidates = localPurchaseTaxFetchAdvanceCandidates($conn, $limit);
        $results['summary']['candidates'] += count($candidates);
        foreach ($candidates as $candidate) {
            try {
                $item = localPurchaseTaxReconcileAdvancePo(
                    $conn,
                    $runId,
                    (int) $candidate['id'],
                    $apply
                );
            } catch (Throwable $error) {
                $item = [
                    'id' => (int) $candidate['id'],
                    'po_number' => $candidate['po_number'] ?? null,
                    'status' => 'failed',
                    'reason' => $error->getMessage(),
                ];
            }
            $results['local_advance'][] = $item;
            $status = (string) ($item['status'] ?? 'failed');
            $results['summary'][$status] = ($results['summary'][$status] ?? 0) + 1;
        }
    }

    echo json_encode(
        $results,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    ) . PHP_EOL;
    exit($results['summary']['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'status' => 'Failed',
        'run_id' => $runId,
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
