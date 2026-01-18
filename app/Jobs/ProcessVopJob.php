<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Debtor;
use App\Services\VopReportService;
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
    private const LARGE_UPLOAD_THRESHOLD = 1000;
    private const LARGE_UPLOAD_BAV_LIMIT = 100;

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
        $uploadId = $this->upload->id;

        Log::info('ProcessVopJob started', ['upload_id' => $uploadId]);

        $debtorIds = Debtor::where('upload_id', $uploadId)
            ->readyForVop()
            ->pluck('id')
            ->toArray();

        if (empty($debtorIds)) {
            $this->upload->markVopCompleted();
            Log::info('ProcessVopJob: no debtors to verify', ['upload_id' => $uploadId]);
            return;
        }

        $bavSelectedCount = $this->selectDebtorsForBav($debtorIds);

        $chunks = array_chunk($debtorIds, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessVopChunkJob(
                debtorIds: $chunk,
                uploadId: $uploadId,
                chunkIndex: $index,
                forceRefresh: $this->forceRefresh
            );
        }

        $upload = $this->upload;

        $batch = Bus::batch($jobs)
            ->name("VOP Upload #{$uploadId}")
            ->allowFailures()
            ->onQueue('vop')
            ->finally(function () use ($upload) {
                $upload->markVopCompleted();
                Log::info('ProcessVopJob batch completed', ['upload_id' => $upload->id]);

                // Automatically generate BAV CSV report after VOP completes
                GenerateVopReportJob::dispatch($upload->id);
                Log::info('GenerateVopReportJob dispatched', ['upload_id' => $upload->id]);
            })
            ->dispatch();

        $this->upload->startVop($batch->id);

        Log::info('ProcessVopJob dispatched', [
            'upload_id' => $uploadId,
            'batch_id' => $batch->id,
            'debtors' => count($debtorIds),
            'bav_selected' => $bavSelectedCount,
            'chunks' => count($chunks),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->upload->markVopFailed();
        Log::error('ProcessVopJob failed', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function selectDebtorsForBav(array $debtorIds): int
    {
        if (!config('services.iban.bav_enabled', false)) {
            return 0;
        }

        $totalDebtors = count($debtorIds);
        $percentage = config('services.iban.bav_sampling_percentage', 10);
        $dailyLimit = config('services.iban.bav_daily_limit', 100);

        $todayCount = Debtor::where('bav_selected', true)
            ->whereDate('updated_at', today())
            ->count();

        $remaining = max(0, $dailyLimit - $todayCount);
        if ($remaining === 0) {
            Log::info('ProcessVopJob: BAV daily limit reached', [
                'daily_limit' => $dailyLimit,
                'today_count' => $todayCount,
            ]);
            return 0;
        }

        if ($totalDebtors > self::LARGE_UPLOAD_THRESHOLD) {
            $selectCount = self::LARGE_UPLOAD_BAV_LIMIT;
        } else {
            $selectCount = (int) ceil($totalDebtors * ($percentage / 100));
        }

        $selectCount = min($selectCount, $remaining);

        if ($selectCount === 0) {
            return 0;
        }

        $selectedIds = collect($debtorIds)
            ->shuffle()
            ->take($selectCount)
            ->toArray();

        Debtor::whereIn('id', $selectedIds)->update(['bav_selected' => true]);

        Log::info('ProcessVopJob: BAV selection completed', [
            'upload_id' => $this->upload->id,
            'total_debtors' => $totalDebtors,
            'large_upload' => $totalDebtors > self::LARGE_UPLOAD_THRESHOLD,
            'percentage' => $percentage,
            'selected' => count($selectedIds),
            'daily_remaining' => $remaining - count($selectedIds),
        ]);

        return count($selectedIds);
    }
}
