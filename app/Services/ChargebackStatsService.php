<?php

/**
 * Service for calculating chargeback statistics by country.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

class ChargebackStatsService
{
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

    public function getStats(string $period = '7d', ?string $model = null): array
    {
        // Cache key must include the model to prevent data mixing
        $modelKey = $model ?? 'all';
        $cacheKey = "chargeback_stats_{$period}_{$modelKey}";
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateStats($period, $model));
    }

    public function calculateStats(string $period, ?string $model = null): array
    {
        $startDate = $this->getStartDate($period);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->where('billing_attempts.created_at', '>=', $startDate);

        // Apply Model Filter
        $this->applyModelFilter($query, $model);

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
            $cbRateApproved = $row->approved > 0
                ? round(($row->chargebacks / $row->approved) * 100, 2)
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
        $totals['cb_rate_approved'] = $totals['approved'] > 0
            ? round(($totals['chargebacks'] / $totals['approved']) * 100, 2)
            : 0;
        $totals['alert'] = $totals['cb_rate_total'] >= $threshold || $totals['cb_rate_approved'] >= $threshold;
        $totals['cb_rate_amount_approved'] = $totals['approved_amount'] > 0
            ? round(($totals['chargeback_amount'] / $totals['approved_amount']) * 100, 2)
            : 0;
        $totals['cb_alert_amount_approved'] = $totals['cb_rate_amount_approved'] >= $threshold;

        return [
            'period' => $period,
            'model' => $model ?? 'all',
            'start_date' => $startDate->toIso8601String(),
            'threshold' => $threshold,
            'countries' => $countries,
            'totals' => $totals,
        ];
    }

    private function getStartDate(string $period): \Carbon\Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(7),
        };
    }

    public function clearCache(): void
    {
        $periods = ['24h', '7d', '30d', '90d'];

        // Include 'all' plus the defined models from DebtorProfile
        $models = array_merge([DebtorProfile::ALL], DebtorProfile::BILLING_MODELS);

        foreach ($periods as $period) {
            foreach ($models as $model) {
                Cache::forget("chargeback_stats_{$period}_{$model}");
                Cache::forget("chargeback_codes_{$period}_{$model}");
                Cache::forget("chargeback_banks_{$period}_{$model}");
            }
        }
    }

    public function getChargebackCodes(string $period, ?string $model = null): array
    {
        $modelKey = $model ?? 'all';
        $cacheKey = "chargeback_codes_{$period}_{$modelKey}";
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateChargebackCodes($period, $model));
    }

    public function calculateChargebackCodes(string $period, ?string $model = null): array
    {
        $startDate = $this->getStartDate($period);

        $query = DB::table('billing_attempts')
            ->where('created_at', '>=', $startDate)
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereNotNull('error_code');

        // Apply Model Filter
        $this->applyModelFilter($query, $model);

        $codes = $query
            ->select([
                'error_message as chargeback_reason',
                'error_code as chargeback_code',
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('COUNT(*) as occurrences')
            ])
            ->groupBy('error_code', 'error_message')
            ->orderBy('total_amount', 'desc')
            ->get();

        $result = [];
        $result['period'] = $period;
        $result['model'] = $model ?? 'all';
        $result['start_date'] = $startDate->toIso8601String();
        $result['codes'] = [];
        $result['totals']['total_amount'] = 0;
        $result['totals']['occurrences'] = 0;

        foreach ($codes as $row) {
            $result['codes'][] = [
                'chargeback_code'   => $row->chargeback_code,
                'chargeback_reason' => $row->chargeback_reason,
                'total_amount'      => (float) $row->total_amount,
                'occurrences'       => (int) $row->occurrences,
            ];
            $result['totals']['total_amount'] = ($result['totals']['total_amount'] ?? 0) + (float) $row->total_amount;
            $result['totals']['occurrences'] = ($result['totals']['occurrences'] ?? 0) + (int) $row->occurrences;
        }

        return $result;
    }

    public function getChargebackBanks(string $period, ?string $model = null): array
    {
        $modelKey = $model ?? 'all';
        $cacheKey = "chargeback_banks_{$period}_{$modelKey}";
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateChargebackBanks($period, $model));
    }

    public function calculateChargebackBanks(string $period, ?string $model = null): array
    {
        $startDate = $this->getStartDate($period);

        $query = DB::table('billing_attempts')
            ->leftJoin('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->leftJoin('vop_logs', 'debtors.id', '=', 'vop_logs.debtor_id')
            ->where('billing_attempts.created_at', '>=', $startDate);

        // Apply Model Filter
        $this->applyModelFilter($query, $model);

        $banks = $query
            ->select([
                DB::raw("COALESCE(vop_logs.bank_name, 'Unknown') as bank_name"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = 'approved' THEN 1 ELSE 0 END) as approved"),
                DB::raw('SUM(billing_attempts.amount) as total_amount'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN 1 ELSE 0 END) as chargebacks"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = '".BillingAttempt::STATUS_CHARGEBACKED."' THEN billing_attempts.amount ELSE 0 END) as chargeback_amount"),
            ])
            ->groupBy('vop_logs.bank_name')
            ->get();

        $result = [
            'period' => $period,
            'model' => $model ?? 'all',
            'start_date' => $startDate->toIso8601String(),
            'banks' => [],
            'totals' => [
                'total' => 0,
                'approved' => 0,
                'total_amount' => 0,
                'chargebacks' => 0,
                'chargeback_amount' => 0,
                'cb_rate' => 0,
            ],
        ];

        foreach ($banks as $row) {
            $cbBankRate = $row->approved > 0
                ? round(($row->chargebacks / $row->approved) * 100, 2)
                : 0;

            $result['banks'][] = [
                'bank_name' => $row->bank_name,
                'total' => (int) $row->total,
                'approved' => (int) $row->approved,
                'total_amount' => round((float) $row->total_amount, 2),
                'chargebacks' => (int) $row->chargebacks,
                'chargeback_amount' => round((float) $row->chargeback_amount, 2),
                'cb_rate' => (float) $cbBankRate,
                'alert' => $cbBankRate >= 25, // Hardcoded threshold for banks
            ];

            $result['totals']['total'] += (int) $row->total;
            $result['totals']['approved'] += (int) $row->approved;
            $result['totals']['total_amount'] += (float) $row->total_amount;
            $result['totals']['chargebacks'] += (int) $row->chargebacks;
            $result['totals']['chargeback_amount'] += (float) $row->chargeback_amount;
        }

        $result['totals']['total_amount'] = round($result['totals']['total_amount'], 2);
        $result['totals']['chargeback_amount'] = round($result['totals']['chargeback_amount'], 2);
        $result['totals']['cb_rate'] = $result['totals']['approved'] > 0
            ? round(($result['totals']['chargebacks'] / $result['totals']['approved']) * 100, 2)
            : 0;

        $result['totals']['alert'] = $result['totals']['cb_rate'] >= 25;

        return $result;
    }
}
