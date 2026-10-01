<?php

declare(strict_types=1);

require_once __DIR__ . '/accountReceivablesService.php';

function accountReceivablesAllocationRequiredText(array $input, string $key, string $label, int $maxLength = 255): string
{
    return accountReceivablesRequiredText($input, $key, $label, $maxLength);
}

function accountReceivablesAllocationPositiveMoney(mixed $value, string $label): float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        throw new RuntimeException("{$label} must be numeric.", 422);
    }

    $amount = round((float) $value, 2);
    if ($amount <= 0) {
        throw new RuntimeException("{$label} must be greater than zero.", 422);
    }

    return $amount;
}

function accountReceivablesAllocationDate(mixed $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return date('Y-m-d');
    }

    $date = accountReceivablesNullableDate($value, 'Allocation date');
    if ($date === null) {
        throw new RuntimeException('Allocation date is required.', 422);
    }

    return $date;
}

function accountReceivablesNormalizeAllocationType(mixed $value): string
{
    $type = strtoupper(trim((string) $value));
    if (!in_array($type, ACCOUNT_RECEIVABLES_ALLOCATION_TYPES, true)) {
        throw new RuntimeException('Allocation type must be Receipt, Advance Amortisation or Adjustment.', 422);
    }

    return $type;
}

function accountReceivablesNormalizeAllocationImpact(string $type, mixed $value): string
{
    if ($type !== 'ADJUSTMENT') {
        return 'REDUCE';
    }

    $impact = strtoupper(trim((string) ($value ?? 'REDUCE')));
    if (!in_array($impact, ACCOUNT_RECEIVABLES_ALLOCATION_IMPACTS, true)) {
        throw new RuntimeException('Adjustment impact must be Reduce or Increase.', 422);
    }

    return $impact;
}

function accountReceivablesAllocationSourceCapacity(array $source): float
{
    $lineType = (string) ($source['line_type'] ?? '');
    if ($lineType === 'Receipt') {
        return round(abs(min(0.0, (float) ($source['outstanding'] ?? 0))), 2);
    }

    if ($lineType === 'Adjustment') {
        return round(abs((float) ($source['outstanding'] ?? 0)), 2);
    }

    return 0.0;
}

function accountReceivablesAllocationSourceUsed(mysqli $conn, int $sourceId): float
{
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS used_amount
         FROM account_receivable_allocations
         WHERE source_receivable_id = ? AND reversed_at IS NULL'
    );
    $stmt->bind_param('i', $sourceId);
    $stmt->execute();
    $used = round((float) ($stmt->get_result()->fetch_assoc()['used_amount'] ?? 0), 2);
    $stmt->close();

    return $used;
}

function accountReceivablesAllocationTargetEffect(mysqli $conn, int $receivableId): float
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN impact = 'INCREASE' THEN amount ELSE -amount END), 0) AS allocation_effect
         FROM account_receivable_allocations
         WHERE receivable_id = ?
           AND reversed_at IS NULL
           AND COALESCE(legacy_field_synced, 0) = 0"
    );
    $stmt->bind_param('i', $receivableId);
    $stmt->execute();
    $effect = round((float) ($stmt->get_result()->fetch_assoc()['allocation_effect'] ?? 0), 2);
    $stmt->close();

    return $effect;
}

