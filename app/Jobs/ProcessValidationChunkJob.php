<?php

namespace App\Jobs;

use App\Models\Debtor;
use App\Services\DebtorValidationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessValidationChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public array $debtorIds,
        public int $uploadId,
        public int $chunkIndex
    ) {
        $this->onQueue('high');
    }

    public function handle(DebtorValidationService $validationService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('ProcessValidationChunkJob started', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'debtors' => count($this->debtorIds),
        ]);

        $valid = 0;
        $invalid = 0;

        foreach ($this->debtorIds as $debtorId) {
            try {
                $debtor = Debtor::find($debtorId);
                if (!$debtor) {
                    continue;
                }

                $validationService->validateAndUpdate($debtor);

                if ($debtor->validation_status === Debtor::VALIDATION_VALID) {
                    $valid++;
                } else {
                    $invalid++;
                }
            } catch (\Exception $e) {
                $invalid++;
                Log::error('Validation error', [
                    'debtor_id' => $debtorId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ProcessValidationChunkJob completed', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'valid' => $valid,
            'invalid' => $invalid,
        ]);
    }
}
