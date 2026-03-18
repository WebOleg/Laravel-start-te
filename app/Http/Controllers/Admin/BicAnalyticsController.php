<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DebtorProfile;
use App\Services\BicAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class BicAnalyticsController extends Controller
{
    public function __construct(
        private BicAnalyticsService $bicAnalyticsService
    ) {}

    /**
     * Get BIC analytics overview.
     *
     * @OA\Get(
     *     path="/api/admin/analytics/bic",
     *     summary="Get BIC analytics overview",
     *     description="Returns aggregated transaction metrics per BIC (bank) for risk monitoring. Includes transaction counts, volumes, chargeback rates, and high-risk flags. Results are cached for 15 minutes.",
     *     tags={"BIC Analytics"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, description="Time period", @OA\Schema(type="string", enum={"7d", "30d", "60d", "90d"}, default="30d")),
     *     @OA\Parameter(name="start_date", in="query", required=false, description="Custom start date (overrides period)", @OA\Schema(type="string", format="date", example="2025-01-01")),
     *     @OA\Parameter(name="end_date", in="query", required=false, description="Custom end date (must be >= start_date)", @OA\Schema(type="string", format="date", example="2025-03-15")),
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance (takes priority over emp_account_id)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="cb_reason_code", in="query", required=false, description="Filter chargebacks by specific reason code", @OA\Schema(type="string", maxLength=50)),
     *     @OA\Response(
     *         response=200,
     *         description="BIC analytics data",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="model", type="string", example="all"),
     *                 @OA\Property(property="emp_account_id", type="integer", nullable=true, example=null),
     *                 @OA\Property(property="tether_instance_id", type="integer", nullable=true, example=null),
     *                 @OA\Property(property="cb_reason_code", type="string", nullable=true, example=null),
     *                 @OA\Property(property="start_date", type="string", format="date-time", example="2025-02-13T00:00:00+00:00"),
     *                 @OA\Property(property="end_date", type="string", format="date-time", example="2025-03-15T23:59:59+00:00"),
     *                 @OA\Property(property="threshold", type="number", format="float", example=30.0),
     *                 @OA\Property(property="high_risk_count", type="integer", example=3),
     *                 @OA\Property(property="bics", type="array",
     *                     @OA\Items(ref="#/components/schemas/BicAnalyticsRow")
     *                 ),
     *                 @OA\Property(property="totals", type="object",
     *                     @OA\Property(property="total_bics", type="integer", example=45),
     *                     @OA\Property(property="high_risk_bics", type="integer", example=3),
     *                     @OA\Property(property="total_transactions", type="integer", example=12500),
     *                     @OA\Property(property="approved_count", type="integer", example=10000),
     *                     @OA\Property(property="declined_count", type="integer", example=1500),
     *                     @OA\Property(property="chargeback_count", type="integer", example=800),
     *                     @OA\Property(property="error_count", type="integer", example=150),
     *                     @OA\Property(property="pending_count", type="integer", example=50),
     *                     @OA\Property(property="total_volume", type="number", format="float", example=125000.00),
     *                     @OA\Property(property="approved_volume", type="number", format="float", example=100000.00),
     *                     @OA\Property(property="chargeback_volume", type="number", format="float", example=8000.00),
     *                     @OA\Property(property="cb_rate_count", type="number", format="float", example=7.41),
     *                     @OA\Property(property="cb_rate_volume", type="number", format="float", example=7.41),
     *                     @OA\Property(property="overall_cb_rate", type="number", format="float", example=7.41),
     *                     @OA\Property(property="is_high_risk", type="boolean", example=false)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get price point breakdown for a specific BIC.
     *
     * @OA\Get(
     *     path="/api/admin/analytics/bic/price-points",
     *     summary="Get BIC price point breakdown",
     *     description="Returns transaction metrics grouped by amount and currency for a specific BIC. Useful for identifying which price points have the highest chargeback rates.",
     *     tags={"BIC Analytics"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="bic", in="query", required=true, description="BIC code", @OA\Schema(type="string", example="DEUTDEFF")),
     *     @OA\Parameter(name="period", in="query", required=false, description="Time period", @OA\Schema(type="string", enum={"7d", "30d", "60d", "90d"}, default="30d")),
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Price point breakdown",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="bic", type="string", example="DEUTDEFF"),
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="segments", type="array",
     *                     @OA\Items(ref="#/components/schemas/BicAnalyticsRow")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get chargeback code breakdown for a specific BIC.
     *
     * @OA\Get(
     *     path="/api/admin/analytics/bic/cb-codes",
     *     summary="Get BIC chargeback code breakdown",
     *     description="Returns chargeback counts and volumes grouped by reason code for a specific BIC. Shows percentage distribution of each code relative to total chargebacks.",
     *     tags={"BIC Analytics"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="bic", in="query", required=true, description="BIC code", @OA\Schema(type="string", example="DEUTDEFF")),
     *     @OA\Parameter(name="period", in="query", required=false, description="Time period", @OA\Schema(type="string", enum={"7d", "30d", "60d", "90d"}, default="30d")),
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Chargeback code breakdown",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="bic", type="string", example="DEUTDEFF"),
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="total_chargebacks", type="integer", example=45),
     *                 @OA\Property(property="total_volume", type="number", format="float", example=4500.00),
     *                 @OA\Property(property="codes", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="code", type="string", example="MD06"),
     *                         @OA\Property(property="count", type="integer", example=20),
     *                         @OA\Property(property="volume", type="number", format="float", example=2000.00),
     *                         @OA\Property(property="percent_count", type="number", format="float", example=44.44),
     *                         @OA\Property(property="percent_volume", type="number", format="float", example=44.44)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get detailed summary for a specific BIC.
     *
     * @OA\Get(
     *     path="/api/admin/analytics/bic/{bic}",
     *     summary="Get summary for a specific BIC",
     *     description="Returns aggregated transaction metrics for a single BIC including approval rates, chargeback rates, volumes, and risk status.",
     *     tags={"BIC Analytics"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="bic", in="path", required=true, description="BIC code", @OA\Schema(type="string", example="DEUTDEFF")),
     *     @OA\Parameter(name="period", in="query", required=false, description="Time period", @OA\Schema(type="string", enum={"7d", "30d", "60d", "90d"}, default="30d")),
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="BIC summary",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/BicAnalyticsRow")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="BIC not found or no data",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="BIC not found or no data")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Clear BIC analytics cache.
     *
     * @OA\Post(
     *     path="/api/admin/analytics/bic/clear-cache",
     *     summary="Clear BIC analytics cache",
     *     description="Clears all cached BIC analytics data across all periods and billing models. Use after data corrections or when fresh calculations are needed.",
     *     tags={"BIC Analytics"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Cache cleared",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Cache cleared")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function clearCache(): JsonResponse
    {
        $this->bicAnalyticsService->clearCache();

        return response()->json(['message' => 'Cache cleared']);
    }

    /**
     * Export BIC analytics as CSV.
     *
     * @OA\Get(
     *     path="/api/admin/analytics/bic/export",
     *     summary="Export BIC analytics as CSV",
     *     description="Downloads BIC analytics data as a CSV file. Accepts the same filters as the index endpoint. Filename reflects applied filters and period.",
     *     tags={"BIC Analytics"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, description="Time period", @OA\Schema(type="string", enum={"7d", "30d", "60d", "90d"}, default="30d")),
     *     @OA\Parameter(name="start_date", in="query", required=false, description="Custom start date", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, description="Custom end date", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="cb_reason_code", in="query", required=false, description="Filter by chargeback reason code", @OA\Schema(type="string", maxLength=50)),
     *     @OA\Response(
     *         response=200,
     *         description="CSV file download",
     *         @OA\MediaType(
     *             mediaType="text/csv",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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
