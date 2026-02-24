<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DebtorProfile;
use App\Services\BicAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BicAnalyticsController extends Controller
{
    public function __construct(
        private BicAnalyticsService $bicAnalyticsService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:7d,30d,60d,90d',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'model' => 'nullable|string|in:' . implode(',', DebtorProfile::BILLING_MODELS),
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
            'cb_reason_code' => 'nullable|string|max:50',
        ]);

        $period = $request->input('period', BicAnalyticsService::DEFAULT_PERIOD);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $billingModel = $request->input('model');
        $empAccountId = $request->input('emp_account_id');
        $tetherInstanceId = $request->input('tether_instance_id');
        $cbReasonCode = $request->input('cb_reason_code');

        $data = $this->bicAnalyticsService->getAnalytics($period, $startDate, $endDate, $billingModel, $empAccountId, $cbReasonCode, $tetherInstanceId);

        return response()->json(['data' => $data]);
    }

    public function pricePoints(Request $request): JsonResponse
    {
        $request->validate([
            'bic' => 'required|string',
            'period' => 'nullable|in:7d,30d,60d,90d',
            'model' => 'nullable|string|in:' . implode(',', DebtorProfile::BILLING_MODELS),
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $data = $this->bicAnalyticsService->getBicPricePointBreakdown(
            $request->input('bic'),
            $request->input('period', BicAnalyticsService::DEFAULT_PERIOD),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $data]);
    }

    public function cbCodeBreakdown(Request $request): JsonResponse
    {
        $request->validate([
            'bic' => 'required|string',
            'period' => 'nullable|in:7d,30d,60d,90d',
            'model' => 'nullable|string|in:' . implode(',', DebtorProfile::BILLING_MODELS),
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $data = $this->bicAnalyticsService->getBicCbCodeBreakdown(
            $request->input('bic'),
            $request->input('period', BicAnalyticsService::DEFAULT_PERIOD),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $bic): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:7d,30d,60d,90d',
            'model' => 'nullable|string|in:' . implode(',', DebtorProfile::BILLING_MODELS),
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $data = $this->bicAnalyticsService->getBicSummary(
            $bic,
            $request->input('period', BicAnalyticsService::DEFAULT_PERIOD),
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        if (!$data) {
            return response()->json(['error' => 'BIC not found or no data'], 404);
        }

        return response()->json(['data' => $data]);
    }

    public function clearCache(): JsonResponse
    {
        $this->bicAnalyticsService->clearCache();

        return response()->json(['message' => 'Cache cleared']);
    }

    public function export(Request $request)
    {
        $request->validate([
            'period' => 'nullable|in:7d,30d,60d,90d',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'model' => 'nullable|string|in:' . implode(',', DebtorProfile::BILLING_MODELS),
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
            'cb_reason_code' => 'nullable|string|max:50',
        ]);

        $period = $request->input('period', BicAnalyticsService::DEFAULT_PERIOD);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $billingModel = $request->input('model');
        $empAccountId = $request->input('emp_account_id');
        $tetherInstanceId = $request->input('tether_instance_id');
        $cbReasonCode = $request->input('cb_reason_code');

        $data = $this->bicAnalyticsService->getAnalytics($period, $startDate, $endDate, $billingModel, $empAccountId, $cbReasonCode, $tetherInstanceId);

        $parts = ['bic_analytics'];
        if ($billingModel) $parts[] = $billingModel;
        if ($tetherInstanceId) $parts[] = "ti{$tetherInstanceId}";
        elseif ($empAccountId) $parts[] = "acc{$empAccountId}";
        if ($cbReasonCode) $parts[] = "cb_{$cbReasonCode}";
        $parts[] = $period;
        $prefix = implode('_', $parts);
        $filename = $prefix . '_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'BIC', 'Country', 'Currency', 'Amount',
                'Total Transactions', 'Approved', 'Declined', 'Chargebacks',
                'Errors', 'Pending', 'Total Volume (EUR)', 'Approved Volume (EUR)',
                'Chargeback Volume (EUR)', 'CB Rate (%)', 'CB Rate Volume (%)', 'High Risk',
            ]);

            foreach ($data['bics'] as $bic) {
                fputcsv($handle, [
                    $bic['bic'], $bic['bank_country'], $bic['currency'], $bic['amount'],
                    $bic['total_transactions'], $bic['approved_count'], $bic['declined_count'],
                    $bic['chargeback_count'], $bic['error_count'], $bic['pending_count'],
                    $bic['total_volume'], $bic['approved_volume'], $bic['chargeback_volume'],
                    $bic['cb_rate_count'], $bic['cb_rate_volume'],
                    $bic['is_high_risk'] ? 'Yes' : 'No',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
