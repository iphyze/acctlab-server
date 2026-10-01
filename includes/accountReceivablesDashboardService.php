<?php

declare(strict_types=1);

require_once __DIR__ . '/accountReceivablesService.php';

function accountReceivablesDashboardMoney(float $value): float
{
    return round($value, 2);
}

function accountReceivablesDashboardRiskBand(array $bands): ?array
{
    $riskBand = null;
    foreach ($bands as $band) {
        if ((int) ($band['from_days'] ?? 0) < 0) {
            continue;
        }
        if ($riskBand === null || (int) $band['from_days'] > (int) $riskBand['from_days']) {
            $riskBand = $band;
        }
    }
    return $riskBand;
}

function accountReceivablesDashboardCurrencyState(array $bands): array
{
    $profile = [];
    foreach ($bands as $band) {
        $profile[(string) $band['label']] = [
            'id' => (int) $band['id'],
            'label' => (string) $band['label'],
            'sort_order' => (int) $band['sort_order'],
            'from_days' => (int) $band['from_days'],
            'to_days' => $band['to_days'] === null ? null : (int) $band['to_days'],
            'amount' => 0.0,
            'ngn_equivalent_amount' => 0.0,
            'percentage' => 0.0,
            'item_count' => 0,
        ];
    }

    return [
        'total_receivable' => 0.0,
        'total_receivable_ngn_equivalent' => 0.0,
        'open_register_outstanding' => 0.0,
        'open_register_outstanding_ngn_equivalent' => 0.0,
        'invoiced_to_date' => 0.0,
        'wht_credit_notes_outstanding' => 0.0,
        'wht_credit_notes_outstanding_ngn_equivalent' => 0.0,
        'review_not_reported' => 0.0,
        'review_not_reported_ngn_equivalent' => 0.0,
        'risk_band_amount' => 0.0,
        'risk_band_amount_ngn_equivalent' => 0.0,
        'risk_band_percentage' => 0.0,
        'open_count' => 0,
        'aged_open_count' => 0,
        'overdue_count' => 0,
        'within_terms_count' => 0,
        'settled_open_count' => 0,
        'unaged_open_count' => 0,
        'unaged_open_amount' => 0.0,
        'unaged_open_amount_ngn_equivalent' => 0.0,
        'missing_historical_rate_count' => 0,
        'ageing_profile' => $profile,
    ];
}