function accountReceivablesAllocationRateState(array $target, array $input): array
{
    $mode = accountReceivablesNormalizeRateMode($target['rate_mode'] ?? 'LOCKED');
    $postingRate = $target['fx_rate_used'] === null ? null : (float) $target['fx_rate_used'];
    $hasAppliedRate = array_key_exists('applied_fx_rate', $input)
        && $input['applied_fx_rate'] !== null
        && trim((string) $input['applied_fx_rate']) !== '';
    $requestedRate = $hasAppliedRate
        ? accountReceivablesRate($input['applied_fx_rate'], 'Applied FX rate', true)
        : null;

    if ($mode === 'LOCKED') {
        if ($hasAppliedRate && !accountReceivablesRatesEqual($postingRate, $requestedRate)) {
            throw new RuntimeException('This posting uses a Locked rate. The applied FX rate must match the original posting rate.', 409);
        }

        return [
            'rate_mode_snapshot' => 'LOCKED',
            'posting_fx_rate' => $postingRate,
            'applied_fx_rate' => $postingRate,
            'rate_override_reason' => null,
        ];
    }

    $appliedRate = $hasAppliedRate ? $requestedRate : $postingRate;
    $isOverride = !accountReceivablesRatesEqual($postingRate, $appliedRate);
    $overrideReason = accountReceivablesNullableText($input['rate_override_reason'] ?? null, 255);

    if ($isOverride && $overrideReason === null) {
        throw new RuntimeException('A rate override reason is required when a Flexible posting uses a different FX rate.', 422);
    }

    return [
        'rate_mode_snapshot' => 'FLEXIBLE',
        'posting_fx_rate' => $postingRate,
        'applied_fx_rate' => $appliedRate,
        'rate_override_reason' => $isOverride ? $overrideReason : null,
    ];
}

function accountReceivablesAllocationValidateSource(
    mysqli $conn,
    ?int $sourceId,
    int $targetId,
    string $type,
    float $amount,
    array $target
): ?array {
    if ($sourceId === null || $sourceId <= 0) {
        if ($type === 'ADVANCE_AMORTISATION') {
            throw new RuntimeException('Select the receipt / advance to amortise.', 422);
        }
        return null;
    }

    if ($sourceId === $targetId) {
        throw new RuntimeException('A receivables posting cannot allocate to itself.', 422);
    }

    $source = accountReceivablesGetInvoice($conn, $sourceId);
    $expectedLineType = $type === 'ADJUSTMENT' ? 'Adjustment' : 'Receipt';
    if ((string) ($source['line_type'] ?? '') !== $expectedLineType) {
        throw new RuntimeException(
            $type === 'ADJUSTMENT'
                ? 'Adjustment allocations must use an Adjustment posting as their source.'
                : 'Receipt and advance allocations must use a Receipt posting as their source.',
            422
        );
    }

    if ((string) ($source['currency'] ?? '') !== (string) ($target['currency'] ?? '')) {
        throw new RuntimeException('The source and target postings must use the same currency.', 422);
    }

    if (in_array($type, ['RECEIPT', 'ADVANCE_AMORTISATION'], true)) {
        $sourceClient = strtolower(trim((string) ($source['client_name'] ?? '')));
        $targetClient = strtolower(trim((string) ($target['client_name'] ?? '')));
        if ($sourceClient === '' || $targetClient === '' || $sourceClient !== $targetClient) {
            throw new RuntimeException('Receipt / advance allocations must belong to the same client as the target posting.', 422);
        }
    }

    $capacity = accountReceivablesAllocationSourceCapacity($source);
    $used = accountReceivablesAllocationSourceUsed($conn, $sourceId);
    $available = round(max(0.0, $capacity - $used), 2);
    if ($amount > $available + 0.005) {
        throw new RuntimeException('The allocation amount exceeds the remaining available amount on the selected source posting.', 409);
    }

    return $source;
}

