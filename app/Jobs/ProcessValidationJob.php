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
        Log::info('ProcessValidationJob started', ['upload_id' => $this->upload->id]);

        $debtorIds = Debtor::where('upload_id', $this->upload->id)
            ->whereNull('validated_at')
            ->pluck('id')
            ->toArray();

        if (empty($debtorIds)) {
            Log::info('ProcessValidationJob: no debtors to validate', ['upload_id' => $this->upload->id]);
            return;
        }

        $chunks = array_chunk($debtorIds, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessValidationChunkJob(
                debtorIds: $chunk,
                uploadId: $this->upload->id,
                chunkIndex: $index
            );
        }

        $uploadId = $this->upload->id;

        Bus::batch($jobs)
            ->name("Validation Upload #{$uploadId}")
            ->allowFailures()
            ->onQueue('high')
            ->dispatch();

        Log::info('ProcessValidationJob dispatched', [
            'upload_id' => $this->upload->id,
            'debtors' => count($debtorIds),
            'chunks' => count($chunks),
        ]);
    }
}
