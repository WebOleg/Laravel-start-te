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

class EmpAccountController extends Controller
{
    /**
     * List all EMP accounts.
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
     * Get caps for all accounts with used amounts for a given month.
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

        // Calculate the last 90 days window for chargeback stats
        $cbStartDate = now()->subDays(90);
        $cbEndDate = now();

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

        $stats90d = BillingAttempt::whereBetween('emp_created_at', [$cbStartDate, $cbEndDate])
                    ->whereNotNull('emp_account_id')
                    ->when(!empty($excludedCbCodes), function ($q) use ($excludedCbCodes) {
                        $q->where(function ($subQ) use ($excludedCbCodes) {
                            $subQ->whereNotIn('chargeback_reason_code', $excludedCbCodes)
                                ->orWhereNull('chargeback_reason_code');
                        });
                    })
                    ->groupBy('emp_account_id')
                    ->selectRaw('
                        emp_account_id,
                        SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as approved_amount,
                        SUM(CASE WHEN status = ? AND chargebacked_at BETWEEN ? AND ? THEN amount ELSE 0 END) as chargeback_amount
                    ', [
                        BillingAttempt::STATUS_APPROVED,
                        BillingAttempt::STATUS_CHARGEBACKED,
                        $cbStartDate,
                        $cbEndDate
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
