<?php

/**
 * Controller for statistics endpoints.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ChargebackStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __construct(
        private ChargebackStatsService $chargebackStatsService
    ) {}

    public function chargebackRates(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:24h,7d,30d,90d',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $period = $request->input('period');
        $month = $request->input('month');
        $year = $request->input('year');

        $stats = $this->chargebackStatsService->getStats($period, $month, $year);

        return response()->json(['data' => $stats]);
    }

    public function chargebackCodes(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:24h,7d,30d,90d',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $period = $request->input('period');
        $month = $request->input('month');
        $year = $request->input('year');

        $codes = $this->chargebackStatsService->getChargebackCodes($period, $month, $year);

        return response()->json(['data' => $codes]);
    }

    public function chargebackBanks(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:24h,7d,30d,90d',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $period = $request->input('period');
        $month = $request->input('month');
        $year = $request->input('year');

        $banks = $this->chargebackStatsService->getChargebackBanks($period, $month, $year);

        return response()->json(['data' => $banks]);
    }
}
