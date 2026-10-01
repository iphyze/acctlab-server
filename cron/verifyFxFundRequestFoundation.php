<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/connection.php';

function fxFoundationObjectType(mysqli $conn, string $database, string $name): ?string
{
    $stmt = $conn->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $database, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string) $row['TABLE_TYPE'] : null;
}

function fxFoundationColumns(mysqli $conn, string $database, string $table): array
{
    $stmt = $conn->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[(string) $row['COLUMN_NAME']] = [
            'type' => strtolower((string) $row['COLUMN_TYPE']),
            'nullable' => strtoupper((string) $row['IS_NULLABLE']) === 'YES',
        ];
    }
    $stmt->close();
    return $columns;
}

function fxFoundationConstraintExists(mysqli $conn, string $database, string $table, string $constraint): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK' LIMIT 1");
    $stmt->bind_param('sss', $database, $table, $constraint);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function fxFoundationIndexes(mysqli $conn, string $database, string $table): array
{
    $stmt = $conn->prepare("SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? GROUP BY INDEX_NAME, NON_UNIQUE");
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[(string) $row['INDEX_NAME']] = [
            'unique' => (int) $row['NON_UNIQUE'] === 0,
            'columns' => (string) $row['columns_list'],
        ];
    }
    $stmt->close();
    return $indexes;
}

$database = trim((string) ($GLOBALS['activeDatabaseName'] ?? envValue('DB_NAME', 'lambert2_acctlab_db')));
$table = 'fx_fund_request_table';

try {
    $tableType = fxFoundationObjectType($conn, $database, $table);
    $instructionType = fxFoundationObjectType($conn, $database, 'fx_instruction_letter_table');
    $columns = fxFoundationColumns($conn, $database, $table);
    $indexes = fxFoundationIndexes($conn, $database, $table);
    $advancePoConstraint = fxFoundationConstraintExists($conn, $database, $table, 'chk_fx_fund_request_advance_po_required');

    $requiredColumns = [
        'id', 'request_type', 'suppliers_name', 'suppliers_id', 'invoice_number', 'purchase_number',
        'po_number', 'invoice_date', 'purchase_date', 'date_received', 'project_code', 'currency', 'sub_total',
        'discount', 'other_charges', 'vat_rate', 'vat_amount', 'wht_rate', 'wht_amount', 'percentage',
        'payable_amount', 'payment_currency', 'payment_amount', 'exchange_rate', 'payment_status',
        'fx_instruction_letter_id', 'processed_by', 'processed_at', 'created_by', 'updated_by',
        'created_at', 'updated_at',
    ];
    $missingColumns = array_values(array_diff($requiredColumns, array_keys($columns)));

    $checks = [
        'single_database_table_exists' => $tableType === 'BASE TABLE',
        'direct_fx_instruction_table_still_exists' => $instructionType === 'BASE TABLE',
        'required_columns_present' => $missingColumns === [],
        'currency_index_present' => isset($indexes['idx_fx_fund_request_currency']),
        'final_purchase_unique_guard_present' => isset($indexes['uq_fx_fund_request_final_purchase'])
            && $indexes['uq_fx_fund_request_final_purchase']['unique']
            && $indexes['uq_fx_fund_request_final_purchase']['columns'] === 'purchase_number',
        'instruction_link_many_to_one_index_present' => isset($indexes['idx_fx_fund_request_instruction'])
            && !$indexes['idx_fx_fund_request_instruction']['unique']
            && $indexes['idx_fx_fund_request_instruction']['columns'] === 'fx_instruction_letter_id',
        'obsolete_instruction_unique_guard_removed' => !isset($indexes['uq_fx_fund_request_instruction']),
        'advance_po_lookup_index_present' => isset($indexes['idx_fx_fund_request_advance_percentage']),
        'final_po_storage_is_optional' => ($columns['po_number']['nullable'] ?? false),
        'advance_po_storage_guard_present' => $advancePoConstraint,
    ];

    $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
    echo json_encode([
        'healthy' => $failed === [],
        'database' => $database,
        'checks' => $checks,
        'missing_columns' => $missingColumns,
        'failed' => $failed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['healthy' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
