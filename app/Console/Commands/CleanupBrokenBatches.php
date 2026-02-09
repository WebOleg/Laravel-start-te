<?php

/**
 * Cleanup broken job batches with negative pending_jobs.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupBrokenBatches extends Command
{
    protected $signature = 'batches:cleanup {--dry-run : Show what would be cleaned without actually cleaning}';
    protected $description = 'Cancel and cleanup broken job batches with negative pending_jobs';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // job_batches uses integer timestamps, not datetime
        $staleThreshold = now()->subHours(24)->timestamp;

        $brokenBatches = DB::table('job_batches')
            ->where('pending_jobs', '<', 0)
            ->orWhere(function ($query) use ($staleThreshold) {
                $query->whereNull('cancelled_at')
                    ->whereNull('finished_at')
                    ->where('created_at', '<', $staleThreshold);
            })
            ->get();

        if ($brokenBatches->isEmpty()) {
            $this->info('No broken batches found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$brokenBatches->count()} broken batch(es):");

        foreach ($brokenBatches as $batch) {
            $createdAt = date('Y-m-d H:i:s', $batch->created_at);
            $this->line("  - {$batch->name} (ID: {$batch->id})");
            $this->line("    pending_jobs: {$batch->pending_jobs}, failed_jobs: {$batch->failed_jobs}");
            $this->line("    created: {$createdAt}");

            if (!$dryRun) {
                DB::table('job_batches')
                    ->where('id', $batch->id)
                    ->update([
                        'cancelled_at' => now()->timestamp,
                        'finished_at' => now()->timestamp,
                    ]);
                $this->info("    → Cancelled");
            }
        }

        if ($dryRun) {
            $this->warn('Dry run - no chang made. Remove --dry-run to actually cleanup.');
        } else {
            $this->info('Cleanup complete!');
        }

        return Command::SUCCESS;
    }
}