function accountReceivablesNormalizeAllocationInput(mysqli $conn, array $input): array
{
    $targetId = (int) ($input['receivable_id'] ?? 0);
    if ($targetId <= 0) {
        throw new RuntimeException('A valid target receivables posting is required.', 422);
    }

    $target = accountReceivablesGetInvoice($conn, $targetId);
    if (!in_array((string) ($target['line_type'] ?? ''), ['Invoice', 'Balance b/f'], true)) {
        throw new RuntimeException('Allocations can currently be applied only to Invoice or Balance b/f postings.', 422);
    }

    $type = accountReceivablesNormalizeAllocationType($input['allocation_type'] ?? null);
    $impact = accountReceivablesNormalizeAllocationImpact($type, $input['impact'] ?? null);
    $amount = accountReceivablesAllocationPositiveMoney($input['amount'] ?? null, 'Allocation amount');
    $sourceIdRaw = $input['source_receivable_id'] ?? null;
    $sourceId = ($sourceIdRaw === null || trim((string) $sourceIdRaw) === '') ? null : (int) $sourceIdRaw;
    if ($sourceId !== null && $sourceId <= 0) {
        throw new RuntimeException('Invalid source receivables posting.', 422);
    }

    accountReceivablesAllocationValidateSource(
        $conn,
        $sourceId,
        $targetId,
        $type,
        $amount,
        $target
    );

    $legacyOutstanding = round((float) ($target['outstanding'] ?? 0), 2);
    $currentEffect = accountReceivablesAllocationTargetEffect($conn, $targetId);
    $effectiveOutstanding = round($legacyOutstanding + $currentEffect, 2);
    if ($impact === 'REDUCE' && $amount > max(0.0, $effectiveOutstanding) + 0.005) {
        throw new RuntimeException('The allocation amount exceeds the target posting balance.', 409);
    }

    $rateState = accountReceivablesAllocationRateState($target, $input);

    return [
        'receivable_id' => $targetId,
        'source_receivable_id' => $sourceId,
        'allocation_type' => $type,
        'impact' => $impact,
        'allocation_date' => accountReceivablesAllocationDate($input['allocation_date'] ?? null),
        'amount' => $amount,
        'currency' => (string) $target['currency'],
        'rate_mode_snapshot' => $rateState['rate_mode_snapshot'],
        'posting_fx_rate' => $rateState['posting_fx_rate'],
        'applied_fx_rate' => $rateState['applied_fx_rate'],
        'rate_override_reason' => $rateState['rate_override_reason'],
        'reference' => accountReceivablesNullableText($input['reference'] ?? null, 255),
        'notes' => accountReceivablesNullableText($input['notes'] ?? null),
    ];
}


function accountReceivablesAvailableAdvances(mysqli $conn, array $query): array
{
    accountReceivablesAssertFoundation($conn);

    $targetId = (int) ($query['receivable_id'] ?? 0);
    $target = $targetId > 0 ? accountReceivablesGetInvoice($conn, $targetId) : null;
    $clientName = trim((string) ($query['client_name'] ?? ($target['client_name'] ?? '')));
    $currency = strtoupper(trim((string) ($query['currency'] ?? ($target['currency'] ?? ''))));
    $projectName = trim((string) ($query['project_name'] ?? ($target['project_name'] ?? '')));

    if ($clientName === '' || !in_array($currency, ACCOUNT_RECEIVABLES_CURRENCIES, true)) {
        return ['rows' => [], 'count' => 0];
    }

    $conditions = ["deleted_at IS NULL", "line_type = 'Receipt'", 'TRIM(client_name) = ?', 'currency = ?'];
    $types = 'ss';
    $params = [$clientName, $currency];
    if ($targetId > 0) {
        $conditions[] = 'id <> ?';
        $types .= 'i';
        $params[] = $targetId;
    }

    $orderProject = '';
    if ($projectName !== '') {
        $orderProject = 'CASE WHEN TRIM(project_name) = ? THEN 0 ELSE 1 END ASC, ';
        $types .= 's';
        $params[] = $projectName;
    }

    $sql = 'SELECT * FROM account_receivable_invoices
            WHERE ' . implode(' AND ', $conditions) . '
            ORDER BY ' . $orderProject . 'CASE WHEN invoice_date IS NULL THEN 1 ELSE 0 END ASC, invoice_date ASC, id ASC
            LIMIT 250';
    $stmt = $conn->prepare($sql);
    accountReceivablesBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);
    $rows = [];
    while ($sourceRow = $result->fetch_assoc()) {
        $source = accountReceivablesDecorateInvoice($sourceRow, $settings, $bands);
        $capacity = accountReceivablesAllocationSourceCapacity($source);
        if ($capacity <= 0.005) {
            continue;
        }

        $used = accountReceivablesAllocationSourceUsed($conn, (int) $source['id']);
        $available = round(max(0.0, $capacity - $used), 2);
        if ($available <= 0.005) {
            continue;
        }

        $rows[] = [
            'id' => (int) $source['id'],
            'project_name' => (string) ($source['project_name'] ?? ''),
            'client_name' => (string) ($source['client_name'] ?? ''),
            'invoice_number' => $source['invoice_number'] ?? null,
            'invoice_date' => $source['invoice_date'] ?? null,
            'source_reference' => $source['source_reference'] ?? null,
            'remarks' => $source['remarks'] ?? null,
            'currency' => (string) ($source['currency'] ?? $currency),
            'source_amount' => $capacity,
            'allocated_amount' => $used,
            'available_amount' => $available,
            'fx_rate_used' => $source['fx_rate_used'] ?? null,
            'rate_mode' => $source['rate_mode'] ?? 'LOCKED',
            'project_match' => $projectName !== '' && trim((string) ($source['project_name'] ?? '')) === $projectName,
        ];
    }
    $stmt->close();

    return ['rows' => $rows, 'count' => count($rows)];
}
function accountReceivablesDecorateAllocation(array $row): array
{
    foreach (['id', 'receivable_id', 'source_receivable_id', 'created_by', 'reversed_by'] as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            $row[$key] = (int) $row[$key];
        }
    }

    $row['amount'] = round((float) ($row['amount'] ?? 0), 2);
    $row['legacy_field_synced'] = !empty($row['legacy_field_synced']);
    foreach (['posting_fx_rate', 'applied_fx_rate'] as $key) {
        $row[$key] = !array_key_exists($key, $row) || $row[$key] === null ? null : (float) $row[$key];
    }
    $row['fx_rate_variance'] = ($row['posting_fx_rate'] === null || $row['applied_fx_rate'] === null)
        ? null
        : round((float) $row['applied_fx_rate'] - (float) $row['posting_fx_rate'], 6);
    $row['is_reversed'] = !empty($row['reversed_at']);

    $createdByName = trim(implode(' ', array_filter([
        (string) ($row['created_by_fname'] ?? ''),
        (string) ($row['created_by_lname'] ?? ''),
    ], static fn(string $value): bool => trim($value) !== '')));
    $reversedByName = trim(implode(' ', array_filter([
        (string) ($row['reversed_by_fname'] ?? ''),
        (string) ($row['reversed_by_lname'] ?? ''),
    ], static fn(string $value): bool => trim($value) !== '')));

    $row['created_by_name'] = $createdByName !== ''
        ? $createdByName
        : (($row['created_by_email'] ?? null) ?: null);
    $row['reversed_by_name'] = $reversedByName !== ''
        ? $reversedByName
        : (($row['reversed_by_email'] ?? null) ?: null);

    return $row;
}

