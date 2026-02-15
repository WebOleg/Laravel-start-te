<?php

/**
 * Queue job for processing standalone BAV batch verification.
 * Runs on 'bav' queue with rate limiting to respect API limits.
 */

namespace App\Jobs;

use App\Models\BavBatch;
use App\Services\BavBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBavBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        private int $bavBatchId
    ) {
        $this->onQueue('bav');
    }

    public function handle(BavBatchService $service): void
    {
        $batch = BavBatch::find($this->bavBatchId);

        if (!$batch) {
            Log::channel('bav')->error('ProcessBavBatchJob: Batch not found', ['id' => $this->bavBatchId]);
            return;
        }

        if ($batch->status !== BavBatch::STATUS_PENDING) {
            Log::channel('bav')->warning('ProcessBavBatchJob: Batch not pending', [
                'id' => $this->bavBatchId,
                'status' => $batch->status,
            ]);
            return;
        }

        $batch->markProcessing($this->job->getJobId() ?? 'sync');

        Log::channel('bav')->info('ProcessBavBatchJob: Starting', [
            'batch_id' => $batch->id,
            'total_records' => $batch->total_records,
            'file' => $batch->original_filename,
        ]);

        try {
            $service->processBatch($batch);

            Log::channel('bav')->info('ProcessBavBatchJob: Completed', [
                'batch_id' => $batch->id,
                'processed' => $batch->processed_records,
                'success' => $batch->success_count,
                'failed' => $batch->failed_count,
                'credits_used' => $batch->credits_used,
            ]);
        } catch (\Exception $e) {
            $batch->markFailed($e->getMessage());

            Log::channel('bav')->error('ProcessBavBatchJob: Failed', [
                'batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
