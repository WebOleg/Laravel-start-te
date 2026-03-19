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
use OpenApi\Annotations as OA;

class ReconciliationController extends Controller
{
    public function __construct(
        private ReconciliationService $reconciliationService
    ) {}

    /**
     * Reconcile a single billing attempt.
     *
     * @OA\Post(
     *     path="/api/admin/billing-attempts/{billing_attempt}/reconcile",
     *     summary="Reconcile a single billing attempt",
     *     description="Queries the EMP gateway for the current status of a pending billing attempt and updates it accordingly. Only pending attempts with a unique_id and fewer than 10 reconciliation attempts are eligible. Automatically handles chargeback blacklisting and debtor status updates.",
     *     tags={"Reconciliation"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="billing_attempt", in="path", required=true, description="Billing attempt ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Reconciliation completed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Status updated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="changed", type="boolean", example=true),
     *                 @OA\Property(property="previous_status", type="string", example="pending"),
     *                 @OA\Property(property="new_status", type="string", example="approved")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Attempt cannot be reconciled",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="This billing attempt cannot be reconciled"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="status", type="string", example="approved"),
     *                 @OA\Property(property="can_reconcile", type="boolean", example=false),
     *                 @OA\Property(property="reconciliation_attempts", type="integer", example=10)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Billing attempt not found"),
     *     @OA\Response(response=500, description="Reconciliation failed at gateway level")
     * )
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
     * Reconcile all eligible attempts for an upload.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/reconcile",
     *     summary="Reconcile all eligible attempts for an upload",
     *     description="Queues a reconciliation job for all pending billing attempts in the upload that have a unique_id, are older than 2 hours, and have fewer than 10 reconciliation attempts.",
     *     tags={"Reconciliation"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=202,
     *         description="Reconciliation queued",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Reconciliation queued for 25 billing attempts"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="eligible", type="integer", example=25),
     *                 @OA\Property(property="queued", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="No eligible attempts",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No eligible billing attempts to reconcile"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="eligible", type="integer", example=0),
     *                 @OA\Property(property="queued", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Reconciliation already in progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Reconciliation already in progress"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="queued", type="boolean", example=true),
     *                 @OA\Property(property="duplicate", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * Get global reconciliation statistics.
     *
     * @OA\Get(
     *     path="/api/admin/reconciliation/stats",
     *     summary="Get global reconciliation statistics",
     *     description="Returns system-wide reconciliation stats including total pending, stale (48h+), never reconciled, maxed out attempts, and currently eligible count.",
     *     tags={"Reconciliation"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Reconciliation statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="pending_total", type="integer", example=150),
     *                 @OA\Property(property="pending_stale", type="integer", example=20),
     *                 @OA\Property(property="never_reconciled", type="integer", example=30),
     *                 @OA\Property(property="maxed_out_attempts", type="integer", example=5),
     *                 @OA\Property(property="eligible", type="integer", example=100)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function stats(): JsonResponse
    {
        $stats = $this->reconciliationService->getStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get reconciliation stats for a specific upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/reconciliation-stats",
     *     summary="Get reconciliation stats for an upload",
     *     description="Returns reconciliation statistics for a specific upload including total attempts, pending count, eligible for reconciliation, and count reconciled today. Also indicates if reconciliation is currently processing.",
     *     tags={"Reconciliation"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Upload reconciliation stats",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="is_processing", type="boolean", example=false),
     *                 @OA\Property(property="reconciliation_status", type="string", nullable=true, enum={"idle", "processing", "completed", "failed"}, example="completed"),
     *                 @OA\Property(property="total", type="integer", example=500),
     *                 @OA\Property(property="pending", type="integer", example=25),
     *                 @OA\Property(property="eligible", type="integer", example=20),
     *                 @OA\Property(property="reconciled_today", type="integer", example=15)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
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

    /**
     * Run bulk reconciliation across all active EMP accounts.
     *
     * @OA\Post(
     *     path="/api/admin/reconciliation/bulk",
     *     summary="Run bulk reconciliation",
     *     description="Queues a bulk reconciliation job for pending billing attempts across all active EMP accounts. Only one bulk reconciliation can run at a time. Eligible attempts must be pending, have a unique_id, be older than 2 hours, have fewer than 10 reconciliation attempts, and be within the specified max age.",
     *     tags={"Reconciliation"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="max_age_hours", type="integer", minimum=2, maximum=720, description="Only reconcile attempts created within this many hours (default 720 = 30 days)", example=720),
     *             @OA\Property(property="limit", type="integer", minimum=1, maximum=10000, description="Maximum number of attempts to process (default 1000)", example=1000)
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="Bulk reconciliation queued",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Bulk reconciliation queued for 150 billing attempts across 2 EMP accounts"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="eligible", type="integer", example=200),
     *                 @OA\Property(property="to_process", type="integer", example=150),
     *                 @OA\Property(property="max_age_hours", type="integer", example=720),
     *                 @OA\Property(property="emp_accounts", type="object", example={"1": 100, "2": 100}),
     *                 @OA\Property(property="queued", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="No eligible attempts or no active accounts",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No eligible billing attempts to reconcile"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="eligible", type="integer", example=0),
     *                 @OA\Property(property="queued", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Bulk reconciliation already in progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Bulk reconciliation already in progress"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="queued", type="boolean", example=true),
     *                 @OA\Property(property="duplicate", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
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
