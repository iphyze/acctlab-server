<?php

declare(strict_types=1);

const ACCOUNT_RECEIVABLES_REQUIRED_TABLES = [
    'account_receivable_invoices',
];

const ACCOUNT_RECEIVABLES_LINE_TYPES = ['Invoice', 'Receipt', 'Adjustment', 'Balance b/f'];
const ACCOUNT_RECEIVABLES_POSITIONS = ['Open', 'Settled', 'Review - not in reported receivable'];
const ACCOUNT_RECEIVABLES_CURRENCIES = ['NGN', 'USD'];
const ACCOUNT_RECEIVABLES_RATE_MODES = ['LOCKED', 'FLEXIBLE'];
const ACCOUNT_RECEIVABLES_ALLOCATION_TYPES = ['RECEIPT', 'ADVANCE_AMORTISATION', 'ADJUSTMENT'];
const ACCOUNT_RECEIVABLES_ALLOCATION_IMPACTS = ['REDUCE', 'INCREASE'];

const ACCOUNT_RECEIVABLES_DEFAULT_SETTINGS = [
    'ageing_basis' => 'invoice_date',
    'default_credit_days' => 30,
    'settled_threshold' => 0.005,
];

const ACCOUNT_RECEIVABLES_STANDARD_AGEING_BANDS = [
    ['id' => 1, 'sort_order' => 1, 'from_days' => -999999, 'to_days' => -1, 'label' => 'Not yet due'],
    ['id' => 2, 'sort_order' => 2, 'from_days' => 0, 'to_days' => 30, 'label' => '1 - 30 days'],
    ['id' => 3, 'sort_order' => 3, 'from_days' => 31, 'to_days' => 60, 'label' => '31 - 60 days'],
    ['id' => 4, 'sort_order' => 4, 'from_days' => 61, 'to_days' => 90, 'label' => '61 - 90 days'],
    ['id' => 5, 'sort_order' => 5, 'from_days' => 91, 'to_days' => 120, 'label' => '91 - 120 days'],
    ['id' => 6, 'sort_order' => 6, 'from_days' => 121, 'to_days' => 180, 'label' => '121 - 180 days'],
    ['id' => 7, 'sort_order' => 7, 'from_days' => 181, 'to_days' => 365, 'label' => '181 - 365 days'],
    ['id' => 8, 'sort_order' => 8, 'from_days' => 366, 'to_days' => null, 'label' => 'Over 365 days'],
];

function accountReceivablesFoundationStatus(mysqli $conn): array
{
    $database = (string) ($conn->query('SELECT DATABASE() AS database_name')->fetch_assoc()['database_name'] ?? '');
    if ($database === '') {
        throw new RuntimeException('Unable to resolve the active AcctLab database.', 500);
    }

    $escapedTables = array_map(
        static fn(string $table): string => "'" . $conn->real_escape_string($table) . "'",
        ACCOUNT_RECEIVABLES_REQUIRED_TABLES
    );
    $tableList = implode(', ', $escapedTables);
    $result = $conn->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = '" . $conn->real_escape_string($database) . "'
           AND table_name IN ({$tableList})"
    );

    $available = [];
    while ($row = $result->fetch_assoc()) {
        $available[] = (string) $row['table_name'];
    }

    $missing = array_values(array_diff(ACCOUNT_RECEIVABLES_REQUIRED_TABLES, $available));

    return [
        'ready' => $missing === [],
        'required_tables' => ACCOUNT_RECEIVABLES_REQUIRED_TABLES,
        'missing_tables' => $missing,
    ];
}

function accountReceivablesAssertFoundation(mysqli $conn): void
{
    $foundation = accountReceivablesFoundationStatus($conn);
    if (!$foundation['ready']) {
        throw new RuntimeException('Receivables database setup is incomplete.', 503);
    }
}

function accountReceivablesLatestUsdNgnRate(mysqli $conn): float
{
    accountReceivablesAssertFoundation($conn);

    $result = $conn->query(
        "SELECT fx_rate_used
         FROM account_receivable_invoices
         WHERE deleted_at IS NULL
           AND currency = 'USD'
           AND fx_rate_used IS NOT NULL
           AND fx_rate_used > 0
         ORDER BY
           CASE WHEN invoice_date IS NULL THEN 1 ELSE 0 END ASC,
           invoice_date DESC,
           created_at DESC,
           id DESC
         LIMIT 1"
    );

    $row = $result->fetch_assoc();
    return round((float) ($row['fx_rate_used'] ?? 0), 6);
}

function accountReceivablesSettings(mysqli $conn): array
{
    return [
        'reporting_date' => date('Y-m-d'),
        'ageing_basis' => ACCOUNT_RECEIVABLES_DEFAULT_SETTINGS['ageing_basis'],
        'default_credit_days' => ACCOUNT_RECEIVABLES_DEFAULT_SETTINGS['default_credit_days'],
        'usd_ngn_rate' => accountReceivablesLatestUsdNgnRate($conn),
        'settled_threshold' => ACCOUNT_RECEIVABLES_DEFAULT_SETTINGS['settled_threshold'],
    ];
}

function accountReceivablesAgeingBands(mysqli $conn): array
{
    accountReceivablesAssertFoundation($conn);
    return ACCOUNT_RECEIVABLES_STANDARD_AGEING_BANDS;
}

