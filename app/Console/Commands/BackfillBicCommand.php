<?php

/**
 * Backfill BIC for records without BIC data.
 * 
 * Uses IBAN API v4 (unlimited) to fetch BIC by IBAN.
 * Supports multiple targets: billing_attempts, debtors, vop_logs.
 */

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\VopLog;
use App\Services\IbanApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillBicCommand extends Command
{
    protected $signature = 'bic:backfill 
                            {--target=billing_attempts : Target table (billing_attempts, debtors, vop_logs, all)}
                            {--limit=1000 : Maximum records to process per target}
                            {--dry-run : Show what would be updated without making changes}
                            {--sleep=50 : Milliseconds to sleep between API calls}
                            {--upload-id= : Filter by specific upload ID}
                            {--from-date= : Filter records created from this date (Y-m-d)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Backfill BIC for billing_attempts, debtors, or vop_logs using IBAN API v4';

    private int $updated = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private int $cached = 0;

    public function __construct(private IbanApiService $ibanApiService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = $this->option('target');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $sleepMs = (int) $this->option('sleep');
        $uploadId = $this->option('upload-id');
        $fromDate = $this->option('from-date');
        $force = $this->option('force');

        $this->info('=== BIC Backfill Tool ===');
        $this->info("Target: {$target}");
        $this->info("Limit: {$limit} per target");
        $this->info("Sleep: {$sleepMs}ms between calls");
        if ($uploadId) $this->info("Upload ID filter: {$uploadId}");
        if ($fromDate) $this->info("From date filter: {$fromDate}");
        if ($dryRun) $this->warn('DRY RUN MODE - No changes will be made');
        $this->newLine();

        $targets = $target === 'all' 
            ? ['billing_attempts', 'debtors', 'vop_logs']
            : [$target];

        $totalCounts = [];
        foreach ($targets as $t) {
            $count = $this->countMissingBic($t, $uploadId, $fromDate, $limit);
            $totalCounts[$t] = $count;
            $this->info("  {$t}: {$count} records without BIC");
        }

        $grandTotal = array_sum($totalCounts);
        if ($grandTotal === 0) {
            $this->info('Nothing to backfill!');
            return Command::SUCCESS;
        }

        if (!$force && !$dryRun) {
            if (!$this->confirm("Proceed with backfilling {$grandTotal} records?")) {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        foreach ($targets as $t) {
            if ($totalCounts[$t] > 0) {
                $this->newLine();
                $this->info("Processing {$t}...");
                $this->processTarget($t, $limit, $dryRun, $sleepMs, $uploadId, $fromDate);
            }
        }

        $this->newLine();
        $this->info('=== Backfill Complete ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated', $this->updated],
                ['From Cache', $this->cached],
                ['Skipped (no IBAN)', $this->skipped],
                ['Failed', $this->failed],
            ]
        );

        return Command::SUCCESS;
    }

    private function countMissingBic(string $target, ?string $uploadId, ?string $fromDate, int $limit): int
    {
        $query = $this->buildQuery($target, $uploadId, $fromDate);
        return min($query->count(), $limit);
    }

    private function buildQuery(string $target, ?string $uploadId, ?string $fromDate)
    {
        $query = match ($target) {
            'billing_attempts' => BillingAttempt::query()
                ->where(fn($q) => $q->whereNull('bic')->orWhere('bic', ''))
                ->whereHas('debtor', fn($q) => $q->whereNotNull('iban')->where('iban', '!=', '')),
            'debtors' => Debtor::query()
                ->where(fn($q) => $q->whereNull('bic')->orWhere('bic', ''))
                ->whereNotNull('iban')
                ->where('iban', '!=', ''),
            'vop_logs' => VopLog::query()
                ->where(fn($q) => $q->whereNull('bic')->orWhere('bic', ''))
                ->whereHas('debtor', fn($q) => $q->whereNotNull('iban')->where('iban', '!=', '')),
            default => throw new \InvalidArgumentException("Unknown target: {$target}"),
        };

        if ($uploadId) {
            $query->where('upload_id', $uploadId);
        }

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        return $query;
    }

    private function processTarget(string $target, int $limit, bool $dryRun, int $sleepMs, ?string $uploadId, ?string $fromDate): void
    {
        $query = $this->buildQuery($target, $uploadId, $fromDate)->limit($limit);
        
        // Eager load debtor for billing_attempts and vop_logs
        if (in_array($target, ['billing_attempts', 'vop_logs'])) {
            $query->with('debtor:id,iban');
        }

        $total = $query->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk(100, function ($records) use ($target, $dryRun, $sleepMs, $bar) {
            foreach ($records as $record) {
                $this->processRecord($record, $target, $dryRun, $sleepMs);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function processRecord($record, string $target, bool $dryRun, int $sleepMs): void
    {
        // Get IBAN based on target
        $iban = match ($target) {
            'debtors' => $record->iban,
            'billing_attempts', 'vop_logs' => $record->debtor?->iban,
        };

        if (!$iban) {
            $this->skipped++;
            return;
        }

        if ($dryRun) {
            $this->updated++;
            return;
        }

        try {
            $result = $this->ibanApiService->verify($iban);
            $bic = $result['bank_data']['bic'] ?? null;

            if ($bic) {
                $record->update(['bic' => $bic]);
                $this->updated++;
                
                if ($result['cached'] ?? false) {
                    $this->cached++;
                }
            } else {
                $this->skipped++;
            }

            // Sleep only for non-cached API calls
            if (!($result['cached'] ?? false)) {
                usleep($sleepMs * 1000);
            }

        } catch (\Exception $e) {
            Log::warning('BIC backfill failed', [
                'target' => $target,
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
            $this->failed++;
        }
    }
}