function accountReceivablesInsertAllocation(mysqli $conn, array $data, array $actor, bool $legacyFieldSynced = false): int
{
    $actorId = (int) ($actor['id'] ?? 0);
    $legacyFieldSyncedInt = $legacyFieldSynced ? 1 : 0;

    $stmt = $conn->prepare(
        'INSERT INTO account_receivable_allocations (
            receivable_id, source_receivable_id, allocation_type, impact, allocation_date,
            amount, legacy_field_synced, currency, rate_mode_snapshot, posting_fx_rate, applied_fx_rate,
            rate_override_reason, reference, notes, created_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iisssdissddsssi',
        $data['receivable_id'],
        $data['source_receivable_id'],
        $data['allocation_type'],
        $data['impact'],
        $data['allocation_date'],
        $data['amount'],
        $legacyFieldSyncedInt,
        $data['currency'],
        $data['rate_mode_snapshot'],
        $data['posting_fx_rate'],
        $data['applied_fx_rate'],
        $data['rate_override_reason'],
        $data['reference'],
        $data['notes'],
        $actorId
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    if ($legacyFieldSynced && $data['allocation_type'] === 'ADVANCE_AMORTISATION') {
        $sync = $conn->prepare(
            'UPDATE account_receivable_invoices
             SET advance_amortisation = COALESCE(advance_amortisation, 0) + ?, updated_by = ?
             WHERE id = ? AND deleted_at IS NULL'
        );
        $sync->bind_param('dii', $data['amount'], $actorId, $data['receivable_id']);
        $sync->execute();
        if ($sync->affected_rows !== 1) {
            $sync->close();
            throw new RuntimeException('Unable to update the invoice advance amortisation balance.', 409);
        }
        $sync->close();
    }

    if ($legacyFieldSynced && $data['allocation_type'] === 'RECEIPT') {
        $sync = $conn->prepare(
            'UPDATE account_receivable_invoices
             SET amount_received = COALESCE(amount_received, 0) + ?, updated_by = ?
             WHERE id = ? AND deleted_at IS NULL'
        );
        $sync->bind_param('dii', $data['amount'], $actorId, $data['receivable_id']);
        $sync->execute();
        if ($sync->affected_rows !== 1) {
            $sync->close();
            throw new RuntimeException('Unable to update the invoice amount received balance.', 409);
        }
        $sync->close();
    }

    accountReceivablesLogAction(
        $conn,
        $actor,
        sprintf(
            '%s recorded %s allocation #%d against receivables record #%d.',
            (string) ($actor['email'] ?? 'User'),
            $data['allocation_type'],
            $id,
            $data['receivable_id']
        )
    );

    return $id;
}

function accountReceivablesCreateAllocation(
    mysqli $conn,
    array $input,
    array $actor,
    bool $manageTransaction = true
): array {
    accountReceivablesAssertFoundation($conn);
    $data = accountReceivablesNormalizeAllocationInput($conn, $input);
    $legacyFieldSynced = $data['allocation_type'] === 'ADVANCE_AMORTISATION'
        || $data['allocation_type'] === 'RECEIPT';

    if ($manageTransaction) {
        $conn->begin_transaction();
    }

    try {
        // Re-run validation inside the transaction so source availability and target balance
        // cannot be silently consumed by another allocation between validation and insert.
        $data = accountReceivablesNormalizeAllocationInput($conn, $input);
        $legacyFieldSynced = $data['allocation_type'] === 'ADVANCE_AMORTISATION'
            || $data['allocation_type'] === 'RECEIPT';
        $id = accountReceivablesInsertAllocation($conn, $data, $actor, $legacyFieldSynced);

        if ($manageTransaction) {
            $conn->commit();
        }
    } catch (Throwable $error) {
        if ($manageTransaction) {
            $conn->rollback();
        }
        throw $error;
    }

    return accountReceivablesGetAllocation($conn, $id);
}

function accountReceivablesGetAllocation(mysqli $conn, int $id): array
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid allocation ID.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT a.*,
                target.invoice_number AS target_invoice_number,
                target.project_name AS target_project_name,
                target.client_name AS target_client_name,
                target.line_type AS target_line_type,
                source.invoice_number AS source_invoice_number,
                source.project_name AS source_project_name,
                source.client_name AS source_client_name,
                source.line_type AS source_line_type,
                source.source_reference AS source_reference,
                creator.fname AS created_by_fname,
                creator.lname AS created_by_lname,
                creator.email AS created_by_email,
                reverser.fname AS reversed_by_fname,
                reverser.lname AS reversed_by_lname,
                reverser.email AS reversed_by_email
         FROM account_receivable_allocations a
         INNER JOIN account_receivable_invoices target ON target.id = a.receivable_id
         LEFT JOIN account_receivable_invoices source ON source.id = a.source_receivable_id
         LEFT JOIN user_table creator ON creator.id = a.created_by
         LEFT JOIN user_table reverser ON reverser.id = a.reversed_by
         WHERE a.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Receivables allocation not found.', 404);
    }

    return accountReceivablesDecorateAllocation($row);
}