function accountReceivablesBootstrap(mysqli $conn, array $actor): array
{
    $foundation = accountReceivablesFoundationStatus($conn);
    $permissions = [
        'can_view' => true,
        'can_manage' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
    ];

    $response = [
        'module' => [
            'key' => 'receivables',
            'label' => 'Receivables',
            'version' => 3,
        ],
        'permissions' => $permissions,
        'foundation' => $foundation,
        'settings' => null,
        'ageing_bands' => [],
        'invoice_options' => [
            'line_types' => ACCOUNT_RECEIVABLES_LINE_TYPES,
            'positions' => ACCOUNT_RECEIVABLES_POSITIONS,
            'currencies' => ACCOUNT_RECEIVABLES_CURRENCIES,
            'rate_modes' => ACCOUNT_RECEIVABLES_RATE_MODES,
            'allocation_types' => ACCOUNT_RECEIVABLES_ALLOCATION_TYPES,
            'allocation_impacts' => ACCOUNT_RECEIVABLES_ALLOCATION_IMPACTS,
        ],
        'counts' => [
            'invoice_rows' => 0,
            'open_rows' => 0,
            'projects' => 0,
        ],
    ];

    if (!$foundation['ready']) {
        return $response;
    }

    $response['settings'] = accountReceivablesSettings($conn);
    $response['ageing_bands'] = accountReceivablesAgeingBands($conn);

    $counts = $conn->query(
        "SELECT
            COUNT(*) AS invoice_rows,
            SUM(CASE WHEN position = 'Open' THEN 1 ELSE 0 END) AS open_rows,
            COUNT(DISTINCT NULLIF(TRIM(project_name), '')) AS projects
         FROM account_receivable_invoices
         WHERE deleted_at IS NULL"
    )->fetch_assoc() ?: [];

    $response['counts'] = [
        'invoice_rows' => (int) ($counts['invoice_rows'] ?? 0),
        'open_rows' => (int) ($counts['open_rows'] ?? 0),
        'projects' => (int) ($counts['projects'] ?? 0),
    ];

    return $response;
}

function accountReceivablesNullableText(mixed $value, int $maxLength = 0): ?string
{
    if ($value === null) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
        throw new RuntimeException("A text value exceeds the {$maxLength}-character limit.", 422);
    }

    return $text;
}

function accountReceivablesRequiredText(array $input, string $key, string $label, int $maxLength): string
{
    $value = accountReceivablesNullableText($input[$key] ?? null, $maxLength);
    if ($value === null) {
        throw new RuntimeException("{$label} is required.", 422);
    }
    return $value;
}

function accountReceivablesNullableDate(mixed $value, string $label): ?string
{
    $text = accountReceivablesNullableText($value, 10);
    if ($text === null) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    if (!$date || $date->format('Y-m-d') !== $text) {
        throw new RuntimeException("{$label} must use YYYY-MM-DD format.", 422);
    }

    return $text;
}

function accountReceivablesRate(mixed $value, string $label, bool $allowNull = false): ?float
{
    if ($allowNull && ($value === null || trim((string) $value) === '')) {
        return null;
    }
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (!is_numeric($value)) {
        throw new RuntimeException("{$label} must be numeric.", 422);
    }
    $number = round((float) $value, 6);
    if ($number < 0) {
        throw new RuntimeException("{$label} cannot be negative.", 422);
    }
    return $number;
}

function accountReceivablesMoney(mixed $value, string $label, bool $allowNull = false): ?float
{
    if ($allowNull && ($value === null || trim((string) $value) === '')) {
        return null;
    }

    if ($value === null || $value === '') {
        return 0.0;
    }

    if (!is_numeric($value)) {
        throw new RuntimeException("{$label} must be numeric.", 422);
    }

    $number = round((float) $value, 2);
    if ($number < 0) {
        throw new RuntimeException("{$label} cannot be negative.", 422);
    }

    return $number;
}

function accountReceivablesPercentageRate(mixed $value, string $label): ?float
{
    $rate = accountReceivablesRate($value, $label, true);
    if ($rate !== null && $rate > 100) {
        throw new RuntimeException("{$label} must be between 0 and 100.", 422);
    }
    return $rate;
}

function accountReceivablesPercentageAmount(
    array $input,
    string $amountKey,
    string $rateKey,
    string $label,
    float $invoiceValueNet
): array {
    $rate = accountReceivablesPercentageRate($input[$rateKey] ?? null, "{$label} rate");
    if ($rate !== null) {
        return [
            'rate' => $rate,
            'amount' => round($invoiceValueNet * $rate / 100, 2),
        ];
    }

    return [
        'rate' => null,
        'amount' => accountReceivablesMoney($input[$amountKey] ?? 0, $label),
    ];
}

function accountReceivablesNormalizeRateMode(mixed $value, string $fallback = 'LOCKED'): string
{
    $mode = strtoupper(trim((string) ($value ?? $fallback)));
    if ($mode === '') {
        $mode = $fallback;
    }

    if (!in_array($mode, ACCOUNT_RECEIVABLES_RATE_MODES, true)) {
        throw new RuntimeException('Rate treatment must be Locked or Flexible.', 422);
    }

    return $mode;
}

function accountReceivablesRatesEqual(?float $left, ?float $right, float $tolerance = 0.000001): bool
{
    if ($left === null || $right === null) {
        return $left === $right;
    }

    return abs($left - $right) <= $tolerance;
}

