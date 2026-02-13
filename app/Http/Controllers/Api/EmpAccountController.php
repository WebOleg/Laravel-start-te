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

        $data = $accounts->map(function ($account) use ($usedByAccount, $txCounts) {
            $cap = $account->monthly_cap ? (float) $account->monthly_cap : null;
            $used = $usedByAccount->get($account->id, 0);
            $remaining = $cap !== null ? max(0, $cap - $used) : null;
            $percentage = $cap !== null && $cap > 0 ? round(($used / $cap) * 100, 1) : null;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'slug' => $account->slug,
                'monthly_cap' => $cap,
                'used' => $used,
                'remaining' => $remaining,
                'usage_percentage' => $percentage,
                'tx_count' => $txCounts->get($account->id, 0),
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
