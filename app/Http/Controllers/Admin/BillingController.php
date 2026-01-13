<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBillingJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Http\JsonResponse;

class BillingController extends Controller
{
    public function sync(Upload $upload): JsonResponse
    {
        if ($upload->isBillingProcessing()) {
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

        $eligibleCount = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_UPLOADED)
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

        return response()->json([
            'data' => [
                'upload_id' => $upload->id,
                'is_processing' => $upload->isBillingProcessing(),
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
