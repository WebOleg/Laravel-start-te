<?php

/**
 * Controller for billing operations.
 * Handles async billing dispatch for high-volume processing.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBillingJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class BillingController extends Controller
{
    /**
     * Start billing process for upload (async).
     * Returns 202 Accepted - client should poll stats endpoint.
     */
    public function sync(Upload $upload): JsonResponse
    {
        $lockKey = "billing_sync_{$upload->id}";

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

        // VOP Gate: Check if VOP verification is completed
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

        // Count eligible debtors
        $eligibleCount = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_PENDING)
            ->whereDoesntHave('billingAttempts', function ($query) {
                $query->whereIn('status', [
                    BillingAttempt::STATUS_PENDING,
                    BillingAttempt::STATUS_APPROVED,
                ]);
            })
            ->count();

        if ($eligibleCount === 0) {
            return response()->json([
                'message' => 'No eligible debtors to bill',
                'data' => [
                    'upload_id' => $upload->id,
                    'eligible' => 0,
                    'queued' => false,
                ],
            ]);
        }

        // Set lock and dispatch
        Cache::put($lockKey, true, 300);
        ProcessBillingJob::dispatch($upload);

        return response()->json([
            'message' => "Billing queued for {$eligibleCount} debtors",
            'data' => [
                'upload_id' => $upload->id,
                'eligible' => $eligibleCount,
                'queued' => true,
            ],
        ], 202);
    }

    /**
     * Get billing statistics for upload.
     * Used for polling progress.
     */
    public function stats(Upload $upload): JsonResponse
    {
        $stats = BillingAttempt::where('upload_id', $upload->id)
            ->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $approved = $stats->get(BillingAttempt::STATUS_APPROVED);
        $pending = $stats->get(BillingAttempt::STATUS_PENDING);
        $declined = $stats->get(BillingAttempt::STATUS_DECLINED);
        $error = $stats->get(BillingAttempt::STATUS_ERROR);

        $isProcessing = Cache::has("billing_sync_{$upload->id}");

        return response()->json([
            'data' => [
                'upload_id' => $upload->id,
                'is_processing' => $isProcessing,
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
     * Check if VOP verification is completed for upload.
     * Billing is blocked until all eligible debtors have VOP logs.
     *
     * @return array{passed: bool, message: string, total_eligible: int, verified: int, pending: int}
     */
    private function checkVopCompleted(Upload $upload): array
    {
        // Count debtors eligible for VOP (valid + iban_valid)
        $totalEligible = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('iban_valid', true)
            ->count();

        // If no eligible debtors, VOP check passes (nothing to verify)
        if ($totalEligible === 0) {
            return [
                'passed' => true,
                'message' => 'No debtors eligible for VOP',
                'total_eligible' => 0,
                'verified' => 0,
                'pending' => 0,
            ];
        }

        // Count VOP logs for this upload
        $verified = VopLog::where('upload_id', $upload->id)->count();
        $pending = $totalEligible - $verified;

        // VOP must be completed for all eligible debtors
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
