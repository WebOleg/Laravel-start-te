<?php

/**
 * Job for async validation of upload debtors.
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

class ProcessValidationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [30, 60, 120];

    private const CHUNK_SIZE = 100;

    public function __construct(
        public Upload $upload
    ) {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return 'validation_upload_' . $this->upload->id;
    }

    public function handle(): void
    {
        $uploadId = $this->upload->id;

        Log::info('ProcessValidationJob started', ['upload_id' => $uploadId]);

        $debtorIds = Debtor::where('upload_id', $uploadId)
            ->whereNull('validated_at')
            ->pluck('id')
            ->toArray();

        if (empty($debtorIds)) {
            Log::info('ProcessValidationJob: no debtors to validate', ['upload_id' => $uploadId]);
            $this->upload->markValidationCompleted();
            return;
        }

        $chunks = array_chunk($debtorIds, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessValidationChunkJob(
                debtorIds: $chunk,
                uploadId: $uploadId,
                chunkIndex: $index
            );
        }

        $upload = $this->upload;

        $batch = Bus::batch($jobs)
            ->name("Validation Upload #{$uploadId}")
            ->allowFailures()
            ->onQueue('high')
            ->finally(function () use ($upload) {
                $upload->markValidationCompleted();
                Log::info('ProcessValidationJob batch completed', ['upload_id' => $upload->id]);
            })
            ->dispatch();

        $this->upload->startValidation($batch->id);

        Log::info('ProcessValidationJob dispatched', [
            'upload_id' => $uploadId,
            'batch_id' => $batch->id,
            'debtors' => count($debtorIds),
            'chunks' => count($chunks),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->upload->markValidationFailed();
        Log::error('ProcessValidationJob failed', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
