<?php

/**
 * Background job for File Clearance.
 *
 * Downloads the raw file from S3 (same pattern as ProcessUploadJob),
 * streams rows via generator (constant memory), processes each row
 * (VOP BIC resolution + blacklist filtering), writes cleared rows
 * incrementally to a CSV, and updates progress in cache for polling.
 *
 * After completion, deletes the source file from S3 and the temp file.
 *
 * Cache key: "file_clearance:{token}"
 */

namespace App\Jobs;

use App\Services\BlacklistService;
use App\Services\FileClearanceService;
use App\Services\IbanApiService;
use App\Services\IbanValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessFileClearanceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    // 1h — large files with many VOP calls
    public int $timeout = 3600;
    public int $backoff = 30;

    private const PROGRESS_INTERVAL    = 50;
    private const MAX_EXCLUDED_STORED  = 500;

    public function __construct(
        private string $token,
        private string $s3Path,
        private array  $headers,
        private array  $headerMeta,
        private string $originalFileName,
        private int    $totalRows,
    ) {
        $this->onQueue('clearance');
    }

    public function uniqueId(): string
    {
        return 'file_clearance_' . $this->token;
    }

    public function handle(
        FileClearanceService $service,
        IbanApiService       $ibanApiService,
        IbanValidator        $ibanValidator,
        BlacklistService     $blacklistService,
    ): void {
        Log::info('ProcessFileClearanceJob: started', [
            'token'      => $this->token,
            'total_rows' => $this->totalRows,
            's3_path'    => $this->s3Path,
            'file'       => $this->originalFileName,
        ]);

        $this->updateProgress([
            'status'     => 'processing',
            'total_rows' => $this->totalRows,
            'processed'  => 0,
        ]);

        // 1. Download from S3 to temp (same as ProcessUploadJob)
        $tempPath = $service->downloadFromS3($this->s3Path);

        $bicInjected   = ($this->headerMeta['bic_header'] === null);
        $outputHeaders = $this->headers;
        if ($bicInjected) {
            $outputHeaders[] = 'bic';
        }

        // 2. Open incremental CSV writer
        [$csvHandle, $csvPath] = $service->openCsvWriter($outputHeaders, $this->originalFileName);

        $excludedDetails = [];
        $vopResolved     = 0;
        $vopFailed       = 0;
        $clearedCount    = 0;
        $excludedCount   = 0;
        $processed       = 0;

        try {
            // 3. Stream rows from temp file — generator, constant memory
            foreach ($service->streamRows($tempPath, $this->headers) as [$rowIndex, $row]) {
                $result = $service->processRow(
                    row: $row,
                    displayRowIndex: $rowIndex + 2, // +2 = header row + 0-index
                    headerMeta: $this->headerMeta,
                    ibanApiService: $ibanApiService,
                    ibanValidator: $ibanValidator,
                    blacklistService: $blacklistService,
                );

                if ($result['row'] !== null) {
                    $service->writeCsvRow($csvHandle, $outputHeaders, $result['row'], $bicInjected);
                    $clearedCount++;
                }

                if ($result['excluded'] !== null) {
                    $excludedCount++;
                    if (count($excludedDetails) < self::MAX_EXCLUDED_STORED) {
                        $excludedDetails[] = $result['excluded'];
                    }
                }

                if ($result['vop_resolved']) $vopResolved++;
                if ($result['vop_failed'])   $vopFailed++;

                $processed++;

                if ($processed % self::PROGRESS_INTERVAL === 0) {
                    $this->updateProgress([
                        'status'        => 'processing',
                        'total_rows'    => $this->totalRows,
                        'processed'     => $processed,
                        'cleared_rows'  => $clearedCount,
                        'excluded_rows' => $excludedCount,
                        'vop_resolved'  => $vopResolved,
                        'vop_failed'    => $vopFailed,
                    ]);
                }
            }
        } finally {
            $service->closeCsvWriter($csvHandle);
        }

        // 4. Cleanup: temp file + S3 source
        $this->cleanupTempFile($tempPath);
        $service->deleteFromS3($this->s3Path);

        // 5. Final cache update
        $this->updateProgress([
            'status'           => 'completed',
            'total_rows'       => $this->totalRows,
            'processed'        => $processed,
            'cleared_rows'     => $clearedCount,
            'excluded_rows'    => $excludedCount,
            'vop_resolved'     => $vopResolved,
            'vop_failed'       => $vopFailed,
            'headers'          => $outputHeaders,
            'excluded_details' => $excludedDetails,
            'file_path'        => $csvPath,
            'file_name'        => basename($csvPath),
            'completed_at'     => now()->toISOString(),
        ]);

        Log::info('ProcessFileClearanceJob: completed', [
            'token'        => $this->token,
            'total'        => $this->totalRows,
            'cleared'      => $clearedCount,
            'excluded'     => $excludedCount,
            'vop_resolved' => $vopResolved,
            'vop_failed'   => $vopFailed,
            'csv'          => $csvPath,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFileClearanceJob: failed', [
            'token' => $this->token,
            'error' => $exception->getMessage(),
        ]);

        $this->updateProgress([
            'status' => 'failed',
            'error'  => $exception->getMessage(),
        ]);
    }

    private function updateProgress(array $data): void
    {
        $cacheKey = "file_clearance:{$this->token}";
        $existing = Cache::get($cacheKey, []);
        Cache::put($cacheKey, array_merge($existing, $data), 7200);
    }

    private function cleanupTempFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}
