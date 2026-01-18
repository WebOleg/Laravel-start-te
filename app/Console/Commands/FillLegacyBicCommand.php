<?php

/**
 * Command to fill BIC for legacy billing attempts.
 * Fetches IBAN from EMP reconcile, then gets BIC via IbanApiService.
 */

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use App\Services\Emp\EmpBillingService;
use App\Services\IbanApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FillLegacyBicCommand extends Command
{
    protected $signature = 'emp:fill-legacy-bic 
                            {--chunk=100 : Number of records per batch}
                            {--limit=0 : Maximum records to process (0 = all)}
                            {--dry-run : Show what would be done without making changes}
                            {--delay=500 : Delay between API calls in milliseconds}';

    protected $description = 'Fill BIC for legacy billing attempts using EMP reconcile and IBAN lookup';

    private int $processed = 0;
    private int $updated = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function __construct(
        private EmpBillingService $empService,
        private IbanApiService $ibanService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $delay = (int) $this->option('delay');

        $query = BillingAttempt::whereNull('debtor_id')
            ->whereNull('bic')
            ->whereNotNull('unique_id');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No legacy billing attempts without BIC found.');
            return self::SUCCESS;
        }

        $toProcess = $limit > 0 ? min($limit, $total) : $total;

        $this->info("Found {$total} legacy billing attempts without BIC.");
        $this->info("Processing: {$toProcess} records" . ($dryRun ? ' (DRY RUN)' : ''));
        $this->newLine();

        $bar = $this->output->createProgressBar($toProcess);
        $bar->start();

        $query->take($toProcess)->chunk($chunkSize, function ($attempts) use ($dryRun, $delay, $bar) {
            foreach ($attempts as $attempt) {
                $this->processAttempt($attempt, $dryRun, $delay);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $this->processed],
                ['Updated', $this->updated],
                ['Skipped (no IBAN)', $this->skipped],
                ['Failed', $this->failed],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a dry run. No changes were made.');
        }

        Log::info('FillLegacyBicCommand completed', [
            'processed' => $this->processed,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    private function processAttempt(BillingAttempt $attempt, bool $dryRun, int $delay): void
    {
        $this->processed++;

        try {
            // Step 1: Get IBAN from EMP reconcile
            $reconcileResult = $this->empService->reconcile($attempt);
            $iban = $reconcileResult['bank_account_number'] ?? null;

            if (empty($iban)) {
                $this->skipped++;
                return;
            }

            usleep($delay * 1000);

            // Step 2: Get BIC from IBAN
            $ibanResult = $this->ibanService->verify($iban);
            $bic = $ibanResult['bank_data']['bic'] ?? null;

            if (empty($bic)) {
                $this->skipped++;
                return;
            }

            // Step 3: Update billing attempt
            if (!$dryRun) {
                $attempt->update(['bic' => $bic]);
            }

            $this->updated++;

        } catch (\Exception $e) {
            $this->failed++;
            Log::warning('FillLegacyBicCommand: Failed to process attempt', [
                'billing_attempt_id' => $attempt->id,
                'unique_id' => $attempt->unique_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
