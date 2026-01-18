<?php

namespace App\Console\Commands;

use App\Models\DebtorProfile;
use App\Models\Debtor;
use App\Jobs\ProcessBillingChunkJob;
use App\Jobs\ProcessValidationChunkJob;
use App\Jobs\ProcessVopChunkJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DispatchRecurringBilling extends Command
{
    protected $signature = 'billing:dispatch';
    protected $description = 'Dispatch recurring billing pipeline with Redis locking';

    private const CHUNK_SIZES = [
        ProcessValidationChunkJob::class => 100,
        ProcessVopChunkJob::class => 50,
        ProcessBillingChunkJob::class => 50,
    ];

    private const DEFAULT_CHUNK_SIZE = 50;
    private const LOCK_TTL_SECONDS = 1800;

    public function handle()
    {
        $targetModels = [
            DebtorProfile::MODEL_FLYWHEEL,
            DebtorProfile::MODEL_RECOVERY,
        ];

        foreach ($targetModels as $model) {
            $this->processModelPipeline($model);
        }

        $this->info('Recurring billing pipeline dispatched.');

        Log::info("Recurring billing pipeline dispatched.");
    }

    private function processModelPipeline(string $model)
    {
        // =================================================================
        // PHASE 1: Validation
        // =================================================================
        $validationCandidates = Debtor::query()
                                      ->whereHas('debtorProfile', function ($q) use ($model) {
                                          $q->where('billing_model', $model)->due();
                                      })
                                      ->where('validation_status', '!=', Debtor::VALIDATION_VALID)
                                      ->pluck('id')
                                      ->toArray();



        // Filter: Only take IDs we can successfully lock in Redis
        $lockedValidationIds = $this->filterAndLock($validationCandidates, 'validation');

        if (!empty($lockedValidationIds)) {
            $this->dispatchBatch($lockedValidationIds, $model, 'Validation', ProcessValidationChunkJob::class);
        }

        // =================================================================
        // PHASE 2: VOP Verification
        // =================================================================
        $vopCandidates = Debtor::query()
            ->whereHas('debtorProfile', function ($q) use ($model) {
                $q->where('billing_model', $model)->due();
            })
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where(function ($q) {
                $q->where('vop_status', '!=', Debtor::VOP_VERIFIED)
                    ->orWhereNull('vop_status');
            })
            ->pluck('id')
            ->toArray();

        $lockedVopIds = $this->filterAndLock($vopCandidates, 'vop');

        if (!empty($lockedVopIds)) {
            $this->dispatchBatch($lockedVopIds, $model, 'VOP', ProcessVopChunkJob::class);
        }

        // =================================================================
        // PHASE 3: Billing (DebtorProfile)
        // =================================================================
        $billingCandidateIds = Debtor::query()
                                     // 1. Filter for valid Debtors
                                     ->where('validation_status', Debtor::VALIDATION_VALID)
                                     ->where('vop_status', Debtor::VOP_VERIFIED)
                                     // 2. Filter by the associated Profile's status
                                     ->whereHas('debtorProfile', function ($q) use ($model) {
                                         $q->where('billing_model', $model)
                                             ->due()
                                             ->underLifetimeCap();
                                     })
                                     ->pluck('id')
                                     ->toArray();

       if (count($billingCandidateIds)) {
           $this->dispatchBatch($billingCandidateIds, $model, 'Billing', ProcessBillingChunkJob::class);
       }
    }

    /**
     * Tries to acquire a lock for each ID. Returns only the IDs that were successfully locked.
     */
    private function filterAndLock(array $ids, string $type): array
    {
        $lockedIds = [];

        foreach ($ids as $id) {
            $lockKey = "billing:lock:{$type}:{$id}";

            // This is an atomic operation.
            if (Cache::add($lockKey, true, self::LOCK_TTL_SECONDS)) {
                $lockedIds[] = $id;
            }
        }

        return $lockedIds;
    }

    private function dispatchBatch(array $ids, string $model, string $type, string $jobClass)
    {
        $chunkSize = self::CHUNK_SIZES[$jobClass] ?? self::DEFAULT_CHUNK_SIZE;

        $chunks = array_chunk($ids, $chunkSize);
        $jobs = [];

        $totalRecords = count($ids);


        if ($totalRecords) {
            Log::info("Billing Dispatch: Preparing batch for [{$model}] - Phase [{$type}]. Locked records: {$totalRecords}. Chunks: " . count($chunks));
        }

        foreach ($chunks as $index => $chunk) {
            $jobs[] = match ($jobClass) {
                ProcessValidationChunkJob::class => new ProcessValidationChunkJob($chunk, null, $index),
                ProcessVopChunkJob::class => new ProcessVopChunkJob($chunk, null, $index, false),
                ProcessBillingChunkJob::class => new ProcessBillingChunkJob($chunk, null, $index, $model, null),
            };
        }

        Bus::batch($jobs)
            ->name("Recurring {$type} ({$model})")
            ->allowFailures()
            ->onQueue('billing')
            ->dispatch();

        Log::info("Dispatched {$type} for " . count($ids) . " records.");
    }
}