function accountReceivablesDashboard(mysqli $conn, array $actor): array
{
    accountReceivablesAssertFoundation($conn);

    $settings = accountReceivablesSettings($conn);
    $bands = accountReceivablesAgeingBands($conn);
    $riskBand = accountReceivablesDashboardRiskBand($bands);
    $riskBandLabel = $riskBand ? (string) $riskBand['label'] : null;

    $currency = [
        'NGN' => accountReceivablesDashboardCurrencyState($bands),
        'USD' => accountReceivablesDashboardCurrencyState($bands),
    ];

    $projectExposure = [];
    $clientExposure = [];
    $historicalRateAdjustments = accountReceivablesHistoricalRateAdjustmentMap($conn);
    $totalOpenRows = 0;
    $totalRows = 0;

    $result = $conn->query(
        "SELECT *
         FROM account_receivable_invoices
         WHERE deleted_at IS NULL
         ORDER BY invoice_date DESC, id DESC"
    );

    while ($raw = $result->fetch_assoc()) {
        $totalRows++;
        $row = accountReceivablesDecorateInvoice($raw, $settings, $bands);
        $row = accountReceivablesApplyHistoricalRateMetrics(
            $row,
            $historicalRateAdjustments[(int) ($row['id'] ?? 0)] ?? []
        );
        $code = strtoupper((string) ($row['currency'] ?? 'NGN'));
        if (!isset($currency[$code])) {
            continue;
        }

        $outstanding = (float) ($row['outstanding'] ?? 0);
        $outstandingNgnEquivalent = $row['outstanding_ngn_equivalent'];
        $gross = $row['invoice_value_gross'] === null ? 0.0 : (float) $row['invoice_value_gross'];
        $currency[$code]['invoiced_to_date'] += $gross;
        $currency[$code]['wht_credit_notes_outstanding'] += (float) ($row['wht_credit_note_outstanding'] ?? 0);
        if ($row['wht_credit_note_outstanding_ngn_equivalent'] !== null) {
            $currency[$code]['wht_credit_notes_outstanding_ngn_equivalent'] += (float) $row['wht_credit_note_outstanding_ngn_equivalent'];
        }

        $position = (string) ($row['position'] ?? '');
        if ($position === 'Review - not in reported receivable') {
            $currency[$code]['review_not_reported'] += $outstanding;
            if ($outstandingNgnEquivalent !== null) {
                $currency[$code]['review_not_reported_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
            }
        }

        if ($position !== 'Open') {
            continue;
        }

        $totalOpenRows++;
        $currency[$code]['open_count']++;
        $currency[$code]['open_register_outstanding'] += $outstanding;
        if ($outstandingNgnEquivalent !== null) {
            $currency[$code]['open_register_outstanding_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
        } elseif ($code === 'USD' && abs($outstanding) >= 0.005) {
            $currency[$code]['missing_historical_rate_count']++;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'Overdue') {
            $currency[$code]['overdue_count']++;
        } elseif ($status === 'Within terms') {
            $currency[$code]['within_terms_count']++;
        } elseif ($status === 'Settled') {
            $currency[$code]['settled_open_count']++;
        }

        $bandLabel = (string) ($row['ageing_band'] ?? '');
        if (isset($currency[$code]['ageing_profile'][$bandLabel])) {
            $currency[$code]['total_receivable'] += $outstanding;
            if ($outstandingNgnEquivalent !== null) {
                $currency[$code]['total_receivable_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
            }
            $currency[$code]['aged_open_count']++;
            $currency[$code]['ageing_profile'][$bandLabel]['amount'] += $outstanding;
            if ($outstandingNgnEquivalent !== null) {
                $currency[$code]['ageing_profile'][$bandLabel]['ngn_equivalent_amount'] += (float) $outstandingNgnEquivalent;
            }
            $currency[$code]['ageing_profile'][$bandLabel]['item_count']++;
            if ($riskBandLabel !== null && $bandLabel === $riskBandLabel) {
                $currency[$code]['risk_band_amount'] += $outstanding;
                if ($outstandingNgnEquivalent !== null) {
                    $currency[$code]['risk_band_amount_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
                }
            }
        } else {
            $currency[$code]['unaged_open_count']++;
            $currency[$code]['unaged_open_amount'] += $outstanding;
            if ($outstandingNgnEquivalent !== null) {
                $currency[$code]['unaged_open_amount_ngn_equivalent'] += (float) $outstandingNgnEquivalent;
            }
        }

        if ($code === 'NGN') {
            $projectName = trim((string) ($row['project_name'] ?? '')) ?: 'Unassigned project';
            $clientName = trim((string) ($row['client_name'] ?? '')) ?: 'Unassigned client';

            if (!isset($projectExposure[$projectName])) {
                $projectExposure[$projectName] = [
                    'project_name' => $projectName,
                    'client_name' => $clientName,
                    'outstanding' => 0.0,
                    'risk_band_amount' => 0.0,
                    'open_count' => 0,
                ];
            }
            $projectExposure[$projectName]['outstanding'] += $outstanding;
            $projectExposure[$projectName]['open_count']++;
            if ($riskBandLabel !== null && $bandLabel === $riskBandLabel) {
                $projectExposure[$projectName]['risk_band_amount'] += $outstanding;
            }

            if (!isset($clientExposure[$clientName])) {
                $clientExposure[$clientName] = [
                    'client_name' => $clientName,
                    'outstanding' => 0.0,
                    'risk_band_amount' => 0.0,
                    'open_count' => 0,
                ];
            }
            $clientExposure[$clientName]['outstanding'] += $outstanding;
            $clientExposure[$clientName]['open_count']++;
            if ($riskBandLabel !== null && $bandLabel === $riskBandLabel) {
                $clientExposure[$clientName]['risk_band_amount'] += $outstanding;
            }
        }
    }

    foreach (['NGN', 'USD'] as $code) {
        $total = (float) $currency[$code]['total_receivable'];
        foreach ($currency[$code]['ageing_profile'] as &$band) {
            $band['amount'] = accountReceivablesDashboardMoney((float) $band['amount']);
            $band['ngn_equivalent_amount'] = accountReceivablesDashboardMoney((float) $band['ngn_equivalent_amount']);
            $band['percentage'] = $total !== 0.0
                ? round(((float) $band['amount'] / $total) * 100, 2)
                : 0.0;
        }
        unset($band);

        $currency[$code]['ageing_profile'] = array_values($currency[$code]['ageing_profile']);
        $currency[$code]['risk_band_percentage'] = $total !== 0.0
            ? round(((float) $currency[$code]['risk_band_amount'] / $total) * 100, 2)
            : 0.0;

        foreach ([
            'total_receivable', 'total_receivable_ngn_equivalent',
            'open_register_outstanding', 'open_register_outstanding_ngn_equivalent', 'invoiced_to_date',
            'wht_credit_notes_outstanding', 'wht_credit_notes_outstanding_ngn_equivalent',
            'review_not_reported', 'review_not_reported_ngn_equivalent',
            'risk_band_amount', 'risk_band_amount_ngn_equivalent',
            'unaged_open_amount', 'unaged_open_amount_ngn_equivalent',
        ] as $moneyKey) {
            $currency[$code][$moneyKey] = accountReceivablesDashboardMoney((float) $currency[$code][$moneyKey]);
        }
    }

    $ngnBook = (float) $currency['NGN']['total_receivable'];
    $projectRows = array_values($projectExposure);
    usort($projectRows, static fn(array $left, array $right): int => $right['outstanding'] <=> $left['outstanding']);
    $projectRows = array_slice($projectRows, 0, 8);
    foreach ($projectRows as &$project) {
        $project['outstanding'] = accountReceivablesDashboardMoney((float) $project['outstanding']);
        $project['risk_band_amount'] = accountReceivablesDashboardMoney((float) $project['risk_band_amount']);
        $project['percentage_of_ngn_book'] = $ngnBook !== 0.0
            ? round(((float) $project['outstanding'] / $ngnBook) * 100, 2)
            : 0.0;
    }
    unset($project);

    $clientRows = array_values($clientExposure);
    usort($clientRows, static fn(array $left, array $right): int => $right['outstanding'] <=> $left['outstanding']);
    $clientRows = array_slice($clientRows, 0, 8);
    foreach ($clientRows as &$client) {
        $client['outstanding'] = accountReceivablesDashboardMoney((float) $client['outstanding']);
        $client['risk_band_amount'] = accountReceivablesDashboardMoney((float) $client['risk_band_amount']);
        $client['percentage_of_ngn_book'] = $ngnBook !== 0.0
            ? round(((float) $client['outstanding'] / $ngnBook) * 100, 2)
            : 0.0;
    }
    unset($client);

    $fxRate = (float) ($settings['usd_ngn_rate'] ?? 0);
    $combinedExposure = (float) $currency['NGN']['total_receivable_ngn_equivalent']
        + (float) $currency['USD']['total_receivable_ngn_equivalent'];

    return [
        'as_at' => (string) $settings['reporting_date'],
        'ageing_basis' => (string) $settings['ageing_basis'],
        'usd_ngn_rate' => $fxRate,
        'fx_conversion_basis' => 'HISTORICAL_POSTING_AND_ALLOCATION_RATES',
        'settled_threshold' => (float) $settings['settled_threshold'],
        'risk_band' => $riskBand ? [
            'label' => (string) $riskBand['label'],
            'from_days' => (int) $riskBand['from_days'],
            'to_days' => $riskBand['to_days'] === null ? null : (int) $riskBand['to_days'],
        ] : null,
        'summary' => [
            'total_rows' => $totalRows,
            'open_rows' => $totalOpenRows,
            'combined_exposure_ngn_equivalent' => accountReceivablesDashboardMoney($combinedExposure),
            'usd_items_missing_historical_rate' => (int) $currency['USD']['missing_historical_rate_count'],
        ],
        'currency' => $currency,
        'largest_ngn_exposures' => $projectRows,
        'ngn_client_exposure' => $clientRows,
        'permissions' => [
            'can_manage' => in_array((string) ($actor['integrity'] ?? ''), ['Admin', 'Super_Admin'], true),
        ],
    ];
}
