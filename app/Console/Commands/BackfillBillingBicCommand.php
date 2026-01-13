<?php

/**
 * Command to backfill BIC field in billing_attempts from debtors table.
 * Run after migration to populate existing records.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillBillingBicCommand extends Command
{
    protected $signature = 'billing:backfill-bic 
                            {--chunk=1000 : Number of records to process per batch}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill BIC field in billing_attempts from debtors table';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        $this->info('Starting BIC backfill for billing_attempts...');

        $totalToUpdate = DB::table('billing_attempts')
            ->whereNull('bic')
            ->whereNotNull('debtor_id')
            ->count();

        if ($totalToUpdate === 0) {
            $this->info('No records to update.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalToUpdate} records to update.");

        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made.');
            
            $sample = DB::table('billing_attempts as ba')
                ->join('debtors as d', 'ba.debtor_id', '=', 'd.id')
                ->whereNull('ba.bic')
                ->whereNotNull('d.bic')
                ->select('ba.id', 'ba.debtor_id', 'd.bic')
                ->limit(10)
                ->get();

            $this->table(
                ['Billing Attempt ID', 'Debtor ID', 'BIC to set'],
                $sample->map(fn ($row) => [$row->id, $row->debtor_id, $row->bic])
            );

            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalToUpdate);
        $bar->start();

        $updated = 0;

        DB::table('billing_attempts')
            ->whereNull('bic')
            ->whereNotNull('debtor_id')
            ->orderBy('id')
            ->chunk($chunkSize, function ($attempts) use (&$updated, $bar) {
                $debtorIds = $attempts->pluck('debtor_id')->unique()->filter();
                
                $debtorBics = DB::table('debtors')
                    ->whereIn('id', $debtorIds)
                    ->whereNotNull('bic')
                    ->pluck('bic', 'id');

                foreach ($attempts as $attempt) {
                    if (isset($debtorBics[$attempt->debtor_id])) {
                        DB::table('billing_attempts')
                            ->where('id', $attempt->id)
                            ->update(['bic' => $debtorBics[$attempt->debtor_id]]);
                        $updated++;
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        $this->info("Backfill complete. Updated {$updated} records.");

        $stillNull = DB::table('billing_attempts')
            ->whereNull('bic')
            ->count();

        if ($stillNull > 0) {
            $this->warn("{$stillNull} records still have NULL bic (no debtor or debtor has no BIC).");
        }

        return Command::SUCCESS;
    }
}
