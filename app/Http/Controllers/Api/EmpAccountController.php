<?php

/**
 * API controller for EMP account management.
 * Handles listing, viewing, switching active EMP accounts, and caps.
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingAttempt;
use App\Models\EmpAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class EmpAccountController extends Controller
{
    /**
     * List all EMP accounts.
     *
     * @OA\Get(
     *     path="/api/admin/emp/accounts",
     *     summary="List all EMP accounts",
     *     description="Returns all EMP merchant accounts ordered by sort_order. Includes basic info and monthly cap.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of EMP accounts",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Primary Account"),
     *                     @OA\Property(property="slug", type="string", example="primary-account"),
     *                     @OA\Property(property="is_active", type="boolean", example=true),
     *                     @OA\Property(property="sort_order", type="integer", example=1),
     *                     @OA\Property(property="monthly_cap", type="number", format="float", nullable=true, example=500000.00),
     *                     @OA\Property(property="created_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(): JsonResponse
    {
        $accounts = EmpAccount::ordered()->get([
            'id',
            'name',
            'slug',
            'is_active',
            'sort_order',
            'monthly_cap',
            'created_at',
        ]);

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Get currently active EMP account.
     *
     * @OA\Get(
     *     path="/api/admin/emp/accounts/active",
     *     summary="Get currently active EMP account",
     *     description="Returns the EMP account currently set as active. Only one account can be active at a time.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Active EMP account",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Primary Account"),
     *                 @OA\Property(property="slug", type="string", example="primary-account")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No active account configured",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No active EMP account configured")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function active(): JsonResponse
    {
        $account = EmpAccount::getActive();

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'No active EMP account configured',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $account->id,
                'name' => $account->name,
                'slug' => $account->slug,
            ],
        ]);
    }

    /**
     * Set an EMP account as active.
     *
     * @OA\Post(
     *     path="/api/admin/emp/accounts/{empAccount}/activate",
     *     summary="Set an EMP account as active",
     *     description="Deactivates all other EMP accounts and sets the specified one as active.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="empAccount", in="path", required=true, description="EMP Account ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Account activated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Account 'Primary Account' is now active"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Primary Account"),
     *                 @OA\Property(property="slug", type="string", example="primary-account")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Account not found")
     * )
     */
    public function setActive(Request $request, EmpAccount $empAccount): JsonResponse
    {
        $empAccount->setAsActive();

        return response()->json([
            'success' => true,
            'message' => "Account '{$empAccount->name}' is now active",
            'data' => [
                'id' => $empAccount->id,
                'name' => $empAccount->name,
                'slug' => $empAccount->slug,
            ],
        ]);
    }

    /**
     * Get account statistics.
     *
     * @OA\Get(
     *     path="/api/admin/emp/accounts/{empAccount}/stats",
     *     summary="Get EMP account statistics",
     *     description="Returns transaction statistics for a specific EMP account including counts by status, total approved amount, chargeback amount, and chargeback rate.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="empAccount", in="path", required=true, description="EMP Account ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Account statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="account", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Primary Account"),
     *                     @OA\Property(property="slug", type="string", example="primary-account")
     *                 ),
     *                 @OA\Property(property="stats", type="object",
     *                     @OA\Property(property="total_transactions", type="integer", example=5000),
     *                     @OA\Property(property="pending", type="integer", example=50),
     *                     @OA\Property(property="approved", type="integer", example=4000),
     *                     @OA\Property(property="declined", type="integer", example=600),
     *                     @OA\Property(property="chargebacked", type="integer", example=300),
     *                     @OA\Property(property="total_amount", type="number", format="float", example=200000.00),
     *                     @OA\Property(property="chargeback_amount", type="number", format="float", example=15000.00),
     *                     @OA\Property(property="chargeback_rate", type="number", format="float", example=7.5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Account not found")
     * )
     */
    public function stats(EmpAccount $empAccount): JsonResponse
    {
        $stats = [
            'total_transactions' => $empAccount->billingAttempts()->count(),
            'pending' => $empAccount->billingAttempts()->where('status', 'pending')->count(),
            'approved' => $empAccount->billingAttempts()->where('status', 'approved')->count(),
            'declined' => $empAccount->billingAttempts()->where('status', 'declined')->count(),
            'chargebacked' => $empAccount->billingAttempts()->where('status', 'chargebacked')->count(),
            'total_amount' => $empAccount->billingAttempts()->where('status', 'approved')->sum('amount'),
            'chargeback_amount' => $empAccount->billingAttempts()->where('status', 'chargebacked')->sum('amount'),
        ];

        $approvedAmount = (float) $stats['total_amount'];
        $chargebackAmount = (float) $stats['chargeback_amount'];

        $stats['chargeback_rate'] = $approvedAmount > 0
            ? round(($chargebackAmount / $approvedAmount) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'account' => [
                    'id' => $empAccount->id,
                    'name' => $empAccount->name,
                    'slug' => $empAccount->slug,
                ],
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Get caps for all accounts with usage for a given month.
     *
     * @OA\Get(
     *     path="/api/admin/emp/caps",
     *     summary="Get monthly caps and usage for all EMP accounts",
     *     description="Returns monthly cap, used amount, remaining capacity, usage percentage, transaction count, and 90-day chargeback gross percentage for each EMP account. The 90-day window is anchored to the end of the selected month (or today for current/future months).",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="month", in="query", required=false, description="Month (1-12)", @OA\Schema(type="integer", minimum=1, maximum=12)),
     *     @OA\Parameter(name="year", in="query", required=false, description="Year", @OA\Schema(type="integer", minimum=2025, maximum=2030)),
     *     @OA\Response(
     *         response=200,
     *         description="Account caps and usage",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="month", type="integer", example=3),
     *                 @OA\Property(property="year", type="integer", example=2025),
     *                 @OA\Property(property="accounts", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="name", type="string", example="Primary Account"),
     *                         @OA\Property(property="slug", type="string", example="primary-account"),
     *                         @OA\Property(property="monthly_cap", type="number", format="float", nullable=true, example=500000.00),
     *                         @OA\Property(property="used", type="number", format="float", example=350000.00),
     *                         @OA\Property(property="remaining", type="number", format="float", nullable=true, example=150000.00),
     *                         @OA\Property(property="usage_percentage", type="number", format="float", nullable=true, example=70.0),
     *                         @OA\Property(property="tx_count", type="integer", example=7000),
     *                         @OA\Property(property="cbk_gross_percentage_90d", type="number", format="float", example=3.5)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function caps(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2025|max:2030',
        ]);

        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Calculate the last 90 days window for chargeback stats,
        // anchored to the end of the selected month for past months,
        // or today for the current/future month.
        $cbEndDate = $endDate->copy()->min(now());
        $cbStartDate = $cbEndDate->copy()->subDays(90);

        // Calculate chargeback gross % for last 90 days per account
        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

        $accounts = EmpAccount::ordered()->get(['id', 'name', 'slug', 'monthly_cap']);

        $usedByAccount = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED)
            ->whereBetween('emp_created_at', [$startDate, $endDate])
            ->whereNotNull('emp_account_id')
            ->groupBy('emp_account_id')
            ->selectRaw('emp_account_id, SUM(amount) as total_used, COUNT(*) as tx_count')
            ->pluck('total_used', 'emp_account_id')
            ->map(fn ($v) => round((float) $v, 2));

        $txCounts = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED)
            ->whereBetween('emp_created_at', [$startDate, $endDate])
            ->whereNotNull('emp_account_id')
            ->groupBy('emp_account_id')
            ->selectRaw('emp_account_id, COUNT(*) as tx_count')
            ->pluck('tx_count', 'emp_account_id');

        $stats90d = BillingAttempt::whereNotNull('emp_account_id')
                    ->whereNotNull('upload_id')
                    ->when(!empty($excludedCbCodes), function ($q) use ($excludedCbCodes) {
                        $q->where(function ($subQ) use ($excludedCbCodes) {
                            $subQ->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                                ->orWhereNull('chargeback_reason_code');
                        });
                    })
                    ->groupBy('emp_account_id')
                    ->selectRaw('
                        emp_account_id,
                        SUM(CASE WHEN status = ? AND emp_created_at BETWEEN ? AND ? THEN amount ELSE 0 END) as approved_amount,
                        SUM(CASE WHEN status = ? AND chargebacked_at BETWEEN ? AND ? THEN amount ELSE 0 END) as chargeback_amount
                    ', [
                        BillingAttempt::STATUS_APPROVED,
                        $cbStartDate,
                        $cbEndDate,
                        BillingAttempt::STATUS_CHARGEBACKED,
                        $cbStartDate,
                        $cbEndDate,
                    ])
                    ->get()
                    ->keyBy('emp_account_id');

        $data = $accounts->map(function ($account) use ($usedByAccount, $txCounts, $stats90d) {
            $cap = $account->monthly_cap ? (float) $account->monthly_cap : null;
            $used = $usedByAccount->get($account->id, 0);
            $remaining = $cap !== null ? max(0, $cap - $used) : null;
            $percentage = $cap !== null && $cap > 0 ? round(($used / $cap) * 100, 1) : null;

            // Calculate chargeback gross % for last 90 days
            $stats = $stats90d->get($account->id);
            $approvedAmount90d = $stats ? (float) $stats->approved_amount : 0;
            $chargebackAmount90d = $stats ? (float) $stats->chargeback_amount : 0;
            $totalAmount90d = $approvedAmount90d + $chargebackAmount90d;
            $cbGrossPercentage90d = $totalAmount90d > 0
                ? round(($chargebackAmount90d / $totalAmount90d) * 100, 2)
                : 0;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'slug' => $account->slug,
                'monthly_cap' => $cap,
                'used' => $used,
                'remaining' => $remaining,
                'usage_percentage' => $percentage,
                'tx_count' => $txCounts->get($account->id, 0),
                'cbk_gross_percentage_90d' => $cbGrossPercentage90d,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'accounts' => $data,
            ],
        ]);
    }

    /**
     * Update monthly cap for an account.
     *
     * @OA\Put(
     *     path="/api/admin/emp/accounts/{empAccount}/cap",
     *     summary="Update monthly cap for an EMP account",
     *     description="Sets the monthly billing cap for a specific EMP account. Set to 0 to remove the cap.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="empAccount", in="path", required=true, description="EMP Account ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"monthly_cap"},
     *             @OA\Property(property="monthly_cap", type="number", format="float", minimum=0, maximum=99999999.99, example=500000.00)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cap updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cap for 'Primary Account' updated to €500,000.00"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Primary Account"),
     *                 @OA\Property(property="monthly_cap", type="number", format="float", example=500000.00)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Account not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function updateCap(Request $request, EmpAccount $empAccount): JsonResponse
    {
        $request->validate([
            'monthly_cap' => 'required|numeric|min:0|max:99999999.99',
        ]);

        $empAccount->update(['monthly_cap' => $request->input('monthly_cap')]);

        return response()->json([
            'success' => true,
            'message' => "Cap for '{$empAccount->name}' updated to €" . number_format($request->input('monthly_cap'), 2),
            'data' => [
                'id' => $empAccount->id,
                'name' => $empAccount->name,
                'monthly_cap' => (float) $empAccount->monthly_cap,
            ],
        ]);
    }
}
