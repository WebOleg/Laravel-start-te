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

    public function __construct(
        private string $token,
        private int    $batchId,
        private string $s3Path,
        private array  $headers,
        private array  $headerMeta,
        private string $originalFileName,
        private int    $totalRows,
        private float  $targetAmount,
        private float  $tolerance,
        private string $pricingStrategy,
        private array  $pricingAmounts,
        private array  $pricingWeights,
    ) {
        $this->onQueue('generation');
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
            Log::warning('ProcessFileGenerationJob: another instance already running', [
                'token' => $this->token,
            ]);
            return;
        }

        try {
            Log::info('ProcessFileGenerationJob: started', [
                'token'            => $this->token,
                'batch_id'         => $this->batchId,
                'total_rows'       => $this->totalRows,
                'target_amount'    => $this->targetAmount,
                'tolerance'        => $this->tolerance,
                'pricing_strategy' => $this->pricingStrategy,
                'pricing_amounts'  => $this->pricingAmounts,
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
                'token'            => $this->token,
                'previously_used'  => count($previouslyUsed),
                'recently_billed'  => count($recentlyBilled),
            ]);

            // ── 3. Stream rows → filter → collect eligible ──
            $this->updateProgress(['phase' => 'filtering']);

            $ibanHeader = $this->headerMeta['iban_header'];
            $bicHeader  = $this->headerMeta['bic_header'];

            $eligibleRows            = [];
            $processed               = 0;
            $excludedBlacklistCount  = 0;
            $excludedBillingCount    = 0;
            $excludedPrevUsedCount   = 0;
            $excludedOtherCount      = 0;

            foreach ($clearanceService->streamRows($tempPath, $this->headers) as [$rowIndex, $row]) {
                $processed++;

                $rawIban = trim($row[$ibanHeader] ?? '');
                if (empty($rawIban)) {
                    $excludedOtherCount++;
                    $this->maybeUpdateFilterProgress($processed);
                    continue;
                }

                $iban = $ibanValidator->normalize($rawIban);

                $check = $generationService->checkEligibility(
                    $iban,
                    $previouslyUsed,
                    $recentlyBilled,
                );

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

            // ── 6. Select records + assign amounts using pricing strategy ──
            $this->updateProgress(['phase' => 'selecting']);

            $selectionResult = $generationService->selectAndAssignAmounts(
                $eligibleAfterBic,
                $this->targetAmount,
                $this->tolerance,
                $this->pricingAmounts,
                $this->pricingWeights,
            );

            $selected       = $selectionResult['selected'];
            $achievedAmount = $selectionResult['achieved_amount'];

            Log::info('ProcessFileGenerationJob: selection complete', [
                'token'            => $this->token,
                'selected_count'   => count($selected),
                'achieved_amount'  => $achievedAmount,
                'target_amount'    => $this->targetAmount,
                'pricing_strategy' => $this->pricingStrategy,
            ]);

            if (empty($selected)) {
                $this->finalizeBatch($batch, $clearanceService, $tempPath, [
                    'status'          => 'completed',
                    'selected_rows'   => 0,
                    'achieved_amount' => 0,
                    'warning'         => 'No eligible records found to reach the target amount.',
                ], $excludedBlacklistCount, $excludedBillingCount, $excludedPrevUsedCount);
                return;
            }

            // ── 7. Write output CSV ──
            $this->updateProgress(['phase' => 'writing']);

            $bicInjected   = ($bicHeader === null);
            $outputHeaders = $generationService->buildOutputHeaders($this->headers, $bicInjected);

            [$csvHandle, $csvPath, $csvFileName] = $clearanceService->openCsvWriter(
                $outputHeaders, $this->originalFileName, 'generated'
            );

            foreach ($selected as $item) {
                $generationService->writeOutputRow(
                    $csvHandle,
                    $outputHeaders,
                    $item['row'],
                    $item['assigned_amount'],
                    $bicInjected,
                    $item['resolved_bic'] ?? '',
                );
            }

            $s3ResultPath = $clearanceService->closeCsvWriter($csvHandle, $csvPath, $csvFileName);

            // ── 8. Persist to DB ──
            $this->updateProgress(['phase' => 'persisting']);
            $generationService->persistSelectedRecords($batch, $selected);

            // ── 9. Finalize ──
            $this->finalizeBatch($batch, $clearanceService, $tempPath, [
                'status'           => 'completed',
                'selected_rows'    => count($selected),
                'achieved_amount'  => $achievedAmount,
                's3_path_result'   => $s3ResultPath,
                'file_name'        => $csvFileName,
            ], $excludedBlacklistCount, $excludedBillingCount, $excludedPrevUsedCount);

            Log::info('ProcessFileGenerationJob: completed', [
                'token'            => $this->token,
                'selected'         => count($selected),
                'achieved_amount'  => $achievedAmount,
                'pricing_strategy' => $this->pricingStrategy,
                's3_result'        => $s3ResultPath,
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFileGenerationJob: failed', [
            'token' => $this->token,
            'error' => $exception->getMessage(),
        ]);

        $batch = FileGenerationBatch::where('token', $this->token)->first();
        $batch?->update([
            'status' => 'failed',
            'error'  => $exception->getMessage(),
        ]);

        if (empty($this->progressState)) {
            $this->progressState = Cache::get("file_generation:{$this->token}", []);
        }

        $this->updateProgress([
            'status' => 'failed',
            'error'  => $exception->getMessage(),
        ]);
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
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
        $clearanceService->deleteFromS3($this->s3Path);

        $batch->update([
            'status'                        => $data['status'],
            's3_path_result'                => $data['s3_path_result'] ?? null,
            'achieved_amount'               => $data['achieved_amount'] ?? 0,
            'selected_rows'                 => $data['selected_rows'] ?? 0,
            'excluded_blacklist_rows'       => $excludedBlacklistCount,
            'excluded_billing_rows'         => $excludedBillingCount,
            'excluded_previously_used_rows' => $excludedPrevUsedCount,
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
                    ? round(($processed / $this->totalRows) * 100, 1)
                    : 0,
            ]);
        }
    }
}
