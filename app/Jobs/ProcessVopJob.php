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

class ProcessVopJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [30, 60, 120];

    private const CHUNK_SIZE = 50;

    public function __construct(
        public Upload $upload,
        public bool $forceRefresh = false
    ) {
        $this->onQueue('vop');
    }

    public function uniqueId(): string
    {
        return 'vop_upload_' . $this->upload->id;
    }

    public function handle(): void
    {
        Log::info('ProcessVopJob started', ['upload_id' => $this->upload->id]);

        $debtors = Debtor::where('upload_id', $this->upload->id)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->where('iban_valid', true)
            ->whereDoesntHave('vopLogs')
            ->pluck('id')
            ->toArray();

        if (empty($debtors)) {
            Log::info('ProcessVopJob: no debtors to verify', ['upload_id' => $this->upload->id]);
            return;
        }

        $chunks = array_chunk($debtors, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $debtorIds) {
            $jobs[] = new ProcessVopChunkJob(
                debtorIds: $debtorIds,
                uploadId: $this->upload->id,
                chunkIndex: $index,
                forceRefresh: $this->forceRefresh
            );
        }

        Bus::batch($jobs)
            ->name("VOP Upload #{$this->upload->id}")
            ->allowFailures()
            ->finally(function () {
                Log::info('ProcessVopJob batch completed', ['upload_id' => $this->upload->id]);
            })
            ->dispatch();

        Log::info('ProcessVopJob dispatched', [
            'upload_id' => $this->upload->id,
            'debtors' => count($debtors),
            'chunks' => count($chunks),
        ]);
    }
}
