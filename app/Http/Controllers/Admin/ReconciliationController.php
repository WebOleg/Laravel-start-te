<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingAttempt;
use App\Models\Upload;
use App\Services\ReconciliationService;
use App\Jobs\ProcessReconciliationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReconciliationController extends Controller
{
    public function __construct(
        private ReconciliationService $reconciliationService
    ) {}

    public function reconcileAttempt(BillingAttempt $billingAttempt): JsonResponse
    {
        if (!$billingAttempt->canReconcile()) {
            return response()->json([
                'message' => 'This billing attempt cannot be reconciled',
                'data' => [
                    'id' => $billingAttempt->id,
                    'status' => $billingAttempt->status,
                    'can_reconcile' => false,
                    'reconciliation_attempts' => $billingAttempt->reconciliation_attempts,
                ],
            ], 422);
        }

        $result = $this->reconciliationService->reconcileAttempt($billingAttempt);

        $statusCode = $result['success'] ? 200 : 500;

        return response()->json([
            'message' => $result['success'] 
                ? ($result['changed'] ? 'Status updated' : 'No change detected')
                : 'Reconciliation failed',
            'data' => [
                'id' => $billingAttempt->id,
                'success' => $result['success'],
                'changed' => $result['changed'],
                'previous_status' => $result['previous_status'] ?? null,
                'new_status' => $result['new_status'] ?? $billingAttempt->status,
            ],
        ], $statusCode);
    }

    public function reconcileUpload(Upload $upload): JsonResponse
    {
        if ($upload->isReconciliationProcessing()) {
            return response()->json([
                'message' => 'Reconciliation already in progress',
                'data' => [
                    'upload_id' => $upload->id,
                    'queued' => true,
                    'duplicate' => true,
                ],
            ], 409);
        }

        $eligible = BillingAttempt::where('upload_id', $upload->id)
            ->needsReconciliation()
            ->count();

        if ($eligible === 0) {
            return response()->json([
                'message' => 'No eligible billing attempts to reconcile',
                'data' => [
                    'upload_id' => $upload->id,
                    'eligible' => 0,
                    'queued' => false,
                ],
            ]);
        }

        ProcessReconciliationJob::dispatch($upload->id, 'upload');

        return response()->json([
            'message' => "Reconciliation queued for {$eligible} billing attempts",
            'data' => [
                'upload_id' => $upload->id,
                'eligible' => $eligible,
                'queued' => true,
            ],
        ], 202);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->reconciliationService->getStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function uploadStats(Upload $upload): JsonResponse
    {
        $stats = $this->reconciliationService->getUploadStats($upload);

        return response()->json([
            'data' => array_merge([
                'upload_id' => $upload->id,
                'is_processing' => $upload->isReconciliationProcessing(),
                'reconciliation_status' => $upload->reconciliation_status,
            ], $stats),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'max_age_hours' => 'nullable|integer|min:2|max:720',
            'limit' => 'nullable|integer|min:1|max:10000',
        ]);

        $cacheKey = 'reconciliation_bulk';

        if (Cache::has($cacheKey)) {
            return response()->json([
                'message' => 'Bulk reconciliation already in progress',
                'data' => [
                    'queued' => true,
                    'duplicate' => true,
                ],
            ], 409);
        }

        $maxAgeHours = $validated['max_age_hours'] ?? 720;
        $limit = $validated['limit'] ?? 1000;
        
        // Get all active EMP accounts
        $empAccountIds = \App\Models\EmpAccount::where('is_active', true)->pluck('id')->toArray();

        if (empty($empAccountIds)) {
            return response()->json([
                'message' => 'No active EMP accounts found',
                'data' => [
                    'eligible' => 0,
                    'queued' => false,
                ],
            ]);
        }

        // Count eligible attempts per EMP account
        $eligibleByAccount = [];
        $totalEligible = 0;
        
        foreach ($empAccountIds as $accountId) {
            $count = BillingAttempt::query()
                ->needsReconciliation()
                ->where('emp_account_id', $accountId)
                ->where('created_at', '>', now()->subHours($maxAgeHours))
                ->count();
                
            if ($count > 0) {
                $eligibleByAccount[$accountId] = $count;
                $totalEligible += $count;
            }
        }

        if ($totalEligible === 0) {
            return response()->json([
                'message' => 'No eligible billing attempts to reconcile',
                'data' => [
                    'eligible' => 0,
                    'queued' => false,
                    'emp_accounts_checked' => count($empAccountIds),
                ],
            ]);
        }

        $toProcess = min($totalEligible, $limit);

        Cache::put($cacheKey, [
            'started_at' => now(),
            'emp_accounts' => $eligibleByAccount,
            'total_eligible' => $totalEligible,
        ], now()->addHours(2));

        ProcessReconciliationJob::dispatch(null, 'bulk', $maxAgeHours, $limit);

        return response()->json([
            'message' => "Bulk reconciliation queued for {$toProcess} billing attempts across " . count($eligibleByAccount) . " EMP accounts",
            'data' => [
                'eligible' => $totalEligible,
                'to_process' => $toProcess,
                'max_age_hours' => $maxAgeHours,
                'emp_accounts' => $eligibleByAccount,
                'queued' => true,
            ],
        ], 202);
    }
}
