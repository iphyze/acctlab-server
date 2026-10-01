<?php

declare(strict_types=1);

require_once __DIR__ . '/accountReceivablesService.php';

const ACCOUNT_RECEIVABLES_AGEING_ALL_BANDS = '__all__';
const ACCOUNT_RECEIVABLES_AGEING_UNAGED = '__unaged__';

function accountReceivablesAgeingMoney(float $value): float
{
    return round($value, 2);
}

function accountReceivablesAgeingFilterValue(array $query, string $key, int $maxLength = 255): ?string
{
    return accountReceivablesNullableText($query[$key] ?? null, $maxLength);
}

function accountReceivablesAgeingQueryRows(mysqli $conn, array $query): array
{
    $conditions = ["deleted_at IS NULL", "position = 'Open'"];
    $types = '';
    $params = [];

    $currency = strtoupper((string) ($query['currency'] ?? ''));
    if ($currency !== '' && in_array($currency, ACCOUNT_RECEIVABLES_CURRENCIES, true)) {
        $conditions[] = 'currency = ?';
        $types .= 's';
        $params[] = $currency;
    }

    foreach (['project_name', 'client_name'] as $key) {
        $value = accountReceivablesAgeingFilterValue($query, $key);
        if ($value !== null) {
            $conditions[] = "{$key} = ?";
            $types .= 's';
            $params[] = $value;
        }
    }

    $dateFrom = accountReceivablesNullableDate($query['date_from'] ?? null, 'Ageing period from');
    if ($dateFrom !== null) {
        $conditions[] = 'invoice_date >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }

    $dateTo = accountReceivablesNullableDate($query['date_to'] ?? null, 'Ageing period to');
    if ($dateTo !== null) {
        $conditions[] = 'invoice_date <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    $search = accountReceivablesAgeingFilterValue($query, 'q');
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

function accountReceivablesAgeingOptions(mysqli $conn): array
{
    $projects = [];
    $clients = [];
    $result = $conn->query(
        "SELECT DISTINCT project_name, client_name
         FROM account_receivable_invoices
         WHERE deleted_at IS NULL AND position = 'Open'
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
        'projects' => array_keys($projects),
        'clients' => array_keys($clients),
    ];
}

function accountReceivablesAgeingEmptyBandTotals(array $bands): array
{
    $totals = [];
    foreach ($bands as $band) {
        $totals[(string) $band['label']] = [
            'id' => (int) $band['id'],
            'label' => (string) $band['label'],
            'sort_order' => (int) $band['sort_order'],
            'amount' => 0.0,
            'ngn_equivalent_amount' => 0.0,
            'item_count' => 0,
        ];
    }
    return $totals;
}

function accountReceivablesAgeingProjectState(string $project, string $client, string $currency, array $bands): array
{
    return [
        'project_name' => $project,
        'client_name' => $client,
        'currency' => $currency,
        'bands' => accountReceivablesAgeingEmptyBandTotals($bands),
        'total' => 0.0,
        'ngn_equivalent_total' => 0.0,
        'percentage_of_currency_total' => 0.0,
        'open_invoices' => 0,
        'aged_open_invoices' => 0,
        'unaged_open_count' => 0,
        'unaged_open_amount' => 0.0,
        'unaged_open_amount_ngn_equivalent' => 0.0,
    ];
}

function accountReceivablesAgeingReport(mysqli $conn, array $query, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);
    $bandLookup = [];
    foreach ($bands as $band) {
        $bandLookup[(string) $band['label']] = true;
    }

    $currencyState = [];
    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        $currencyState[$currency] = [
            'currency' => $currency,
            'total' => 0.0,
            'ngn_equivalent_total' => 0.0,
            'open_invoices' => 0,
            'aged_open_invoices' => 0,
            'unaged_open_count' => 0,
            'unaged_open_amount' => 0.0,
            'unaged_open_amount_ngn_equivalent' => 0.0,
            'missing_historical_rate_count' => 0,
            'band_totals' => accountReceivablesAgeingEmptyBandTotals($bands),
            'rows' => [],
        ];
    }

    $historicalRateAdjustments = accountReceivablesHistoricalRateAdjustmentMap($conn);
    $projectRows = [];
    foreach (accountReceivablesAgeingQueryRows($conn, $query) as $raw) {
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
        $key = $currency . '|' . $project . '|' . $client;
        if (!isset($projectRows[$key])) {
            $projectRows[$key] = accountReceivablesAgeingProjectState($project, $client, $currency, $bands);
        }

        $outstanding = (float) ($row['outstanding'] ?? 0);
        $outstandingNgnEquivalent = $row['outstanding_ngn_equivalent'];
        $bandLabel = (string) ($row['ageing_band'] ?? '');
        $isAged = isset($bandLookup[$bandLabel]);

        $projectRows[$key]['open_invoices']++;
        $currencyState[$currency]['open_invoices']++;

        if (!$isAged) {
            $projectRows[$key]['unaged_open_count']++;
            $projectRows[$key]['unaged_open_amount'] += $outstanding;
            if ($outstandingNgnEquivalent !== null) {
                $projectRows[$key]['unaged_open_amount_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
            }
            $currencyState[$currency]['unaged_open_count']++;
            $currencyState[$currency]['unaged_open_amount'] += $outstanding;
            if ($outstandingNgnEquivalent !== null) {
                $currencyState[$currency]['unaged_open_amount_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
            } elseif ($currency === 'USD' && abs($outstanding) >= 0.005) {
                $currencyState[$currency]['missing_historical_rate_count']++;
            }
            continue;
        }

        $projectRows[$key]['aged_open_invoices']++;
        $projectRows[$key]['total'] += $outstanding;
        if ($outstandingNgnEquivalent !== null) {
            $projectRows[$key]['ngn_equivalent_total'] += (float) $outstandingNgnEquivalent;
        }
        $projectRows[$key]['bands'][$bandLabel]['amount'] += $outstanding;
        if ($outstandingNgnEquivalent !== null) {
            $projectRows[$key]['bands'][$bandLabel]['ngn_equivalent_amount'] += (float) $outstandingNgnEquivalent;
        }
        $projectRows[$key]['bands'][$bandLabel]['item_count']++;

        $currencyState[$currency]['aged_open_invoices']++;
        $currencyState[$currency]['total'] += $outstanding;
        if ($outstandingNgnEquivalent !== null) {
            $currencyState[$currency]['ngn_equivalent_total'] += (float) $outstandingNgnEquivalent;
        } elseif ($currency === 'USD' && abs($outstanding) >= 0.005) {
            $currencyState[$currency]['missing_historical_rate_count']++;
        }
        $currencyState[$currency]['band_totals'][$bandLabel]['amount'] += $outstanding;
        if ($outstandingNgnEquivalent !== null) {
            $currencyState[$currency]['band_totals'][$bandLabel]['ngn_equivalent_amount'] += (float) $outstandingNgnEquivalent;
        }
        $currencyState[$currency]['band_totals'][$bandLabel]['item_count']++;
    }

    foreach ($projectRows as &$projectRow) {
        $currency = $projectRow['currency'];
        $currencyTotal = (float) ($currencyState[$currency]['total'] ?? 0);
        $projectRow['total'] = accountReceivablesAgeingMoney((float) $projectRow['total']);
        $projectRow['ngn_equivalent_total'] = accountReceivablesAgeingMoney((float) $projectRow['ngn_equivalent_total']);
        $projectRow['unaged_open_amount'] = accountReceivablesAgeingMoney((float) $projectRow['unaged_open_amount']);
        $projectRow['unaged_open_amount_ngn_equivalent'] = accountReceivablesAgeingMoney((float) $projectRow['unaged_open_amount_ngn_equivalent']);
        $projectRow['percentage_of_currency_total'] = $currencyTotal !== 0.0
            ? round(((float) $projectRow['total'] / $currencyTotal) * 100, 2)
            : 0.0;
        foreach ($projectRow['bands'] as &$band) {
            $band['amount'] = accountReceivablesAgeingMoney((float) $band['amount']);
            $band['ngn_equivalent_amount'] = accountReceivablesAgeingMoney((float) $band['ngn_equivalent_amount']);
        }
        unset($band);
        $projectRow['bands'] = array_values($projectRow['bands']);
    }
    unset($projectRow);

    foreach (ACCOUNT_RECEIVABLES_CURRENCIES as $currency) {
        $rows = array_values(array_filter(
            $projectRows,
            static fn(array $row): bool => $row['currency'] === $currency
        ));
        usort($rows, static function (array $left, array $right): int {
            $amountCompare = (float) $right['total'] <=> (float) $left['total'];
            return $amountCompare !== 0 ? $amountCompare : strcmp((string) $left['project_name'], (string) $right['project_name']);
        });

        $currencyState[$currency]['rows'] = $rows;
        $currencyState[$currency]['total'] = accountReceivablesAgeingMoney((float) $currencyState[$currency]['total']);
        $currencyState[$currency]['ngn_equivalent_total'] = accountReceivablesAgeingMoney((float) $currencyState[$currency]['ngn_equivalent_total']);
        $currencyState[$currency]['unaged_open_amount'] = accountReceivablesAgeingMoney((float) $currencyState[$currency]['unaged_open_amount']);
        $currencyState[$currency]['unaged_open_amount_ngn_equivalent'] = accountReceivablesAgeingMoney((float) $currencyState[$currency]['unaged_open_amount_ngn_equivalent']);
        foreach ($currencyState[$currency]['band_totals'] as &$band) {
            $band['amount'] = accountReceivablesAgeingMoney((float) $band['amount']);
            $band['ngn_equivalent_amount'] = accountReceivablesAgeingMoney((float) $band['ngn_equivalent_amount']);
            $band['percentage'] = (float) $currencyState[$currency]['total'] !== 0.0
                ? round(((float) $band['amount'] / (float) $currencyState[$currency]['total']) * 100, 2)
                : 0.0;
        }
        unset($band);
        $currencyState[$currency]['band_totals'] = array_values($currencyState[$currency]['band_totals']);
    }

    $fxRate = (float) ($settings['usd_ngn_rate'] ?? 0);
    $combined = (float) $currencyState['NGN']['ngn_equivalent_total']
        + (float) $currencyState['USD']['ngn_equivalent_total'];

    return [
        'as_at' => (string) $settings['reporting_date'],
        'ageing_basis' => (string) $settings['ageing_basis'],
        'usd_ngn_rate' => $fxRate,
        'fx_conversion_basis' => 'HISTORICAL_POSTING_AND_ALLOCATION_RATES',
        'bands' => $bands,
        'summary' => [
            'ngn_total' => (float) $currencyState['NGN']['total'],
            'usd_total' => (float) $currencyState['USD']['total'],
            'combined_exposure_ngn_equivalent' => accountReceivablesAgeingMoney($combined),
            'usd_items_missing_historical_rate' => (int) $currencyState['USD']['missing_historical_rate_count'],
            'open_invoices' => (int) $currencyState['NGN']['open_invoices'] + (int) $currencyState['USD']['open_invoices'],
            'aged_open_invoices' => (int) $currencyState['NGN']['aged_open_invoices'] + (int) $currencyState['USD']['aged_open_invoices'],
            'unaged_open_count' => (int) $currencyState['NGN']['unaged_open_count'] + (int) $currencyState['USD']['unaged_open_count'],
        ],
        'currency' => $currencyState,
        'options' => accountReceivablesAgeingOptions($conn),
        'permissions' => [
            'can_manage' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
        ],
    ];
}

function accountReceivablesAgeingDetail(mysqli $conn, array $query, array $actor): array
{
    accountReceivablesAssertFoundation($conn);
    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);
    $bandLookup = [];
    foreach ($bands as $band) {
        $bandLookup[(string) $band['label']] = $band;
    }

    $detailQuery = $query;
    $requestedCurrency = strtoupper(trim((string) ($detailQuery['currency'] ?? '')));
    if (!in_array($requestedCurrency, ACCOUNT_RECEIVABLES_CURRENCIES, true)) {
        $requestedCurrency = 'NGN';
    }
    $detailQuery['currency'] = $requestedCurrency;

    $requestedBand = accountReceivablesAgeingFilterValue($detailQuery, 'band', 255) ?? ACCOUNT_RECEIVABLES_AGEING_ALL_BANDS;
    if ($requestedBand !== ACCOUNT_RECEIVABLES_AGEING_ALL_BANDS
        && $requestedBand !== ACCOUNT_RECEIVABLES_AGEING_UNAGED
        && !isset($bandLookup[$requestedBand])) {
        throw new RuntimeException('The selected ageing band is not active.', 422);
    }

    $historicalRateAdjustments = accountReceivablesHistoricalRateAdjustmentMap($conn);
    $allRows = [];
    foreach (accountReceivablesAgeingQueryRows($conn, $detailQuery) as $raw) {
        $row = accountReceivablesDecorateInvoice($raw, $settings, $bands);
        $row = accountReceivablesApplyHistoricalRateMetrics(
            $row,
            $historicalRateAdjustments[(int) ($row['id'] ?? 0)] ?? []
        );
        $bandLabel = (string) ($row['ageing_band'] ?? '');
        $isAged = isset($bandLookup[$bandLabel]);

        if ($requestedBand === ACCOUNT_RECEIVABLES_AGEING_UNAGED && $isAged) {
            continue;
        }
        if ($requestedBand !== ACCOUNT_RECEIVABLES_AGEING_ALL_BANDS
            && $requestedBand !== ACCOUNT_RECEIVABLES_AGEING_UNAGED
            && $bandLabel !== $requestedBand) {
            continue;
        }
        if ($requestedBand === ACCOUNT_RECEIVABLES_AGEING_ALL_BANDS && !$isAged) {
            continue;
        }

        $allRows[] = $row;
    }

    usort($allRows, static function (array $left, array $right): int {
        $daysCompare = ((int) ($right['days_outstanding'] ?? -999999)) <=> ((int) ($left['days_outstanding'] ?? -999999));
        if ($daysCompare !== 0) {
            return $daysCompare;
        }
        return ((int) $right['id']) <=> ((int) $left['id']);
    });

    $listedTotal = 0.0;
    $listedTotalNgnEquivalent = 0.0;
    $whtTotal = 0.0;
    $whtTotalNgnEquivalent = 0.0;
    $missingHistoricalRateCount = 0;
    foreach ($allRows as $row) {
        $listedTotal += (float) ($row['outstanding'] ?? 0);
        if ($row['outstanding_ngn_equivalent'] !== null) {
            $listedTotalNgnEquivalent += (float) $row['outstanding_ngn_equivalent'];
        } elseif ((string) ($row['currency'] ?? '') === 'USD' && abs((float) ($row['outstanding'] ?? 0)) >= 0.005) {
            $missingHistoricalRateCount++;
        }
        $whtTotal += (float) ($row['wht_credit_note_outstanding'] ?? 0);
        if ($row['wht_credit_note_outstanding_ngn_equivalent'] !== null) {
            $whtTotalNgnEquivalent += (float) $row['wht_credit_note_outstanding_ngn_equivalent'];
        }
    }
    $listedTotal = accountReceivablesAgeingMoney($listedTotal);

    $page = max(1, (int) ($query['page'] ?? 1));
    $perPage = max(10, min(250, (int) ($query['per_page'] ?? 50)));
    $totalRows = count($allRows);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $rows = array_slice($allRows, ($page - 1) * $perPage, $perPage);

    $bandLabel = match ($requestedBand) {
        ACCOUNT_RECEIVABLES_AGEING_ALL_BANDS => 'All ageing bands',
        ACCOUNT_RECEIVABLES_AGEING_UNAGED => 'Unaged open items',
        default => $requestedBand,
    };

    return [
        'as_at' => (string) $settings['reporting_date'],
        'ageing_basis' => (string) $settings['ageing_basis'],
        'selection' => [
            'project_name' => accountReceivablesAgeingFilterValue($detailQuery, 'project_name'),
            'client_name' => accountReceivablesAgeingFilterValue($detailQuery, 'client_name'),
            'currency' => $requestedCurrency,
            'band' => $requestedBand,
            'band_label' => $bandLabel,
        ],
        'summary' => [
            'invoice_lines' => $totalRows,
            'listed_total' => $listedTotal,
            'listed_total_ngn_equivalent' => accountReceivablesAgeingMoney($listedTotalNgnEquivalent),
            'wht_credit_note_outstanding' => accountReceivablesAgeingMoney($whtTotal),
            'wht_credit_note_outstanding_ngn_equivalent' => accountReceivablesAgeingMoney($whtTotalNgnEquivalent),
            'usd_items_missing_historical_rate' => $missingHistoricalRateCount,
        ],
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'bands' => $bands,
        'options' => accountReceivablesAgeingOptions($conn),
        'permissions' => [
            'can_manage' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
        ],
    ];
}
