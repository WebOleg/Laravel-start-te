<?php

/**
 * Backfill chargebacks table from billing_attempts with status = chargebacked.
 */

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillChargebacksCommand extends Command
{
    protected $signature = 'chargebacks:backfill
                            {--limit= : Limit number of records to process}
                            {--dry-run : Show what would be inserted without making changes}
                            {--chunk=500 : Chunk size for processing}';

    protected $description = 'Backfill chargebacks table from billing_attempts with status = chargebacked';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $chunk  = (int) $this->option('chunk');

        $existingIds = DB::table('chargebacks')
            ->whereNotNull('billing_attempt_id')
            ->pluck('billing_attempt_id')
            ->flip();

        $query = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereNotNull('unique_id')
            ->whereNotNull('debtor_id')
            ->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        $total   = $query->count();
        $missing = $query->get()->filter(fn($ba) => !isset($existingIds[$ba->id]));
        $count   = $missing->count();

        $this->info("Total chargebacked billing_attempts: {$total}");
        $this->info("Already in chargebacks table: " . ($total - $count));
        $this->info("To backfill: {$count}" . ($dryRun ? ' [DRY RUN]' : ''));

        if ($count === 0) {
            $this->info('Nothing to backfill.');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $inserted = 0;
        $errors   = 0;

        foreach ($missing->chunk($chunk) as $batch) {
            $rows = [];
            $now  = now()->toDateTimeString();

            foreach ($batch as $ba) {
                $rows[] = [
                    'billing_attempt_id'             => $ba->id,
                    'debtor_id'                      => $ba->debtor_id,
                    'original_transaction_unique_id' => $ba->unique_id,
                    'type'                           => '1st chargeback',
                    'reason_code'                    => $ba->chargeback_reason_code,
                    'reason_description'             => $ba->chargeback_reason_description,
                    'chargeback_amount'              => $ba->amount,
                    'chargeback_currency'            => $ba->currency,
                    'post_date'                      => null,
                    'import_date'                    => $ba->chargebacked_at
                        ? \Carbon\Carbon::parse($ba->chargebacked_at)->toDateString()
                        : now()->toDateString(),
                    'source'                         => 'backfill',
                    'api_response'                   => null,
                    'created_at'                     => $now,
                    'updated_at'                     => $now,
                ];
            }

            if (!$dryRun) {
                try {
                    DB::table('chargebacks')->insert($rows);
                    $inserted += count($rows);
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Chargeback backfill batch failed', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->newLine();
                    $this->error('Batch error: ' . $e->getMessage());
                }
            } else {
                $inserted += count($rows);
            }

            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Results:');
        $this->info("  Inserted: {$inserted}" . ($dryRun ? ' (dry run)' : ''));
        $this->info("  Errors: {$errors}");

        return Command::SUCCESS;
    }
}