function accountReceivablesAllocationList(mysqli $conn, array $query): array
{
    accountReceivablesAssertFoundation($conn);
    $conditions = ['1 = 1'];
    $types = '';
    $params = [];

    $receivableId = (int) ($query['receivable_id'] ?? 0);
    if ($receivableId > 0) {
        $conditions[] = '(a.receivable_id = ? OR a.source_receivable_id = ?)';
        $types .= 'ii';
        $params[] = $receivableId;
        $params[] = $receivableId;
    }

    if (isset($query['active_only']) && filter_var($query['active_only'], FILTER_VALIDATE_BOOLEAN)) {
        $conditions[] = 'a.reversed_at IS NULL';
    }

    $sql = 'SELECT a.*,
                   target.invoice_number AS target_invoice_number,
                   target.project_name AS target_project_name,
                   target.client_name AS target_client_name,
                   target.line_type AS target_line_type,
                   source.invoice_number AS source_invoice_number,
                   source.project_name AS source_project_name,
                   source.client_name AS source_client_name,
                   source.line_type AS source_line_type,
                   source.source_reference AS source_reference,
                   creator.fname AS created_by_fname,
                   creator.lname AS created_by_lname,
                   creator.email AS created_by_email,
                   reverser.fname AS reversed_by_fname,
                   reverser.lname AS reversed_by_lname,
                   reverser.email AS reversed_by_email
            FROM account_receivable_allocations a
            INNER JOIN account_receivable_invoices target ON target.id = a.receivable_id
            LEFT JOIN account_receivable_invoices source ON source.id = a.source_receivable_id
            LEFT JOIN user_table creator ON creator.id = a.created_by
            LEFT JOIN user_table reverser ON reverser.id = a.reversed_by
            WHERE ' . implode(' AND ', $conditions) . '
            ORDER BY a.allocation_date DESC, a.id DESC
            LIMIT 500';
    $stmt = $conn->prepare($sql);
    accountReceivablesBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = accountReceivablesDecorateAllocation($row);
    }
    $stmt->close();

    return [
        'rows' => $rows,
        'count' => count($rows),
        'options' => [
            'allocation_types' => ACCOUNT_RECEIVABLES_ALLOCATION_TYPES,
            'impacts' => ACCOUNT_RECEIVABLES_ALLOCATION_IMPACTS,
            'rate_modes' => ACCOUNT_RECEIVABLES_RATE_MODES,
        ],
    ];
}

