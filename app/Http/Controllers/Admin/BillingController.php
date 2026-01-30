<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBillingJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;

class BillingController extends Controller
{
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

        $isProcessing = Cache::has("billing_sync_{$upload->id}_{$debtorType}");

        return response()->json([
            'data' => [
                'upload_id' => $upload->id,
                'filter_type' => $debtorType ?? DebtorProfile::ALL,
                'is_processing' => $isProcessing,
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
