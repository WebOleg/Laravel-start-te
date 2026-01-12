<?php
/**
 * Dispatches VOP verification jobs for upload debtors.
 * Handles BAV sampling selection based on configured percentage.
 */
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
use Illuminate\Support\Facades\Cache;
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
        Log::info('ProcessVopJob started', ['upload_id' => $this->upload->id]);

        $uploadId = $this->upload->id;
        $lockKey = "vop_verify_{$uploadId}";

        $debtorIds = Debtor::where('upload_id', $uploadId)
            ->readyForVop()
            ->pluck('id')
            ->toArray();

        if (empty($debtorIds)) {
            Cache::forget($lockKey);
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

        Bus::batch($jobs)
            ->name("VOP Upload #{$uploadId}")
            ->allowFailures()
            ->onQueue('vop')
            ->finally(function () use ($lockKey, $uploadId) {
                Cache::forget($lockKey);
                Log::info('ProcessVopJob batch completed', [
                    'upload_id' => $uploadId,
                ]);

                // Dispatch job to generate VOP report
                GenerateVopReportJob::dispatch($uploadId);
            })
            ->dispatch();

        Log::info('ProcessVopJob dispatched', [
            'upload_id' => $uploadId,
            'debtors' => count($debtorIds),
            'bav_selected' => $bavSelectedCount,
            'chunks' => count($chunks),
        ]);
    }

    /**
     * Select debtors for BAV verification.
     * - For uploads <= 1000 records: use percentage sampling (default 10%)
     * - For uploads > 1000 records: max 100 BAV verifications
     *
     * @param array<int> $debtorIds
     * @return int
     */
    private function selectDebtorsForBav(array $debtorIds): int
    {
        if (!config('services.iban.bav_enabled', false)) {
            return 0;
        }

        $totalDebtors = count($debtorIds);
        $percentage = config('services.iban.bav_sampling_percentage', 10);
        $dailyLimit = config('services.iban.bav_daily_limit', 100);

        // Check daily limit
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

        // Calculate how many to select
        if ($totalDebtors > self::LARGE_UPLOAD_THRESHOLD) {
            // Large upload: max 100 BAV verifications
            $selectCount = self::LARGE_UPLOAD_BAV_LIMIT;
        } else {
            // Normal upload: use percentage
            $selectCount = (int) ceil($totalDebtors * ($percentage / 100));
        }

        // Apply daily limit
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
