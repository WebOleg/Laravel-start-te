<?php

/**
 * Background job for File Clearance.
 *
 * Downloads the raw file from S3 (same pattern as ProcessUploadJob),
 * streams rows via generator (constant memory), processes each row
 * (VOP BIC resolution + blacklist filtering + name validation),
 * writes cleared rows incrementally to a CSV, and writes excluded rows
 * to 3 separate exclusion CSVs:
 *   - excluded_ibans:  rows filtered by IBAN blacklist
 *   - excluded_bics:   rows filtered by BIC blacklist
 *   - invalid_names:   rows with invalid first/last name characters
 *
 * Updates progress in cache for polling.
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
use Illuminate\Support\Facades\Storage;

class ProcessFileClearanceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    // 1h — large files with many VOP calls
    public int $timeout = 3600;
    public int $backoff = 30;

    private const PROGRESS_INTERVAL    = 50;
    private const MAX_EXCLUDED_STORED  = 500;
    private array $progressState = [];

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
        $lock = Cache::lock("file_clearance_lock:{$this->token}", $this->timeout);

        if (!$lock->get()) {
            Log::warning('ProcessFileClearanceJob: another instance already running', [
                'token' => $this->token,
            ]);
            return;
        }

        try {
            Log::info('ProcessFileClearanceJob: started', [
                'token'      => $this->token,
                'total_rows' => $this->totalRows,
                's3_path'    => $this->s3Path,
                'file'       => $this->originalFileName,
            ]);

            $this->progressState = Cache::get("file_clearance:{$this->token}", []);

            $this->updateProgress([
                'status'     => 'processing',
                'total_rows' => $this->totalRows,
                'processed'  => 0,
            ]);

            // 1. Download from S3 to temp
            $tempPath = $service->downloadFromS3($this->s3Path);

            // Pre-load all blacklist data into memory — eliminates DB queries per row
            $blacklistService->preload();


            $bicInjected   = ($this->headerMeta['bic_header'] === null);
            $outputHeaders = $this->headers;
            if ($bicInjected) {
                $outputHeaders[] = 'bic';
            }

            // Exclusion file headers = original headers + reason column
            $exclusionHeaders = array_merge($this->headers, [FileClearanceService::EXCLUSION_REASON_HEADER]);

            // 2. Open incremental CSV writers — main cleared + 3 exclusion files
            [$csvHandle, $csvPath, $csvFileName] = $service->openCsvWriter(
                $outputHeaders, $this->originalFileName, FileClearanceService::SUFFIX_CLEARED
            );

            [$exIbanHandle, $exIbanPath, $exIbanFileName] = $service->openCsvWriter(
                $exclusionHeaders, $this->originalFileName, FileClearanceService::SUFFIX_EXCLUDED_IBANS
            );

            [$exBicHandle, $exBicPath, $exBicFileName] = $service->openCsvWriter(
                $exclusionHeaders, $this->originalFileName, FileClearanceService::SUFFIX_EXCLUDED_BICS
            );

            [$exNameHandle, $exNamePath, $exNameFileName] = $service->openCsvWriter(
                $exclusionHeaders, $this->originalFileName, FileClearanceService::SUFFIX_INVALID_NAMES
            );

            $excludedDetails    = [];
            $vopResolved        = 0;
            $vopFailed          = 0;
            $clearedCount       = 0;
            $excludedCount      = 0;
            $excludedIbanCount  = 0;
            $excludedBicCount   = 0;
            $invalidNameCount   = 0;
            $processed          = 0;

            try {
                // 3. Stream rows in chunks for batched VOP resolution
                $chunk     = [];
                $chunkSize = 100;

                foreach ($service->streamRows($tempPath, $this->headers) as [$rowIndex, $row]) {
                    $chunk[] = [$rowIndex, $row];

                    if (count($chunk) >= $chunkSize) {
                        $this->processChunk(
                            $chunk, $service, $ibanApiService, $ibanValidator, $blacklistService,
                            $csvHandle, $outputHeaders, $bicInjected,
                            $exIbanHandle, $exBicHandle, $exNameHandle, $exclusionHeaders,
                            $clearedCount, $excludedCount, $excludedIbanCount, $excludedBicCount,
                            $invalidNameCount, $vopResolved, $vopFailed, $processed, $excludedDetails,
                        );
                        $chunk = [];
                    }
                }

                // Flush remaining rows
                if (!empty($chunk)) {
                    $this->processChunk(
                        $chunk, $service, $ibanApiService, $ibanValidator, $blacklistService,
                        $csvHandle, $outputHeaders, $bicInjected,
                        $exIbanHandle, $exBicHandle, $exNameHandle, $exclusionHeaders,
                        $clearedCount, $excludedCount, $excludedIbanCount, $excludedBicCount,
                        $invalidNameCount, $vopResolved, $vopFailed, $processed, $excludedDetails,
                    );
                }
            } finally {
                // Close all CSV writers — exclusion files skip upload if empty
                $csvPath     = $service->closeCsvWriter($csvHandle, $csvPath, $csvFileName);
                $exIbanPath  = $service->closeCsvWriter($exIbanHandle, $exIbanPath, $exIbanFileName, skipIfEmpty: true);
                $exBicPath   = $service->closeCsvWriter($exBicHandle, $exBicPath, $exBicFileName, skipIfEmpty: true);
                $exNamePath  = $service->closeCsvWriter($exNameHandle, $exNamePath, $exNameFileName, skipIfEmpty: true);
            }

            // Wait for S3 consistency on the main cleared file
//            $maxWait = 60;
//            for ($i = 0; $i < $maxWait; $i++) {
//                if (Storage::disk('s3')->exists($csvPath)) break;
//                sleep(1);
//            }

            // 4. Cleanup
            $this->cleanupTempFile($tempPath);
            $service->deleteFromS3($this->s3Path);

            // 5. Final cache update
            $this->updateProgress([
                'status'                   => 'completed',
                'total_rows'               => $this->totalRows,
                'processed'                => $processed,
                'cleared_rows'             => $clearedCount,
                'excluded_rows'            => $excludedCount,
                'excluded_iban_rows'       => $excludedIbanCount,
                'excluded_bic_rows'        => $excludedBicCount,
                'invalid_name_rows'        => $invalidNameCount,
                'vop_resolved'             => $vopResolved,
                'vop_failed'               => $vopFailed,
                'headers'                  => $outputHeaders,
                'excluded_details'         => $excludedDetails,
                's3_path_result'           => $csvPath,
                's3_path_excluded_ibans'   => $exIbanPath,
                's3_path_excluded_bics'    => $exBicPath,
                's3_path_invalid_names'    => $exNamePath,
                'file_name'                => basename($csvPath),
                'file_name_excluded_ibans' => $exIbanPath ? basename($exIbanPath) : null,
                'file_name_excluded_bics'  => $exBicPath ? basename($exBicPath) : null,
                'file_name_invalid_names'  => $exNamePath ? basename($exNamePath) : null,
                'completed_at'             => now()->toISOString(),
            ]);

            Log::info('ProcessFileClearanceJob: completed', [
                'token'              => $this->token,
                'total'              => $this->totalRows,
                'cleared'            => $clearedCount,
                'excluded'           => $excludedCount,
                'excluded_iban'      => $excludedIbanCount,
                'excluded_bic'       => $excludedBicCount,
                'invalid_name'       => $invalidNameCount,
                'vop_resolved'       => $vopResolved,
                'vop_failed'         => $vopFailed,
                'csv'                => $csvPath,
                'csv_excluded_ibans' => $exIbanPath,
                'csv_excluded_bics'  => $exBicPath,
                'csv_invalid_names'  => $exNamePath,
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFileClearanceJob: failed', [
            'token' => $this->token,
            'error' => $exception->getMessage(),
        ]);

        if (empty($this->progressState)) {
            $this->progressState = Cache::get("file_clearance:{$this->token}", []);
        }

        $this->updateProgress([
            'status' => 'failed',
            'error'  => $exception->getMessage(),
        ]);
    }

    private function updateProgress(array $data): void
    {
        $this->progressState = array_merge($this->progressState, $data);
        Cache::put("file_clearance:{$this->token}", $this->progressState, 7200);
    }

    private function cleanupTempFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    private function processChunk(
        array $chunk,
        FileClearanceService $service,
        IbanApiService $ibanApiService,
        IbanValidator $ibanValidator,
        BlacklistService $blacklistService,
        $csvHandle, array $outputHeaders, bool $bicInjected,
        $exIbanHandle, $exBicHandle, $exNameHandle, array $exclusionHeaders,
        int &$clearedCount, int &$excludedCount,
        int &$excludedIbanCount, int &$excludedBicCount, int &$invalidNameCount,
        int &$vopResolved, int &$vopFailed, int &$processed,
        array &$excludedDetails,
    ): void {
        $ibanHeader = $this->headerMeta['iban_header'];

        // 1. Collect IBANs that need VOP resolution
        $ibansToResolve = [];
        foreach ($chunk as [$rowIndex, $row]) {
            $iban = trim($row[$ibanHeader] ?? '');
            if (!empty($iban)) {
                $normalized = $ibanValidator->normalize($iban);
                $validation = $ibanValidator->validate($normalized);
                if ($validation['valid'] && $validation['is_sepa']) {
                    $ibansToResolve[$rowIndex] = $normalized;
                }
            }
        }

        // 2. Batch-resolve BICs via concurrent HTTP
        $resolvedBics = [];
        if (!empty($ibansToResolve)) {
            $resolvedBics = $ibanApiService->getBicBatch($ibansToResolve);
        }

        // 3. Inject resolved BICs into service->processRow via a wrapper
        //    We temporarily set the BIC in the row so processRow picks it up
        //    from VOP without making another HTTP call.
        foreach ($chunk as [$rowIndex, $row]) {
            $iban = trim($row[$ibanHeader] ?? '');
            $normalizedIban = !empty($iban) ? $ibanValidator->normalize($iban) : '';

            // Pre-inject VOP result so processRow skips the HTTP call
            $preResolvedBic = $resolvedBics[$normalizedIban] ?? null;

            $result = $service->processRow(
                row: $row,
                displayRowIndex: $rowIndex + 2,
                headerMeta: $this->headerMeta,
                ibanApiService: $ibanApiService,
                ibanValidator: $ibanValidator,
                blacklistService: $blacklistService,
                preResolvedBic: $preResolvedBic, // NEW parameter
            );

            // ... same write logic as before ...
            if ($result['row'] !== null) {
                $service->writeCsvRow($csvHandle, $outputHeaders, $result['row'], $bicInjected);
                $clearedCount++;
            }

            if ($result['excluded'] !== null) {
                $excludedCount++;
                if (count($excludedDetails) < self::MAX_EXCLUDED_STORED) {
                    $excludedDetails[] = $result['excluded'];
                }
                $reasonStr  = implode('; ', $result['excluded']['reasons'] ?? []);
                $categories = $result['exclusion_categories'] ?? [];

                if (in_array(FileClearanceService::CATEGORY_IBAN_BLACKLISTED, $categories)) {
                    $service->writeExclusionCsvRow($exIbanHandle, $exclusionHeaders, $row, $reasonStr);
                    $excludedIbanCount++;
                }
                if (in_array(FileClearanceService::CATEGORY_BIC_BLACKLISTED, $categories)) {
                    $service->writeExclusionCsvRow($exBicHandle, $exclusionHeaders, $row, $reasonStr);
                    $excludedBicCount++;
                }
                if (in_array(FileClearanceService::CATEGORY_INVALID_NAME, $categories)) {
                    $service->writeExclusionCsvRow($exNameHandle, $exclusionHeaders, $row, $reasonStr);
                    $invalidNameCount++;
                }
            }

            if ($result['vop_resolved']) $vopResolved++;
            if ($result['vop_failed'])   $vopFailed++;
            $processed++;
        }

        // 4. Progress update once per chunk
        $this->updateProgress([
            'status'             => 'processing',
            'total_rows'         => $this->totalRows,
            'processed'          => $processed,
            'cleared_rows'       => $clearedCount,
            'excluded_rows'      => $excludedCount,
            'excluded_iban_rows' => $excludedIbanCount,
            'excluded_bic_rows'  => $excludedBicCount,
            'invalid_name_rows'  => $invalidNameCount,
            'vop_resolved'       => $vopResolved,
            'vop_failed'         => $vopFailed,
        ]);
    }
}
