<?php

namespace App\Services;

use App\Jobs\ProcessBillingJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\Dto\ResyncEligibility;
use App\Services\Dto\ResyncResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingResyncService
{
    // Only Legacy supports resync; Flywheel and Recovery manage their own cycles
    private const RESYNCABLE_MODELS = [
        DebtorProfile::MODEL_LEGACY,
    ];

    // Check if resync is allowed for the given billing model
    public function isResyncAllowedForModel(string $billingModel): bool
    {
        return in_array($billingModel, self::RESYNCABLE_MODELS, true);
    }

    // Evaluate if resync can proceed (model validation, locks, cooldown, eligible debtors)
    public function canResync(Upload $upload, ?string $billingModel = null): ResyncEligibility
    {
        $effectiveModel = $billingModel ?: DebtorProfile::ALL;

        // Reject non-Legacy explicit model requests
        if ($effectiveModel !== DebtorProfile::ALL && !$this->isResyncAllowedForModel($effectiveModel)) {
            return new ResyncEligibility(
                allowed: false,
                reason: "Resync is not supported for the '{$effectiveModel}' billing model. "
                    . "Only Legacy supports resync — Flywheel and Recovery manage their own billing cycles automatically.",
                billingModel: $effectiveModel,
                code: ResyncEligibility::CODE_MODEL_NOT_SUPPORTED,
            );
        }

        // Guard: if non-Legacy upload, check for Legacy debtors
        if (
            $upload->billing_model !== DebtorProfile::MODEL_LEGACY
            && $effectiveModel === DebtorProfile::ALL
        ) {
            $hasLegacyDebtors = $upload->debtors()
                ->where('billing_model', DebtorProfile::MODEL_LEGACY)
                ->where(function ($q) {
                    $q->whereDoesntHave('debtorProfile')
                      ->orWhereHas('debtorProfile', fn (Builder $p) => $p->where('billing_model', DebtorProfile::MODEL_LEGACY));
                })
                ->exists();

            if (!$hasLegacyDebtors) {
                return new ResyncEligibility(
                    allowed: false,
                    reason: "This upload uses the '{$upload->billing_model}' billing model and contains no Legacy debtors. "
                        . 'Resync is only available for Legacy debtors.',
                    billingModel: $effectiveModel,
                    code: ResyncEligibility::CODE_NO_LEGACY_DEBTORS,
                );
            }
        }

        // Check concurrent resync lock
        if (Cache::has("billing_resync_{$upload->id}")) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'A resync is already in progress for this upload.',
                billingModel: $effectiveModel,
                code: ResyncEligibility::CODE_LOCK,
            );
        }

        // Check if billing is processing
        if ($upload->billing_status === Upload::JOB_PROCESSING) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'Billing is currently processing. Wait for it to complete before resyncing.',
                billingModel: $effectiveModel,
                code: ResyncEligibility::CODE_PROCESSING,
            );
        }

        // Enforce cooldown period — check current run or last archived run
        $lastCompletedAt = $upload->billing_completed_at;
        if (!$lastCompletedAt && !empty($upload->billing_runs)) {
            $lastRun = collect($upload->billing_runs)->last();
            $lastCompletedAt = isset($lastRun['completed_at'])
                ? Carbon::parse($lastRun['completed_at'])
                : null;
        }

        if ($lastCompletedAt) {
            $minutesSinceLastRun = $lastCompletedAt->diffInMinutes(now());
            $cooldownMinutes = Upload::RESYNC_COOLDOWN_HOURS * 60;

            if ($minutesSinceLastRun < $cooldownMinutes) {
                $minutesRemaining = $cooldownMinutes - $minutesSinceLastRun;

                $hours = intdiv($minutesRemaining, 60);
                $minutes = $minutesRemaining % 60;

                return new ResyncEligibility(
                    allowed: false,
                    reason: "Cooldown period active. {$hours} hour(s) and {$minutes} minute(s) remaining before the next resync is allowed.",
                    billingModel: $effectiveModel,
                    code: ResyncEligibility::CODE_COOLDOWN,
                );
            }
        }

        // Count eligible debtors — resync stops automatically when all debtors reach the billing cap
        $eligibleCount = $this->getResyncableDebtors($upload)->count();
        if ($eligibleCount === 0) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'No eligible Legacy debtors found for resync. '
                    . 'All debtors have either reached the billing cap, been chargebacked, '
                    . 'or belong to non-Legacy billing models.',
                billingModel: $effectiveModel,
                code: ResyncEligibility::CODE_NO_ELIGIBLE,
            );
        }

        return new ResyncEligibility(
            allowed: true,
            reason: 'Resync is available.',
            eligibleCount: $eligibleCount,
            billingModel: $effectiveModel,
        );
    }

    // Query debtors eligible for resync (valid, legacy, not chargebacked, not pending in EMP, cap not reached)
    public function getResyncableDebtors(Upload $upload): Builder
    {
        $query = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            // Only Legacy debtors
            ->where('billing_model', DebtorProfile::MODEL_LEGACY)
            // No profile OR profile is Legacy
            ->where(function (Builder $q) {
                $q->whereDoesntHave('debtorProfile')
                  ->orWhereHas('debtorProfile', fn (Builder $p) => $p->where('billing_model', DebtorProfile::MODEL_LEGACY));
            })
            // Exclude chargebacked debtors — these are permanently excluded
            ->whereDoesntHave('billingAttempts', function (Builder $ba) {
                $ba->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            })
            // Exclude debtors with pending attempts already submitted to EMP (unique_id set)
            // These are live in the gateway — reconciliation will resolve their final status
            ->whereDoesntHave('billingAttempts', function (Builder $ba) {
                $ba->where('status', BillingAttempt::STATUS_PENDING)
                   ->whereNotNull('unique_id');
            })
            // No VOP check or passed verification
            ->where(function (Builder $q) {
                $q->whereDoesntHave('vopLogs')
                  ->orWhereHas('vopLogs', function (Builder $vop) {
                      $vop->whereIn('result', [
                          VopLog::RESULT_VERIFIED,
                          VopLog::RESULT_LIKELY_VERIFIED,
                      ]);
                  });
            });

        // If billing cap is set, only include debtors that have not yet reached it
        if ($upload->max_billing_amount !== null && (float) $upload->max_billing_amount > 0) {
            $query->withinBillingCap((float) $upload->max_billing_amount);
        }

        return $query;
    }

    // Check if resync is currently in progress
    public function isResyncInProgress(Upload $upload): bool
    {
        return Cache::has("billing_resync_{$upload->id}");
    }

    // Get voidable attempts (approved/pending with unique_id, scoped to recent run if applicable).
    //
    // IMPORTANT: When billing_runs is non-empty (resync history exists), this
    // query is scoped to the latest run only (created_at >= billing_started_at).
    // Earlier runs' approved attempts are NOT included. If you need to void ALL
    // historical attempts, query BillingAttempt directly without the time filter.
    public function getVoidableAttempts(Upload $upload): Builder
    {
        $query = BillingAttempt::where('upload_id', $upload->id)
            ->whereIn('status', [
                BillingAttempt::STATUS_APPROVED,
                BillingAttempt::STATUS_PENDING,
            ])
            ->whereNotNull('unique_id');

        if (!empty($upload->billing_runs) && $upload->billing_started_at) {
            $query->where('created_at', '>=', $upload->billing_started_at);
        }

        return $query;
    }

    // Cancel resync: set kill switch, clear locks, update status to 'cancelling'
    public function cancelResync(Upload $upload): void
    {
        // Set kill switch (60 min TTL)
        Cache::put("billing_sync_stop_{$upload->id}", true, 3600);

        // Clear all resync and sync locks
        Cache::forget("billing_resync_{$upload->id}");
        foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
            Cache::forget("billing_sync_{$upload->id}_{$model}");
        }

        $upload->update([
            'billing_status' => Upload::STATUS_CANCELLING,
            'status' => Upload::STATUS_CANCELLING,
        ]);

        Log::info('BillingResyncService: resync cancelled by user', [
            'upload_id' => $upload->id,
        ]);
    }

    // Execute resync: acquire lock, archive run, reset debtors (approved included), dispatch job
    public function executeResync(Upload $upload): ResyncResult
    {
        $resyncLockKey = "billing_resync_{$upload->id}";

        // Set resync flag only if prior run exists (avoid initial sync false positive)
        $isResync = $upload->billing_started_at !== null || !empty($upload->billing_runs ?? []);

        if ($isResync) {
            Cache::put($resyncLockKey, true, 300);
        }

        try {
            $resyncDebtorIds = collect();

            $archived = false;
            $resetCount = 0;

            DB::transaction(function () use ($upload, &$resyncDebtorIds, &$archived, &$resetCount) {
                $resyncDebtorIds = $this->getResyncableDebtors($upload)->pluck('id');

                // Archive previous billing run if exists
                if ($upload->billing_started_at !== null) {
                    $existingRuns = $upload->billing_runs ?? [];
                    $existingRuns[] = [
                        'run'           => count($existingRuns) + 1,
                        'billing_model' => DebtorProfile::MODEL_LEGACY,
                        'status'        => $upload->billing_status,
                        'batch_id'      => $upload->billing_batch_id,
                        'started_at'    => $upload->billing_started_at?->toISOString(),
                        'completed_at'  => $upload->billing_completed_at?->toISOString(),
                    ];

                    $upload->billing_runs         = $existingRuns;
                    $upload->billing_status       = Upload::JOB_IDLE;
                    $upload->billing_batch_id     = null;
                    $upload->billing_started_at   = null;
                    $upload->billing_completed_at = null;
                    $upload->save();

                    $archived = true;
                }

                // Reset eligible debtors to 'uploaded' so they can be billed again.
                // Approved debtors are included — their billing history is preserved in billing_attempts.
                // Only chargebacked debtors are permanently excluded (filtered in getResyncableDebtors).
                if ($resyncDebtorIds->isNotEmpty()) {
                    // Void pending attempts NOT yet submitted to EMP (no unique_id)
                    // Attempts with unique_id are left as pending — reconciliation resolves them
                    BillingAttempt::whereIn('debtor_id', $resyncDebtorIds)
                        ->where('status', BillingAttempt::STATUS_PENDING)
                        ->whereNull('unique_id')
                        ->update(['status' => BillingAttempt::STATUS_VOIDED]);

                    $resetCount = Debtor::whereIn('id', $resyncDebtorIds)
                        ->update(['status' => Debtor::STATUS_UPLOADED]);
                }
            });

            // Use the count from the transaction to avoid race conditions
            $eligibleCount = $resyncDebtorIds->count();

            if ($eligibleCount > 0) {
                $syncLockKey = "billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY;
                Cache::put($syncLockKey, true, 300);

                if ($isResync) {
                    Cache::put($resyncLockKey, $eligibleCount, 300);
                }

                ProcessBillingJob::dispatch($upload, null, DebtorProfile::MODEL_LEGACY);

                Log::info('BillingResyncService: resync dispatched', [
                    'upload_id'      => $upload->id,
                    'eligible_count' => $eligibleCount,
                    'reset_count'    => $resetCount,
                    'archived'       => $archived,
                ]);

                return new ResyncResult(
                    archived: $archived,
                    resetCount: $resetCount,
                    dispatched: true,
                    eligibleCount: $eligibleCount,
                );
            }

            // Edge case: no eligible debtors remain, clean up locks
            if ($isResync) {
                Cache::forget($resyncLockKey);
            }

            return new ResyncResult(
                archived: $archived,
                resetCount: $resetCount,
                dispatched: false,
                eligibleCount: 0,
            );
        } catch (\Throwable $e) {
            if ($isResync) {
                Cache::forget($resyncLockKey);
            }
            throw $e;
        }
    }
}
