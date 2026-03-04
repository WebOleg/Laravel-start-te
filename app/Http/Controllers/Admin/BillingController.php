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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * Void all successful transactions for an upload via Queue.
     */
    public function void(Upload $upload): JsonResponse
    {
        // Time window
        $lastActivity = $upload->billing_completed_at ?? $upload->billing_started_at;
        if ($lastActivity && $lastActivity->diffInHours(now()) > 24) {
            return response()->json([
                'message' => 'Cannot void transactions older than 24 hours. Please use Refund instead.',
            ], 422);
        }

        // Is anything actually billable?
        $count = BillingAttempt::where('upload_id', $upload->id)
            ->whereIn('status', [
                BillingAttempt::STATUS_APPROVED,
                BillingAttempt::STATUS_PENDING
            ])
            ->whereNotNull('unique_id')
            ->count();

        if ($count === 0) {
            return response()->json([
                'message' => 'No eligible transactions found to void.',
            ], 422);
        }

        // Dispatch Job
        VoidUploadJob::dispatch($upload);

        // Update UI Status
        $upload->update([
            'billing_status' => Upload::STATUS_VOIDING,
            'status' => Upload::STATUS_VOIDING
        ]);

        return response()->json([
            'message' => "Void process queued for {$count} transactions.",
            'data' => [
                'queued_count' => $count
            ]
        ], 202);
    }

    /**
     * Cancel an active billing sync.
     * Sets a signal flag that running jobs check to terminate execution.
     */
    public function cancel(Upload $upload): JsonResponse
    {
        $lockKey = "billing_sync_stop_{$upload->id}";

        // Set the Kill Switch (Valid for 60 minutes)
        Cache::put($lockKey, true, 3600);

        // Mark upload status immediately for UI feedback
        $upload->update([
            'billing_status' => Upload::STATUS_CANCELLING,
            'status' => Upload::STATUS_CANCELLING
        ]);

        return response()->json([
            'message' => 'Termination signal sent. The sync will stop shortly.',
            'data' => [
                'upload_id' => $upload->id,
                'billing_status' => $upload->id,
                'signal_sent_at' => now()->toIso8601String(),
            ]
        ]);
    }

    /**
     * Start billing process for upload (async).
     * Returns 202 Accepted - client should poll stats endpoint.
     */
    public function sync(Upload $upload, Request $request): JsonResponse
    {
        // 1. Handle Input: Default to 'all' if empty
        $debtorType = $request->input('debtor_type') ?: DebtorProfile::ALL;

        $lockKey = "billing_sync_{$upload->id}_{$debtorType}";

        // Validate: Allow 'all' OR specific models
        $validTypes = array_merge([DebtorProfile::ALL], DebtorProfile::BILLING_MODELS);

        if (!in_array($debtorType, $validTypes)) {
            return response()->json(['message' => 'Invalid billing model provided'], 422);
        }

        // Prevent duplicate dispatches (5 min lock)
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
        // Reset now — at the moment the user deliberately presses "Sync to Gateway".
        // This archives the previous billing run, clears the live lifecycle fields, and
        // moves all valid (non-chargebacked) debtors back to 'uploaded' so they are eligible again.
        // Invalid and BIC-blacklisted debtors (validation_status != valid) are untouched.
        // Chargebacked debtors are excluded and never re-billed.
        if ($upload->is_30d_cool === false) {
            // Use a per-upload (not per-debtor-type) lock so that concurrent sync requests
            // with different debtor_type values cannot both enter this section simultaneously
            // and produce a double-archive or a partial debtor reset.
            $resyncLockKey = "billing_resync_{$upload->id}";
            if (Cache::has($resyncLockKey)) {
                return response()->json([
                    'message' => 'A resync is already in progress for this upload.',
                    'data'    => ['upload_id' => $upload->id, 'queued' => false],
                ], 409);
            }
            Cache::put($resyncLockKey, true, 300);

            // Guard: enforce the per-upload resync cap defined on the model.
            $resyncCount = count($upload->billing_runs ?? []);
            if ($resyncCount >= Upload::MAX_RESYNC_ATTEMPTS) {
                Cache::forget($resyncLockKey);
                return response()->json([
                    'message' => 'Resync limit reached. This upload has already been resynced ' .
                                 Upload::MAX_RESYNC_ATTEMPTS . ' time(s), which is the maximum allowed.',
                    'data' => [
                        'upload_id'    => $upload->id,
                        'resync_count' => $resyncCount,
                        'max_resync'   => Upload::MAX_RESYNC_ATTEMPTS,
                        'queued'       => false,
                    ],
                ], 422);
            }

            // Wrap archive + debtor reset in a transaction so both succeed or both
            // roll back — prevents partial state where billing_runs is updated but
            // debtors are not reset (or vice versa) due to a DB error mid-way.
            try {
                DB::transaction(function () use ($upload) {
                    if ($upload->billing_started_at !== null) {
                        $existingRuns = $upload->billing_runs ?? [];
                        $existingRuns[] = [
                            'run'          => count($existingRuns) + 1,
                            'status'       => $upload->billing_status,
                            'batch_id'     => $upload->billing_batch_id,
                            'started_at'   => $upload->billing_started_at?->toISOString(),
                            'completed_at' => $upload->billing_completed_at?->toISOString(),
                        ];
                        $upload->billing_runs         = $existingRuns;
                        $upload->billing_status       = Upload::JOB_IDLE;
                        $upload->billing_batch_id     = null;
                        $upload->billing_started_at   = null;
                        $upload->billing_completed_at = null;
                        $upload->save();
                    }

                    Debtor::where('upload_id', $upload->id)
                        ->where('validation_status', Debtor::VALIDATION_VALID)
                        ->whereNotIn('status', [
                            Debtor::STATUS_APPROVED,
                            Debtor::STATUS_CHARGEBACKED,
                        ])
                        ->update(['status' => Debtor::STATUS_UPLOADED]);

                    // Abandon any pending billing attempts from the previous run so they
                    // no longer block the eligibility check for legacy/no-profile debtors.
                    BillingAttempt::where('upload_id', $upload->id)
                        ->where('status', BillingAttempt::STATUS_PENDING)
                        ->update(['status' => BillingAttempt::STATUS_ERROR]);
                });
            } catch (\Throwable $e) {
                Cache::forget($resyncLockKey);
                throw $e;
            }
        }

        // If we are syncing 'flywheel', we MUST NOT process IBANs that are already 'recovery', and vice versa.
        $conflictingModel = match($debtorType) {
            DebtorProfile::MODEL_FLYWHEEL => DebtorProfile::MODEL_RECOVERY,
            DebtorProfile::MODEL_RECOVERY => DebtorProfile::MODEL_FLYWHEEL,
            default => null
        };

        // 3. Count eligible debtors
        $query = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_UPLOADED);

        // Filter by requested Debtor Type (if not 'all')
        // Matches logic: (Has Profile == Type) OR (Has No Profile)
        if ($debtorType !== DebtorProfile::ALL) {
            $query->where(function ($q) use ($debtorType) {
                $q->whereHas('debtorProfile', function ($p) use ($debtorType) {
                    $p->where('billing_model', $debtorType);
                })
                    ->orWhereDoesntHave('debtorProfile');
            });
        }

        // Conditional Billing Attempt Check
        // Logic: (Is Non-Legacy Profile) OR (Has No Active Attempts)
        $query->where(function ($q) {
            // A. If non-legacy (Flywheel/Recovery), ignore attempts (Always True)
            $q->whereHas('debtorProfile', function ($p) {
                $p->where('billing_model', '!=', DebtorProfile::MODEL_LEGACY);
            })
                // B. If Legacy or No Profile, must not have pending/approved attempts
                ->orWhereDoesntHave('billingAttempts', function ($ba) {
                    $ba->whereIn('status', [
                        BillingAttempt::STATUS_PENDING,
                        BillingAttempt::STATUS_APPROVED,
                    ]);
                });
        });

        // Exclude VOP failed results (mismatch, rejected, inconclusive)
        // Only allow debtors with no VOP check OR passed VOP check
        $query->where(function ($q) {
            $q->whereDoesntHave('vopLogs')
              ->orWhereHas('vopLogs', function ($vopQuery) {
                  $vopQuery->whereIn('result', [
                      VopLog::RESULT_VERIFIED,
                      VopLog::RESULT_LIKELY_VERIFIED,
                  ]);
              });
        });

        // Apply strict cross-contamination check
        if ($conflictingModel) {
            $query->whereNotExists(function ($subQuery) use ($conflictingModel) {
                $subQuery->select('id')
                    ->from('debtor_profiles')
                    ->whereColumn('debtor_profiles.iban_hash', 'debtors.iban_hash')
                    ->where('debtor_profiles.billing_model', $conflictingModel);
            });
        }

        $eligibleCount = $query->count();

        if ($eligibleCount === 0) {
            return response()->json([
                'message' => $conflictingModel
                    ? "No eligible debtors found (duplicates or {$conflictingModel} conflicts removed)"
                    : 'No eligible debtors to bill',
                'data' => [
                    'upload_id' => $upload->id,
                    'eligible' => 0,
                    'queued' => false,
                ],
            ]);
        }

        // Set lock and dispatch
        Cache::put($lockKey, true, 300);
        // Store the eligible count in the resync cache so billing-stats can display
        // a stable total from the moment the resync starts (overwrites the initial 'true' lock signal).
        if (isset($resyncLockKey)) {
            Cache::put($resyncLockKey, $eligibleCount, 300);
        }
        // Note: Ensure ProcessBillingJob constructor accepts $debtorType
        ProcessBillingJob::dispatch($upload, null, $debtorType);

        return response()->json([
            'message' => "Billing queued for {$eligibleCount} debtors ({$debtorType} model)",
            'data' => [
                'upload_id' => $upload->id,
                'eligible' => $eligibleCount,
                'queued' => true,
                'model' => $debtorType
            ],
        ], 202);
    }

    /**
     * Get billing statistics for upload.
     * Used for polling progress.
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

        $isResyncProcessing = Cache::has("billing_resync_{$upload->id}")
            && count($upload->billing_runs ?? []) > 0;
        $isProcessing       = Cache::has("billing_sync_{$upload->id}_{$debtorType}")
            && !$isResyncProcessing;

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
