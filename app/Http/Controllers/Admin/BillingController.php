<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBillingJob;
use App\Jobs\VoidUploadJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\BillingResyncService;
use App\Services\Dto\ResyncEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingResyncService $resyncService,
    ) {}

    /**
     * Void all successful transactions for an upload.
     *
     * @OA\Post(
     *     path="/api/admin/billing/{upload}/void",
     *     summary="Void transactions for an upload",
     *     description="Queues a void job for all approved/pending transactions of an upload. Only transactions within the last 24 hours can be voided. For resynced uploads, only the latest run's transactions are voided.",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=202,
     *         description="Void process queued",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Void process queued for 150 transactions."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="queued_count", type="integer", example=150),
     *                 @OA\Property(property="is_resync", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Cannot void (too old or no eligible transactions)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Cannot void transactions older than 24 hours. Please use Refund instead.")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function void(Upload $upload): JsonResponse
    {
        $lastActivity = $upload->billing_completed_at ?? $upload->billing_started_at;
        if ($lastActivity && $lastActivity->diffInHours(now()) > 24) {
            return response()->json([
                'message' => 'Cannot void transactions older than 24 hours. Please use Refund instead.',
            ], 422);
        }

        // Get eligible attempts (scoped to latest run for resyncs)
        $count = $this->resyncService->getVoidableAttempts($upload)->count();

        if ($count === 0) {
            return response()->json([
                'message' => 'No eligible transactions found to void.',
            ], 422);
        }

        VoidUploadJob::dispatch($upload);

        $upload->update([
            'billing_status' => Upload::STATUS_VOIDING,
            'status' => Upload::STATUS_VOIDING
        ]);

        return response()->json([
            'message' => "Void process queued for {$count} transactions.",
            'data' => [
                'queued_count' => $count,
                'is_resync' => !empty($upload->billing_runs),
            ]
        ], 202);
    }

    /**
     * Cancel an active billing sync or resync.
     *
     * @OA\Post(
     *     path="/api/admin/billing/{upload}/cancel",
     *     summary="Cancel active billing for an upload",
     *     description="Sets a termination signal that running billing jobs check to stop execution. Works for both initial syncs and resyncs. Clears all sync lock keys.",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Termination signal sent",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Termination signal sent. The sync will stop shortly."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="billing_status", type="string", example="cancelling"),
     *                 @OA\Property(property="is_resync", type="boolean", example=false),
     *                 @OA\Property(property="signal_sent_at", type="string", format="date-time", example="2025-03-15T10:30:00+00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="No active billing to cancel",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No active billing to cancel.")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function cancel(Upload $upload): JsonResponse
    {
        // Guard: only allow cancel when billing is actually running
        if (!in_array($upload->billing_status, [Upload::JOB_PROCESSING, Upload::STATUS_CANCELLING])) {
            return response()->json([
                'message' => 'No active billing to cancel.',
            ], 422);
        }

        $isResync = $this->resyncService->isResyncInProgress($upload);

        if ($isResync) {
            $this->resyncService->cancelResync($upload);
        } else {
            $upload->update([
                'billing_status' => Upload::STATUS_CANCELLING,
                'status' => Upload::STATUS_CANCELLING,
            ]);

            Cache::put("billing_sync_stop_{$upload->id}", true, 3600);

            // Clear sync lock keys so duplicate-dispatch check doesn't block future syncs
            foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
                Cache::forget("billing_sync_{$upload->id}_{$model}");
            }
        }

        return response()->json([
            'message' => $isResync
                ? 'Resync termination signal sent. The resync will stop shortly.'
                : 'Termination signal sent. The sync will stop shortly.',
            'data' => [
                'upload_id' => $upload->id,
                'billing_status' => Upload::STATUS_CANCELLING,
                'is_resync' => $isResync,
                'signal_sent_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Start billing sync or resync for an upload.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/sync",
     *     summary="Start billing sync for an upload",
     *     description="Dispatches billing jobs for eligible debtors. Supports billing model filtering (all, legacy, flywheel, recovery). For uploads with cooldown disabled (is_30d_cool=false), triggers resync flow with automatic run archival. Validates VOP completion before allowing billing. Prevents duplicate syncs via cache locks. Respects billing caps and excludes conflicting billing model debtors.",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="debtor_type", type="string", description="Billing model to sync", enum={"all", "legacy", "flywheel", "recovery"}, example="all")
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="Billing queued",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Billing queued for 500 debtors (all model)"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="eligible", type="integer", example=500),
     *                 @OA\Property(property="queued", type="boolean", example=true),
     *                 @OA\Property(property="model", type="string", example="all"),
     *                 @OA\Property(property="capped", type="integer", example=10)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="No eligible debtors or resync completed without dispatch",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No eligible debtors to bill"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="eligible", type="integer", example=0),
     *                 @OA\Property(property="capped", type="integer", example=0),
     *                 @OA\Property(property="queued", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Billing already in progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Billing already in progress"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="queued", type="boolean", example=true),
     *                 @OA\Property(property="duplicate", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Cannot start billing (VOP incomplete, voiding, invalid model, resync denied)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="VOP verification must be completed before billing. 50 debtors pending verification."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function sync(Upload $upload, Request $request): JsonResponse
    {
        $debtorType = $request->input('debtor_type') ?: DebtorProfile::ALL;

        $lockKey = "billing_sync_{$upload->id}_{$debtorType}";

        // Block sync/resync if upload is voiding or already cancelled
        if (in_array($upload->billing_status, [Upload::STATUS_VOIDING, Upload::STATUS_CANCELLED]) ||
            in_array($upload->status, [Upload::STATUS_VOIDING, Upload::STATUS_CANCELLED])) {
            return response()->json([
                'message' => 'Cannot start billing while upload is voiding or has been cancelled.',
                'data' => [
                    'upload_id' => $upload->id,
                    'status' => $upload->status,
                    'billing_status' => $upload->billing_status,
                ],
            ], 422);
        }

        // Validate: Allow 'all' OR specific models
        $validTypes = array_merge([DebtorProfile::ALL], DebtorProfile::BILLING_MODELS);

        if (!in_array($debtorType, $validTypes)) {
            return response()->json(['message' => 'Invalid billing model provided'], 422);
        }

        if (Cache::has($lockKey)) {
            return response()->json([
                'message' => 'Billing already in progress',
                'data' => [
                    'upload_id' => $upload->id,
                    'queued' => true,
                    'duplicate' => true,
                ],
            ], 409);
        }

        $vopCheck = $this->checkVopCompleted($upload);
        if (!$vopCheck['passed']) {
            return response()->json([
                'message' => $vopCheck['message'],
                'data' => [
                    'upload_id' => $upload->id,
                    'queued' => false,
                    'vop_required' => true,
                    'vop_total_eligible' => $vopCheck['total_eligible'],
                    'vop_verified' => $vopCheck['verified'],
                    'vop_pending' => $vopCheck['pending'],
                ],
            ], 422);
        }

        // Resync mode: cooldown is explicitly OFF for this upload.
        // Delegate all resync logic to the dedicated service.
        if ($upload->is_30d_cool === false) {
            $eligibility = $this->resyncService->canResync($upload, $debtorType);

            if ($eligibility->denied()) {
                return response()->json([
                    'message' => $eligibility->reason,
                    'data' => [
                        'upload_id'    => $upload->id,
                        'queued'       => false,
                        'resync_count' => count($upload->billing_runs ?? []),
                        'max_resync'   => Upload::MAX_RESYNC_ATTEMPTS,
                    ],
                ], $this->resyncHttpStatus($eligibility));
            }

            $result = $this->resyncService->executeResync($upload);

            return response()->json([
                'message' => "Resync queued for {$result->eligibleCount} debtors",
                'data' => [
                    'upload_id'      => $upload->id,
                    'eligible'       => $result->eligibleCount,
                    'reset_count'    => $result->resetCount,
                    'archived'       => $result->archived,
                    'queued'         => $result->dispatched,
                    'model'          => DebtorProfile::MODEL_LEGACY,
                ],
            ], $result->dispatched ? 202 : 200);
        }

        // If we are syncing 'flywheel', we MUST NOT process IBANs that are already 'recovery', and vice versa.
        $conflictingModel = match($debtorType) {
            DebtorProfile::MODEL_FLYWHEEL => DebtorProfile::MODEL_RECOVERY,
            DebtorProfile::MODEL_RECOVERY => DebtorProfile::MODEL_FLYWHEEL,
            default => null
        };

        $query = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_UPLOADED);

        if ($debtorType !== DebtorProfile::ALL) {
            $query->where(function ($q) use ($debtorType) {
                $q->whereHas('debtorProfile', function ($p) use ($debtorType) {
                    $p->where('billing_model', $debtorType);
                })
                    ->orWhereDoesntHave('debtorProfile');
            });
        }

        $query->where(function ($q) {
            $q->whereHas('debtorProfile', function ($p) {
                $p->where('billing_model', '!=', DebtorProfile::MODEL_LEGACY);
            })
                ->orWhereDoesntHave('billingAttempts', function ($ba) {
                    $ba->whereIn('status', [
                        BillingAttempt::STATUS_PENDING,
                        BillingAttempt::STATUS_APPROVED,
                    ]);
                });
        });

        $query->where(function ($q) {
            $q->whereDoesntHave('vopLogs')
              ->orWhereHas('vopLogs', function ($vopQuery) {
                  $vopQuery->whereIn('result', [
                      VopLog::RESULT_VERIFIED,
                      VopLog::RESULT_LIKELY_VERIFIED,
                  ]);
              });
        });

        if ($conflictingModel) {
            $query->whereNotExists(function ($subQuery) use ($conflictingModel) {
                $subQuery->select('id')
                    ->from('debtor_profiles')
                    ->whereColumn('debtor_profiles.iban_hash', 'debtors.iban_hash')
                    ->where('debtor_profiles.billing_model', $conflictingModel);
            });
        }

        if ($upload->max_billing_amount !== null && (float) $upload->max_billing_amount > 0) {
            $maxAmount = (float) $upload->max_billing_amount;
            $query->withinBillingCap($maxAmount);
        }

        $eligibleCount = $query->count();
        $cappedCount = 0;

        if ($upload->max_billing_amount !== null && (float) $upload->max_billing_amount > 0) {
            $cappedCount = Debtor::where('upload_id', $upload->id)
                ->where('validation_status', Debtor::VALIDATION_VALID)
                ->where('status', Debtor::STATUS_UPLOADED)
                ->exceedsBillingCap((float) $upload->max_billing_amount)
                ->count();
        }

        if ($eligibleCount === 0) {
            $message = $conflictingModel
                ? "No eligible debtors found (duplicates or {$conflictingModel} conflicts removed)"
                : 'No eligible debtors to bill';

            if ($cappedCount > 0) {
                $message = "No eligible debtors to bill. {$cappedCount} debtors reached billing cap ({$upload->max_billing_amount} EUR).";
            }

            return response()->json([
                'message' => $message,
                'data' => [
                    'upload_id' => $upload->id,
                    'eligible' => 0,
                    'capped' => $cappedCount,
                    'queued' => false,
                ],
            ]);
        }

        Cache::put($lockKey, true, 300);
        ProcessBillingJob::dispatch($upload, null, $debtorType);

        $responseData = [
            'upload_id' => $upload->id,
            'eligible' => $eligibleCount,
            'queued' => true,
            'model' => $debtorType,
        ];

        $message = "Billing queued for {$eligibleCount} debtors ({$debtorType} model)";

        if ($cappedCount > 0) {
            $responseData['capped'] = $cappedCount;
            $message .= ". {$cappedCount} debtors skipped (billing cap reached).";
        }

        return response()->json([
            'message' => $message,
            'data' => $responseData,
        ], 202);
    }

    /**
     * Get billing stats for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/billing-stats",
     *     summary="Get billing statistics for an upload",
     *     description="Returns aggregated billing attempt statistics grouped by status, including counts and amounts. Indicates whether billing or resync is currently processing.",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="debtor_type", in="query", required=false, description="Filter by billing model", @OA\Schema(type="string", enum={"all", "legacy", "flywheel", "recovery"}, default="all")),
     *     @OA\Response(
     *         response=200,
     *         description="Billing statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="filter_type", type="string", example="all"),
     *                 @OA\Property(property="is_processing", type="boolean", example=false),
     *                 @OA\Property(property="is_resync_processing", type="boolean", example=false),
     *                 @OA\Property(property="billing_status", type="string", enum={"idle", "processing", "completed", "failed", "cancelled", "cancelling", "voiding"}, example="completed"),
     *                 @OA\Property(property="billing_started_at", type="string", format="date-time", nullable=true, example="2025-03-15T10:00:00+00:00"),
     *                 @OA\Property(property="billing_completed_at", type="string", format="date-time", nullable=true, example="2025-03-15T10:15:00+00:00"),
     *                 @OA\Property(property="total_attempts", type="integer", example=500),
     *                 @OA\Property(property="approved", type="integer", example=400),
     *                 @OA\Property(property="approved_amount", type="number", format="float", example=19960.00),
     *                 @OA\Property(property="pending", type="integer", example=20),
     *                 @OA\Property(property="pending_amount", type="number", format="float", example=998.00),
     *                 @OA\Property(property="declined", type="integer", example=60),
     *                 @OA\Property(property="declined_amount", type="number", format="float", example=2994.00),
     *                 @OA\Property(property="error", type="integer", example=20),
     *                 @OA\Property(property="error_amount", type="number", format="float", example=998.00)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function stats(Upload $upload, Request $request): JsonResponse
    {
        $debtorType = $request->input('debtor_type') ?: DebtorProfile::ALL;

        $stats = BillingAttempt::where('upload_id', $upload->id)
            ->when($debtorType !== DebtorProfile::ALL, function ($query) use ($debtorType) {
                return $query->where('billing_model', $debtorType);
            })
            ->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $approved = $stats->get(BillingAttempt::STATUS_APPROVED);
        $pending = $stats->get(BillingAttempt::STATUS_PENDING);
        $declined = $stats->get(BillingAttempt::STATUS_DECLINED);
        $error = $stats->get(BillingAttempt::STATUS_ERROR);

        $isResyncProcessing = Cache::has("billing_resync_{$upload->id}");

        if ($debtorType === DebtorProfile::ALL) {
            $isProcessing = collect(['all', 'legacy', 'flywheel', 'recovery'])
                ->contains(fn($m) => Cache::has("billing_sync_{$upload->id}_{$m}"));
        } else {
            $isProcessing = Cache::has("billing_sync_{$upload->id}_{$debtorType}");
        }

        return response()->json([
            'data' => [
                'upload_id' => $upload->id,
                'filter_type' => $debtorType ?? DebtorProfile::ALL,
                'is_processing' => $isProcessing,
                'is_resync_processing' => $isResyncProcessing,
                'billing_status' => $upload->billing_status,
                'billing_started_at' => $upload->billing_started_at?->toIso8601String(),
                'billing_completed_at' => $upload->billing_completed_at?->toIso8601String(),
                'total_attempts' => (int) $stats->sum('count'),
                'approved' => (int) ($approved?->count ?? 0),
                'approved_amount' => (float) ($approved?->total_amount ?? 0),
                'pending' => (int) ($pending?->count ?? 0),
                'pending_amount' => (float) ($pending?->total_amount ?? 0),
                'declined' => (int) ($declined?->count ?? 0),
                'declined_amount' => (float) ($declined?->total_amount ?? 0),
                'error' => (int) ($error?->count ?? 0),
                'error_amount' => (float) ($error?->total_amount ?? 0),
            ],
        ]);
    }

    /**
     * Map resync denial reasons to appropriate HTTP status codes.
     */
    private function resyncHttpStatus(ResyncEligibility $eligibility): int
    {
        return match ($eligibility->code) {
            ResyncEligibility::CODE_LOCK,
            ResyncEligibility::CODE_PROCESSING => 409,
            default => 422,
        };
    }

    private function checkVopCompleted(Upload $upload): array
    {
        $totalEligible = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('iban_valid', true)
            ->count();

        if ($totalEligible === 0) {
            return [
                'passed' => true,
                'message' => 'No debtors eligible for VOP',
                'total_eligible' => 0,
                'verified' => 0,
                'pending' => 0,
            ];
        }

        $verified = VopLog::where('upload_id', $upload->id)->count();
        $pending = $totalEligible - $verified;

        if ($pending > 0) {
            return [
                'passed' => false,
                'message' => "VOP verification must be completed before billing. {$pending} debtors pending verification.",
                'total_eligible' => $totalEligible,
                'verified' => $verified,
                'pending' => $pending,
            ];
        }

        return [
            'passed' => true,
            'message' => 'VOP verification completed',
            'total_eligible' => $totalEligible,
            'verified' => $verified,
            'pending' => 0,
        ];
    }
}
