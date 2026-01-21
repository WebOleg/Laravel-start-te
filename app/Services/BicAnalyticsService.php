<?php

/**
 * Service for BIC (Bank Identifier Code) analytics.
 * Provides aggregated transaction metrics per bank for risk monitoring.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BicAnalyticsService
{
    public const PERIODS = ['7d', '30d', '60d', '90d'];
    public const DEFAULT_PERIOD = '30d';
    public const CACHE_TTL = 900;
    public const HIGH_RISK_THRESHOLD = 25.0;

    /**
     * Get BIC analytics with caching.
     */
    public function getAnalytics(
        string $period = self::DEFAULT_PERIOD,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $billingModel = null
    ): array
    {
        $cacheKey = $this->buildCacheKey($period, $startDate, $endDate, $billingModel);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($period, $startDate, $endDate, $billingModel) {
            return $this->calculateAnalytics($period, $startDate, $endDate, $billingModel);
        });
    }

    /**
     * Calculate BIC analytics.
     */
    public function calculateAnalytics(
        string $period,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $billingModel = null
    ): array
    {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate);
        $threshold = config('tether.chargeback.alert_threshold', self::HIGH_RISK_THRESHOLD);

        $query = DB::table('billing_attempts')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('bic')
            ->where('bic', '!=', '');

        if ($billingModel) {
            $query->where('billing_model', $billingModel);
        }

        $query->groupBy('bic')
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

        $highRiskCount = count(array_filter($bics, fn ($b) => $b['is_high_risk']));
        $totals['total_bics'] = count($bics);
        $totals['high_risk_bics'] = $highRiskCount;
        $totals['total_chargebacks'] = $totals['chargeback_count'];
        $totals['overall_cb_rate'] = $totals['cb_rate_count'];

        return [
            'period' => $period,
            'model' => $billingModel ?? 'all',
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
    public function getBicSummary(string $bic, string $period = self::DEFAULT_PERIOD, ?string $billingModel = null): ?array
    {
        [$start, $end] = $this->resolveDateRange($period, null, null);

        $query = DB::table('billing_attempts')
            ->where('bic', $bic)
            ->whereBetween('created_at', [$start, $end]);

        if ($billingModel) {
            $query->where('billing_model', $billingModel);
        }

        $result = $query->select([
            DB::raw('COUNT(*) as total_transactions'),
            DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_APPROVED . "' THEN 1 ELSE 0 END) as approved_count"),
            DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_DECLINED . "' THEN 1 ELSE 0 END) as declined_count"),
            DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN 1 ELSE 0 END) as chargeback_count"),
            DB::raw('SUM(amount) as total_volume'),
            DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_APPROVED . "' THEN amount ELSE 0 END) as approved_volume"),
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
        $models = array_merge([null], DebtorProfile::BILLING_MODELS);

        foreach (self::PERIODS as $period) {
            foreach ($models as $model) {
                $key = $this->buildCacheKey($period, null, null, $model);
                Cache::forget($key);
            }
        }
    }

    private function buildCacheKey(
        string $period,
        ?string $startDate,
        ?string $endDate,
        ?string $billingModel
    ): string
    {
        $suffix = $billingModel ? "_{$billingModel}" : "";

        if ($startDate && $endDate) {
            return "bic_analytics_custom_{$startDate}_{$endDate}{$suffix}";
        }
        return "bic_analytics_{$period}{$suffix}";
    }

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
     *
     * KPIs (bank standard):
     * - cb_rate_count: chargebacks / approved_count × 100
     * - cb_rate_volume: chargeback_volume / approved_volume × 100
     */
    private function processBicRow(object $row, float $threshold): array
    {
        $totalTxCount = (int) $row->total_transactions;
        $totalTxVolume = (int) $row->total_volume;
        $approvedCount = (int) $row->approved_count;
        $chargebackCount = (int) $row->chargeback_count;
        $approvedVolume = (float) ($row->approved_volume ?? 0);
        $chargebackVolume = (float) $row->chargeback_volume;

        $cbRateCount = $chargebackCount > 0
            ? round(($chargebackCount / $totalTxCount) * 100, 2)
            : 0;

        $cbRateVolume = $chargebackVolume > 0
            ? round(($chargebackVolume / $totalTxVolume) * 100, 2)
            : 0;

        $country = $this->extractCountryFromBic($row->bic);

        return [
            'bic' => $row->bic,
            'bank_country' => $country,
            'total_transactions' => (int) $row->total_transactions,
            'approved_count' => $approvedCount,
            'declined_count' => (int) $row->declined_count,
            'chargeback_count' => $chargebackCount,
            'error_count' => (int) ($row->error_count ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'total_volume' => round((float) $row->total_volume, 2),
            'approved_volume' => round($approvedVolume, 2),
            'chargeback_volume' => round($chargebackVolume, 2),
            'cb_rate_count' => $cbRateCount,
            'cb_rate_volume' => $cbRateVolume,
            'is_high_risk' => $cbRateCount >= $threshold || $cbRateVolume >= $threshold,
        ];
    }

    private function extractCountryFromBic(string $bic): string
    {
        if (strlen($bic) >= 6) {
            return strtoupper(substr($bic, 4, 2));
        }

        return 'XX';
    }

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
     *
     * KPIs (bank standard):
     * - cb_rate_count: chargebacks / approved_count × 100
     * - cb_rate_volume: chargeback_volume / approved_volume × 100
     */
    private function finalizeTotals(array &$totals, float $threshold): void
    {
        $totals['total_volume'] = round($totals['total_volume'], 2);
        $totals['approved_volume'] = round($totals['approved_volume'], 2);
        $totals['chargeback_volume'] = round($totals['chargeback_volume'], 2);

        $totals['cb_rate_count'] = $totals['chargeback_count'] > 0
            ? round(($totals['chargeback_count'] / $totals['total_transactions']) * 100, 2)
            : 0;

        $totals['cb_rate_volume'] = $totals['chargeback_volume'] > 0
            ? round(($totals['chargeback_volume'] / $totals['total_volume']) * 100, 2)
            : 0;

        $totals['is_high_risk'] = $totals['cb_rate_count'] >= $threshold || $totals['cb_rate_volume'] >= $threshold;
    }
}
