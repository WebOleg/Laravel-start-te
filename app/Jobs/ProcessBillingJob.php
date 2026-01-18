<?php
/**
 * Dispatches billing jobs for upload debtors.
 * Excludes debtors with BAV name mismatch to prevent chargebacks.
 */
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
        Log::info('ProcessBillingJob started', [
            'upload_id' => $this->upload->id,
            'model' => $this->billingModel
        ]);

        $query = Debtor::where('upload_id', $this->upload->id)
                        ->where('validation_status', Debtor::VALIDATION_VALID)
                        ->where('status', Debtor::STATUS_PENDING);

        // 1. Filter by Target Billing Model
        // Match debtors that have the requested Profile Model OR have No Profile at all.
        $query->when($this->billingModel !== DebtorProfile::ALL, function ($q) {
            // Match debtors that have the requested Profile Model OR have No Profile at all.
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

        // Count excluded for logging
        $excludedCount = Debtor::where('upload_id', $this->upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_PENDING)
            ->whereHas('vopLogs', function ($q) {
                $q->where('name_match', 'no');
            })
            ->count();

        if (empty($debtorIds)) {
            Log::info('ProcessBillingJob: no debtors to bill', [
                'upload_id' => $this->upload->id,
                'excluded_bav_mismatch' => $excludedCount,
            ]);
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
                uploadId: $this->upload->id,
                chunkIndex: $index,
                billingModel: $this->billingModel,
                notificationUrl: $this->notificationUrl
            );
        }

        $uploadId = $this->upload->id;
        $model = $this->billingModel;

        Bus::batch($jobs)
            ->name("Billing Upload #{$uploadId} ({$model})")
            ->allowFailures()
            ->onQueue('billing')
            ->finally(function () use ($uploadId) {
                Log::info('ProcessBillingJob batch completed', ['upload_id' => $uploadId]);
            })
            ->dispatch();

        Log::info('ProcessBillingJob dispatched', [
            'upload_id' => $this->upload->id,
            'debtors' => count($debtorIds),
            'excluded_bav_mismatch' => $excludedCount,
            'chunks' => count($chunks),
        ]);
    }
}
