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

class BavController extends Controller
{
    public function __construct(
        private readonly IbanBavService $bavService
    ) {}

    /**
     * @return JsonResponse
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
     * @param Upload $upload
     * @return JsonResponse
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
     * @param Request $request
     * @param Upload $upload
     * @return JsonResponse
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
     * @param Upload $upload
     * @return JsonResponse
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
     * @param Upload $upload
     * @return JsonResponse
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
     * @param Request $request
     * @return JsonResponse
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