function accountReceivablesReverseAllocation(mysqli $conn, int $id, array $input, array $actor): array
{
    $allocation = accountReceivablesGetAllocation($conn, $id);
    if (!empty($allocation['reversed_at'])) {
        throw new RuntimeException('This allocation has already been reversed.', 409);
    }

    $reason = accountReceivablesRequiredText($input, 'reversal_reason', 'Reversal reason', 255);
    $actorId = (int) ($actor['id'] ?? 0);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE account_receivable_allocations
             SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ?
             WHERE id = ? AND reversed_at IS NULL'
        );
        $stmt->bind_param('isi', $actorId, $reason, $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('The allocation could not be reversed because it changed before this request completed.', 409);
        }
        $stmt->close();

        if (($allocation['allocation_type'] ?? '') === 'ADVANCE_AMORTISATION' && !empty($allocation['legacy_field_synced'])) {
            $amount = round((float) ($allocation['amount'] ?? 0), 2);
            $targetId = (int) ($allocation['receivable_id'] ?? 0);
            $sync = $conn->prepare(
                'UPDATE account_receivable_invoices
                 SET advance_amortisation = GREATEST(0, COALESCE(advance_amortisation, 0) - ?), updated_by = ?
                 WHERE id = ? AND deleted_at IS NULL'
            );
            $sync->bind_param('dii', $amount, $actorId, $targetId);
            $sync->execute();
            if ($sync->affected_rows !== 1) {
                $sync->close();
                throw new RuntimeException('Unable to reverse the invoice advance amortisation balance.', 409);
            }
            $sync->close();
        }

        if (($allocation['allocation_type'] ?? '') === 'RECEIPT' && !empty($allocation['legacy_field_synced'])) {
            $amount = round((float) ($allocation['amount'] ?? 0), 2);
            $targetId = (int) ($allocation['receivable_id'] ?? 0);
            $sync = $conn->prepare(
                'UPDATE account_receivable_invoices
                 SET amount_received = GREATEST(0, COALESCE(amount_received, 0) - ?), updated_by = ?
                 WHERE id = ? AND deleted_at IS NULL'
            );
            $sync->bind_param('dii', $amount, $actorId, $targetId);
            $sync->execute();
            if ($sync->affected_rows !== 1) {
                $sync->close();
                throw new RuntimeException('Unable to reverse the invoice amount received balance.', 409);
            }
            $sync->close();
        }

        accountReceivablesLogAction(
            $conn,
            $actor,
            sprintf('%s reversed receivables allocation #%d.', (string) ($actor['email'] ?? 'User'), $id)
        );

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return accountReceivablesGetAllocation($conn, $id);
}
