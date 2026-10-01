<?php

declare(strict_types=1);

require_once __DIR__ . '/accountReceivablesService.php';

const ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS = [
    'retention' => 'Retention',
    'advance_amortisation' => 'Advance Amortisation',
    'admin_other_charges' => 'Admin & Other Charges',
    'wht' => 'WHT',
    'vat_deducted_at_source' => 'VAT Deducted at Source',
    'ncd_levy' => 'NCD Levy',
    'stamp_duty' => 'Stamp Duty',
    'bank_charges' => 'Bank Charges',
    'other_deductions' => 'Other Deductions',
];

const ACCOUNT_RECEIVABLES_DEDUCTION_ALL = '__all__';
const ACCOUNT_RECEIVABLES_WHT_CREDIT_OUTSTANDING = 'wht_credit_note_outstanding';

function accountReceivablesDeductionsFilterValue(array $query, string $key, int $maxLength = 255): ?string
{
    return accountReceivablesNullableText($query[$key] ?? null, $maxLength);
}

function accountReceivablesDeductionsRows(mysqli $conn, array $query): array
{
    $conditions = ['deleted_at IS NULL'];
    $types = '';
    $params = [];

    $currency = strtoupper((string) ($query['currency'] ?? ''));
    if ($currency !== '' && in_array($currency, ACCOUNT_RECEIVABLES_CURRENCIES, true)) {
        $conditions[] = 'currency = ?';
        $types .= 's';
        $params[] = $currency;
    }

    foreach (['project_name', 'client_name', 'position'] as $key) {
        $value = accountReceivablesDeductionsFilterValue($query, $key, $key === 'position' ? 60 : 255);
        if ($value !== null) {
            if ($key === 'position' && !in_array($value, ACCOUNT_RECEIVABLES_POSITIONS, true)) {
                throw new RuntimeException('Invalid receivables position filter.', 422);
            }
            $conditions[] = "{$key} = ?";
            $types .= 's';
            $params[] = $value;
        }
    }

    $dateFrom = accountReceivablesNullableDate($query['date_from'] ?? null, 'Deductions period from');
    if ($dateFrom !== null) {
        $conditions[] = 'invoice_date >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }

    $dateTo = accountReceivablesNullableDate($query['date_to'] ?? null, 'Deductions period to');
    if ($dateTo !== null) {
        $conditions[] = 'invoice_date <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    $search = accountReceivablesDeductionsFilterValue($query, 'q');
    if ($search !== null) {
        $needle = '%' . $search . '%';
        $conditions[] = '(project_name LIKE ? OR client_name LIKE ? OR invoice_number LIKE ? OR source_reference LIKE ? OR remarks LIKE ?)';
        for ($index = 0; $index < 5; $index++) {
            $types .= 's';
            $params[] = $needle;
        }
    }

    $sql = 'SELECT * FROM account_receivable_invoices WHERE ' . implode(' AND ', $conditions)
        . ' ORDER BY currency ASC, project_name ASC, client_name ASC, invoice_date ASC, id ASC';
    $stmt = $conn->prepare($sql);
    accountReceivablesBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function accountReceivablesDeductionsOptions(mysqli $conn): array
{
    $projects = [];
    $clients = [];
    $result = $conn->query(
        "SELECT DISTINCT project_name, client_name
         FROM account_receivable_invoices
         WHERE deleted_at IS NULL
         ORDER BY project_name ASC, client_name ASC"
    );

    while ($row = $result->fetch_assoc()) {
        $project = trim((string) ($row['project_name'] ?? ''));
        $client = trim((string) ($row['client_name'] ?? ''));
        if ($project !== '') {
            $projects[$project] = true;
        }
        if ($client !== '') {
            $clients[$client] = true;
        }
    }

    return [
        'currencies' => ACCOUNT_RECEIVABLES_CURRENCIES,
        'positions' => ACCOUNT_RECEIVABLES_POSITIONS,
        'projects' => array_keys($projects),
        'clients' => array_keys($clients),
        'categories' => array_map(
            static fn(string $label, string $key): array => ['key' => $key, 'label' => $label],
            ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS,
            array_keys(ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS)
        ),
    ];
}

function accountReceivablesDeductionsEmptyTotals(): array
{
    $categories = [];
    foreach (ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS as $key => $label) {
        $categories[$key] = [
            'key' => $key,
            'label' => $label,
            'amount' => 0.0,
            'percentage' => 0.0,
        ];
    }

    return [
        'invoice_rows' => 0,
        'projects' => 0,
        'gross_invoiced' => 0.0,
        'vat_charged' => 0.0,
        'total_deductions' => 0.0,
        'total_deductions_ngn_equivalent' => 0.0,
        'wht_credit_note_outstanding' => 0.0,
        'wht_credit_note_outstanding_ngn_equivalent' => 0.0,
        'missing_historical_rate_count' => 0,
        'categories' => $categories,
        'rows' => [],
    ];
}

function accountReceivablesDeductionsProjectState(string $project, string $client, string $currency): array
{
    $state = accountReceivablesDeductionsEmptyTotals();
    $state['project_name'] = $project;
    $state['client_name'] = $client;
    $state['currency'] = $currency;
    unset($state['projects'], $state['rows']);
    return $state;
}

function accountReceivablesDeductionsRoundState(array &$state): void
{
    foreach (['gross_invoiced', 'vat_charged', 'total_deductions', 'total_deductions_ngn_equivalent', 'wht_credit_note_outstanding', 'wht_credit_note_outstanding_ngn_equivalent'] as $field) {
        $state[$field] = round((float) ($state[$field] ?? 0), 2);
    }

    $total = (float) ($state['total_deductions'] ?? 0);
    foreach ($state['categories'] as &$category) {
        $category['amount'] = round((float) ($category['amount'] ?? 0), 2);
        $category['percentage'] = $total !== 0.0
            ? round(((float) $category['amount'] / $total) * 100, 2)
            : 0.0;
    }
    unset($category);
    $state['categories'] = array_values($state['categories']);
}

function accountReceivablesDeductionsReport(mysqli $conn, array $query, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);

    $currencyState = [];
    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        $currencyState[$currency] = accountReceivablesDeductionsEmptyTotals();
        $currencyState[$currency]['currency'] = $currency;
    }

    $historicalRateAdjustments = accountReceivablesHistoricalRateAdjustmentMap($conn);
    $projectRows = [];
    $projectKeysByCurrency = ['NGN' => [], 'USD' => []];

    foreach (accountReceivablesDeductionsRows($conn, $query) as $raw) {
        $row = accountReceivablesDecorateInvoice($raw, $settings, $bands);
        $row = accountReceivablesApplyHistoricalRateMetrics(
            $row,
            $historicalRateAdjustments[(int) ($row['id'] ?? 0)] ?? []
        );
        $currency = strtoupper((string) ($row['currency'] ?? 'NGN'));
        if (!isset($currencyState[$currency])) {
            continue;
        }

        $project = trim((string) ($row['project_name'] ?? '')) ?: 'Unassigned project';
        $client = trim((string) ($row['client_name'] ?? '')) ?: 'Unassigned client';
        $projectKey = $currency . '|' . $project . '|' . $client;
        if (!isset($projectRows[$projectKey])) {
            $projectRows[$projectKey] = accountReceivablesDeductionsProjectState($project, $client, $currency);
            $projectKeysByCurrency[$currency][$projectKey] = true;
        }

        $gross = (float) ($row['invoice_value_gross'] ?? 0);
        $vat = (float) ($row['vat_charged'] ?? 0);
        $totalDeductions = (float) ($row['total_deductions'] ?? 0);
        $totalDeductionsNgnEquivalent = $row['total_deductions_ngn_equivalent'];
        $whtCredit = (float) ($row['wht_credit_note_outstanding'] ?? 0);
        $whtCreditNgnEquivalent = $row['wht_credit_note_outstanding_ngn_equivalent'];


        $currencyState[$currency]['invoice_rows']++;
        $currencyState[$currency]['gross_invoiced'] += $gross;
        $currencyState[$currency]['vat_charged'] += $vat;
        $currencyState[$currency]['total_deductions'] += $totalDeductions;
        if ($totalDeductionsNgnEquivalent !== null) {
            $currencyState[$currency]['total_deductions_ngn_equivalent'] += (float) $totalDeductionsNgnEquivalent;
        } elseif ($currency === 'USD' && abs($totalDeductions) >= 0.005) {
            $currencyState[$currency]['missing_historical_rate_count']++;
        }
        $currencyState[$currency]['wht_credit_note_outstanding'] += $whtCredit;
        if ($whtCreditNgnEquivalent !== null) {
            $currencyState[$currency]['wht_credit_note_outstanding_ngn_equivalent'] += (float) $whtCreditNgnEquivalent;
        }

        $projectRows[$projectKey]['invoice_rows']++;
        $projectRows[$projectKey]['gross_invoiced'] += $gross;
        $projectRows[$projectKey]['vat_charged'] += $vat;
        $projectRows[$projectKey]['total_deductions'] += $totalDeductions;
        if ($totalDeductionsNgnEquivalent !== null) {
            $projectRows[$projectKey]['total_deductions_ngn_equivalent'] += (float) $totalDeductionsNgnEquivalent;
        }
        $projectRows[$projectKey]['wht_credit_note_outstanding'] += $whtCredit;
        if ($whtCreditNgnEquivalent !== null) {
            $projectRows[$projectKey]['wht_credit_note_outstanding_ngn_equivalent'] += (float) $whtCreditNgnEquivalent;
        }

        foreach (ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS as $field => $label) {
            $amount = (float) ($row[$field] ?? 0);
            $currencyState[$currency]['categories'][$field]['amount'] += $amount;
            $projectRows[$projectKey]['categories'][$field]['amount'] += $amount;
        }
    }

    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        $currencyState[$currency]['projects'] = count($projectKeysByCurrency[$currency]);
        $rows = array_values(array_filter(
            $projectRows,
            static fn(array $row): bool => $row['currency'] === $currency
        ));
        foreach ($rows as &$projectRow) {
            accountReceivablesDeductionsRoundState($projectRow);
        }
        unset($projectRow);
        usort($rows, static function (array $left, array $right): int {
            $amountCompare = (float) $right['total_deductions'] <=> (float) $left['total_deductions'];
            return $amountCompare !== 0 ? $amountCompare : strcmp((string) $left['project_name'], (string) $right['project_name']);
        });
        $currencyState[$currency]['rows'] = $rows;
        accountReceivablesDeductionsRoundState($currencyState[$currency]);
    }

    $fxRate = (float) ($settings['usd_ngn_rate'] ?? 0);
    $combinedDeductions = (float) $currencyState['NGN']['total_deductions_ngn_equivalent']
        + (float) $currencyState['USD']['total_deductions_ngn_equivalent'];
    $combinedWhtCredit = (float) $currencyState['NGN']['wht_credit_note_outstanding_ngn_equivalent']
        + (float) $currencyState['USD']['wht_credit_note_outstanding_ngn_equivalent'];

    return [
        'as_at' => (string) $settings['reporting_date'],
        'usd_ngn_rate' => $fxRate,
        'fx_conversion_basis' => 'HISTORICAL_POSTING_AND_ALLOCATION_RATES',
        'scope_note' => 'Complete non-deleted invoice register history across Open, Settled and Review positions.',
        'summary' => [
            'total_deductions_ngn_equivalent' => round($combinedDeductions, 2),
            'wht_credit_note_outstanding_ngn_equivalent' => round($combinedWhtCredit, 2),
            'usd_items_missing_historical_rate' => (int) $currencyState['USD']['missing_historical_rate_count'],
            'invoice_rows' => (int) $currencyState['NGN']['invoice_rows'] + (int) $currencyState['USD']['invoice_rows'],
            'projects' => count(array_unique(array_map(
                static fn(array $row): string => (string) $row['project_name'],
                array_values($projectRows)
            ))),
        ],
        'currency' => $currencyState,
        'options' => accountReceivablesDeductionsOptions($conn),
        'permissions' => [
            'can_manage' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
        ],
    ];
}

