<?php

/**
 * Controller for statistics endpoints.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ChargebackStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class StatsController extends Controller
{
    public function __construct(
        private ChargebackStatsService $chargebackStatsService
    ) {}

    /**
     * Get chargeback rates by country.
     *
     * @OA\Get(
     *     path="/api/admin/stats/chargeback-rates",
     *     summary="Get chargeback rates by country",
     *     description="Returns transaction statistics grouped by country with chargeback rates. CB rate formula: chargebacks / approved * 100 (EMP-aligned). Supports period, monthly, billing model, and account filtering. When date_mode is 'chargeback', rates are not calculated (only counts).",
     *     tags={"Stats"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"24h", "7d", "30d", "90d", "all"})),
     *     @OA\Parameter(name="month", in="query", required=false, description="Month (1-12), used with year for monthly filtering", @OA\Schema(type="integer", minimum=1, maximum=12)),
     *     @OA\Parameter(name="year", in="query", required=false, description="Year, used with month", @OA\Schema(type="integer", minimum=2020, maximum=2100)),
     *     @OA\Parameter(name="date_mode", in="query", required=false, description="Filter by transaction date or chargeback date", @OA\Schema(type="string", enum={"transaction", "chargeback"}, default="transaction")),
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Chargeback rate statistics by country",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="model", type="string", example="all"),
     *                 @OA\Property(property="emp_account_id", type="integer", nullable=true),
     *                 @OA\Property(property="tether_instance_id", type="integer", nullable=true),
     *                 @OA\Property(property="start_date", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="end_date", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="month", type="integer", nullable=true),
     *                 @OA\Property(property="year", type="integer", nullable=true),
     *                 @OA\Property(property="date_mode", type="string", example="transaction"),
     *                 @OA\Property(property="threshold", type="number", format="float", example=25),
     *                 @OA\Property(property="countries", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="country", type="string", example="DE"),
     *                         @OA\Property(property="total", type="integer", example=5000),
     *                         @OA\Property(property="approved", type="integer", example=4000),
     *                         @OA\Property(property="declined", type="integer", example=500),
     *                         @OA\Property(property="errors", type="integer", example=200),
     *                         @OA\Property(property="chargebacks", type="integer", example=300),
     *                         @OA\Property(property="chargeback_amount", type="number", format="float", example=15000.00),
     *                         @OA\Property(property="cb_rate_total", type="number", format="float", nullable=true, example=6.0),
     *                         @OA\Property(property="cb_rate_approved", type="number", format="float", nullable=true, example=7.5),
     *                         @OA\Property(property="alert", type="boolean", example=false)
     *                     )
     *                 ),
     *                 @OA\Property(property="totals", type="object",
     *                     @OA\Property(property="total", type="integer", example=12000),
     *                     @OA\Property(property="approved", type="integer", example=9500),
     *                     @OA\Property(property="declined", type="integer", example=1200),
     *                     @OA\Property(property="errors", type="integer", example=500),
     *                     @OA\Property(property="chargebacks", type="integer", example=800),
     *                     @OA\Property(property="chargeback_amount", type="number", format="float", example=40000.00),
     *                     @OA\Property(property="approved_amount", type="number", format="float", example=475000.00),
     *                     @OA\Property(property="cb_rate_total", type="number", format="float", nullable=true, example=6.67),
     *                     @OA\Property(property="cb_rate_approved", type="number", format="float", nullable=true, example=8.42),
     *                     @OA\Property(property="cb_rate_amount_approved", type="number", format="float", nullable=true, example=8.42),
     *                     @OA\Property(property="alert", type="boolean", example=false),
     *                     @OA\Property(property="cb_alert_amount_approved", type="boolean", example=false)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get chargeback reason code breakdown.
     *
     * @OA\Get(
     *     path="/api/admin/stats/chargeback-codes",
     *     summary="Get chargeback reason code breakdown",
     *     description="Returns chargeback counts and amounts grouped by reason code. Excludes configured reason codes. Results are cached.",
     *     tags={"Stats"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"24h", "7d", "30d", "90d", "all"})),
     *     @OA\Parameter(name="month", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=12)),
     *     @OA\Parameter(name="year", in="query", required=false, @OA\Schema(type="integer", minimum=2020, maximum=2100)),
     *     @OA\Parameter(name="date_mode", in="query", required=false, @OA\Schema(type="string", enum={"transaction", "chargeback"}, default="transaction")),
     *     @OA\Parameter(name="model", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Chargeback codes breakdown",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="model", type="string", example="all"),
     *                 @OA\Property(property="date_mode", type="string", example="transaction"),
     *                 @OA\Property(property="codes", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="chargeback_code", type="string", nullable=true, example="MD06"),
     *                         @OA\Property(property="total_amount", type="number", format="float", example=5000.00),
     *                         @OA\Property(property="occurrences", type="integer", example=100)
     *                     )
     *                 ),
     *                 @OA\Property(property="totals", type="object",
     *                     @OA\Property(property="total_amount", type="number", format="float", example=40000.00),
     *                     @OA\Property(property="occurrences", type="integer", example=800)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get chargeback stats by bank.
     *
     * @OA\Get(
     *     path="/api/admin/stats/chargeback-banks",
     *     summary="Get chargeback statistics by bank",
     *     description="Returns transaction and chargeback statistics grouped by bank name (from VOP logs). Includes per-bank chargeback rates and alert flags when rates exceed the configured threshold.",
     *     tags={"Stats"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"24h", "7d", "30d", "90d", "all"})),
     *     @OA\Parameter(name="month", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=12)),
     *     @OA\Parameter(name="year", in="query", required=false, @OA\Schema(type="integer", minimum=2020, maximum=2100)),
     *     @OA\Parameter(name="date_mode", in="query", required=false, @OA\Schema(type="string", enum={"transaction", "chargeback"}, default="transaction")),
     *     @OA\Parameter(name="model", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Chargeback stats by bank",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="model", type="string", example="all"),
     *                 @OA\Property(property="date_mode", type="string", example="transaction"),
     *                 @OA\Property(property="banks", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="bank_name", type="string", example="Deutsche Bank"),
     *                         @OA\Property(property="total", type="integer", example=2000),
     *                         @OA\Property(property="approved", type="integer", example=1700),
     *                         @OA\Property(property="total_amount", type="number", format="float", example=100000.00),
     *                         @OA\Property(property="chargebacks", type="integer", example=120),
     *                         @OA\Property(property="chargeback_amount", type="number", format="float", example=6000.00),
     *                         @OA\Property(property="cb_rate", type="number", format="float", nullable=true, example=7.06),
     *                         @OA\Property(property="alert", type="boolean", example=false)
     *                     )
     *                 ),
     *                 @OA\Property(property="totals", type="object",
     *                     @OA\Property(property="total", type="integer", example=12000),
     *                     @OA\Property(property="approved", type="integer", example=9500),
     *                     @OA\Property(property="total_amount", type="number", format="float", example=600000.00),
     *                     @OA\Property(property="chargebacks", type="integer", example=800),
     *                     @OA\Property(property="chargeback_amount", type="number", format="float", example=40000.00),
     *                     @OA\Property(property="cb_rate", type="number", format="float", nullable=true, example=8.42),
     *                     @OA\Property(property="alert", type="boolean", example=false)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Get chargeback stats by price point.
     *
     * @OA\Get(
     *     path="/api/admin/stats/price-points",
     *     summary="Get chargeback statistics by price point",
     *     description="Returns transaction and chargeback statistics grouped by billing amount. Shows which price points have the highest chargeback rates. Includes approved and chargeback volumes.",
     *     tags={"Stats"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"24h", "7d", "30d", "90d", "all"}, default="30d")),
     *     @OA\Parameter(name="month", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=12)),
     *     @OA\Parameter(name="year", in="query", required=false, @OA\Schema(type="integer", minimum=2020, maximum=2100)),
     *     @OA\Parameter(name="date_mode", in="query", required=false, @OA\Schema(type="string", enum={"transaction", "chargeback"}, default="transaction")),
     *     @OA\Parameter(name="model", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Price point statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="period", type="string", example="30d"),
     *                 @OA\Property(property="model", type="string", example="all"),
     *                 @OA\Property(property="date_mode", type="string", example="transaction"),
     *                 @OA\Property(property="threshold", type="number", format="float", example=25),
     *                 @OA\Property(property="price_points", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="price_point", type="number", format="float", example=49.99),
     *                         @OA\Property(property="total", type="integer", example=3000),
     *                         @OA\Property(property="approved", type="integer", example=2500),
     *                         @OA\Property(property="declined", type="integer", example=300),
     *                         @OA\Property(property="errors", type="integer", example=50),
     *                         @OA\Property(property="chargebacks", type="integer", example=150),
     *                         @OA\Property(property="approved_volume", type="number", format="float", example=124975.00),
     *                         @OA\Property(property="chargeback_volume", type="number", format="float", example=7498.50),
     *                         @OA\Property(property="cb_rate", type="number", format="float", nullable=true, example=6.0),
     *                         @OA\Property(property="alert", type="boolean", example=false)
     *                     )
     *                 ),
     *                 @OA\Property(property="totals", type="object",
     *                     @OA\Property(property="total", type="integer", example=12000),
     *                     @OA\Property(property="approved", type="integer", example=9500),
     *                     @OA\Property(property="declined", type="integer", example=1200),
     *                     @OA\Property(property="errors", type="integer", example=500),
     *                     @OA\Property(property="chargebacks", type="integer", example=800),
     *                     @OA\Property(property="approved_volume", type="number", format="float", example=475000.00),
     *                     @OA\Property(property="chargeback_volume", type="number", format="float", example=40000.00),
     *                     @OA\Property(property="cb_rate", type="number", format="float", nullable=true, example=8.42),
     *                     @OA\Property(property="alert", type="boolean", example=false)
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

    /**
     * Get all-time chargeback code statistics.
     *
     * @OA\Get(
     *     path="/api/admin/stats/chargeback-all-time",
     *     summary="Get all-time chargeback code statistics",
     *     description="Returns chargeback statistics grouped by reason code across all time, with percentage breakdowns relative to total chargebacks and total approved+chargebacked records. Excludes configured reason codes.",
     *     tags={"Stats"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="model", in="query", required=false, description="Billing model filter", @OA\Schema(type="string")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="All-time chargeback code statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="codes", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="chargeback_code", type="string", nullable=true, example="MD06"),
     *                         @OA\Property(property="chargeback_reason", type="string", nullable=true, example="Refund request by end customer"),
     *                         @OA\Property(property="total_amount", type="number", format="float", example=15000.00),
     *                         @OA\Property(property="occurrences", type="integer", example=300),
     *                         @OA\Property(property="cb_count_percentage", type="number", format="float", example=37.5),
     *                         @OA\Property(property="total_count_percentage", type="number", format="float", example=2.5),
     *                         @OA\Property(property="cb_amount_percentage", type="number", format="float", example=37.5),
     *                         @OA\Property(property="total_amount_percentage", type="number", format="float", example=2.5),
     *                         @OA\Property(property="last_occurrence", type="string", format="date-time", nullable=true)
     *                     )
     *                 ),
     *                 @OA\Property(property="totals", type="object",
     *                     @OA\Property(property="total_amount", type="number", format="float", example=40000.00),
     *                     @OA\Property(property="occurrences", type="integer", example=800),
     *                     @OA\Property(property="total_records", type="integer", example=12000),
     *                     @OA\Property(property="total_records_amount", type="number", format="float", example=600000.00)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function chargebackAllTime(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'nullable|string',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
        ]);

        $stats = $this->chargebackStatsService->getChargebackAllTimeStats(
            $request->input('model'),
            $request->input('emp_account_id'),
            $request->input('tether_instance_id')
        );

        return response()->json(['data' => $stats]);
    }
}
