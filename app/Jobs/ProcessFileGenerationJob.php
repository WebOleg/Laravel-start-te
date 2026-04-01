<?php

namespace App\Jobs;

use App\Models\FileGenerationBatch;
use App\Services\BlacklistService;
use App\Services\FileClearanceService;
use App\Services\FileGenerationService;
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

class ProcessFileGenerationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 3600;
    public int $backoff = 30;

    private array $progressState = [];

    /**
     * @param array $fileConfigs  Per-file configs: [{amount: float, tolerance: float}, ...]
     */
    public function __construct(
        private string $token,
        private int    $batchId,
        private string $s3Path,
        private array  $headers,
        private array  $headerMeta,
        private string $originalFileName,
        private int    $totalRows,
        private string $pricingStrategy,
        private array  $pricingAmounts,
        private array  $pricingWeights,
        private array  $fileConfigs = [],
    ) {
        $this->onQueue('generation');

        // Ensure at least one config
        if (empty($this->fileConfigs)) {
            $this->fileConfigs = [['amount' => 0, 'tolerance' => 200]];
        }
    }

    public function uniqueId(): string
    {
        return 'file_generation_' . $this->token;
    }

    public function handle(
        FileGenerationService $generationService,
        FileClearanceService  $clearanceService,
        IbanApiService        $ibanApiService,
        IbanValidator         $ibanValidator,
        BlacklistService      $blacklistService,
    ): void {
        $lock = Cache::lock("file_generation_lock:{$this->token}", $this->timeout);

        if (!$lock->get()) {
            Log::warning('ProcessFileGenerationJob: already running', ['token' => $this->token]);
            return;
        }

        $fileCount = count($this->fileConfigs);

        try {
            Log::info('ProcessFileGenerationJob: started', [
                'token'        => $this->token,
                'batch_id'     => $this->batchId,
                'total_rows'   => $this->totalRows,
                'file_configs' => $this->fileConfigs,
                'pricing'      => $this->pricingStrategy,
            ]);

            $this->progressState = Cache::get("file_generation:{$this->token}", []);
            $this->updateProgress(['status' => 'processing', 'phase' => 'downloading']);

            $batch = FileGenerationBatch::findOrFail($this->batchId);
            $batch->update(['status' => 'processing']);

            // ── 1. Download source file ──
            $tempPath = $clearanceService->downloadFromS3($this->s3Path);

            // ── 2. Pre-load exclusion sets ──
            $this->updateProgress(['phase' => 'loading_filters']);

            $blacklistService->preload();
            $previouslyUsed = $generationService->loadPreviouslyUsedIbans();
            $recentlyBilled = $generationService->loadRecentlyBilledIbans();

            Log::info('ProcessFileGenerationJob: filters loaded', [
                'token'           => $this->token,
                'previously_used' => count($previouslyUsed),
                'recently_billed' => count($recentlyBilled),
            ]);

            // ── 3. Stream rows → filter → collect eligible ──
            $this->updateProgress(['phase' => 'filtering']);

            $ibanHeader = $this->headerMeta['iban_header'];
            $bicHeader  = $this->headerMeta['bic_header'];

            $eligibleRows           = [];
            $processed              = 0;
            $excludedBlacklistCount = 0;
            $excludedBillingCount   = 0;
            $excludedPrevUsedCount  = 0;
            $excludedOtherCount     = 0;

            foreach ($clearanceService->streamRows($tempPath, $this->headers) as [$rowIndex, $row]) {
                $processed++;

                $rawIban = trim($row[$ibanHeader] ?? '');
                if (empty($rawIban)) {
                    $excludedOtherCount++;
                    $this->maybeUpdateFilterProgress($processed);
                    continue;
                }

                $iban = $ibanValidator->normalize($rawIban);

                $check = $generationService->checkEligibility($iban, $previouslyUsed, $recentlyBilled);

                if (!$check['eligible']) {
                    match ($check['reason']) {
                        'IBAN blacklisted'                 => $excludedBlacklistCount++,
                        'Previously used'                  => $excludedPrevUsedCount++,
                        'Billing activity in last 30 days' => $excludedBillingCount++,
                        default                            => $excludedOtherCount++,
                    };
                    $this->maybeUpdateFilterProgress($processed);
                    continue;
                }

                $eligibleRows[] = [
                    'row_index' => $rowIndex,
                    'row'       => $row,
                    'iban'      => $iban,
                ];

                $this->maybeUpdateFilterProgress($processed);
            }

            $this->updateProgress([
                'processed'                     => $processed,
                'eligible_rows'                 => count($eligibleRows),
                'excluded_blacklist_rows'       => $excludedBlacklistCount,
                'excluded_billing_rows'         => $excludedBillingCount,
                'excluded_previously_used_rows' => $excludedPrevUsedCount,
            ]);

            // ── 4. Batch-resolve BICs via VOP ──
            $this->updateProgress(['phase' => 'resolving_bics']);

            $ibansToResolve = [];
            foreach ($eligibleRows as $item) {
                $ibansToResolve[$item['row_index']] = $item['iban'];
            }

            $resolvedBics = [];
            if (!empty($ibansToResolve)) {
                $resolvedBics = $ibanApiService->getBicBatch($ibansToResolve);
            }

            // ── 5. Post-BIC-resolution: filter BIC-blacklisted rows ──
            $this->updateProgress(['phase' => 'filtering_bics']);

            $eligibleAfterBic = [];
            foreach ($eligibleRows as $item) {
                $bic = $resolvedBics[$item['iban']] ?? ($bicHeader ? trim($item['row'][$bicHeader] ?? '') : '');

                if (!empty($bic) && $blacklistService->isBicBlacklisted($bic)) {
                    $excludedBlacklistCount++;
                    continue;
                }

                $item['resolved_bic'] = $bic;
                $eligibleAfterBic[] = $item;
            }

            $this->updateProgress([
                'eligible_rows'           => count($eligibleAfterBic),
                'excluded_blacklist_rows' => $excludedBlacklistCount,
            ]);

            // ══════════════════════════════════════════════════════════
            // 6. Select records + assign amounts — per file config
            //
            // Each file has its own target amount and tolerance.
            // After each selection round, consumed rows are removed
            // from the pool so no row appears in multiple files.
            // ══════════════════════════════════════════════════════════
            $this->updateProgress(['phase' => 'selecting']);

            $bicInjected   = ($bicHeader === null);
            $outputHeaders = $generationService->buildOutputHeaders($this->headers, $bicInjected);

            $remainingPool      = $eligibleAfterBic;
            $allSelected        = [];
            $resultFiles        = [];
            $totalAchieved      = 0.0;
            $totalSelectedCount = 0;
            $warning            = null;

            foreach ($this->fileConfigs as $fileIndex => $config) {
                $fileNum       = $fileIndex + 1;
                $fileAmount    = (float) ($config['amount'] ?? 0);
                $fileTolerance = (float) ($config['tolerance'] ?? 200);

                if (empty($remainingPool)) {
                    $warning = "Eligible rows exhausted after file " . ($fileNum - 1) . " of {$fileCount}. "
                        . "Not enough records to fill all requested files.";
                    Log::warning('ProcessFileGenerationJob: pool exhausted', [
                        'token'           => $this->token,
                        'files_completed' => $fileNum - 1,
                        'files_requested' => $fileCount,
                    ]);
                    break;
                }

                $selectionResult = $generationService->selectAndAssignAmounts(
                    $remainingPool,
                    $fileAmount,
                    $fileTolerance,
                    $this->pricingAmounts,
                    $this->pricingWeights,
                );

                $selected       = $selectionResult['selected'];
                $achievedAmount = $selectionResult['achieved_amount'];

                if (empty($selected)) {
                    $warning = "Could not select any records for file {$fileNum} of {$fileCount} "
                        . "(target {$fileAmount}, tolerance ±{$fileTolerance}). "
                        . "Remaining eligible pool too small.";
                    Log::warning('ProcessFileGenerationJob: selection empty', [
                        'token'          => $this->token,
                        'file_num'       => $fileNum,
                        'target'         => $fileAmount,
                        'remaining_pool' => count($remainingPool),
                    ]);
                    break;
                }

                Log::info('ProcessFileGenerationJob: file selection complete', [
                    'token'           => $this->token,
                    'file_num'        => $fileNum,
                    'target'          => $fileAmount,
                    'tolerance'       => $fileTolerance,
                    'selected_count'  => count($selected),
                    'achieved_amount' => $achievedAmount,
                    'remaining_pool'  => count($remainingPool) - count($selected),
                ]);

                // ── Write this file's CSV ──
                $suffix = $fileCount > 1
                    ? 'generated_' . $fileNum . '_of_' . $fileCount
                    : 'generated';

                [$csvHandle, $csvPath, $csvFileName] = $clearanceService->openCsvWriter(
                    $outputHeaders, $this->originalFileName, $suffix
                );

                foreach ($selected as $item) {
                    $generationService->writeOutputRow(
                        $csvHandle, $outputHeaders, $item['row'],
                        $item['assigned_amount'], $bicInjected, $item['resolved_bic'] ?? '',
                    );
                }

                $s3ResultPath = $clearanceService->closeCsvWriter($csvHandle, $csvPath, $csvFileName);

                $resultFiles[] = [
                    's3_path'        => $s3ResultPath,
                    'file_name'      => $csvFileName,
                    'row_count'      => count($selected),
                    'amount'         => round($achievedAmount, 2),
                    'target_amount'  => $fileAmount,
                    'tolerance'      => $fileTolerance,
                ];

                // ── Remove selected rows from the pool ──
                $usedRowIndices = [];
                foreach ($selected as $item) {
                    $usedRowIndices[$item['row_index']] = true;
                    $allSelected[] = $item;
                }

                $remainingPool = array_values(array_filter(
                    $remainingPool,
                    fn($item) => !isset($usedRowIndices[$item['row_index']])
                ));

                $totalAchieved      += $achievedAmount;
                $totalSelectedCount += count($selected);

                $this->updateProgress([
                    'phase'           => "writing_file_{$fileNum}_of_{$fileCount}",
                    'selected_rows'   => $totalSelectedCount,
                    'achieved_amount' => round($totalAchieved, 2),
                ]);
            }

            // ── No files at all ──
            if (empty($allSelected)) {
                $this->finalizeBatch($batch, $clearanceService, $tempPath, [
                    'status'          => 'completed',
                    'selected_rows'   => 0,
                    'achieved_amount' => 0,
                    'warning'         => $warning ?? 'No eligible records found to reach any target amount.',
                ], $excludedBlacklistCount, $excludedBillingCount, $excludedPrevUsedCount);
                return;
            }

            Log::info('ProcessFileGenerationJob: all files written', [
                'token'           => $this->token,
                'files_generated' => count($resultFiles),
                'files_requested' => $fileCount,
                'total_selected'  => $totalSelectedCount,
                'total_achieved'  => round($totalAchieved, 2),
            ]);

            // ── 7. Write leftover CSV ──
            $this->updateProgress(['phase' => 'writing_leftover']);

            $leftoverRows     = $remainingPool;
            $s3LeftoverPath   = null;
            $leftoverFileName = null;

            if (!empty($leftoverRows)) {
                $leftoverHeaders = $this->headers;
                if ($bicInjected && !in_array('bic', $leftoverHeaders)) {
                    $leftoverHeaders[] = 'bic';
                }

                [$leftoverHandle, $leftoverTempPath, $leftoverFileName] = $clearanceService->openCsvWriter(
                    $leftoverHeaders, $this->originalFileName, 'leftover'
                );

                foreach ($leftoverRows as $item) {
                    $row = $item['row'];
                    if ($bicInjected) {
                        $row['bic'] = $item['resolved_bic'] ?? '';
                    }
                    $clearanceService->writeCsvRow($leftoverHandle, $leftoverHeaders, $row, $bicInjected);
                }

                $s3LeftoverPath = $clearanceService->closeCsvWriter(
                    $leftoverHandle, $leftoverTempPath, $leftoverFileName, skipIfEmpty: true
                );
            }

            Log::info('ProcessFileGenerationJob: leftover written', [
                'token'         => $this->token,
                'leftover_rows' => count($leftoverRows),
                's3_leftover'   => $s3LeftoverPath,
            ]);

            // ── 8. Persist to DB ──
            $this->updateProgress(['phase' => 'persisting']);
            $generationService->persistSelectedRecords($batch, $allSelected);

            // ── 9. Finalize ──
            $this->finalizeBatch($batch, $clearanceService, $tempPath, [
                'status'              => 'completed',
                'selected_rows'       => $totalSelectedCount,
                'achieved_amount'     => round($totalAchieved, 2),
                's3_path_result'      => $resultFiles[0]['s3_path'] ?? null,
                'file_name'           => $resultFiles[0]['file_name'] ?? null,
                's3_result_files'     => $resultFiles,
                's3_path_leftover'    => $s3LeftoverPath,
                'leftover_file_name'  => $leftoverFileName,
                'leftover_rows'       => count($leftoverRows),
                'warning'             => $warning,
            ], $excludedBlacklistCount, $excludedBillingCount, $excludedPrevUsedCount);

            Log::info('ProcessFileGenerationJob: completed', [
                'token'          => $this->token,
                'total_selected' => $totalSelectedCount,
                'total_achieved' => round($totalAchieved, 2),
                'output_files'   => count($resultFiles),
                'leftover_rows'  => count($leftoverRows),
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFileGenerationJob: failed', [
            'token' => $this->token, 'error' => $exception->getMessage(),
        ]);

        $batch = FileGenerationBatch::where('token', $this->token)->first();
        $batch?->update(['status' => 'failed', 'error' => $exception->getMessage()]);

        if (empty($this->progressState)) {
            $this->progressState = Cache::get("file_generation:{$this->token}", []);
        }
        $this->updateProgress(['status' => 'failed', 'error' => $exception->getMessage()]);
    }

    private function finalizeBatch(
        FileGenerationBatch  $batch,
        FileClearanceService $clearanceService,
        string               $tempPath,
        array                $data,
        int                  $excludedBlacklistCount,
        int                  $excludedBillingCount,
        int                  $excludedPrevUsedCount,
    ): void {
        if (file_exists($tempPath)) @unlink($tempPath);
        $clearanceService->deleteFromS3($this->s3Path);

        $batch->update([
            'status'                        => $data['status'],
            's3_path_result'                => $data['s3_path_result'] ?? null,
            'achieved_amount'               => $data['achieved_amount'] ?? 0,
            'selected_rows'                 => $data['selected_rows'] ?? 0,
            'excluded_blacklist_rows'       => $excludedBlacklistCount,
            'excluded_billing_rows'         => $excludedBillingCount,
            'excluded_previously_used_rows' => $excludedPrevUsedCount,
            'file_count'                    => count($this->fileConfigs),
            's3_result_files'               => $data['s3_result_files'] ?? null,
            's3_path_leftover'              => $data['s3_path_leftover'] ?? null,
            'leftover_rows'                 => $data['leftover_rows'] ?? 0,
            'completed_at'                  => now(),
        ]);

        $this->updateProgress(array_merge($data, [
            'excluded_blacklist_rows'       => $excludedBlacklistCount,
            'excluded_billing_rows'         => $excludedBillingCount,
            'excluded_previously_used_rows' => $excludedPrevUsedCount,
            'completed_at'                  => now()->toISOString(),
        ]));
    }

    private function updateProgress(array $data): void
    {
        $this->progressState = array_merge($this->progressState, $data);
        Cache::put("file_generation:{$this->token}", $this->progressState, 7200);
    }

    private function maybeUpdateFilterProgress(int $processed): void
    {
        if ($processed % 100 === 0) {
            $this->updateProgress([
                'processed' => $processed,
                'progress'  => $this->totalRows > 0
                    ? round(($processed / $this->totalRows) * 100, 1) : 0,
            ]);
        }
    }
}
