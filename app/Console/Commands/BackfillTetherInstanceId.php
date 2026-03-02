<?php

/**
 * Backfill tether_instance_id for all existing records.
 * Sets tether_instance_id = 1 for debtors, debtor_profiles, uploads, billing_attempts.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTetherInstanceId extends Command
{
    protected $signature = 'tether:backfill-instance-id {--dry-run : Show counts without updating}';
    protected $description = 'Assign all existing records to tether_instance_id = 1';

    public function handle(): int
    {
        $tables = ['debtors', 'debtor_profiles', 'uploads', 'billing_attempts'];
        $instanceId = 1;

        $instance = DB::table('tether_instances')->find($instanceId);
        if (!$instance) {
            $this->error("Tether instance #{$instanceId} not found. Run migrations first.");
            return self::FAILURE;
        }

        $this->info("Backfilling to instance: {$instance->name} (#{$instanceId})");

        foreach ($tables as $table) {
            $nullCount = DB::table($table)->whereNull('tether_instance_id')->count();

            if ($this->option('dry-run')) {
                $this->line("  {$table}: {$nullCount} records to update");
                continue;
            }

            if ($nullCount === 0) {
                $this->line("  {$table}: already backfilled");
                continue;
            }

            $updated = 0;
            $batchSize = 5000;

            do {
                $affected = DB::table($table)
                    ->whereNull('tether_instance_id')
                    ->limit($batchSize)
                    ->update(['tether_instance_id' => $instanceId]);

                $updated += $affected;
                $this->output->write("\r  {$table}: {$updated}/{$nullCount} updated");
            } while ($affected > 0);

            $this->line("");
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
