<?php

namespace App\Jobs;

use App\Enums\BillingModel;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Services\DebtorImportService;
use App\Services\DeduplicationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessUploadChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public Upload $upload,
        public array $rows,
        public array $columnMapping,
        public int $chunkIndex,
        public int $startRow
    ) {
        $this->onQueue('default');
    }

    public function handle(DebtorImportService $debtorImportService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info("ProcessUploadChunkJob started", [
            'upload_id' => $this->upload->id,
            'chunk' => $this->chunkIndex,
            'rows' => count($this->rows),
            'billing_model' => $this->upload->billing_model,
        ]);

        $result = $debtorImportService->importRows(
            upload: $this->upload,
            rows: $this->rows,
            columnMapping: $this->columnMapping,
            validateBasicStructure: true,
            startRow: $this->startRow
        );

        $this->upload->increment('processed_records', (int) ($result['created'] ?? 0));
        $this->upload->increment('failed_records', (int) ($result['failed'] ?? 0));

        $this->storeChunkResult($result);

        Log::info("ProcessUploadChunkJob completed", [
            'upload_id' => $this->upload->id,
            'chunk' => $this->chunkIndex,
            'created' => $result['created'] ?? 0,
            'failed' => $result['failed'] ?? 0,
            'skipped_total' => ($result['skipped']['total'] ?? null),
            'billing_model' => $this->upload->billing_model,
        ]);
    }

    private function storeChunkResult(array $result): void
    {
        $uploadId = $this->upload->id;
        $chunkKey = (string) $this->chunkIndex;

        // Keys expected in the 'skipped' statistics breakdown
        // Merged keys from HEAD and main to ensure full coverage
        $skippedKeys = [
            'total',
            'blacklisted',
            'chargebacked',
            'blacklisted_name',
            'already_recovered',
            'blacklisted_email',
            'blacklisted_bic', 
            'recently_attempted',
            'duplicates',
        ];

        DB::transaction(function () use ($uploadId, $chunkKey, $result, $skippedKeys) {
            /** @var Upload $upload */
            $upload = Upload::query()->lockForUpdate()->findOrFail($uploadId);

            $meta = $upload->meta ?? [];

            // 1. Initialize global structure if missing
            if (!isset($meta['skipped'])) {
                $meta['skipped'] = array_fill_keys($skippedKeys, 0);
            }
            if (!isset($meta['errors'])) {
                $meta['errors'] = [];
            }
            if (!isset($meta['skipped_rows'])) {
                $meta['skipped_rows'] = [];
            }

            // 2. Aggregate 'Skipped' Counters (Global + Current Chunk)
            $chunkSkippedStats = $result['skipped'] ?? [];

            foreach ($skippedKeys as $key) {
                $currentGlobal = (int) ($meta['skipped'][$key] ?? 0);
                $newChunkValue = (int) ($chunkSkippedStats[$key] ?? 0);

                $meta['skipped'][$key] = $currentGlobal + $newChunkValue;
            }

            // 3. Aggregate Arrays (Errors & Skipped Rows)
            // We merge the new items into the existing global list.
            // NOTE: We apply a slice (e.g., 100) to prevent the JSON column from becoming too large.
            $meta['errors'] = array_merge($meta['errors'], $result['errors'] ?? []);
            $meta['errors'] = array_slice($meta['errors'], 0, 100);

            $meta['skipped_rows'] = array_merge($meta['skipped_rows'], $result['skipped_rows'] ?? []);
            $meta['skipped_rows'] = array_slice($meta['skipped_rows'], 0, 100);

            // 4. Store individual chunk payload
            $chunks = $meta['chunks'] ?? [];
            $chunks[$chunkKey] = [
                'created'      => (int) ($result['created'] ?? 0),
                'failed'       => (int) ($result['failed'] ?? 0),
                'skipped'      => $result['skipped'] ?? [],
                'errors'       => array_slice(($result['errors'] ?? []), 0, 20),
                'skipped_rows' => array_slice(($result['skipped_rows'] ?? []), 0, 20),
            ];
            $meta['chunks'] = $chunks;

            $upload->update(['meta' => $meta]);
        });
    }
}
