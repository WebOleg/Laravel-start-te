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
        $period = $request->input('period', '7d');

        $stats = $this->chargebackStatsService->getStats($period);

        return response()->json(['data' => $stats]);
    }

    public function chargebackCodes(Request $request): JsonResponse
    {
        $period = $request->input('period', '7d');

        $codes = $this->chargebackStatsService->getChargebackCodes($period);

        return response()->json(['data' => $codes]);
    }
}
