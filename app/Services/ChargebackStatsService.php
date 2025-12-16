<?php

/**
 * Service for calculating chargeback statistics by country.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChargebackStatsService
{
    public function getStats(string $period = '7d'): array
    {
        $cacheKey = "chargeback_stats_{$period}";
        $ttl = config('tether.chargeback.cache_ttl', 900);

        return Cache::remember($cacheKey, $ttl, fn () => $this->calculateStats($period));
    }

    public function calculateStats(string $period): array
    {
        $startDate = $this->getStartDate($period);
        $threshold = config('tether.chargeback.alert_threshold', 25);

        $stats = DB::table('billing_attempts')
            ->join('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->where('billing_attempts.created_at', '>=', $startDate)
            ->groupBy('debtors.country')
            ->select([
                'debtors.country',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN billing_attempts.status = 'approved' THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = 'declined' THEN 1 ELSE 0 END) as declined"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = 'error' THEN 1 ELSE 0 END) as errors"),
                DB::raw("SUM(CASE WHEN billing_attempts.status = 'chargebacked' THEN 1 ELSE 0 END) as chargebacks"),
            ])
            ->get();

        $countries = [];
        $totals = [
            'total' => 0,
            'approved' => 0,
            'declined' => 0,
            'errors' => 0,
            'chargebacks' => 0,
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
                'cb_rate_total' => $cbRateTotal,
                'cb_rate_approved' => $cbRateApproved,
                'alert' => $cbRateTotal >= $threshold || $cbRateApproved >= $threshold,
            ];

            $totals['total'] += $row->total;
            $totals['approved'] += $row->approved;
            $totals['declined'] += $row->declined;
            $totals['errors'] += $row->errors;
            $totals['chargebacks'] += $row->chargebacks;
        }

        $totals['cb_rate_total'] = $totals['total'] > 0 
            ? round(($totals['chargebacks'] / $totals['total']) * 100, 2) 
            : 0;
        $totals['cb_rate_approved'] = $totals['approved'] > 0 
            ? round(($totals['chargebacks'] / $totals['approved']) * 100, 2) 
            : 0;
        $totals['alert'] = $totals['cb_rate_total'] >= $threshold || $totals['cb_rate_approved'] >= $threshold;

        return [
            'period' => $period,
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
        foreach (['24h', '7d', '30d', '90d'] as $period) {
            Cache::forget("chargeback_stats_{$period}");
        }
    }
}
