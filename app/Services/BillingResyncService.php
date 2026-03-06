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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingResyncService
{
    /**
     * Models that support resync. Only Legacy is eligible — Flywheel and Recovery
     * manage their own billing cycles via DebtorProfile.next_bill_at and are retried
     * automatically by the scheduler.
     */
    private const RESYNCABLE_MODELS = [
        DebtorProfile::MODEL_LEGACY,
    ];

    /**
     * Check whether resync is allowed for a specific billing model.
     */
    public function isResyncAllowedForModel(string $billingModel): bool
    {
        return in_array($billingModel, self::RESYNCABLE_MODELS, true);
    }

    /**
     * Evaluate whether resync can proceed for the given upload.
     *
     * Checks (in order):
     *  1. Explicit billing model must be Legacy (or 'all' to auto-filter).
     *  2. Upload-level guard: reject if upload is non-Legacy with zero Legacy debtors.
     *  3. Cache lock: no concurrent resync in progress.
     *  4. Billing not currently processing.
     *  5. Resync cap not exceeded.
     *  6. At least one eligible debtor exists.
     */
    public function canResync(Upload $upload, ?string $billingModel = null): ResyncEligibility
    {
        $effectiveModel = $billingModel ?: DebtorProfile::ALL;

        // 1. Reject non-Legacy explicit model requests.
        if ($effectiveModel !== DebtorProfile::ALL && !$this->isResyncAllowedForModel($effectiveModel)) {
            return new ResyncEligibility(
                allowed: false,
                reason: "Resync is not supported for the '{$effectiveModel}' billing model. "
                    . "Only Legacy supports resync — Flywheel and Recovery manage their own billing cycles automatically.",
                billingModel: $effectiveModel,
            );
        }

        // 2. Upload-level guard: if the upload's own billing_model is non-Legacy,
        //    check whether it has any Legacy debtors at all. If not, there is nothing to resync.
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
                );
            }
        }

        // 3. Concurrent resync lock.
        if (Cache::has("billing_resync_{$upload->id}")) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'A resync is already in progress for this upload.',
                billingModel: $effectiveModel,
            );
        }

        // 4. Billing currently processing.
        if ($upload->billing_status === Upload::JOB_PROCESSING) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'Billing is currently processing. Wait for it to complete before resyncing.',
                billingModel: $effectiveModel,
            );
        }

        // 5. Resync cap.
        $resyncCount = count($upload->billing_runs ?? []);
        if ($resyncCount >= Upload::MAX_RESYNC_ATTEMPTS) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'Resync limit reached. This upload has already been resynced '
                    . Upload::MAX_RESYNC_ATTEMPTS . ' time(s), which is the maximum allowed.',
                billingModel: $effectiveModel,
            );
        }

        // 6. Count eligible debtors.
        $eligibleCount = $this->getResyncableDebtors($upload)->count();
        if ($eligibleCount === 0) {
            return new ResyncEligibility(
                allowed: false,
                reason: 'No eligible Legacy debtors found for resync. '
                    . 'All debtors are either approved, chargebacked, or belong to non-Legacy billing models. '
                    . 'Note: debtors with pending billing attempts are also eligible for resync.',
                billingModel: $effectiveModel,
            );
        }

        return new ResyncEligibility(
            allowed: true,
            reason: 'Resync is available.',
            eligibleCount: $eligibleCount,
            billingModel: $effectiveModel,
        );
    }

    /**
     * Build an Eloquent query for debtors eligible for resync.
     *
     * A debtor is resyncable when ALL of the following are true:
     *  - validation_status = 'valid'
     *  - debtor.billing_model = 'legacy' (debtor-level snapshot)
     *  - debtor has no profile OR profile.billing_model = 'legacy' (source of truth)
     *  - No billing attempt is in a terminal non-retriable state (approved or chargebacked);
     *    pending attempts are treated as stuck/unresolved and are retriable
     *  - VOP verification passed or was never required
     */
    public function getResyncableDebtors(Upload $upload): Builder
    {
        return Debtor::where('upload_id', $upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            // Debtor-level model check: only Legacy debtors.
            ->where('billing_model', DebtorProfile::MODEL_LEGACY)
            // Profile-level model check: no profile OR profile is Legacy.
            ->where(function (Builder $q) {
                $q->whereDoesntHave('debtorProfile')
                  ->orWhereHas('debtorProfile', fn (Builder $p) => $p->where('billing_model', DebtorProfile::MODEL_LEGACY));
            })
            // Status check: exclude debtors that have ANY billing attempt in a
            // terminal non-retriable state (approved, chargebacked).
            // Pending attempts are treated as stuck/unresolved and are retriable.
            ->whereDoesntHave('billingAttempts', function (Builder $ba) {
                $ba->whereIn('status', [
                    BillingAttempt::STATUS_APPROVED,
                    BillingAttempt::STATUS_CHARGEBACKED,
                ]);
            })
            // VOP filter: only debtors with no VOP check or passed verification.
            ->where(function (Builder $q) {
                $q->whereDoesntHave('vopLogs')
                  ->orWhereHas('vopLogs', function (Builder $vop) {
                      $vop->whereIn('result', [
                          VopLog::RESULT_VERIFIED,
                          VopLog::RESULT_LIKELY_VERIFIED,
                      ]);
                  });
            });
    }

    /**
     * Check whether a resync is currently in progress for the given upload.
     */
    public function isResyncInProgress(Upload $upload): bool
    {
        return Cache::has("billing_resync_{$upload->id}");
    }

    /**
     * Get billing attempts eligible for voiding.
     * For resync uploads, scopes to only the most recent run.
     * For initial sync uploads, returns all eligible attempts.
     */
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

    /**
     * Cancel an active resync by setting the kill switch and clearing resync-specific locks.
     *
     * The kill switch (billing_sync_stop_{id}) is shared with normal sync — ProcessBillingChunkJob
     * checks it to terminate the batch. This method additionally clears the resync cache lock
     * so the stats endpoint no longer reports resync as in-progress.
     */
    public function cancelResync(Upload $upload): void
    {
        // Set the shared kill switch (60 min TTL safety net).
        Cache::put("billing_sync_stop_{$upload->id}", true, 3600);

        // Clear resync-specific locks so stats endpoint reflects the cancellation.
        Cache::forget("billing_resync_{$upload->id}");
        Cache::forget("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY);

        $upload->update([
            'billing_status' => Upload::STATUS_CANCELLING,
            'status' => Upload::STATUS_CANCELLING,
        ]);

        Log::info('BillingResyncService: resync cancelled by user', [
            'upload_id' => $upload->id,
        ]);
    }

    /**
     * Execute the full resync workflow:
     *  1. Acquire cache lock
     *  2. Archive the previous billing run
     *  3. Reset eligible debtors to 'uploaded'
     *  4. Dispatch ProcessBillingJob for Legacy model
     */
    public function executeResync(Upload $upload): ResyncResult
    {
        $resyncLockKey = "billing_resync_{$upload->id}";

        // Only treat this as a true resync (and set the resync lock) when there is
        // an existing billing run to archive. On the very first sync for an upload
        // with is_30d_cool = false, there is no prior run — setting the resync lock
        // would cause is_resync_processing to appear true on the stats endpoint even
        // though it is just an initial sync.
        $isResync = $upload->billing_started_at !== null || !empty($upload->billing_runs ?? []);

        if ($isResync) {
            Cache::put($resyncLockKey, true, 300);
        }

        try {
            $resyncDebtorIds = $this->getResyncableDebtors($upload)->pluck('id');

            $archived = false;
            $resetCount = 0;

            DB::transaction(function () use ($upload, $resyncDebtorIds, &$archived, &$resetCount) {
                // Archive the previous billing run if one exists.
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

                // Reset eligible debtors back to 'uploaded' so ProcessBillingJob picks them up.
                if ($resyncDebtorIds->isNotEmpty()) {
                    // Void any stuck pending billing attempts so ProcessBillingJob
                    // does not re-exclude these debtors via its active-attempt guard.
                    BillingAttempt::whereIn('debtor_id', $resyncDebtorIds)
                        ->where('status', BillingAttempt::STATUS_PENDING)
                        ->update(['status' => BillingAttempt::STATUS_VOIDED]);

                    $resetCount = Debtor::whereIn('id', $resyncDebtorIds)
                        ->update(['status' => Debtor::STATUS_UPLOADED]);
                }
            });

            // Dispatch the billing job targeting Legacy debtors only.
            $eligibleCount = $this->getResyncableDebtors($upload)->count();

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

            // Edge case: all debtors became ineligible between canResync and execute
            // (e.g. concurrent reconciliation approved them). Clean up locks.
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
