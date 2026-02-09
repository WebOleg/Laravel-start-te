<?php
/**
 * Process BAV verification for a chunk of debtors.
 */
namespace App\Jobs;

use App\Models\BavCredit;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\IbanBavService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessBavJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 60;

    /**
     * @param int $uploadId
     * @param array<int> $debtorIds
     * @param string $batchId
     */
    public function __construct(
        public int $uploadId,
        public array $debtorIds,
        public string $batchId
    ) {
        $this->onQueue('bav');
    }

    public function handle(IbanBavService $bavService): void
    {
        if ($this->batch()?->cancelled()) {
            Log::channel('bav')->info('ProcessBavJob: Batch cancelled', [
                'upload_id' => $this->uploadId,
                'batch_id' => $this->batchId,
            ]);
            return;
        }

        $upload = Upload::find($this->uploadId);
        if (!$upload) {
            Log::channel('bav')->error('ProcessBavJob: Upload not found', [
                'upload_id' => $this->uploadId,
            ]);
            return;
        }

        Log::channel('bav')->info('ProcessBavJob: Starting chunk', [
            'upload_id' => $this->uploadId,
            'debtor_count' => count($this->debtorIds),
        ]);

        $processed = 0;
        $debtors = Debtor::whereIn('id', $this->debtorIds)->get();

        foreach ($debtors as $debtor) {
            if ($this->batch()?->cancelled()) {
                break;
            }

            // Check and consume credit before API call
            if (!BavCredit::consume(1)) {
                Log::channel('bav')->warning('ProcessBavJob: No credits remaining, stopping', [
                    'upload_id' => $this->uploadId,
                    'processed_in_chunk' => $processed,
                ]);
                break;
            }

            $this->verifyDebtor($debtor, $bavService);
            $processed++;

            $this->updateProgress($upload);
        }

        Log::channel('bav')->info('ProcessBavJob: Chunk completed', [
            'upload_id' => $this->uploadId,
            'processed' => $processed,
        ]);
    }

    private function verifyDebtor(Debtor $debtor, IbanBavService $bavService): void
    {
        $name = trim($debtor->first_name . ' ' . $debtor->last_name);
        $iban = $debtor->iban;

        if (empty($iban) || empty($name)) {
            Log::channel('bav')->warning('ProcessBavJob: Missing IBAN or name', [
                'debtor_id' => $debtor->id,
            ]);
            return;
        }

        $result = $bavService->verify($iban, $name);

        $vopLog = VopLog::where('debtor_id', $debtor->id)->first();
        if ($vopLog) {
            $bavScore = $this->calculateBavScore($result['name_match']);
            $newVopScore = min(100, ($vopLog->vop_score ?? 0) + $bavScore);

            $vopLog->update([
                'bav_verified' => $result['success'],
                'bav_name_match' => $result['name_match'],
                'bav_verified_at' => now(),
                'vop_score' => $newVopScore,
            ]);

            Log::channel('bav')->info('ProcessBavJob: Debtor verified', [
                'debtor_id' => $debtor->id,
                'name_match' => $result['name_match'],
                'bav_score' => $bavScore,
                'new_vop_score' => $newVopScore,
            ]);
        }
    }

    private function calculateBavScore(string $nameMatch): int
    {
        return match ($nameMatch) {
            'yes' => 15,
            'partial' => 10,
            'unavailable' => 0,
            'no' => 0,
            default => 0,
        };
    }

    private function updateProgress(Upload $upload): void
    {
        $upload->incrementBavProcessed();

        $cacheKey = "bav_progress_{$this->uploadId}";
        $cached = Cache::get($cacheKey, []);
        $cached['processed'] = ($cached['processed'] ?? 0) + 1;
        Cache::put($cacheKey, $cached, 3600);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('bav')->error('ProcessBavJob: Failed', [
            'upload_id' => $this->uploadId,
            'error' => $exception->getMessage(),
        ]);
    }
}
