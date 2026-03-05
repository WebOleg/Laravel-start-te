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
    public function void(Upload $upload): JsonResponse
    {
        $lastActivity = $upload->billing_completed_at ?? $upload->billing_started_at;
        if ($lastActivity && $lastActivity->diffInHours(now()) > 24) {
            return response()->json([
                'message' => 'Cannot void transactions older than 24 hours. Please use Refund instead.',
            ], 422);
        }

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

        VoidUploadJob::dispatch($upload);

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

    public function cancel(Upload $upload): JsonResponse
    {
        $lockKey = "billing_sync_stop_{$upload->id}";

        Cache::put($lockKey, true, 3600);

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

    public function sync(Upload $upload, Request $request): JsonResponse
    {
        $debtorType = $request->input('debtor_type') ?: DebtorProfile::ALL;

        $lockKey = "billing_sync_{$upload->id}_{$debtorType}";

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
            $query->where(function ($q) use ($maxAmount) {
                $q->whereRaw(
                    'COALESCE((SELECT SUM(ba.amount) FROM billing_attempts ba WHERE ba.debtor_id = debtors.id AND ba.status IN (?, ?)), 0) < ?',
                    [BillingAttempt::STATUS_APPROVED, BillingAttempt::STATUS_PENDING, $maxAmount]
                );
            });
        }

        $eligibleCount = $query->count();
        $cappedCount = 0;

        if ($upload->max_billing_amount !== null && (float) $upload->max_billing_amount > 0) {
            $cappedCount = Debtor::where('upload_id', $upload->id)
                ->where('validation_status', Debtor::VALIDATION_VALID)
                ->where('status', Debtor::STATUS_UPLOADED)
                ->whereRaw(
                    'COALESCE((SELECT SUM(ba.amount) FROM billing_attempts ba WHERE ba.debtor_id = debtors.id AND ba.status IN (?, ?)), 0) >= ?',
                    [BillingAttempt::STATUS_APPROVED, BillingAttempt::STATUS_PENDING, (float) $upload->max_billing_amount]
                )
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
