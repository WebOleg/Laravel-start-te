<?php
/**
 * BAV (Bank Account Verification) Controller.
 * Handles separate BAV verification flow for uploads.
 */
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBavJob;
use App\Models\BavCredit;
use App\Models\Upload;
use App\Services\IbanBavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class BavController extends Controller
{
    public function __construct(
        private readonly IbanBavService $bavService
    ) {}

    /**
     * Get current BAV credit balance.
     *
     * @OA\Get(
     *     path="/api/admin/bav/balance",
     *     summary="Get BAV credit balance",
     *     description="Returns the current BAV credit balance including remaining credits, total credits, expiration status, and usage history.",
     *     tags={"BAV"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Credit balance info",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="credits_total", type="integer", example=2500),
     *                 @OA\Property(property="credits_used", type="integer", example=1493),
     *                 @OA\Property(property="credits_remaining", type="integer", example=1007),
     *                 @OA\Property(property="is_expired", type="boolean", example=false),
     *                 @OA\Property(property="expires_at", type="string", format="date-time", nullable=true, example="2025-12-31T23:59:59+00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function getBalance(): JsonResponse
    {
        $credit = BavCredit::getInstance();

        return response()->json([
            'success' => true,
            'data' => $credit->getBalanceInfo(),
        ]);
    }

    /**
     * Get BAV stats for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/bav/stats",
     *     summary="Get BAV verification stats for an upload",
     *     description="Returns the number of eligible debtors for BAV verification, current credit balance, and whether verification can be started.",
     *     tags={"BAV"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="upload",
     *         in="path",
     *         required=true,
     *         description="Upload ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="BAV stats for the upload",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="eligible_count", type="integer", example=350),
     *                 @OA\Property(property="credits_remaining", type="integer", example=1007),
     *                 @OA\Property(property="credits_total", type="integer", example=2500),
     *                 @OA\Property(property="is_expired", type="boolean", example=false),
     *                 @OA\Property(property="bav_status", type="string", enum={"idle", "processing", "completed", "failed"}, example="idle"),
     *                 @OA\Property(property="can_start", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function getStats(Upload $upload): JsonResponse
    {
        $eligibleCount = $upload->getBavEligibleCount();
        $credit = BavCredit::getInstance();

        return response()->json([
            'success' => true,
            'data' => [
                'upload_id' => $upload->id,
                'eligible_count' => $eligibleCount,
                'credits_remaining' => $credit->getRemaining(),
                'credits_total' => $credit->credits_total,
                'is_expired' => $credit->isExpired(),
                'bav_status' => $upload->bav_status ?? 'idle',
                'can_start' => $eligibleCount > 0 && $credit->hasCredits() && !$credit->isExpired(),
            ],
        ]);
    }

    /**
     * Start BAV verification for an upload.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/bav/start",
     *     summary="Start BAV verification for an upload",
     *     description="Starts BAV verification for eligible debtors in the upload. Debtors are chunked into jobs and dispatched as a Laravel batch. Requires sufficient BAV credits and non-expired subscription.",
     *     tags={"BAV"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="upload",
     *         in="path",
     *         required=true,
     *         description="Upload ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"limit"},
     *             @OA\Property(property="limit", type="integer", minimum=1, maximum=10000, description="Number of debtors to verify", example=500)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="BAV verification started",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="batch_id", type="string", example="9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d"),
     *                 @OA\Property(property="total_count", type="integer", example=500),
     *                 @OA\Property(property="message", type="string", example="Started BAV verification for 500 debtors")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Verification cannot be started",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Insufficient BAV credits. Available: 50, Requested: 500")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function startVerification(Request $request, Upload $upload): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'required|integer|min:1|max:10000',
        ]);

        $limit = (int) $validated['limit'];

        if ($upload->bav_status === 'processing') {
            return response()->json([
                'success' => false,
                'error' => 'BAV verification already in progress',
            ], 422);
        }

        $credit = BavCredit::getInstance();

        if ($credit->isExpired()) {
            return response()->json([
                'success' => false,
                'error' => 'BAV credits have expired. Please renew subscription.',
            ], 422);
        }

        if (!$credit->hasCredits($limit)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient BAV credits. Available: ' . $credit->getRemaining() . ', Requested: ' . $limit,
            ], 422);
        }

        $eligibleIds = $upload->getBavEligibleDebtorIds($limit);
        $actualCount = count($eligibleIds);

        if ($actualCount === 0) {
            return response()->json([
                'success' => false,
                'error' => 'No eligible debtors for BAV verification',
            ], 422);
        }

        $batchId = (string) Str::uuid();
        $chunkSize = (int) config('services.iban.bav_chunk_size', 50);
        $chunks = array_chunk($eligibleIds, $chunkSize);

        $jobs = [];
        foreach ($chunks as $chunkIds) {
            $jobs[] = new ProcessBavJob($upload->id, $chunkIds, $batchId);
        }

        $batch = Bus::batch($jobs)
            ->name("BAV Upload #{$upload->id}")
            ->allowFailures()
            ->dispatch();

        $upload->startBav($batch->id, $actualCount);

        Cache::put("bav_progress_{$upload->id}", [
            'status' => 'processing',
            'total' => $actualCount,
            'processed' => 0,
            'started_at' => now()->toISOString(),
        ], 3600);

        return response()->json([
            'success' => true,
            'data' => [
                'batch_id' => $batch->id,
                'total_count' => $actualCount,
                'message' => "Started BAV verification for {$actualCount} debtors",
            ],
        ]);
    }

    /**
     * Get BAV verification status for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/bav/status",
     *     summary="Get BAV verification progress for an upload",
     *     description="Returns the current BAV verification progress including processed count, total count, and status. Combines database state with cached progress data.",
     *     tags={"BAV"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="upload",
     *         in="path",
     *         required=true,
     *         description="Upload ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="BAV verification progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="status", type="string", enum={"idle", "processing", "completed", "failed"}, example="processing"),
     *                 @OA\Property(property="total", type="integer", example=500),
     *                 @OA\Property(property="processed", type="integer", example=230),
     *                 @OA\Property(property="percentage", type="number", format="float", example=46.0)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function getStatus(Upload $upload): JsonResponse
    {
        $progress = $upload->getBavProgress();

        $cached = Cache::get("bav_progress_{$upload->id}");
        if ($cached) {
            $progress['processed'] = $cached['processed'] ?? $progress['processed'];
        }

        return response()->json([
            'success' => true,
            'data' => $progress,
        ]);
    }

    /**
     * Cancel BAV verification for an upload.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/bav/cancel",
     *     summary="Cancel BAV verification for an upload",
     *     description="Cancels an in-progress BAV verification by cancelling the Laravel batch and marking the upload BAV status as failed. Clears cached progress data.",
     *     tags={"BAV"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="upload",
     *         in="path",
     *         required=true,
     *         description="Upload ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Verification cancelled",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="BAV verification cancelled")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="No verification in progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="No BAV verification in progress")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function cancelVerification(Upload $upload): JsonResponse
    {
        if ($upload->bav_status !== 'processing') {
            return response()->json([
                'success' => false,
                'error' => 'No BAV verification in progress',
            ], 422);
        }

        if ($upload->bav_batch_id) {
            $batch = Bus::findBatch($upload->bav_batch_id);
            if ($batch) {
                $batch->cancel();
            }
        }

        $upload->markBavFailed();
        Cache::forget("bav_progress_{$upload->id}");

        return response()->json([
            'success' => true,
            'message' => 'BAV verification cancelled',
        ]);
    }

    /**
     * Manual credit adjustment (admin only).
     *
     * @OA\Post(
     *     path="/api/admin/bav/adjust",
     *     summary="Adjust BAV credits manually",
     *     description="Allows an admin to manually adjust the total and used BAV credits. Logs the adjustment with the requesting user's email.",
     *     tags={"BAV"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"credits_total", "credits_used"},
     *             @OA\Property(property="credits_total", type="integer", minimum=0, description="New total credits value", example=5000),
     *             @OA\Property(property="credits_used", type="integer", minimum=0, description="New used credits value", example=1000)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Credits adjusted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="credits_total", type="integer", example=5000),
     *                 @OA\Property(property="credits_used", type="integer", example=1000),
     *                 @OA\Property(property="credits_remaining", type="integer", example=4000),
     *                 @OA\Property(property="is_expired", type="boolean", example=false),
     *                 @OA\Property(property="expires_at", type="string", format="date-time", nullable=true, example=null)
     *             ),
     *             @OA\Property(property="message", type="string", example="Credits adjusted successfully")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function adjustCredits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credits_total' => 'required|integer|min:0',
            'credits_used' => 'required|integer|min:0',
        ]);

        $credit = BavCredit::getInstance();
        $credit->adjust(
            $validated['credits_total'],
            $validated['credits_used'],
            $request->user()?->email ?? 'system'
        );

        return response()->json([
            'success' => true,
            'data' => $credit->fresh()->getBalanceInfo(),
            'message' => 'Credits adjusted successfully',
        ]);
    }
}
