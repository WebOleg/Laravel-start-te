<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\Upload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [30, 60, 120];

    private const CHUNK_SIZE = 50;

    public function __construct(
        public ?int $uploadId = null,
        public string $type = 'bulk',
        public int $maxAgeHours = 24,
        public int $limit = 1000
    ) {
        $this->onQueue('reconciliation');
    }

    public function handle(): void
    {
        Log::info('ProcessReconciliationJob started', [
            'type' => $this->type,
            'upload_id' => $this->uploadId,
            'max_age_hours' => $this->maxAgeHours,
            'limit' => $this->limit,
        ]);

        $query = BillingAttempt::query()->needsReconciliation();

        if ($this->type === 'upload' && $this->uploadId) {
            $query->where('upload_id', $this->uploadId);
        } else {
            $query->where('created_at', '>', now()->subHours($this->maxAgeHours));
        }

        $attemptIds = $query
            ->orderBy('created_at', 'asc')
            ->orderByRaw('CASE WHEN last_reconciled_at IS NULL THEN 0 ELSE 1 END')
            ->limit($this->limit)
            ->pluck('id')
            ->toArray();

        if (empty($attemptIds)) {
            Log::info('No eligible attempts for reconciliation');
            $this->markCompleted();
            return;
        }

        $chunks = array_chunk($attemptIds, self::CHUNK_SIZE);
        $jobs = array_map(
            fn($chunk) => new ProcessReconciliationChunkJob($chunk),
            $chunks
        );

        $type = $this->type;
        $uploadId = $this->uploadId;

        $batch = Bus::batch($jobs)
            ->name("Reconciliation: {$this->type}")
            ->onQueue('reconciliation')
            ->finally(function () use ($type, $uploadId) {
                if ($type === 'upload' && $uploadId) {
                    $upload = Upload::find($uploadId);
                    $upload?->markReconciliationCompleted();
                } else {
                    Cache::forget('reconciliation_bulk');
                }
                Log::info('Reconciliation batch completed', [
                    'type' => $type,
                    'upload_id' => $uploadId,
                ]);
            })
            ->dispatch();

        if ($this->type === 'upload' && $this->uploadId) {
            $upload = Upload::find($this->uploadId);
            $upload?->startReconciliation($batch->id);
        }

        Log::info('ProcessReconciliationJob dispatched chunks', [
            'total_attempts' => count($attemptIds),
            'chunks' => count($chunks),
            'batch_id' => $batch->id,
        ]);
    }

    private function markCompleted(): void
    {
        if ($this->type === 'upload' && $this->uploadId) {
            $upload = Upload::find($this->uploadId);
            $upload?->markReconciliationCompleted();
        } else {
            Cache::forget('reconciliation_bulk');
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessReconciliationJob failed', [
            'type' => $this->type,
            'upload_id' => $this->uploadId,
            'error' => $exception->getMessage(),
        ]);

        if ($this->type === 'upload' && $this->uploadId) {
            $upload = Upload::find($this->uploadId);
            $upload?->markReconciliationFailed();
        } else {
            Cache::forget('reconciliation_bulk');
        }
    }
}
