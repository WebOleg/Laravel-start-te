<?php

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

/**
 * Chargeback Statistics Service
 * 
 * Supports two date filtering modes:
 * 1. Transaction Date (default): Filter by emp_created_at/created_at, calculate rates normally
 * 2. Chargeback Date: Filter by chargebacked_at, rates are NULL (approved transactions don't have this date)
 */
class ChargebackStatsService
{
    public const DATE_MODE_TRANSACTION = 'transaction';
    public const DATE_MODE_CHARGEBACK = 'chargeback';

    /**
     * Helper to apply model filtering to the query.
     */
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

    /**
     * Helper to apply EMP account filtering to the query.
     */
    private function applyEmpAccountFilter(Builder $query, ?int $empAccountId): void
    {
        if (empty($empAccountId)) {
            return;
        }
        $query->where('billing_attempts.emp_account_id', $empAccountId);
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
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id');

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

        // When filtering by chargeback date, rates cannot be calculated
        // because approved transactions don't have chargebacked_at timestamp
        $canCalculateRates = $dateMode !== self::DATE_MODE_CHARGEBACK;

        foreach ($stats as $row) {
            if ($canCalculateRates) {
                $cbRateTotal = $row->total > 0 
                    ? round(($row->chargebacks / $row->total) * 100, 2) 
                    : 0;
                
                $everApproved = $row->approved + $row->chargebacks;
                $cbRateApproved = $everApproved > 0 
                    ? round(($row->chargebacks / $everApproved) * 100, 2) 
                    : 0;

                $alert = $cbRateTotal >= $threshold || $cbRateApproved >= $threshold;
            } else {
                // Chargeback date mode: rates are NULL (not calculable)
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
            
            $totalsEverApproved = $totals['approved'] + $totals['chargebacks'];
            $totals['cb_rate_approved'] = $totalsEverApproved > 0 
                ? round(($totals['chargebacks'] / $totalsEverApproved) * 100, 2) 
                : 0;
            
            $totals['alert'] = $totals['cb_rate_total'] >= $threshold || $totals['cb_rate_approved'] >= $threshold;
            
            $totals['cb_rate_amount_approved'] = $totals['approved_amount'] > 0 
                ? round(($totals['chargeback_amount'] / $totals['approved_amount']) * 100, 2) 
                : 0;
            
            $totals['cb_alert_amount_approved'] = $totals['cb_rate_amount_approved'] >= $threshold;
        } else {
            // Chargeback date mode: rates are NULL
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

        // If period is 'all', return all-time (no date filter)
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
        // If type is 'all', don't apply any date filter
        if ($dateFilter['type'] === 'all') {
            return;
        }

        $dateMode = $dateFilter['date_mode'] ?? self::DATE_MODE_TRANSACTION;

        // Choose date column based on mode
        if ($dateMode === self::DATE_MODE_CHARGEBACK) {
            // Filter by chargeback date - only chargebacked transactions have this date
            $dateColumn = 'billing_attempts.chargebacked_at';
        } else {
            // Filter by transaction date (default) - use emp_created_at with fallback
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
        // Include 'all' plus the defined models from DebtorProfile
        $models = array_merge([DebtorProfile::ALL], DebtorProfile::BILLING_MODELS);
        $dateModes = ['_tx', '_cb'];

        foreach ($periods as $period) {
            foreach ($dateModes as $modeSuffix) {
                foreach ($models as $model) {
                    $modelKey = $model;
                    Cache::forget("chargeback_stats_{$period}{$modeSuffix}_{$modelKey}");
                    Cache::forget("chargeback_codes_{$period}{$modeSuffix}_{$modelKey}");
                    Cache::forget("chargeback_banks_{$period}{$modeSuffix}_{$modelKey}");
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

        // Use a subquery to get the most recent vop_log per debtor
        $latestVopLogs = DB::table('vop_logs')
            ->select('debtor_id', DB::raw('MAX(id) as latest_vop_log_id'))
            ->groupBy('debtor_id');

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->leftJoinSub($latestVopLogs, 'latest_vop', function ($join) {
                $join->on('debtors.id', '=', 'latest_vop.debtor_id');
            })
            ->leftJoin('vop_logs', 'latest_vop.latest_vop_log_id', '=', 'vop_logs.id');

        $this->applyDateFilter($query, $dateFilter);
        $this->applyModelFilter($query, $model);
        $this->applyEmpAccountFilter($query, $empAccountId);

        $banks = $query
            ->select([
                DB::raw("COALESCE(vop_logs.bank_name, 'Unknown') as bank_name"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_APPROVED."' THEN 1 ELSE 0 END) as approved"),
                DB::raw('SUM(billing_attempts.amount) as total_amount'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN 1 ELSE 0 END) as chargebacks"),
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

        // When filtering by chargeback date, rates cannot be calculated
        // because approved transactions don't have chargebacked_at timestamp
        $canCalculateRates = $dateMode !== self::DATE_MODE_CHARGEBACK;

        foreach ($banks as $row) {

            if ($canCalculateRates) {
                $everApproved = $row->approved + $row->chargebacks;
                $cbBankRate = $everApproved > 0
                    ? round(($row->chargebacks / $everApproved) * 100, 2) 
                    : 0;
                $cbBankRateAmount = $row->total_amount > 0 
                    ? round(($row->chargeback_amount / $row->total_amount) * 100, 2) 
                    : 0;
                $alert = $cbBankRate >= $threshold || $cbBankRateAmount >= $threshold;
            } else {
                // Chargeback date mode: rates are NULL
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
            $totalsEverApproved = $result['totals']['approved'] + $result['totals']['chargebacks'];
            $result['totals']['cb_rate'] = $totalsEverApproved > 0 
                ? round(($result['totals']['chargebacks'] / $totalsEverApproved) * 100, 2) 
                : 0;
            
            $totalsAlertAmount = $result['totals']['total_amount'] > 0 
                ? round(($result['totals']['chargeback_amount'] / $result['totals']['total_amount']) * 100, 2) 
                : 0;
            $result['totals']['alert'] = $result['totals']['cb_rate'] >= $threshold || $totalsAlertAmount >= $threshold;
        } else {
            // Chargeback date mode: rates are NULL
            $result['totals']['cb_rate'] = null;
            $result['totals']['alert'] = false;
        }

        return $result;
    }
}
