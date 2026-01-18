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
        $model = $request->input('model'); // e.g., 'flywheel', 'recovery', 'legacy', or null/'all'

        $stats = $this->chargebackStatsService->getStats($period, $model);

        return response()->json(['data' => $stats]);
    }

    public function chargebackCodes(Request $request): JsonResponse
    {
        $period = $request->input('period', '7d');
        $model = $request->input('model'); // Accept the model filter

        // Pass the model to the service method we updated earlier
        $codes = $this->chargebackStatsService->getChargebackCodes($period, $model);

        return response()->json(['data' => $codes]);
    }

    public function chargebackBanks(Request $request): JsonResponse
    {
        $period = $request->input('period', '7d');
        $model = $request->input('model'); // Accept the model filter

        // Pass the model to the service method we updated earlier
        $banks = $this->chargebackStatsService->getChargebackBanks($period, $model);

        return response()->json(['data' => $banks]);
    }
}
