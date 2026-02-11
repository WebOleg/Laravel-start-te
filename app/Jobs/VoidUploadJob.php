<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class VoidUploadJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    private const CHUNK_SIZE = 50;

    public function __construct(
        public Upload $upload
    ) {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return 'void_upload_' . $this->upload->id;
    }

    public function handle(): void
    {
        $uploadId = $this->upload->id;

        Log::info('VoidUploadJob started', ['upload_id' => $uploadId]);

        // Find eligible attempts:
        // Must be Approved or Pending (async), and have a unique_id from EMP to void against.
        $query = BillingAttempt::where('upload_id', $uploadId)
                               ->whereIn('status', [
                                   BillingAttempt::STATUS_APPROVED,
                                   BillingAttempt::STATUS_PENDING,
                               ])
                               ->whereNotNull('unique_id');

        $attemptIds = $query->pluck('id')->toArray();

        if (empty($attemptIds)) {
            Log::info('VoidUploadJob: no eligible transactions to void', ['upload_id' => $uploadId]);

            // Set cancelled
            $this->upload->update([
                'billing_status' => Upload::STATUS_CANCELLED,
                'status' => Upload::STATUS_CANCELLED
            ]);

            return;
        }

        $chunks = array_chunk($attemptIds, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new VoidUploadChunkJob(
                attemptIds: $chunk,
                uploadId: $uploadId,
                chunkIndex: $index
            );
        }

        $upload = $this->upload;

        $batch = Bus::batch($jobs)
            ->name("Void Upload #{$uploadId}")
            ->allowFailures()
            ->onQueue('billing')
            ->finally(function () use ($upload) {
                // When done, mark upload as fully Cancelled
                $upload->update([
                    'billing_status' => Upload::STATUS_CANCELLED,
                    'status' => Upload::STATUS_CANCELLED
                ]);
                Log::info('VoidUploadJob batch completed', ['upload_id' => $upload->id]);
            })
            ->dispatch();

        // Optional: Store void batch ID if you want to track it specifically
        // $this->upload->update(['void_batch_id' => $batch->id]);

        Log::info('VoidUploadJob dispatched', [
            'upload_id' => $uploadId,
            'batch_id' => $batch->id,
            'attempts' => count($attemptIds),
            'chunks' => count($chunks),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('VoidUploadJob failed', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
