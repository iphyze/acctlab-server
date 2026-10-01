<?php

declare(strict_types=1);

const PROCUREMENT_PURCHASE_REPLACEMENT_TYPES = [
    'local_final_purchase',
    'local_advance_purchase',
    'fx_final_purchase',
    'fx_advance_purchase',
];

function procurementPurchaseReplacementEnsureStorage(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $query = "CREATE TABLE IF NOT EXISTS procurement_purchase_replacement_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source_type VARCHAR(40) NOT NULL,
        source_purchase_id BIGINT UNSIGNED NOT NULL,
        replacement_type VARCHAR(40) NOT NULL,
        replacement_purchase_id BIGINT UNSIGNED NOT NULL,
        reason TEXT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_procurement_purchase_replacement
            (source_type, source_purchase_id, replacement_type, replacement_purchase_id),
        INDEX idx_procurement_purchase_replacement_source (source_type, source_purchase_id),
        INDEX idx_procurement_purchase_replacement_target (replacement_type, replacement_purchase_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($query)) {
        throw new RuntimeException('Unable to prepare purchase replacement storage.', 500);
    }
    $ensured = true;
}

function procurementPurchaseReplacementValidateType(string $type): string
{
    $type = trim($type);
    if (!in_array($type, PROCUREMENT_PURCHASE_REPLACEMENT_TYPES, true)) {
        throw new RuntimeException('Replacement purchase type is invalid.', 400);
    }
    return $type;
}

function procurementPurchaseReplacementCreate(
    mysqli $conn,
    string $sourceType,
    int $sourcePurchaseId,
    string $replacementType,
    int $replacementPurchaseId,
    int $actorId,
    string $reason = ''
): void {
    procurementPurchaseReplacementEnsureStorage($conn);
    $sourceType = procurementPurchaseReplacementValidateType($sourceType);
    $replacementType = procurementPurchaseReplacementValidateType($replacementType);
    if ($sourcePurchaseId <= 0 || $replacementPurchaseId <= 0 || $actorId <= 0) {
        throw new RuntimeException('Replacement link identity is incomplete.', 400);
    }
    if ($sourceType === $replacementType && $sourcePurchaseId === $replacementPurchaseId) {
        throw new RuntimeException('A purchase cannot replace itself.', 400);
    }

    $stmt = $conn->prepare(
        'INSERT INTO procurement_purchase_replacement_links
            (source_type, source_purchase_id, replacement_type, replacement_purchase_id, reason, created_by)
         VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason)'
    );
    $stmt->bind_param(
        'sisisi',
        $sourceType,
        $sourcePurchaseId,
        $replacementType,
        $replacementPurchaseId,
        $reason,
        $actorId
    );
    $stmt->execute();
    $stmt->close();
}

function procurementPurchaseReplacementLinks(
    mysqli $conn,
    string $type,
    int $purchaseId
): array {
    procurementPurchaseReplacementEnsureStorage($conn);
    $type = procurementPurchaseReplacementValidateType($type);
    if ($purchaseId <= 0) {
        return ['replacement_of' => [], 'replaced_by' => []];
    }

    $replacementOf = [];
    $stmt = $conn->prepare(
        'SELECT source_type, source_purchase_id, reason, created_at
         FROM procurement_purchase_replacement_links
         WHERE replacement_type = ? AND replacement_purchase_id = ?
         ORDER BY id DESC'
    );
    $stmt->bind_param('si', $type, $purchaseId);
    $stmt->execute();
    $replacementOf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $replacedBy = [];
    $stmt = $conn->prepare(
        'SELECT replacement_type, replacement_purchase_id, reason, created_at
         FROM procurement_purchase_replacement_links
         WHERE source_type = ? AND source_purchase_id = ?
         ORDER BY id DESC'
    );
    $stmt->bind_param('si', $type, $purchaseId);
    $stmt->execute();
    $replacedBy = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['replacement_of' => $replacementOf, 'replaced_by' => $replacedBy];
}
