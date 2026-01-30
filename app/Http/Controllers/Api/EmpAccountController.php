<?php

/**
 * API controller for EMP account management.
 * Handles listing, viewing, and switching active EMP accounts.
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
