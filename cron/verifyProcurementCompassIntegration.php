<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/procurementCompassHandoffService.php';
require_once __DIR__ . '/../includes/accountPaymentStorageRuntimeReadService.php';
require_once __DIR__ . '/../includes/paymentReminderUnifiedStoragePlanService.php';

$database = (string) ($conn->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] ?? '');
$checks = [];
$failed = false;

$record = static function (
    string $name,
    bool $passed,
    string $message,
    array $details = []
) use (&$checks, &$failed): void {
    $checks[] = [
        'name' => $name,
        'status' => $passed ? 'Passed' : 'Failed',
        'message' => $message,
        'details' => $details,
    ];
    if (!$passed) {
        $failed = true;
    }
};

$count = static function (mysqli $db, string $sql): int {
    $result = $db->query($sql);
    $row = $result->fetch_assoc() ?: [];
    return (int) ($row['total'] ?? 0);
};

$indexExists = static function (mysqli $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
};

try {
    $record(
        'Compass storage table',
        procurementRequestCanonicalRuntimeObjectType($conn, 'compass_fund_request_table') === 'BASE TABLE',
        'The existing Compass Fund Request table must remain the Account-side storage.'
    );

    try {
        procurementCompassAssertStorageReady($conn);
        $record('Compass storage columns', true, 'All Compass procurement/payment foundation columns are present.');
    } catch (Throwable $error) {
        $record('Compass storage columns', false, $error->getMessage());
    }

    $record(
        'Compass procurement unique index',
        $indexExists($conn, 'compass_fund_request_table', 'uq_compass_procurement_purchase'),
        'The existing Compass table must keep one ProcureDesk handoff per source purchase.'
    );

    try {
        accountPaymentStorageCanonicalReadsEnabled($conn, ACCOUNT_PAYMENT_TYPE_COMPASS);
        $record(
            'Canonical payment runtime',
            true,
            'Compass uses the shared canonical payment storage under its own request_type namespace.'
        );
    } catch (Throwable $error) {
        $record('Canonical payment runtime', false, $error->getMessage());
    }

    $orphanHandoffs = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_requests request_row
         LEFT JOIN compass_fund_request_table compass
           ON compass.id = request_row.account_request_id
         WHERE request_row.account_request_type = 'compass_fund_request'
           AND request_row.account_request_id IS NOT NULL
           AND request_row.deleted_at IS NULL
           AND compass.id IS NULL"
    );
    $record(
        'No orphan Compass handoffs',
        $orphanHandoffs === 0,
        'Every active ProcureDesk Compass handoff must resolve to an existing Compass Fund Request.',
        ['orphan_handoffs' => $orphanHandoffs]
    );

    $duplicateLinks = $count(
        $conn,
        "SELECT COUNT(*) AS total FROM (
            SELECT account_request_id
            FROM procurement_requests
            WHERE account_request_type = 'compass_fund_request'
              AND account_request_id IS NOT NULL
              AND request_type IN ('local_final_purchase', 'local_advance_purchase')
              AND deleted_at IS NULL
            GROUP BY account_request_id
            HAVING COUNT(*) > 1
         ) duplicate_rows"
    );
    $record(
        'No duplicate active Compass links',
        $duplicateLinks === 0,
        'A Compass Fund Request must not be linked to more than one active ProcureDesk purchase.',
        ['duplicate_links' => $duplicateLinks]
    );

    $invalidRequestTypes = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_requests
         WHERE account_request_type = 'compass_fund_request'
           AND account_request_id IS NOT NULL
           AND request_type NOT IN ('local_final_purchase', 'local_advance_purchase')
           AND deleted_at IS NULL"
    );
    $record(
        'FX and unrelated request types excluded',
        $invalidRequestTypes === 0,
        'Only Local Final and Local Advance requests may use the Compass Account destination.',
        ['invalid_request_types' => $invalidRequestTypes]
    );

    $nonCompassSupplierHandoffs = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_requests request_row
         WHERE request_row.account_request_type = 'compass_fund_request'
           AND request_row.account_request_id IS NOT NULL
           AND request_row.deleted_at IS NULL
           AND request_row.request_type IN ('local_final_purchase', 'local_advance_purchase')
           AND COALESCE(request_row.supplier_id, 0) <> 440
           AND LOWER(
                 REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                   TRIM(COALESCE(request_row.supplier_name, '')),
                   'limited', 'ltd'), ' ', ''), '.', ''), ',', ''), '-', ''), '_', '')
               ) <> 'compasspowersolutionsltd'"
    );
    $record(
        'Only Compass supplier is routed to Compass',
        $nonCompassSupplierHandoffs === 0,
        'The Compass destination must not be assigned to another supplier.',
        ['non_compass_supplier_handoffs' => $nonCompassSupplierHandoffs]
    );

    $metadataMismatches = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM procurement_requests request_row
         INNER JOIN compass_fund_request_table compass
           ON compass.id = request_row.account_request_id
         WHERE request_row.account_request_type = 'compass_fund_request'
           AND request_row.deleted_at IS NULL
           AND request_row.request_type IN ('local_final_purchase', 'local_advance_purchase')
           AND (
                COALESCE(compass.procurement_purchase_id, 0) <> COALESCE(request_row.legacy_source_id, 0)
             OR BINARY COALESCE(compass.procurement_request_type, '') <> BINARY request_row.request_type
             OR BINARY COALESCE(compass.procurement_source, '') <> BINARY request_row.request_type
           )"
    );
    $record(
        'Compass handoff metadata matches ProcureDesk',
        $metadataMismatches === 0,
        'Compass procurement metadata must identify the same source purchase and Local request type.',
        ['metadata_mismatches' => $metadataMismatches]
    );

    $orphanCompassItems = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM account_payment_batch_items item
         LEFT JOIN compass_fund_request_table compass ON compass.id = item.request_id
         WHERE item.request_type = 'compass_fund_request'
           AND compass.id IS NULL"
    );
    $record(
        'No orphan canonical Compass payment items',
        $orphanCompassItems === 0,
        'Every canonical Compass payment item must resolve to an existing Compass Fund Request.',
        ['orphan_items' => $orphanCompassItems]
    );

    $crossTypePaymentItems = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM account_payment_batch_items item
         INNER JOIN account_payment_batches batch ON batch.id = item.batch_id
         WHERE item.request_type = 'compass_fund_request'
           AND batch.request_type <> 'compass_fund_request'"
    );
    $record(
        'Compass payment batches are type-isolated',
        $crossTypePaymentItems === 0,
        'Compass items must never be attached to Supplier, Advance or FX canonical batches.',
        ['cross_type_items' => $crossTypePaymentItems]
    );

    $missingCompassBatchMappings = $count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM compass_fund_request_table compass
         LEFT JOIN account_payment_batches batch
           ON batch.request_type = 'compass_fund_request'
          AND batch.legacy_source_id = compass.payment_batch_id
         LEFT JOIN account_payment_batch_items item
           ON item.batch_id = batch.id
          AND item.request_type = 'compass_fund_request'
          AND item.request_id = compass.id
         WHERE compass.payment_batch_id IS NOT NULL
           AND compass.payment_batch_id > 0
           AND (batch.id IS NULL OR item.id IS NULL)"
    );
    $record(
        'Compass payment_batch_id mappings are complete',
        $missingCompassBatchMappings === 0,
        'Every Compass request with a payment operation must map to its canonical Compass batch and item.',
        ['missing_batch_mappings' => $missingCompassBatchMappings]
    );

    $compassBatchCountMismatches = $count(
        $conn,
        "SELECT COUNT(*) AS total FROM (
            SELECT batch.id
            FROM account_payment_batches batch
            LEFT JOIN account_payment_batch_items item
              ON item.batch_id = batch.id
             AND item.request_type = 'compass_fund_request'
            WHERE batch.request_type = 'compass_fund_request'
            GROUP BY batch.id, batch.item_count
            HAVING COUNT(item.id) <> batch.item_count
         ) invalid_batches"
    );
    $record(
        'Compass canonical batch item counts agree',
        $compassBatchCountMismatches === 0,
        'Canonical Compass batch item_count must equal its actual scoped item count.',
        ['batch_count_mismatches' => $compassBatchCountMismatches]
    );

    if (paymentReminderUnifiedObjectType($conn, PAYMENT_REMINDER_UNIFIED_REMINDER_TABLE) === 'BASE TABLE') {
        $orphanReminders = paymentReminderUnifiedRequestOrphans($conn);
        $mappingMetrics = paymentReminderUnifiedCanonicalMappingMetrics($conn);
        $activeMetrics = paymentReminderUnifiedActiveSourceMetrics($conn);

        $compassOrphanReminders = (int) ($orphanReminders['compass'] ?? 0);
        $compassMissingMappings = (int) ($mappingMetrics['compass']['missing_item_mappings'] ?? 0)
            + (int) ($mappingMetrics['compass']['batch_public_id_mismatches'] ?? 0)
            + (int) ($mappingMetrics['compass']['request_type_mismatches'] ?? 0);
        $compassActiveNotProcessing = (int) ($activeMetrics['active_compass_not_processing'] ?? 0);

        $record(
            'No orphan Compass payment reminders',
            $compassOrphanReminders === 0,
            'Compass reminder rows must resolve to existing Compass requests.',
            ['orphan_reminders' => $compassOrphanReminders]
        );
        $record(
            'Compass reminder canonical mappings agree',
            $compassMissingMappings === 0,
            'Compass reminders linked to payment items must preserve Compass request_type and public batch/item IDs.',
            ['mapping_issues' => $compassMissingMappings]
        );
        $record(
            'Active Compass reminders match Processing requests',
            $compassActiveNotProcessing === 0,
            'An active Compass payment reminder must not remain attached to a request outside Processing.',
            ['active_not_processing' => $compassActiveNotProcessing]
        );
    } else {
        $record(
            'Compass reminder storage exists',
            false,
            'The shared account_payment_reminders table is required for Notify completion mode.'
        );
    }
} catch (Throwable $error) {
    $record('Compass integration verification', false, $error->getMessage());
}

$payload = [
    'healthy' => !$failed,
    'database' => $database,
    'checks' => $checks,
    'failed' => array_values(array_map(
        static fn(array $check): string => (string) $check['name'],
        array_filter($checks, static fn(array $check): bool => $check['status'] === 'Failed')
    )),
    'checked_at' => date(DATE_ATOM),
];

$stream = $failed ? STDERR : STDOUT;
fwrite($stream, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failed ? 1 : 0);
