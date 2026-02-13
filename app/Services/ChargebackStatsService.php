<?php

/**
 * Chargeback Statistics Service.
 *
 * CB Rate formula aligned with EMP: chargebacks / approved * 100
 *
 * Note: When a billing attempt gets chargebacked, its status changes from
 * 'approved' to 'chargebacked'. The 'approved' count reflects only currently
 * approved transactions, not those that later became chargebacks.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

class ChargebackStatsService
{
    public const DATE_MODE_TRANSACTION = 'transaction';
    public const DATE_MODE_CHARGEBACK = 'chargeback';

    private function applyModelFilter(Builder $query, ?string $model): void
    {
        if (empty($model) || $model === 'all') {
            return;
        }

        if ($model === 'legacy') {
            $query->where(function ($q) {
                $q->where('billing_attempts.billing_model', 'legacy')
                    ->orWhereNull('billing_attempts.billing_model');
            });
        } else {
            $query->where('billing_attempts.billing_model', $model);
        }
    }

    private function applyEmpAccountFilter(Builder $query, ?int $empAccountId): void
    {
        if (empty($empAccountId)) {
            return;
        }
        $query->where('billing_attempts.emp_account_id', $empAccountId);
    }

    /**
     * Calculate CB Rate vs Approved (EMP-aligned).
     *
     * Formula: chargebacks / approved * 100
     */
    private function calculateCbRateApproved(int $chargebacks, int $approved): float
    {
        if ($approved > 0) {
            return round(($chargebacks / $approved) * 100, 2);
        }

        return $chargebacks > 0 ? 100.00 : 0.00;
    }

    /**
     * Calculate CB Rate vs Approved Amount (EMP-aligned).
     *
     * Formula: chargeback_amount / approved_amount * 100
     */
    private function calculateCbRateAmountApproved(float $chargebackAmount, float $approvedAmount): float
    {
        if ($approvedAmount > 0) {
            return round(($chargebackAmount / $approvedAmount) * 100, 2);
        }

        return $chargebackAmount > 0 ? 100.00 : 0.00;
    }

    public function getStats(?string $period = null, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $period = $period ?? 'all';
        $cacheKey = $this->getCacheKey('chargeback_stats', $period, $month, $year, $dateMode, $model, $empAccountId);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateStats($period, $month, $year, $dateMode, $model, $empAccountId));
    }

    public function calculateStats(?string $period, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year, $dateMode);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->where(function ($q) {
                $excludedCodes = config('tether.chargeback.excluded_cb_reason_codes', []);
                if (!empty($excludedCodes)) {
                    $q->whereNotIn('billing_attempts.chargeback_reason_code', $excludedCodes)
                      ->orWhereNull('billing_attempts.chargeback_reason_code');
                }
            });

        $this->applyDateFilter($query, $dateFilter);
        $this->applyModelFilter($query, $model);
        $this->applyEmpAccountFilter($query, $empAccountId);

        $stats = $query
            ->groupBy('debtors.country')
            ->select([
                DB::raw("COALESCE(debtors.country, 'LEGACY') as country"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_APPROVED."' THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_DECLINED."' THEN 1 ELSE 0 END) as declined"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_ERROR."' THEN 1 ELSE 0 END) as errors"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN 1 ELSE 0 END) as chargebacks"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN billing_attempts.amount ELSE 0 END) as chargeback_amount"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_APPROVED."' THEN billing_attempts.amount ELSE 0 END) as approved_amount"),
            ])
            ->get();

        $countries = [];
        $totals = [
            'total' => 0,
            'approved' => 0,
            'declined' => 0,
            'errors' => 0,
            'chargebacks' => 0,
            'chargeback_amount' => 0,
            'approved_amount' => 0,
        ];

        $canCalculateRates = $dateMode !== self::DATE_MODE_CHARGEBACK;

        foreach ($stats as $row) {
            if ($canCalculateRates) {
                $cbRateTotal = $row->total > 0
                    ? round(($row->chargebacks / $row->total) * 100, 2)
                    : 0;

                $cbRateApproved = $this->calculateCbRateApproved(
                    (int) $row->chargebacks,
                    (int) $row->approved
                );

                $alert = $cbRateTotal >= $threshold || $cbRateApproved >= $threshold;
            } else {
                $cbRateTotal = null;
                $cbRateApproved = null;
                $alert = false;
            }

            $countries[] = [
                'country' => $row->country,
                'total' => (int) $row->total,
                'approved' => (int) $row->approved,
                'declined' => (int) $row->declined,
                'errors' => (int) $row->errors,
                'chargebacks' => (int) $row->chargebacks,
                'chargeback_amount' => round((float) $row->chargeback_amount, 2),
                'cb_rate_total' => $cbRateTotal,
                'cb_rate_approved' => $cbRateApproved,
                'alert' => $alert,
            ];

            $totals['total'] += $row->total;
            $totals['approved'] += $row->approved;
            $totals['declined'] += $row->declined;
            $totals['errors'] += $row->errors;
            $totals['chargebacks'] += $row->chargebacks;
            $totals['chargeback_amount'] += (float) $row->chargeback_amount;
            $totals['approved_amount'] += (float) $row->approved_amount;
        }

        usort($countries, function ($a, $b) {
            if ($a['country'] === 'LEGACY') return 1;
            if ($b['country'] === 'LEGACY') return -1;
            return $b['chargebacks'] <=> $a['chargebacks'];
        });

        $totals['chargeback_amount'] = round($totals['chargeback_amount'], 2);
        $totals['approved_amount'] = round($totals['approved_amount'], 2);

        if ($canCalculateRates) {
            $totals['cb_rate_total'] = $totals['total'] > 0
                ? round(($totals['chargebacks'] / $totals['total']) * 100, 2)
                : 0;

            $totals['cb_rate_approved'] = $this->calculateCbRateApproved(
                (int) $totals['chargebacks'],
                (int) $totals['approved']
            );

            $totals['alert'] = $totals['cb_rate_total'] >= $threshold || $totals['cb_rate_approved'] >= $threshold;

            $totals['cb_rate_amount_approved'] = $this->calculateCbRateAmountApproved(
                (float) $totals['chargeback_amount'],
                (float) $totals['approved_amount']
            );

            $totals['cb_alert_amount_approved'] = $totals['cb_rate_amount_approved'] >= $threshold;
        } else {
            $totals['cb_rate_total'] = null;
            $totals['cb_rate_approved'] = null;
            $totals['cb_rate_amount_approved'] = null;
            $totals['alert'] = false;
            $totals['cb_alert_amount_approved'] = false;
        }

        return [
            'period' => $month && $year ? 'monthly' : $period,
            'model' => $model ?? 'all',
            'emp_account_id' => $empAccountId,
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
            'date_mode' => $dateMode,
            'threshold' => $threshold,
            'countries' => $countries,
            'totals' => $totals,
        ];
    }

    private function buildDateFilter(?string $period, ?int $month, ?int $year, string $dateMode = self::DATE_MODE_TRANSACTION): array
    {
        $period = $period ?? 'all';

        if ($month && $year) {
            return [
                'start' => Carbon::create($year, $month, 1)->startOfMonth(),
                'end' => Carbon::create($year, $month, 1)->endOfMonth(),
                'type' => 'monthly',
                'date_mode' => $dateMode,
            ];
        }

        if ($period === 'all') {
            return [
                'start' => null,
                'end' => null,
                'type' => 'all',
                'date_mode' => $dateMode,
            ];
        }

        return [
            'start' => $this->getStartDate($period),
            'end' => null,
            'type' => 'period',
            'date_mode' => $dateMode,
        ];
    }

    private function applyDateFilter($query, array $dateFilter): void
    {
        if ($dateFilter['type'] === 'all') {
            return;
        }

        $dateMode = $dateFilter['date_mode'] ?? self::DATE_MODE_TRANSACTION;

        if ($dateMode === self::DATE_MODE_CHARGEBACK) {
            $dateColumn = 'billing_attempts.chargebacked_at';
        } else {
            $dateColumn = 'COALESCE(billing_attempts.emp_created_at, billing_attempts.created_at)';
        }

        if ($dateFilter['type'] === 'monthly') {
            $query->whereRaw(
                "{$dateColumn} BETWEEN ? AND ?",
                [$dateFilter['start'], $dateFilter['end']]
            );
        } else {
            $query->whereRaw(
                "{$dateColumn} >= ?",
                [$dateFilter['start']]
            );
        }
    }

    private function getStartDate(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            'all' => Carbon::parse('1970-01-01'),
            default => now()->subDays(7),
        };
    }

    private function getCacheKey(string $prefix, string $period, ?int $month, ?int $year, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): string
    {
        $modeSuffix = $dateMode === self::DATE_MODE_CHARGEBACK ? '_cb' : '_tx';
        $modelKey = $model ?? 'all';
        $accountKey = $empAccountId ? "_acc{$empAccountId}" : '';

        if ($month && $year) {
            return "{$prefix}_monthly_{$year}_{$month}{$modeSuffix}_{$modelKey}{$accountKey}";
        }
        return "{$prefix}_{$period}{$modeSuffix}_{$modelKey}{$accountKey}";
    }

    public function clearCache(): void
    {
        $periods = ['24h', '7d', '30d', '90d', 'all'];
        $models = array_merge([DebtorProfile::ALL], DebtorProfile::BILLING_MODELS);
        $dateModes = ['_tx', '_cb'];

        foreach ($periods as $period) {
            foreach ($dateModes as $modeSuffix) {
                foreach ($models as $model) {
                    $modelKey = $model;
                    Cache::forget("chargeback_stats_{$period}{$modeSuffix}_{$modelKey}");
                    Cache::forget("chargeback_codes_{$period}{$modeSuffix}_{$modelKey}");
                    Cache::forget("chargeback_banks_{$period}{$modeSuffix}_{$modelKey}");
                    Cache::forget("price_points_{$period}{$modeSuffix}_{$modelKey}");
                }
            }
        }
    }

    public function getChargebackCodes(?string $period = null, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $period = $period ?? 'all';
        $cacheKey = $this->getCacheKey('chargeback_codes', $period, $month, $year, $dateMode, $model, $empAccountId);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateChargebackCodes($period, $month, $year, $dateMode, $model, $empAccountId));
    }

    public function calculateChargebackCodes(?string $period, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year, $dateMode);

        $query = DB::table('billing_attempts')
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        $excludedCodes = config('tether.chargeback.excluded_cb_reason_codes', []);
        if (!empty($excludedCodes)) {
            $query->where(function ($q) use ($excludedCodes) {
                $q->whereNotIn('chargeback_reason_code', $excludedCodes)
                  ->orWhereNull('chargeback_reason_code');
            });
        }

        $this->applyDateFilter($query, $dateFilter);
        $this->applyModelFilter($query, $model);
        $this->applyEmpAccountFilter($query, $empAccountId);

        $codes = $query
            ->select([
                'chargeback_reason_code as chargeback_code',
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('COUNT(*) as occurrences')
            ])
            ->groupBy('chargeback_reason_code')
            ->orderBy('total_amount', 'desc')
            ->get();

        $result = [
            'period' => $month && $year ? 'monthly' : $period,
            'model' => $model ?? 'all',
            'emp_account_id' => $empAccountId,
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
            'date_mode' => $dateMode,
            'codes' => [],
            'totals' => ['total_amount' => 0, 'occurrences' => 0],
        ];

        foreach ($codes as $row) {
            $result['codes'][] = [
                'chargeback_code'   => $row->chargeback_code,
                'total_amount'      => (float) $row->total_amount,
                'occurrences'       => (int) $row->occurrences,
            ];
            $result['totals']['total_amount'] += (float) $row->total_amount;
            $result['totals']['occurrences'] += (int) $row->occurrences;
        }

        return $result;
    }

    public function getChargebackBanks(?string $period = null, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $period = $period ?? 'all';
        $cacheKey = $this->getCacheKey('chargeback_banks', $period, $month, $year, $dateMode, $model, $empAccountId);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateChargebackBanks($period, $month, $year, $dateMode, $model, $empAccountId));
    }

    public function calculateChargebackBanks(?string $period, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year, $dateMode);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $latestVopLogs = DB::table('vop_logs')
            ->select('debtor_id', DB::raw('MAX(id) as latest_vop_log_id'))
            ->groupBy('debtor_id');

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->leftJoinSub($latestVopLogs, 'latest_vop', function ($join) {
                $join->on('debtors.id', '=', 'latest_vop.debtor_id');
            })
            ->leftJoin('vop_logs', 'latest_vop.latest_vop_log_id', '=', 'vop_logs.id')
            ->where(function ($q) {
                $excludedCodes = config('tether.chargeback.excluded_cb_reason_codes', []);
                if (!empty($excludedCodes)) {
                    $q->whereNotIn('billing_attempts.chargeback_reason_code', $excludedCodes)
                      ->orWhereNull('billing_attempts.chargeback_reason_code');
                }
            });

        $this->applyDateFilter($query, $dateFilter);
        $this->applyModelFilter($query, $model);
        $this->applyEmpAccountFilter($query, $empAccountId);

        $banks = $query
            ->select([
                DB::raw("COALESCE(vop_logs.bank_name, 'Unknown') as bank_name"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_APPROVED."' THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN 1 ELSE 0 END) as chargebacks"),
                DB::raw('SUM(billing_attempts.amount) as total_amount'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN billing_attempts.amount ELSE 0 END) as chargeback_amount"),
            ])
            ->groupBy('vop_logs.bank_name')
            ->get();

        $result = [
            'period' => $month && $year ? 'monthly' : $period,
            'model' => $model ?? 'all',
            'emp_account_id' => $empAccountId,
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
            'date_mode' => $dateMode,
            'banks' => [],
            'totals' => [
                'total' => 0,
                'approved' => 0,
                'total_amount' => 0,
                'chargebacks' => 0,
                'chargeback_amount' => 0,
                'cb_rate' => 0,
                'alert' => false,
            ],
        ];

        $canCalculateRates = $dateMode !== self::DATE_MODE_CHARGEBACK;

        foreach ($banks as $row) {
            if ($canCalculateRates) {
                $cbBankRate = $this->calculateCbRateApproved(
                    (int) $row->chargebacks,
                    (int) $row->approved
                );
                $cbBankRateAmount = $row->total_amount > 0
                    ? round(($row->chargeback_amount / $row->total_amount) * 100, 2)
                    : 0;
                $alert = $cbBankRate >= $threshold || $cbBankRateAmount >= $threshold;
            } else {
                $cbBankRate = null;
                $alert = false;
            }

            $result['banks'][] = [
                'bank_name' => $row->bank_name,
                'total' => (int) $row->total,
                'approved' => (int) $row->approved,
                'total_amount' => round((float) $row->total_amount, 2),
                'chargebacks' => (int) $row->chargebacks,
                'chargeback_amount' => round((float) $row->chargeback_amount, 2),
                'cb_rate' => $cbBankRate,
                'alert' => $alert,
            ];

            $result['totals']['total'] += (int) $row->total;
            $result['totals']['approved'] += (int) $row->approved;
            $result['totals']['total_amount'] += (float) $row->total_amount;
            $result['totals']['chargebacks'] += (int) $row->chargebacks;
            $result['totals']['chargeback_amount'] += (float) $row->chargeback_amount;
        }

        $result['totals']['total_amount'] = round($result['totals']['total_amount'], 2);
        $result['totals']['chargeback_amount'] = round($result['totals']['chargeback_amount'], 2);

        if ($canCalculateRates) {
            $result['totals']['cb_rate'] = $this->calculateCbRateApproved(
                (int) $result['totals']['chargebacks'],
                (int) $result['totals']['approved']
            );

            $totalsAlertAmount = $result['totals']['total_amount'] > 0
                ? round(($result['totals']['chargeback_amount'] / $result['totals']['total_amount']) * 100, 2)
                : 0;
            $result['totals']['alert'] = $result['totals']['cb_rate'] >= $threshold || $totalsAlertAmount >= $threshold;
        } else {
            $result['totals']['cb_rate'] = null;
            $result['totals']['alert'] = false;
        }

        return $result;
    }

    /**
     * Get price point statistics with CB rates per amount.
     */
    public function getPricePointStats(?string $period = null, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $period = $period ?? '30d';
        $cacheKey = $this->getCacheKey('price_points', $period, $month, $year, $dateMode, $model, $empAccountId);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculatePricePointStats($period, $month, $year, $dateMode, $model, $empAccountId));
    }

    private function calculatePricePointStats(?string $period, ?int $month = null, ?int $year = null, string $dateMode = self::DATE_MODE_TRANSACTION, ?string $model = null, ?int $empAccountId = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year, $dateMode);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $query = DB::table('billing_attempts')
            ->where(function ($q) {
                $excludedCodes = config('tether.chargeback.excluded_cb_reason_codes', []);
                if (!empty($excludedCodes)) {
                    $q->whereNotIn('billing_attempts.chargeback_reason_code', $excludedCodes)
                      ->orWhereNull('billing_attempts.chargeback_reason_code');
                }
            });

        $this->applyDateFilter($query, $dateFilter);
        $this->applyModelFilter($query, $model);
        $this->applyEmpAccountFilter($query, $empAccountId);

        $rows = $query
            ->groupBy('billing_attempts.amount')
            ->select([
                'billing_attempts.amount as price_point',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '" . BillingAttempt::STATUS_APPROVED . "' THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '" . BillingAttempt::STATUS_DECLINED . "' THEN 1 ELSE 0 END) as declined"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '" . BillingAttempt::STATUS_ERROR . "' THEN 1 ELSE 0 END) as errors"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN 1 ELSE 0 END) as chargebacks"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '" . BillingAttempt::STATUS_APPROVED . "' THEN billing_attempts.amount ELSE 0 END) as approved_volume"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN billing_attempts.amount ELSE 0 END) as chargeback_volume"),
            ])
            ->orderBy('billing_attempts.amount')
            ->get();

        $canCalculateRates = $dateMode !== self::DATE_MODE_CHARGEBACK;

        $pricePoints = [];
        $totals = [
            'total' => 0,
            'approved' => 0,
            'declined' => 0,
            'errors' => 0,
            'chargebacks' => 0,
            'approved_volume' => 0,
            'chargeback_volume' => 0,
        ];

        foreach ($rows as $row) {
            $cbRateApproved = null;
            $alert = false;

            if ($canCalculateRates) {
                $cbRateApproved = $this->calculateCbRateApproved(
                    (int) $row->chargebacks,
                    (int) $row->approved
                );
                $alert = $cbRateApproved >= $threshold;
            }

            $pricePoints[] = [
                'price_point' => round((float) $row->price_point, 2),
                'total' => (int) $row->total,
                'approved' => (int) $row->approved,
                'declined' => (int) $row->declined,
                'errors' => (int) $row->errors,
                'chargebacks' => (int) $row->chargebacks,
                'approved_volume' => round((float) $row->approved_volume, 2),
                'chargeback_volume' => round((float) $row->chargeback_volume, 2),
                'cb_rate' => $cbRateApproved,
                'alert' => $alert,
            ];

            $totals['total'] += (int) $row->total;
            $totals['approved'] += (int) $row->approved;
            $totals['declined'] += (int) $row->declined;
            $totals['errors'] += (int) $row->errors;
            $totals['chargebacks'] += (int) $row->chargebacks;
            $totals['approved_volume'] += (float) $row->approved_volume;
            $totals['chargeback_volume'] += (float) $row->chargeback_volume;
        }

        $totals['approved_volume'] = round($totals['approved_volume'], 2);
        $totals['chargeback_volume'] = round($totals['chargeback_volume'], 2);

        if ($canCalculateRates) {
            $totals['cb_rate'] = $this->calculateCbRateApproved(
                (int) $totals['chargebacks'],
                (int) $totals['approved']
            );
            $totals['alert'] = $totals['cb_rate'] >= $threshold;
        } else {
            $totals['cb_rate'] = null;
            $totals['alert'] = false;
        }

        return [
            'period' => $month && $year ? 'monthly' : $period,
            'model' => $model ?? 'all',
            'emp_account_id' => $empAccountId,
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
            'date_mode' => $dateMode,
            'threshold' => $threshold,
            'price_points' => $pricePoints,
            'totals' => $totals,
        ];
    }
}
