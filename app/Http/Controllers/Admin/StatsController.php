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
            'period' => 'nullable|string|in:24h,7d,30d,90d,all',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
            'date_mode' => 'nullable|string|in:transaction,chargeback',
            'model' => 'nullable|string',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $stats = $this->chargebackStatsService->getStats(
            $request->input('period'),
            $request->input('month'),
            $request->input('year'),
            $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $stats]);
    }

    public function chargebackCodes(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:24h,7d,30d,90d,all',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
            'date_mode' => 'nullable|string|in:transaction,chargeback',
            'model' => 'nullable|string',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $codes = $this->chargebackStatsService->getChargebackCodes(
            $request->input('period'),
            $request->input('month'),
            $request->input('year'),
            $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $codes]);
    }

    public function chargebackBanks(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:24h,7d,30d,90d,all',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
            'date_mode' => 'nullable|string|in:transaction,chargeback',
            'model' => 'nullable|string',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $banks = $this->chargebackStatsService->getChargebackBanks(
            $request->input('period'),
            $request->input('month'),
            $request->input('year'),
            $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $banks]);
    }

    public function pricePoints(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:24h,7d,30d,90d,all',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2100',
            'date_mode' => 'nullable|string|in:transaction,chargeback',
            'model' => 'nullable|string',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $stats = $this->chargebackStatsService->getPricePointStats(
            $request->input('period', '30d'),
            $request->input('month'),
            $request->input('year'),
            $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $stats]);
    }

    public function chargebackAllTime(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'nullable|string',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
        ]);

        $model = $request->input('model');
        $empAccountId = $request->input('emp_account_id');

        $stats = $this->chargebackStatsService->getChargebackAllTimeStats($model, $empAccountId);

        return response()->json(['data' => $stats]);
    }
}
