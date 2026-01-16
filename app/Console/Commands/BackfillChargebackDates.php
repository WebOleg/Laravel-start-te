<?php

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use App\Services\Emp\EmpClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillChargebackDates extends Command
{
    protected $signature = 'chargebacks:backfill-dates 
                            {--limit= : Limit number of records to process}
                            {--dry-run : Show what would be updated without making changes}';
    
    protected $description = 'Backfill chargebacked_at with actual EMP post_date';

    private const RATE_LIMIT_DELAY_MS = 500;

    public function handle(EmpClient $client): int
    {
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');

        $query = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED);
        
        if ($limit) {
            $query->limit((int) $limit);
        }

        $chargebacks = $query->get();
        $total = $chargebacks->count();

        $this->info("Found {$total} chargebacks to process" . ($dryRun ? ' [DRY RUN]' : ''));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($chargebacks as $chargeback) {
            try {
                $xml = $client->buildChargebackDetailXml($chargeback->unique_id);
                $response = $client->sendRequest('/chargebacks', $xml);

                if (empty($response) || (isset($response['status']) && $response['status'] === 'error')) {
                    $errors++;
                    $bar->advance();
                    continue;
                }

                $postDate = $response['post_date'] ?? null;

                if (!$postDate) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                if (!$dryRun) {
                    $chargeback->update([
                        'chargebacked_at' => Carbon::parse($postDate),
                        'chargeback_reason_code' => $response['reason_code'] ?? $chargeback->chargeback_reason_code,
                        'chargeback_reason_description' => $response['reason_description'] ?? $chargeback->chargeback_reason_description,
                    ]);
                }

                $updated++;
                
                usleep(self::RATE_LIMIT_DELAY_MS * 1000);

            } catch (\Exception $e) {
                $errors++;
                Log::error('Backfill chargeback date failed', [
                    'unique_id' => $chargeback->unique_id,
                    'error' => $e->getMessage()
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Results:");
        $this->info("  Updated: {$updated}");
        $this->info("  Skipped (no post_date): {$skipped}");
        $this->info("  Errors: {$errors}");

        return Command::SUCCESS;
    }
}
