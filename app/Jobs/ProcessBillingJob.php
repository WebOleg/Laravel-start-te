<?php
/**
 * Dispatches billing jobs for upload debtors.
 * Excludes debtors with BAV name mismatch to prevent chargebacks.
 */
namespace App\Jobs;

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
        public ?string $notificationUrl = null
    ) {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return 'billing_upload_' . $this->upload->id;
    }

    public function handle(): void
    {
        Log::info('ProcessBillingJob started', ['upload_id' => $this->upload->id]);

        // Get debtors ready for billing (excluding BAV mismatches)
        $query = Debtor::where('upload_id', $this->upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_PENDING)
            ->whereDoesntHave('billingAttempts', function ($query) {
                $query->whereIn('status', ['pending', 'approved']);
            });

        // Exclude debtors with BAV name mismatch (name_match = 'no')
        // Debtors without BAV check (90%) or with yes/partial are OK to bill
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

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessBillingChunkJob(
                debtorIds: $chunk,
                uploadId: $this->upload->id,
                chunkIndex: $index,
                notificationUrl: $this->notificationUrl
            );
        }

        $uploadId = $this->upload->id;

        Bus::batch($jobs)
            ->name("Billing Upload #{$uploadId}")
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