function accountReceivablesNormalizeInvoiceInput(array $input, array $settings, ?array $existing = null): array
{
    $lineType = accountReceivablesRequiredText($input, 'line_type', 'Line type', 40);
    if (!in_array($lineType, ACCOUNT_RECEIVABLES_LINE_TYPES, true)) {
        throw new RuntimeException('Invalid receivables line type.', 422);
    }

    $currency = strtoupper(accountReceivablesRequiredText($input, 'currency', 'Currency', 3));
    if (!in_array($currency, ACCOUNT_RECEIVABLES_CURRENCIES, true)) {
        throw new RuntimeException('Currency must be NGN or USD.', 422);
    }

    $position = accountReceivablesRequiredText($input, 'position', 'Position', 60);
    if (!in_array($position, ACCOUNT_RECEIVABLES_POSITIONS, true)) {
        throw new RuntimeException('Invalid receivables position.', 422);
    }

    $invoiceDate = accountReceivablesNullableDate($input['invoice_date'] ?? null, 'Invoice date');
    if ($lineType === 'Invoice' && $invoiceDate === null) {
        throw new RuntimeException('Invoice date is required for invoice lines.', 422);
    }

    $creditDaysRaw = $input['credit_days'] ?? ($settings['default_credit_days'] ?? 30);
    if (!is_numeric($creditDaysRaw)) {
        throw new RuntimeException('Credit days must be numeric.', 422);
    }
    $creditDays = (int) $creditDaysRaw;
    if ($creditDays < 0 || $creditDays > 3650) {
        throw new RuntimeException('Credit days must be between 0 and 3650.', 422);
    }

    $dueDate = null;
    if ($invoiceDate !== null) {
        $dueDate = (new DateTimeImmutable($invoiceDate))->modify("+{$creditDays} days")->format('Y-m-d');
    }

    $fxRate = accountReceivablesRate($input['fx_rate_used'] ?? ($existing['fx_rate_used'] ?? null), 'FX rate used', true);
    $rateMode = accountReceivablesNormalizeRateMode(
        array_key_exists('rate_mode', $input) ? $input['rate_mode'] : ($existing['rate_mode'] ?? 'LOCKED')
    );

    $invoiceValueNet = accountReceivablesMoney($input['invoice_value_net'] ?? 0, 'Invoice value (net)');
    $retention = accountReceivablesPercentageAmount($input, 'retention', 'retention_rate_pct', 'Retention', $invoiceValueNet);
    $vatCharged = accountReceivablesPercentageAmount($input, 'vat_charged', 'vat_rate_pct', 'VAT', $invoiceValueNet);
    $wht = accountReceivablesPercentageAmount($input, 'wht', 'wht_rate_pct', 'WHT', $invoiceValueNet);
    $ncdLevy = accountReceivablesPercentageAmount($input, 'ncd_levy', 'ncd_rate_pct', 'NCD levy', $invoiceValueNet);
    $stampDuty = accountReceivablesPercentageAmount($input, 'stamp_duty', 'stamp_duty_rate_pct', 'Stamp duty', $invoiceValueNet);

    return [
        'project_name' => accountReceivablesRequiredText($input, 'project_name', 'Project', 255),
        'client_name' => accountReceivablesRequiredText($input, 'client_name', 'Client / Main contractor', 255),
        'invoice_number' => accountReceivablesNullableText($input['invoice_number'] ?? null, 120),
        'invoice_date' => $invoiceDate,
        'credit_days' => $creditDays,
        'due_date' => $dueDate,
        'currency' => $currency,
        'line_type' => $lineType,
        'invoice_value_net' => $invoiceValueNet,
        'retention_rate_pct' => $retention['rate'],
        'retention' => $retention['amount'],
        'advance_amortisation' => accountReceivablesMoney($input['advance_amortisation'] ?? 0, 'Advance amortisation'),
        'admin_other_charges' => accountReceivablesMoney($input['admin_other_charges'] ?? 0, 'Admin & other charges'),
        'vat_rate_pct' => $vatCharged['rate'],
        'vat_charged' => $vatCharged['amount'],
        'invoice_value_gross' => accountReceivablesMoney($input['invoice_value_gross'] ?? null, 'Invoice value (gross)', true),
        'wht_rate_pct' => $wht['rate'],
        'wht' => $wht['amount'],
        'vat_deducted_at_source' => accountReceivablesMoney($input['vat_deducted_at_source'] ?? 0, 'VAT deducted at source'),
        'ncd_rate_pct' => $ncdLevy['rate'],
        'ncd_levy' => $ncdLevy['amount'],
        'stamp_duty_rate_pct' => $stampDuty['rate'],
        'stamp_duty' => $stampDuty['amount'],
        'bank_charges' => accountReceivablesMoney($input['bank_charges'] ?? 0, 'Bank charges'),
        'other_deductions' => accountReceivablesMoney($input['other_deductions'] ?? 0, 'Other deductions'),
        'amount_received' => accountReceivablesMoney($input['amount_received'] ?? 0, 'Amount received'),
        'wht_credit_note_outstanding' => accountReceivablesMoney($input['wht_credit_note_outstanding'] ?? 0, 'WHT credit note outstanding'),
        'fx_rate_used' => $fxRate,
        'rate_mode' => $rateMode,
        'position' => $position,
        'date_basis_note' => accountReceivablesNullableText($input['date_basis_note'] ?? null, 255),
        'source_reference' => accountReceivablesNullableText($input['source_reference'] ?? null, 255),
        'remarks' => accountReceivablesNullableText($input['remarks'] ?? null),
    ];
}

function accountReceivablesDecimal(mixed $value): float
{
    return round((float) ($value ?? 0), 2);
}

