<?php

declare(strict_types=1);

const ACCOUNT_PAYMENT_TYPE_LOCAL_FINAL = 'local_final_purchase';
const ACCOUNT_PAYMENT_TYPE_LOCAL_ADVANCE = 'local_advance_purchase';
const ACCOUNT_PAYMENT_TYPE_FX_FINAL = 'fx_final_purchase';
const ACCOUNT_PAYMENT_TYPE_FX_ADVANCE = 'fx_advance_purchase';
const ACCOUNT_PAYMENT_TYPE_FX_MANUAL = 'manual_fx_payment';
const ACCOUNT_PAYMENT_TYPE_COMPASS = 'compass_fund_request';

function accountPaymentStorageObjectType(mysqli $conn, string $object): ?string
{
    $stmt = $conn->prepare(
        'SELECT TABLE_TYPE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $object);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
    $stmt->close();
    return $type !== null ? strtoupper((string) $type) : null;
}

function accountPaymentStorageColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = @active_database_name
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function accountPaymentStorageBaseTableCount(mysqli $conn): int
{
    $row = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = @active_database_name AND TABLE_TYPE = 'BASE TABLE'"
    )->fetch_assoc() ?: [];
    return (int) ($row['total'] ?? 0);
}

function accountPaymentStorageCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc() ?: [];
    return (int) ($row['total'] ?? 0);
}

function accountPaymentStorageTargets(): array
{
    return [
        'account_payment_batches',
        'account_payment_batch_items',
        'account_payment_artifacts',
    ];
}
