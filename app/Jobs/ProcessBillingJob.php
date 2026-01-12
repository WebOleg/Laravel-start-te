<?php

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
        $uploadId = $this->upload->id;

        Log::info('ProcessBillingJob started', ['upload_id' => $uploadId]);

        $query = Debtor::where('upload_id', $uploadId)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_PENDING)
            ->whereDoesntHave('billingAttempts', function ($query) {
                $query->whereIn('status', ['pending', 'approved']);
            });

        $query->where(function ($q) {
            $q->whereDoesntHave('vopLogs', function ($vopQuery) {
                $vopQuery->where('name_match', 'no');
            });
        });

        $debtorIds = $query->pluck('id')->toArray();

        $excludedCount = Debtor::where('upload_id', $uploadId)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('status', Debtor::STATUS_PENDING)
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

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessBillingChunkJob(
                debtorIds: $chunk,
                uploadId: $uploadId,
                chunkIndex: $index,
                notificationUrl: $this->notificationUrl
            );
        }

        $upload = $this->upload;

        $batch = Bus::batch($jobs)
            ->name("Billing Upload #{$uploadId}")
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