function accountReceivablesDecorateInvoice(array $row, array $settings, array $bands): array
{
    $moneyFields = [
        'invoice_value_net', 'retention', 'advance_amortisation', 'admin_other_charges',
        'vat_charged', 'wht', 'vat_deducted_at_source', 'ncd_levy', 'stamp_duty',
        'bank_charges', 'other_deductions', 'amount_received', 'wht_credit_note_outstanding',
    ];
    foreach ($moneyFields as $field) {
        $row[$field] = accountReceivablesDecimal($row[$field] ?? 0);
    }

    $grossOverride = $row['invoice_value_gross'] === null ? null : accountReceivablesDecimal($row['invoice_value_gross']);
    $row['invoice_value_gross_override'] = $grossOverride;
    $grossCalculated = $row['invoice_value_net'] + $row['vat_charged'];
    $hasGrossBase = $row['invoice_value_net'] != 0.0 || $row['vat_charged'] != 0.0 || $grossOverride !== null;
    $row['invoice_value_gross'] = $grossOverride ?? ($hasGrossBase ? round($grossCalculated, 2) : null);

    $row['total_deductions'] = round(
        $row['retention']
        + $row['advance_amortisation']
        + $row['admin_other_charges']
        + $row['wht']
        + $row['vat_deducted_at_source']
        + $row['ncd_levy']
        + $row['stamp_duty']
        + $row['bank_charges']
        + $row['other_deductions'],
        2
    );
    $row['net_receivable'] = round(
        $row['invoice_value_net'] + $row['vat_charged'] - $row['total_deductions'],
        2
    );
    $row['outstanding'] = round($row['net_receivable'] - $row['amount_received'], 2);
    $row['gross_less_deductions_vs_net_receivable'] = $row['invoice_value_gross'] === null
        ? null
        : round($row['invoice_value_gross'] - $row['total_deductions'] - $row['net_receivable'], 2);

    $row['id'] = (int) $row['id'];
    $row['credit_days'] = (int) $row['credit_days'];
    foreach (['retention_rate_pct', 'vat_rate_pct', 'wht_rate_pct', 'ncd_rate_pct', 'stamp_duty_rate_pct'] as $rateField) {
        $row[$rateField] = !array_key_exists($rateField, $row) || $row[$rateField] === null
            ? null
            : (float) $row[$rateField];
    }
    $row['fx_rate_used'] = $row['fx_rate_used'] === null ? null : (float) $row['fx_rate_used'];
    $row['rate_mode'] = accountReceivablesNormalizeRateMode($row['rate_mode'] ?? 'LOCKED');

    $isOpen = (string) ($row['position'] ?? '') === 'Open';
    $row['in_ageing'] = $isOpen ? 'Yes' : 'No';
    $row['days_outstanding'] = null;
    $row['ageing_band'] = $isOpen ? 'No invoice date' : 'Not aged';
    $row['status'] = $isOpen ? 'Within terms' : 'Not aged';

    $threshold = (float) ($settings['settled_threshold'] ?? 0.005);
    if ($isOpen && abs($row['outstanding']) < $threshold) {
        $row['status'] = 'Settled';
    }

    if ($isOpen && !empty($row['invoice_date'])) {
        $basis = (string) ($settings['ageing_basis'] ?? 'invoice_date') === 'due_date'
            ? ($row['due_date'] ?: $row['invoice_date'])
            : $row['invoice_date'];
        $reportingDate = new DateTimeImmutable((string) $settings['reporting_date']);
        $basisDate = new DateTimeImmutable((string) $basis);
        $days = (int) $basisDate->diff($reportingDate)->format('%r%a');
        $row['days_outstanding'] = $days;

        if ($row['status'] !== 'Settled' && !empty($row['due_date'])) {
            $dueDate = new DateTimeImmutable((string) $row['due_date']);
            $row['status'] = $reportingDate > $dueDate ? 'Overdue' : 'Within terms';
        }

        foreach ($bands as $band) {
            $from = (int) $band['from_days'];
            $to = $band['to_days'] === null ? null : (int) $band['to_days'];
            if ($days >= $from && ($to === null || $days <= $to)) {
                $row['ageing_band'] = (string) $band['label'];
                break;
            }
        }
    }

    return $row;
}

function accountReceivablesHistoricalRateAdjustmentMap(mysqli $conn): array
{
    $database = (string) ($conn->query('SELECT DATABASE() AS database_name')->fetch_assoc()['database_name'] ?? '');
    if ($database === '') {
        return [];
    }

    $tableCheck = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = '" . $conn->real_escape_string($database) . "'
           AND table_name = 'account_receivable_allocations'"
    );
    if ((int) ($tableCheck->fetch_assoc()['total'] ?? 0) === 0) {
        return [];
    }

    $result = $conn->query(
        "SELECT
            receivable_id,
            COALESCE(SUM(
                CASE
                    WHEN impact = 'INCREASE'
                        THEN amount * (applied_fx_rate - posting_fx_rate)
                    ELSE -amount * (applied_fx_rate - posting_fx_rate)
                END
            ), 0) AS outstanding_fx_adjustment_ngn,
            COALESCE(SUM(
                CASE
                    WHEN allocation_type = 'ADVANCE_AMORTISATION'
                        THEN amount * (applied_fx_rate - posting_fx_rate)
                    ELSE 0
                END
            ), 0) AS advance_amortisation_fx_adjustment_ngn,
            COUNT(*) AS rate_tracked_allocations
         FROM account_receivable_allocations
         WHERE reversed_at IS NULL
           AND currency = 'USD'
           AND posting_fx_rate IS NOT NULL
           AND posting_fx_rate > 0
           AND applied_fx_rate IS NOT NULL
           AND applied_fx_rate > 0
         GROUP BY receivable_id"
    );

    $map = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['receivable_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = [
            'outstanding_fx_adjustment_ngn' => round((float) ($row['outstanding_fx_adjustment_ngn'] ?? 0), 2),
            'advance_amortisation_fx_adjustment_ngn' => round((float) ($row['advance_amortisation_fx_adjustment_ngn'] ?? 0), 2),
            'rate_tracked_allocations' => (int) ($row['rate_tracked_allocations'] ?? 0),
        ];
    }

    return $map;
}

