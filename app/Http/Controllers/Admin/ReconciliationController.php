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

    /**
     * Reconcile single billing attempt
     * POST /admin/billing-attempts/{billing_attempt}/reconcile
     */
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

    /**
     * Reconcile all pending for upload (async)
     * POST /admin/uploads/{upload}/reconcile
     */
    public function reconcileUpload(Upload $upload): JsonResponse
    {
        $cacheKey = "reconciliation_upload_{$upload->id}";

        if (Cache::has($cacheKey)) {
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

        Cache::put($cacheKey, true, now()->addMinutes(30));

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

    /**
     * Get reconciliation stats
     * GET /admin/reconciliation/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->reconciliationService->getStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get reconciliation stats for upload
     * GET /admin/uploads/{upload}/reconciliation-stats
     */
    public function uploadStats(Upload $upload): JsonResponse
    {
        $stats = $this->reconciliationService->getUploadStats($upload);

        return response()->json([
            'data' => array_merge(['upload_id' => $upload->id], $stats),
        ]);
    }

    /**
     * Bulk reconciliation (admin only)
     * POST /admin/reconciliation/bulk
     */
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

        $maxAgeHours = $validated['max_age_hours'] ?? 24;
        $limit = $validated['limit'] ?? 1000;

        $eligible = BillingAttempt::query()->needsReconciliation()
            ->where('created_at', '<', now()->subHours($maxAgeHours))
            ->count();

        if ($eligible === 0) {
            return response()->json([
                'message' => 'No eligible billing attempts to reconcile',
                'data' => [
                    'eligible' => 0,
                    'queued' => false,
                ],
            ]);
        }

        $toProcess = min($eligible, $limit);

        Cache::put($cacheKey, true, now()->addHours(2));

        ProcessReconciliationJob::dispatch(null, 'bulk', $maxAgeHours, $limit);

        return response()->json([
            'message' => "Bulk reconciliation queued for {$toProcess} billing attempts",
            'data' => [
                'eligible' => $eligible,
                'to_process' => $toProcess,
                'max_age_hours' => $maxAgeHours,
                'queued' => true,
            ],
        ], 202);
    }
}
