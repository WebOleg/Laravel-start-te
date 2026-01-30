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
        ]);

        $period = $request->input('period');
        $month = $request->input('month');
        $year = $request->input('year');
        $dateMode = $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION);
        $model = $request->input('model');
        $empAccountId = $request->input('emp_account_id');

        $stats = $this->chargebackStatsService->getStats($period, $month, $year, $dateMode, $model, $empAccountId);

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
        ]);

        $period = $request->input('period');
        $month = $request->input('month');
        $year = $request->input('year');
        $dateMode = $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION);
        $model = $request->input('model');
        $empAccountId = $request->input('emp_account_id');

        $codes = $this->chargebackStatsService->getChargebackCodes($period, $month, $year, $dateMode, $model, $empAccountId);

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
        ]);

        $period = $request->input('period');
        $month = $request->input('month');
        $year = $request->input('year');
        $dateMode = $request->input('date_mode', ChargebackStatsService::DATE_MODE_TRANSACTION);
        $model = $request->input('model');
        $empAccountId = $request->input('emp_account_id');

        $banks = $this->chargebackStatsService->getChargebackBanks($period, $month, $year, $dateMode, $model, $empAccountId);

        return response()->json(['data' => $banks]);
    }
}