function accountReceivablesApplyHistoricalRateMetrics(array $row, array $adjustment = []): array
{
    $currency = strtoupper((string) ($row['currency'] ?? 'NGN'));
    $outstanding = (float) ($row['outstanding'] ?? 0);
    $deductions = (float) ($row['total_deductions'] ?? 0);
    $whtCredit = (float) ($row['wht_credit_note_outstanding'] ?? 0);
    $gross = (float) ($row['invoice_value_gross'] ?? 0);
    $outstandingAdjustment = round((float) ($adjustment['outstanding_fx_adjustment_ngn'] ?? 0), 2);
    $advanceAdjustment = round((float) ($adjustment['advance_amortisation_fx_adjustment_ngn'] ?? 0), 2);

    $row['historical_rate_available'] = true;
    $row['historical_rate_basis'] = $currency === 'USD' ? 'POSTING_RATE' : 'NATIVE_NGN';
    $row['outstanding_fx_adjustment_ngn'] = $currency === 'USD' ? $outstandingAdjustment : 0.0;
    $row['advance_amortisation_fx_adjustment_ngn'] = $currency === 'USD' ? $advanceAdjustment : 0.0;

    if ($currency !== 'USD') {
        $row['outstanding_ngn_equivalent'] = round($outstanding, 2);
        $row['total_deductions_ngn_equivalent'] = round($deductions, 2);
        $row['wht_credit_note_outstanding_ngn_equivalent'] = round($whtCredit, 2);
        $row['gross_invoice_ngn_equivalent'] = round($gross, 2);
        return $row;
    }

    $postingRate = $row['fx_rate_used'] === null ? 0.0 : (float) $row['fx_rate_used'];
    if ($postingRate <= 0) {
        $row['historical_rate_available'] = false;
        $row['historical_rate_basis'] = 'MISSING_POSTING_RATE';
        $row['outstanding_ngn_equivalent'] = null;
        $row['total_deductions_ngn_equivalent'] = null;
        $row['wht_credit_note_outstanding_ngn_equivalent'] = null;
        $row['gross_invoice_ngn_equivalent'] = null;
        return $row;
    }

    if (abs($outstandingAdjustment) >= 0.005 || abs($advanceAdjustment) >= 0.005) {
        $row['historical_rate_basis'] = 'POSTING_AND_ALLOCATION_RATES';
    } elseif (accountReceivablesNormalizeRateMode($row['rate_mode'] ?? 'LOCKED') === 'LOCKED') {
        $row['historical_rate_basis'] = 'LOCKED_POSTING_RATE';
    }

    $row['outstanding_ngn_equivalent'] = round(($outstanding * $postingRate) + $outstandingAdjustment, 2);
    $row['total_deductions_ngn_equivalent'] = round(($deductions * $postingRate) + $advanceAdjustment, 2);
    $row['wht_credit_note_outstanding_ngn_equivalent'] = round($whtCredit * $postingRate, 2);
    $row['gross_invoice_ngn_equivalent'] = round($gross * $postingRate, 2);

    return $row;
}

function accountReceivablesBindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    $stmt->bind_param($types, ...$refs);
}

