<?php

namespace App\Services;

use App\Models\BillingAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChargebackStatsService
{
    public function getStats(?string $period = null, ?int $month = null, ?int $year = null): array
    {
        $cacheKey = $this->getCacheKey('chargeback_stats', $period, $month, $year);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateStats($period, $month, $year));
    }

    public function calculateStats(?string $period, ?int $month = null, ?int $year = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id');

        $this->applyDateFilter($query, $dateFilter);

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

        foreach ($stats as $row) {
            $cbRateTotal = $row->total > 0 
                ? round(($row->chargebacks / $row->total) * 100, 2) 
                : 0;
            
            $everApproved = $row->approved + $row->chargebacks;
            $cbRateApproved = $everApproved > 0 
                ? round(($row->chargebacks / $everApproved) * 100, 2) 
                : 0;

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
                'alert' => $cbRateTotal >= $threshold || $cbRateApproved >= $threshold,
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

        return [
            'period' => $month && $year ? 'monthly' : ($period ?? 'all-time'),
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
            'threshold' => $threshold,
            'countries' => $countries,
            'totals' => $totals,
        ];
    }

    private function buildDateFilter(?string $period, ?int $month, ?int $year): array
    {
        if ($month && $year) {
            return [
                'start' => Carbon::create($year, $month, 1)->startOfMonth(),
                'end' => Carbon::create($year, $month, 1)->endOfMonth(),
                'type' => 'monthly',
            ];
        }

        // If period is null, return all-time (no date filter)
        if ($period === null) {
            return [
                'start' => null,
                'end' => null,
                'type' => 'all-time',
            ];
        }

        return [
            'start' => $this->getStartDate($period),
            'end' => null,
            'type' => 'period',
        ];
    }

    private function applyDateFilter($query, array $dateFilter): void
    {
        // Use emp_created_at (real EMP transaction date) with fallback to created_at
        if ($dateFilter['type'] === 'monthly') {
            $query->whereRaw(
                'COALESCE(billing_attempts.emp_created_at, billing_attempts.created_at) BETWEEN ? AND ?',
                [$dateFilter['start'], $dateFilter['end']]
            );
        } elseif ($dateFilter['type'] === 'period') {
            $query->whereRaw(
                'COALESCE(billing_attempts.emp_created_at, billing_attempts.created_at) >= ?',
                [$dateFilter['start']]
            );
        }
        // For 'all-time', don't apply any date filter
    }

    private function getStartDate(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(7),
        };
    }

    private function getCacheKey(string $prefix, ?string $period, ?int $month, ?int $year): string
    {
        if ($month && $year) {
            return "{$prefix}_monthly_{$year}_{$month}";
        }
        return "{$prefix}_" . ($period ?? 'all_time');
    }

    public function clearCache(): void
    {
        foreach (['24h', '7d', '30d', '90d'] as $period) {
            Cache::forget("chargeback_stats_{$period}");
            Cache::forget("chargeback_codes_{$period}");
            Cache::forget("chargeback_banks_{$period}");
        }
    }

    public function getChargebackCodes(?string $period, ?int $month = null, ?int $year = null): array
    {
        $cacheKey = $this->getCacheKey('chargeback_codes', $period, $month, $year);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateChargebackCodes($period, $month, $year));
    }

    public function calculateChargebackCodes(?string $period, ?int $month = null, ?int $year = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year);

        $query = DB::table('billing_attempts')
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereNotNull('chargeback_reason_code');

        $this->applyDateFilter($query, $dateFilter);

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
            'period' => $month && $year ? 'monthly' : ($period ?? 'all-time'),
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
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

    public function getChargebackBanks(?string $period, ?int $month = null, ?int $year = null): array
    {
        $cacheKey = $this->getCacheKey('chargeback_banks', $period, $month, $year);
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateChargebackBanks($period, $month, $year));
    }

    public function calculateChargebackBanks(?string $period, ?int $month = null, ?int $year = null): array
    {
        $dateFilter = $this->buildDateFilter($period, $month, $year);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->leftJoin('vop_logs', 'debtors.id', '=', 'vop_logs.debtor_id');

        $this->applyDateFilter($query, $dateFilter);

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
            'period' => $month && $year ? 'monthly' : ($period ?? 'all-time'),
            'start_date' => $dateFilter['start']?->toIso8601String(),
            'end_date' => $dateFilter['end']?->toIso8601String(),
            'month' => $month,
            'year' => $year,
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

        foreach ($banks as $row) {
            $everApproved = $row->approved + $row->chargebacks;
            $cbBankRate = $everApproved > 0
                ? round(($row->chargebacks / $everApproved) * 100, 2) 
                : 0;
            $cbBankRateAmount = $row->total_amount > 0 
                ? round(($row->chargeback_amount / $row->total_amount) * 100, 2) 
                : 0;

            $result['banks'][] = [
                'bank_name' => $row->bank_name,
                'total' => (int) $row->total,
                'approved' => (int) $row->approved,
                'total_amount' => round((float) $row->total_amount, 2),
                'chargebacks' => (int) $row->chargebacks,
                'chargeback_amount' => round((float) $row->chargeback_amount, 2),
                'cb_rate' => (float) $cbBankRate,
                'alert' => $cbBankRate >= $threshold || $cbBankRateAmount >= $threshold,
            ];

            $result['totals']['total'] += (int) $row->total;
            $result['totals']['approved'] += (int) $row->approved;
            $result['totals']['total_amount'] += (float) $row->total_amount;
            $result['totals']['chargebacks'] += (int) $row->chargebacks;
            $result['totals']['chargeback_amount'] += (float) $row->chargeback_amount;
        }

        $result['totals']['total_amount'] = round($result['totals']['total_amount'], 2);
        $result['totals']['chargeback_amount'] = round($result['totals']['chargeback_amount'], 2);
        
        $totalsEverApproved = $result['totals']['approved'] + $result['totals']['chargebacks'];
        $result['totals']['cb_rate'] = $totalsEverApproved > 0 
            ? round(($result['totals']['chargebacks'] / $totalsEverApproved) * 100, 2) 
            : 0;
        
        $totalsAlertAmount = $result['totals']['total_amount'] > 0 
            ? round(($result['totals']['chargeback_amount'] / $result['totals']['total_amount']) * 100, 2) 
            : 0;
        $result['totals']['alert'] = $result['totals']['cb_rate'] >= $threshold || $totalsAlertAmount >= $threshold;

        return $result;
    }
}
