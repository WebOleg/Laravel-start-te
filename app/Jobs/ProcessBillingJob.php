<?php

namespace App\Jobs;

use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\Debtor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessBillingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [30, 60, 120];

    private const CHUNK_SIZE = 50;

    public function __construct(
        public Upload $upload,
        public ?string $notificationUrl = null,
        public ?string $billingModel = DebtorProfile::MODEL_LEGACY
    ) {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return 'billing_upload_' . $this->upload->id;
    }

    public function handle(): void
    {
        $uploadId = $this->upload->id;

        Log::info('ProcessBillingJob started', [
            'upload_id' => $uploadId,
            'model' => $this->billingModel
        ]);

        $query = Debtor::where('upload_id', $uploadId)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_UPLOADED);

        // 1. Filter by Target Billing Model
        // Match debtors that have the requested Profile Model OR have No Profile at all.
        $query->when($this->billingModel !== DebtorProfile::ALL, function ($q) {
            $q->where(function ($subQuery) {
                $subQuery->whereHas('debtorProfile', function ($profileQuery) {
                    $profileQuery->where('billing_model', $this->billingModel);
                })
                    ->orWhereDoesntHave('debtorProfile');
            });
        });

        // 2. Conditional Billing Attempt Check
        // Rule: "If not legacy, billing attempts don't matter."
        // Logic: (Is Non-Legacy Profile) OR (Has No Active Attempts)
        $query->where(function ($q) {
            // Condition A: The profile is explicitly NOT legacy (e.g. Flywheel/Recovery)
            // We include these regardless of billing attempts.
            $q->whereHas('debtorProfile', function ($p) {
                $p->where('billing_model', '!=', DebtorProfile::MODEL_LEGACY);
            })
                // Condition B: Otherwise (Legacy or No Profile), we MUST ensure no active attempts exist.
                ->orWhereDoesntHave('billingAttempts', function ($ba) {
                    $ba->whereIn('status', ['pending', 'approved']);
                });
        });

        // 3. Exclude BAV mismatches
        $query->where(function ($q) {
            $q->whereDoesntHave('vopLogs', function ($vopQuery) {
                $vopQuery->where('name_match', 'no');
            });
        });

        $debtorIds = $query->pluck('id')->toArray();

        // Calculate exclusions for logging
        $excludedCount = Debtor::where('upload_id', $uploadId)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_UPLOADED)
            ->whereHas('vopLogs', function ($q) {
                $q->where('name_match', 'no');
            })
            ->count();

        if (empty($debtorIds)) {
            Log::info('ProcessBillingJob: no debtors to bill', [
                'upload_id' => $uploadId,
                'excluded_bav_mismatch' => $excludedCount,
            ]);
            $this->upload->markBillingCompleted();
            return;
        }

        $chunks = array_chunk($debtorIds, self::CHUNK_SIZE);
        $jobs = [];

        Log::info('Ids=', [
            'ids' => $chunks,
            'model' => $this->billingModel
        ]);

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessBillingChunkJob(
                debtorIds: $chunk,
                uploadId: $uploadId,
                chunkIndex: $index,
                billingModel: $this->billingModel,
                notificationUrl: $this->notificationUrl
            );
        }

        $upload = $this->upload;
        $model = $this->billingModel;

        $batch = Bus::batch($jobs)
            ->name("Billing Upload #{$uploadId} ({$model})")
            ->allowFailures()
            ->onQueue('billing')
            ->finally(function () use ($upload) {
                $upload->markBillingCompleted();
                Log::info('ProcessBillingJob batch completed', ['upload_id' => $upload->id]);
            })
            ->dispatch();

        $this->upload->startBilling($batch->id);

        Log::info('ProcessBillingJob dispatched', [
            'upload_id' => $uploadId,
            'batch_id' => $batch->id,
            'debtors' => count($debtorIds),
            'excluded_bav_mismatch' => $excludedCount,
            'chunks' => count($chunks),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->upload->markBillingFailed();
        Log::error('ProcessBillingJob failed', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
