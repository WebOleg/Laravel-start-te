<?php

/**
 * Controller for BIC (Bank Identifier Code) analytics endpoints.
 * Provides bank-level transaction metrics for risk monitoring.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BicAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BicAnalyticsController extends Controller
{
    public function __construct(
        private BicAnalyticsService $bicAnalyticsService
    ) {}

    /**
     * Get BIC analytics summary.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:7d,30d,60d,90d',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $period = $request->input('period', BicAnalyticsService::DEFAULT_PERIOD);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->bicAnalyticsService->getAnalytics($period, $startDate, $endDate);

        return response()->json(['data' => $data]);
    }

    /**
     * Get analytics for a specific BIC.
     *
     * @param Request $request
     * @param string $bic
     * @return JsonResponse
     */
    public function show(Request $request, string $bic): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:7d,30d,60d,90d',
        ]);

        $period = $request->input('period', BicAnalyticsService::DEFAULT_PERIOD);

        $data = $this->bicAnalyticsService->getBicSummary($bic, $period);

        if (!$data) {
            return response()->json(['error' => 'BIC not found or no data'], 404);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Clear analytics cache.
     *
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        $this->bicAnalyticsService->clearCache();

        return response()->json(['message' => 'Cache cleared']);
    }

    /**
     * Export BIC analytics as CSV.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $request->validate([
            'period' => 'nullable|in:7d,30d,60d,90d',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $period = $request->input('period', BicAnalyticsService::DEFAULT_PERIOD);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $data = $this->bicAnalyticsService->getAnalytics($period, $startDate, $endDate);

        $filename = 'bic_analytics_' . $period . '_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'BIC',
                'Country',
                'Total Transactions',
                'Approved',
                'Declined',
                'Chargebacks',
                'Errors',
                'Pending',
                'Total Volume (EUR)',
                'Approved Volume (EUR)',
                'Chargeback Volume (EUR)',
                'CB Rate (%)',
                'CB Rate Volume (%)',
                'High Risk',
            ]);

            foreach ($data['bics'] as $bic) {
                fputcsv($handle, [
                    $bic['bic'],
                    $bic['country'],
                    $bic['total_transactions'],
                    $bic['approved_count'],
                    $bic['declined_count'],
                    $bic['chargeback_count'],
                    $bic['error_count'],
                    $bic['pending_count'],
                    $bic['total_volume'],
                    $bic['approved_volume'],
                    $bic['chargeback_volume'],
                    $bic['cb_rate_count'],
                    $bic['cb_rate_volume'],
                    $bic['is_high_risk'] ? 'Yes' : 'No',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
