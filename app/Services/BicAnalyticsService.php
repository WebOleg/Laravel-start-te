<?php

/**
 * Service for BIC (Bank Identifier Code) analytics.
 * Provides aggregated transaction metrics per bank for risk monitoring.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BicAnalyticsService
{
    public const PERIODS = ['7d', '30d', '60d', '90d'];
    public const DEFAULT_PERIOD = '30d';
    public const CACHE_TTL = 900;
    public const HIGH_RISK_THRESHOLD = 1.0;

    /**
     * Get BIC analytics with caching.
     */
    public function getAnalytics(string $period = self::DEFAULT_PERIOD, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->buildCacheKey($period, $startDate, $endDate);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($period, $startDate, $endDate) {
            return $this->calculateAnalytics($period, $startDate, $endDate);
        });
    }

    /**
     * Calculate BIC analytics.
     */
    public function calculateAnalytics(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate);
        $threshold = config('tether.chargeback.alert_threshold', self::HIGH_RISK_THRESHOLD);

        $query = DB::table('billing_attempts')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('bic')
            ->where('bic', '!=', '')
            ->groupBy('bic')
            ->select([
                'bic',
                DB::raw('COUNT(*) as total_transactions'),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_APPROVED . "' THEN 1 ELSE 0 END) as approved_count"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_DECLINED . "' THEN 1 ELSE 0 END) as declined_count"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN 1 ELSE 0 END) as chargeback_count"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_ERROR . "' THEN 1 ELSE 0 END) as error_count"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_PENDING . "' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_APPROVED . "' THEN amount ELSE 0 END) as approved_volume"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN amount ELSE 0 END) as chargeback_volume"),
            ]);

        $results = $query->get();

        $bics = [];
        $totals = $this->initTotals();

        foreach ($results as $row) {
            $bicData = $this->processBicRow($row, $threshold);
            $bics[] = $bicData;
            $this->addToTotals($totals, $bicData);
        }

        $this->finalizeTotals($totals, $threshold);

        usort($bics, fn ($a, $b) => $b['chargeback_count'] <=> $a['chargeback_count']);

        // Add summary fields for frontend
        $highRiskCount = count(array_filter($bics, fn ($b) => $b['is_high_risk']));
        $totals['total_bics'] = count($bics);
        $totals['high_risk_bics'] = $highRiskCount;
        $totals['total_chargebacks'] = $totals['chargeback_count'];
        $totals['overall_cb_rate'] = $totals['cb_rate_count'];

        return [
            'period' => $period,
            'start_date' => $start->toIso8601String(),
            'end_date' => $end->toIso8601String(),
            'threshold' => $threshold,
            'bics' => $bics,
            'totals' => $totals,
            'high_risk_count' => $highRiskCount,
        ];
    }

    /**
     * Get summary for a specific BIC.
     */
    public function getBicSummary(string $bic, string $period = self::DEFAULT_PERIOD): ?array
    {
        [$start, $end] = $this->resolveDateRange($period, null, null);

        $result = DB::table('billing_attempts')
            ->where('bic', $bic)
            ->whereBetween('created_at', [$start, $end])
            ->select([
                DB::raw('COUNT(*) as total_transactions'),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_APPROVED . "' THEN 1 ELSE 0 END) as approved_count"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_DECLINED . "' THEN 1 ELSE 0 END) as declined_count"),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN 1 ELSE 0 END) as chargeback_count"),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN amount ELSE 0 END) as chargeback_volume"),
            ])
            ->first();

        if (!$result || $result->total_transactions == 0) {
            return null;
        }

        $threshold = config('tether.chargeback.alert_threshold', self::HIGH_RISK_THRESHOLD);

        return $this->processBicRow((object) array_merge((array) $result, ['bic' => $bic]), $threshold);
    }

    /**
     * Clear analytics cache.
     */
    public function clearCache(): void
    {
        foreach (self::PERIODS as $period) {
            Cache::forget("bic_analytics_{$period}");
        }
    }

    /**
     * Resolve date range from period or explicit dates.
     */
    private function resolveDateRange(string $period, ?string $startDate, ?string $endDate): array
    {
        if ($startDate && $endDate) {
            return [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()];
        }

        $end = now();
        $start = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '60d' => now()->subDays(60),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        return [$start, $end];
    }

    /**
     * Process a single BIC row into analytics data.
     */
    private function processBicRow(object $row, float $threshold): array
    {
        $everApproved = (int) $row->approved_count + (int) $row->chargeback_count;
        
        $cbRateCount = $everApproved > 0
            ? round(((int) $row->chargeback_count / $everApproved) * 100, 2)
            : 0;

        $cbRateVolume = (float) $row->total_volume > 0
            ? round(((float) $row->chargeback_volume / (float) $row->total_volume) * 100, 2)
            : 0;

        $country = $this->extractCountryFromBic($row->bic);

        return [
            'bic' => $row->bic,
            'bank_country' => $country,
            'total_transactions' => (int) $row->total_transactions,
            'approved_count' => (int) $row->approved_count,
            'declined_count' => (int) $row->declined_count,
            'chargeback_count' => (int) $row->chargeback_count,
            'error_count' => (int) ($row->error_count ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'total_volume' => round((float) $row->total_volume, 2),
            'approved_volume' => round((float) ($row->approved_volume ?? 0), 2),
            'chargeback_volume' => round((float) $row->chargeback_volume, 2),
            'cb_rate_count' => $cbRateCount,
            'cb_rate_volume' => $cbRateVolume,
            'is_high_risk' => $cbRateCount >= $threshold || $cbRateVolume >= $threshold,
        ];
    }

    /**
     * Extract country code from BIC (positions 5-6).
     */
    private function extractCountryFromBic(string $bic): string
    {
        if (strlen($bic) >= 6) {
            return strtoupper(substr($bic, 4, 2));
        }
        return 'XX';
    }

    /**
     * Initialize totals array.
     */
    private function initTotals(): array
    {
        return [
            'total_transactions' => 0,
            'approved_count' => 0,
            'declined_count' => 0,
            'chargeback_count' => 0,
            'error_count' => 0,
            'pending_count' => 0,
            'total_volume' => 0,
            'approved_volume' => 0,
            'chargeback_volume' => 0,
            'cb_rate_count' => 0,
            'cb_rate_volume' => 0,
            'is_high_risk' => false,
        ];
    }

    /**
     * Add BIC data to running totals.
     */
    private function addToTotals(array &$totals, array $bicData): void
    {
        $totals['total_transactions'] += $bicData['total_transactions'];
        $totals['approved_count'] += $bicData['approved_count'];
        $totals['declined_count'] += $bicData['declined_count'];
        $totals['chargeback_count'] += $bicData['chargeback_count'];
        $totals['error_count'] += $bicData['error_count'];
        $totals['pending_count'] += $bicData['pending_count'];
        $totals['total_volume'] += $bicData['total_volume'];
        $totals['approved_volume'] += $bicData['approved_volume'];
        $totals['chargeback_volume'] += $bicData['chargeback_volume'];
    }

    /**
     * Calculate final rates for totals.
     */
    private function finalizeTotals(array &$totals, float $threshold): void
    {
        $totals['total_volume'] = round($totals['total_volume'], 2);
        $totals['approved_volume'] = round($totals['approved_volume'], 2);
        $totals['chargeback_volume'] = round($totals['chargeback_volume'], 2);

        $everApproved = $totals['approved_count'] + $totals['chargeback_count'];
        
        $totals['cb_rate_count'] = $everApproved > 0
            ? round(($totals['chargeback_count'] / $everApproved) * 100, 2)
            : 0;

        $totals['cb_rate_volume'] = $totals['total_volume'] > 0
            ? round(($totals['chargeback_volume'] / $totals['total_volume']) * 100, 2)
            : 0;

        $totals['is_high_risk'] = $totals['cb_rate_count'] >= $threshold || $totals['cb_rate_volume'] >= $threshold;
    }

    /**
     * Build cache key for analytics.
     */
    private function buildCacheKey(string $period, ?string $startDate, ?string $endDate): string
    {
        if ($startDate && $endDate) {
            return "bic_analytics_custom_{$startDate}_{$endDate}";
        }
        return "bic_analytics_{$period}";
    }
}