function accountReceivablesBuildInvoiceFilters(array $query): array
{
    $conditions = ['deleted_at IS NULL'];
    $types = '';
    $params = [];

    $search = trim((string) ($query['q'] ?? ''));
    if ($search !== '') {
        $conditions[] = '(project_name LIKE ? OR client_name LIKE ? OR invoice_number LIKE ? OR source_reference LIKE ? OR remarks LIKE ?)';
        $needle = '%' . $search . '%';
        for ($index = 0; $index < 5; $index++) {
            $types .= 's';
            $params[] = $needle;
        }
    }

    $filterMap = [
        'currency' => ACCOUNT_RECEIVABLES_CURRENCIES,
        'line_type' => ACCOUNT_RECEIVABLES_LINE_TYPES,
        'position' => ACCOUNT_RECEIVABLES_POSITIONS,
    ];
    foreach ($filterMap as $key => $allowed) {
        $value = trim((string) ($query[$key] ?? ''));
        if ($value !== '' && in_array($value, $allowed, true)) {
            $conditions[] = "{$key} = ?";
            $types .= 's';
            $params[] = $value;
        }
    }

    $dateFrom = accountReceivablesNullableDate($query['date_from'] ?? null, 'Date from');
    if ($dateFrom !== null) {
        $conditions[] = 'invoice_date >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }

    $dateTo = accountReceivablesNullableDate($query['date_to'] ?? null, 'Date to');
    if ($dateTo !== null) {
        $conditions[] = 'invoice_date <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    return [implode(' AND ', $conditions), $types, $params];
}

function accountReceivablesInvoiceList(mysqli $conn, array $query): array
{
    accountReceivablesAssertFoundation($conn);
    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);

    $page = max(1, (int) ($query['page'] ?? 1));
    $perPage = (int) ($query['per_page'] ?? 25);
    $perPage = max(5, min(250, $perPage));
    $offset = ($page - 1) * $perPage;

    $allowedSorts = [
        'id', 'invoice_date', 'due_date', 'project_name', 'client_name', 'invoice_number',
        'currency', 'line_type', 'position', 'created_at', 'updated_at',
    ];
    $sortBy = (string) ($query['sort_by'] ?? 'created_at');
    if (!in_array($sortBy, $allowedSorts, true)) {
        $sortBy = 'created_at';
    }
    $sortOrder = strtoupper((string) ($query['sort_order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    [$where, $types, $params] = accountReceivablesBuildInvoiceFilters($query);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM account_receivable_invoices WHERE {$where}");
    accountReceivablesBindParams($countStmt, $types, $params);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $summarySql = "SELECT
        COUNT(*) AS row_count,
        SUM(CASE WHEN position = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN currency = 'NGN' THEN (invoice_value_net + vat_charged - (retention + advance_amortisation + admin_other_charges + wht + vat_deducted_at_source + ncd_levy + stamp_duty + bank_charges + other_deductions) - amount_received) ELSE 0 END) AS ngn_outstanding,
        SUM(CASE WHEN currency = 'USD' THEN (invoice_value_net + vat_charged - (retention + advance_amortisation + admin_other_charges + wht + vat_deducted_at_source + ncd_levy + stamp_duty + bank_charges + other_deductions) - amount_received) ELSE 0 END) AS usd_outstanding
        FROM account_receivable_invoices WHERE {$where}";
    $summaryParams = $params;
    $summaryStmt = $conn->prepare($summarySql);
    accountReceivablesBindParams($summaryStmt, $types, $summaryParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $listSql = "SELECT * FROM account_receivable_invoices
        WHERE {$where}
        ORDER BY {$sortBy} {$sortOrder}, id DESC
        LIMIT ? OFFSET ?";
    $listParams = $params;
    $listTypes = $types . 'ii';
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $stmt = $conn->prepare($listSql);
    accountReceivablesBindParams($stmt, $listTypes, $listParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = accountReceivablesDecorateInvoice($row, $settings, $bands);
    }
    $stmt->close();

    return [
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ],
        'summary' => [
            'row_count' => (int) ($summary['row_count'] ?? 0),
            'open_count' => (int) ($summary['open_count'] ?? 0),
            'ngn_outstanding' => round((float) ($summary['ngn_outstanding'] ?? 0), 2),
            'usd_outstanding' => round((float) ($summary['usd_outstanding'] ?? 0), 2),
        ],
        'settings' => [
            'reporting_date' => $settings['reporting_date'],
            'ageing_basis' => $settings['ageing_basis'],
            'default_credit_days' => (int) $settings['default_credit_days'],
            'usd_ngn_rate' => (float) $settings['usd_ngn_rate'],
            'settled_threshold' => (float) $settings['settled_threshold'],
        ],
        'options' => [
            'line_types' => ACCOUNT_RECEIVABLES_LINE_TYPES,
            'positions' => ACCOUNT_RECEIVABLES_POSITIONS,
            'currencies' => ACCOUNT_RECEIVABLES_CURRENCIES,
            'rate_modes' => ACCOUNT_RECEIVABLES_RATE_MODES,
            'allocation_types' => ACCOUNT_RECEIVABLES_ALLOCATION_TYPES,
            'allocation_impacts' => ACCOUNT_RECEIVABLES_ALLOCATION_IMPACTS,
        ],
    ];
}

function accountReceivablesGetInvoice(mysqli $conn, int $id): array
{
    accountReceivablesAssertFoundation($conn);
    if ($id <= 0) {
        throw new RuntimeException('Invalid receivables record ID.', 422);
    }

    $stmt = $conn->prepare('SELECT * FROM account_receivable_invoices WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Receivables record not found.', 404);
    }

    return accountReceivablesDecorateInvoice($row, accountReceivablesSettings($conn), accountReceivablesAgeingBands($conn));
}

function accountReceivablesLogAction(mysqli $conn, array $actor, string $action): void
{
    try {
        $userId = (int) ($actor['id'] ?? 0);
        $email = (string) ($actor['email'] ?? 'system');
        $stmt = $conn->prepare('INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $userId, $action, $email);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Receivables audit log failed: ' . $error->getMessage());
    }
}

function accountReceivablesCreateInvoice(mysqli $conn, array $input, array $actor): array
{
    $settings = accountReceivablesSettings($conn);
    $data = accountReceivablesNormalizeInvoiceInput($input, $settings);
    $actorId = (int) ($actor['id'] ?? 0);

    $sql = "INSERT INTO account_receivable_invoices (
        project_name, client_name, invoice_number, invoice_date, credit_days, due_date,
        currency, line_type, invoice_value_net, retention_rate_pct, retention, advance_amortisation,
        admin_other_charges, vat_rate_pct, vat_charged, invoice_value_gross, wht_rate_pct, wht,
        vat_deducted_at_source, ncd_rate_pct, ncd_levy, stamp_duty_rate_pct, stamp_duty,
        bank_charges, other_deductions, amount_received, wht_credit_note_outstanding, fx_rate_used, rate_mode,
        position, date_basis_note, source_reference, remarks, created_by, updated_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssssisssddddddddddddddddddddsssssii',
        $data['project_name'],
        $data['client_name'],
        $data['invoice_number'],
        $data['invoice_date'],
        $data['credit_days'],
        $data['due_date'],
        $data['currency'],
        $data['line_type'],
        $data['invoice_value_net'],
        $data['retention_rate_pct'],
        $data['retention'],
        $data['advance_amortisation'],
        $data['admin_other_charges'],
        $data['vat_rate_pct'],
        $data['vat_charged'],
        $data['invoice_value_gross'],
        $data['wht_rate_pct'],
        $data['wht'],
        $data['vat_deducted_at_source'],
        $data['ncd_rate_pct'],
        $data['ncd_levy'],
        $data['stamp_duty_rate_pct'],
        $data['stamp_duty'],
        $data['bank_charges'],
        $data['other_deductions'],
        $data['amount_received'],
        $data['wht_credit_note_outstanding'],
        $data['fx_rate_used'],
        $data['rate_mode'],
        $data['position'],
        $data['date_basis_note'],
        $data['source_reference'],
        $data['remarks'],
        $actorId,
        $actorId
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    accountReceivablesLogAction(
        $conn,
        $actor,
        sprintf('%s created receivables record #%d (%s).', (string) ($actor['email'] ?? 'User'), $id, $data['invoice_number'] ?? $data['line_type'])
    );

    return accountReceivablesGetInvoice($conn, $id);
}

function accountReceivablesUpdateInvoice(mysqli $conn, int $id, array $input, array $actor): array
{
    $existing = accountReceivablesGetInvoice($conn, $id);
    $settings = accountReceivablesSettings($conn);
    $data = accountReceivablesNormalizeInvoiceInput($input, $settings, $existing);

    if (accountReceivablesAllocationHistoryCount($conn, $id) > 0) {
        $existingFxRate = $existing['fx_rate_used'] === null ? null : (float) $existing['fx_rate_used'];
        $nextFxRate = $data['fx_rate_used'] === null ? null : (float) $data['fx_rate_used'];
        if (!accountReceivablesRatesEqual($existingFxRate, $nextFxRate)) {
            throw new RuntimeException('The original posting FX rate cannot be changed after allocations have been recorded.', 409);
        }
        if ((string) ($existing['currency'] ?? '') !== (string) $data['currency']) {
            throw new RuntimeException('Currency cannot be changed after allocations have been recorded.', 409);
        }
        if (abs((float) ($existing['amount_received'] ?? 0) - (float) $data['amount_received']) > 0.005
            || abs((float) ($existing['advance_amortisation'] ?? 0) - (float) $data['advance_amortisation']) > 0.005) {
            throw new RuntimeException('Use the allocation ledger for new receipts and advance amortisation after allocation history has started.', 409);
        }
    }
    $actorId = (int) ($actor['id'] ?? 0);

    $sql = "UPDATE account_receivable_invoices SET
        project_name = ?, client_name = ?, invoice_number = ?, invoice_date = ?,
        credit_days = ?, due_date = ?, currency = ?, line_type = ?, invoice_value_net = ?,
        retention_rate_pct = ?, retention = ?, advance_amortisation = ?, admin_other_charges = ?,
        vat_rate_pct = ?, vat_charged = ?, invoice_value_gross = ?, wht_rate_pct = ?, wht = ?,
        vat_deducted_at_source = ?, ncd_rate_pct = ?, ncd_levy = ?, stamp_duty_rate_pct = ?,
        stamp_duty = ?, bank_charges = ?, other_deductions = ?, amount_received = ?,
        wht_credit_note_outstanding = ?, fx_rate_used = ?, rate_mode = ?, position = ?, date_basis_note = ?,
        source_reference = ?, remarks = ?, updated_by = ?
        WHERE id = ? AND deleted_at IS NULL";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssssisssddddddddddddddddddddsssssii',
        $data['project_name'],
        $data['client_name'],
        $data['invoice_number'],
        $data['invoice_date'],
        $data['credit_days'],
        $data['due_date'],
        $data['currency'],
        $data['line_type'],
        $data['invoice_value_net'],
        $data['retention_rate_pct'],
        $data['retention'],
        $data['advance_amortisation'],
        $data['admin_other_charges'],
        $data['vat_rate_pct'],
        $data['vat_charged'],
        $data['invoice_value_gross'],
        $data['wht_rate_pct'],
        $data['wht'],
        $data['vat_deducted_at_source'],
        $data['ncd_rate_pct'],
        $data['ncd_levy'],
        $data['stamp_duty_rate_pct'],
        $data['stamp_duty'],
        $data['bank_charges'],
        $data['other_deductions'],
        $data['amount_received'],
        $data['wht_credit_note_outstanding'],
        $data['fx_rate_used'],
        $data['rate_mode'],
        $data['position'],
        $data['date_basis_note'],
        $data['source_reference'],
        $data['remarks'],
        $actorId,
        $id
    );
    $stmt->execute();
    $stmt->close();

    accountReceivablesLogAction(
        $conn,
        $actor,
        sprintf('%s updated receivables record #%d.', (string) ($actor['email'] ?? 'User'), $id)
    );

    return accountReceivablesGetInvoice($conn, $id);
}


function accountReceivablesAllocationHistoryCount(mysqli $conn, int $receivableId): int
{
    if ($receivableId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM account_receivable_allocations WHERE receivable_id = ? OR source_receivable_id = ?'
    );
    $stmt->bind_param('ii', $receivableId, $receivableId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $count;
}

function accountReceivablesActiveAllocationCount(mysqli $conn, int $receivableId): int
{
    if ($receivableId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM account_receivable_allocations WHERE reversed_at IS NULL AND (receivable_id = ? OR source_receivable_id = ?)'
    );
    $stmt->bind_param('ii', $receivableId, $receivableId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $count;
}

function accountReceivablesAssertNoActiveAllocations(mysqli $conn, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $types = str_repeat('i', count($ids));
    $sql = "SELECT DISTINCT receivable_id AS id FROM account_receivable_allocations WHERE reversed_at IS NULL AND receivable_id IN ({$placeholders})"
        . " UNION SELECT DISTINCT source_receivable_id AS id FROM account_receivable_allocations WHERE reversed_at IS NULL AND source_receivable_id IN ({$placeholders}) LIMIT 1";
    $params = [...$ids, ...$ids];
    $types .= $types;
    $stmt = $conn->prepare($sql);
    accountReceivablesBindParams($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        throw new RuntimeException('Reverse the active receivables allocations before deleting the related posting.', 409);
    }
}

function accountReceivablesNormalizeBulkIds($ids): array
{
    if (!is_array($ids)) {
        throw new RuntimeException('Select at least one receivables record.', 422);
    }

    $normalized = [];
    foreach ($ids as $id) {
        $value = (int) $id;
        if ($value > 0) {
            $normalized[$value] = $value;
        }
    }

    $normalized = array_values($normalized);
    if ($normalized === []) {
        throw new RuntimeException('Select at least one receivables record.', 422);
    }

    if (count($normalized) > 250) {
        throw new RuntimeException('Bulk actions are limited to 250 records at a time.', 422);
    }

    return $normalized;
}

function accountReceivablesBulkUpdateInvoices(mysqli $conn, array $ids, array $changes, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $ids = accountReceivablesNormalizeBulkIds($ids);

    if (!is_array($changes) || $changes === []) {
        throw new RuntimeException('Choose at least one field to update.', 422);
    }

    $setParts = [];
    $types = '';
    $params = [];

    if (array_key_exists('position', $changes)) {
        $position = trim((string) $changes['position']);
        if (!in_array($position, ACCOUNT_RECEIVABLES_POSITIONS, true)) {
            throw new RuntimeException('Invalid receivables position.', 422);
        }
        $setParts[] = 'position = ?';
        $types .= 's';
        $params[] = $position;
    }

    if (array_key_exists('line_type', $changes)) {
        $lineType = trim((string) $changes['line_type']);
        if (!in_array($lineType, ACCOUNT_RECEIVABLES_LINE_TYPES, true)) {
            throw new RuntimeException('Invalid receivables line type.', 422);
        }
        $setParts[] = 'line_type = ?';
        $types .= 's';
        $params[] = $lineType;
    }

    if (array_key_exists('credit_days', $changes)) {
        $creditDays = filter_var($changes['credit_days'], FILTER_VALIDATE_INT);
        if ($creditDays === false || $creditDays < 0 || $creditDays > 3650) {
            throw new RuntimeException('Credit days must be a whole number between 0 and 3650.', 422);
        }
        $setParts[] = 'credit_days = ?';
        $setParts[] = 'due_date = CASE WHEN invoice_date IS NULL THEN due_date ELSE DATE_ADD(invoice_date, INTERVAL ? DAY) END';
        $types .= 'ii';
        $params[] = $creditDays;
        $params[] = $creditDays;
    }

    if ($setParts === []) {
        throw new RuntimeException('The selected bulk update field is not supported.', 422);
    }

    $actorId = (int) ($actor['id'] ?? 0);
    $setParts[] = 'updated_by = ?';
    $types .= 'i';
    $params[] = $actorId;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types .= str_repeat('i', count($ids));
    foreach ($ids as $id) {
        $params[] = $id;
    }

    $sql = 'UPDATE account_receivable_invoices SET ' . implode(', ', $setParts)
        . " WHERE deleted_at IS NULL AND id IN ({$placeholders})";

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare($sql);
        accountReceivablesBindParams($stmt, $types, $params);
        $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        accountReceivablesLogAction(
            $conn,
            $actor,
            sprintf(
                '%s bulk-updated %d receivables record(s): %s.',
                (string) ($actor['email'] ?? 'User'),
                count($ids),
                implode(', ', array_keys($changes))
            )
        );

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return [
        'selected_count' => count($ids),
        'updated_count' => $affected,
    ];
}

function accountReceivablesBulkDeleteInvoices(mysqli $conn, array $ids, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $ids = accountReceivablesNormalizeBulkIds($ids);
    accountReceivablesAssertNoActiveAllocations($conn, $ids);
    $actorId = (int) ($actor['id'] ?? 0);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 'ii' . str_repeat('i', count($ids));
    $params = [$actorId, $actorId, ...$ids];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE account_receivable_invoices
             SET deleted_at = NOW(), deleted_by = ?, updated_by = ?
             WHERE deleted_at IS NULL AND id IN ({$placeholders})"
        );
        accountReceivablesBindParams($stmt, $types, $params);
        $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        accountReceivablesLogAction(
            $conn,
            $actor,
            sprintf(
                '%s bulk-deleted %d receivables record(s).',
                (string) ($actor['email'] ?? 'User'),
                count($ids)
            )
        );

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    return [
        'selected_count' => count($ids),
        'deleted_count' => $affected,
    ];
}


function accountReceivablesDeleteInvoice(mysqli $conn, int $id, array $actor): void
{
    $row = accountReceivablesGetInvoice($conn, $id);
    accountReceivablesAssertNoActiveAllocations($conn, [$id]);
    $actorId = (int) ($actor['id'] ?? 0);
    $stmt = $conn->prepare(
        'UPDATE account_receivable_invoices SET deleted_at = NOW(), deleted_by = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('iii', $actorId, $actorId, $id);
    $stmt->execute();
    $stmt->close();

    accountReceivablesLogAction(
        $conn,
        $actor,
        sprintf('%s deleted receivables record #%d (%s).', (string) ($actor['email'] ?? 'User'), $id, $row['invoice_number'] ?? $row['line_type'])
    );
}