function accountReceivablesDeductionsDetail(mysqli $conn, array $query, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);

    $category = accountReceivablesDeductionsFilterValue($query, 'category', 80) ?? ACCOUNT_RECEIVABLES_DEDUCTION_ALL;
    $allowed = array_merge(
        [ACCOUNT_RECEIVABLES_DEDUCTION_ALL, ACCOUNT_RECEIVABLES_WHT_CREDIT_OUTSTANDING],
        array_keys(ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS)
    );
    if (!in_array($category, $allowed, true)) {
        throw new RuntimeException('Invalid deductions detail category.', 422);
    }

    $currency = strtoupper((string) ($query['currency'] ?? 'NGN'));
    if (!in_array($currency, ACCOUNT_RECEIVABLES_CURRENCIES, true)) {
        $currency = 'NGN';
    }

    $page = max(1, (int) ($query['page'] ?? 1));
    $perPage = max(10, min(200, (int) ($query['per_page'] ?? 50)));
    $detailQuery = $query;
    $detailQuery['currency'] = $currency;

    $historicalRateAdjustments = accountReceivablesHistoricalRateAdjustmentMap($conn);
    $matching = [];
    $totalAmount = 0.0;
    $totalAmountNgnEquivalent = 0.0;
    $missingHistoricalRateCount = 0;
    foreach (accountReceivablesDeductionsRows($conn, $detailQuery) as $raw) {
        $row = accountReceivablesDecorateInvoice($raw, $settings, $bands);
        $row = accountReceivablesApplyHistoricalRateMetrics(
            $row,
            $historicalRateAdjustments[(int) ($row['id'] ?? 0)] ?? []
        );
        $selectedAmount = 0.0;
        if ($category === ACCOUNT_RECEIVABLES_DEDUCTION_ALL) {
            $selectedAmount = (float) ($row['total_deductions'] ?? 0);
        } elseif ($category === ACCOUNT_RECEIVABLES_WHT_CREDIT_OUTSTANDING) {
            $selectedAmount = (float) ($row['wht_credit_note_outstanding'] ?? 0);
        } else {
            $selectedAmount = (float) ($row[$category] ?? 0);
        }

        if (abs($selectedAmount) < 0.005) {
            continue;
        }

        $totalAmount += $selectedAmount;
        $selectedAmountNgnEquivalent = null;
        if ((string) ($row['currency'] ?? '') === 'NGN') {
            $selectedAmountNgnEquivalent = $selectedAmount;
        } elseif (!empty($row['historical_rate_available'])) {
            $postingRate = (float) ($row['fx_rate_used'] ?? 0);
            if ($category === ACCOUNT_RECEIVABLES_DEDUCTION_ALL) {
                $selectedAmountNgnEquivalent = (float) ($row['total_deductions_ngn_equivalent'] ?? 0);
            } elseif ($category === ACCOUNT_RECEIVABLES_WHT_CREDIT_OUTSTANDING) {
                $selectedAmountNgnEquivalent = (float) ($row['wht_credit_note_outstanding_ngn_equivalent'] ?? 0);
            } elseif ($category === 'advance_amortisation') {
                $selectedAmountNgnEquivalent = round(($selectedAmount * $postingRate) + (float) ($row['advance_amortisation_fx_adjustment_ngn'] ?? 0), 2);
            } else {
                $selectedAmountNgnEquivalent = round($selectedAmount * $postingRate, 2);
            }
        }
        if ($selectedAmountNgnEquivalent !== null) {
            $totalAmountNgnEquivalent += $selectedAmountNgnEquivalent;
        } elseif ((string) ($row['currency'] ?? '') === 'USD') {
            $missingHistoricalRateCount++;
        }
        $matching[] = [
            'id' => (int) $row['id'],
            'project_name' => (string) $row['project_name'],
            'client_name' => (string) $row['client_name'],
            'invoice_number' => $row['invoice_number'],
            'invoice_date' => $row['invoice_date'],
            'line_type' => (string) $row['line_type'],
            'position' => (string) $row['position'],
            'currency' => (string) $row['currency'],
            'invoice_value_gross' => $row['invoice_value_gross'],
            'total_deductions' => (float) $row['total_deductions'],
            'selected_amount' => round($selectedAmount, 2),
            'selected_amount_ngn_equivalent' => $selectedAmountNgnEquivalent === null ? null : round($selectedAmountNgnEquivalent, 2),
            'wht_credit_note_outstanding' => (float) $row['wht_credit_note_outstanding'],
            'source_reference' => $row['source_reference'],
            'remarks' => $row['remarks'],
        ];
    }

    usort($matching, static function (array $left, array $right): int {
        $dateCompare = strcmp((string) ($right['invoice_date'] ?? ''), (string) ($left['invoice_date'] ?? ''));
        return $dateCompare !== 0 ? $dateCompare : ((int) $right['id'] <=> (int) $left['id']);
    });

    $totalRows = count($matching);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $rows = array_slice($matching, $offset, $perPage);

    $categoryLabel = $category === ACCOUNT_RECEIVABLES_DEDUCTION_ALL
        ? 'Total Deductions'
        : ($category === ACCOUNT_RECEIVABLES_WHT_CREDIT_OUTSTANDING
            ? 'WHT Credit Note Not Yet Collected'
            : (ACCOUNT_RECEIVABLES_DEDUCTION_FIELDS[$category] ?? $category));

    return [
        'as_at' => (string) $settings['reporting_date'],
        'currency' => $currency,
        'category' => $category,
        'category_label' => $categoryLabel,
        'total_amount' => round($totalAmount, 2),
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'filters' => [
            'project_name' => accountReceivablesDeductionsFilterValue($detailQuery, 'project_name'),
            'client_name' => accountReceivablesDeductionsFilterValue($detailQuery, 'client_name'),
            'position' => accountReceivablesDeductionsFilterValue($detailQuery, 'position', 60),
            'q' => accountReceivablesDeductionsFilterValue($detailQuery, 'q'),
        ],
        'permissions' => [
            'can_manage' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
        ],
    ];
}
