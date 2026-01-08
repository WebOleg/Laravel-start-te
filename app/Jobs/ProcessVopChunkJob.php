<?php
/**
 * Processes VOP verification for a chunk of debtors.
 * Handles BAV API calls with appropriate delays and timeouts.
 */
namespace App\Jobs;

use App\Models\Debtor;
use App\Services\VopVerificationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVopChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [10, 30, 60];

    private const DELAY_BETWEEN_REQUESTS_MS = 500;
    private const DELAY_AFTER_BAV_MS = 1000;

    public function __construct(
        public array $debtorIds,
        public int $uploadId,
        public int $chunkIndex,
        public bool $forceRefresh = false
    ) {
        $this->onQueue('vop');
    }

    public function handle(VopVerificationService $vopService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('ProcessVopChunkJob started', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'debtors' => count($this->debtorIds),
        ]);

        $verified = 0;
        $bavVerified = 0;
        $failed = 0;

        foreach ($this->debtorIds as $debtorId) {
            try {
                $debtor = Debtor::find($debtorId);
                if (!$debtor) {
                    continue;
                }

                $vopLog = $vopService->verify($debtor, $this->forceRefresh);

                if ($vopLog) {
                    $verified++;
                    if ($vopLog->bav_verified) {
                        $bavVerified++;
                        usleep(self::DELAY_AFTER_BAV_MS * 1000);
                    } else {
                        usleep(self::DELAY_BETWEEN_REQUESTS_MS * 1000);
                    }
                } else {
                    $failed++;
                    usleep(self::DELAY_BETWEEN_REQUESTS_MS * 1000);
                }

            } catch (\Exception $e) {
                $failed++;
                Log::error('VOP verification error', [
                    'debtor_id' => $debtorId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ProcessVopChunkJob completed', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'verified' => $verified,
            'bav_verified' => $bavVerified,
            'failed' => $failed,
        ]);
    }
}
