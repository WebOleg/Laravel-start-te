<?php

namespace App\Console\Commands;

use App\Models\BicBlacklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BicBlacklistImportCommand extends Command
{
    protected $signature = 'bic-blacklist:import
                            {path : Path to text file with BIC codes (one per line, prefix with *)}
                            {--reason=Imported from file : Reason for blacklisting}
                            {--source=import : Source identifier}
                            {--blacklisted-by= : Who added this}
                            {--clear : Clear existing entries before import}
                            {--dry-run : Show what would be imported without making changes}';

    protected $description = 'Import BIC blacklist from text file';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');

        if (! File::exists($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $lines = collect(explode("\n", File::get($path)))
            ->map(fn ($line) => strtoupper(trim($line)))
            ->filter(fn ($line) => $line !== '' && ! str_starts_with($line, '#'));

        if ($lines->isEmpty()) {
            $this->warn('No BIC codes found in file');
            return 0;
        }

        $this->info("Found {$lines->count()} entries in file");

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        if ($this->option('clear') && ! $dryRun) {
            $deleted = BicBlacklist::count();
            BicBlacklist::truncate();
            $this->warn("Cleared {$deleted} existing entries");
        }

        $created = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            $isPrefix = str_ends_with($line, '*');
            $bic = $isPrefix ? rtrim($line, '*') : $line;

            if ($bic === '') {
                $skipped++;
                continue;
            }

            $exists = BicBlacklist::where('bic', $bic)->where('is_prefix', $isPrefix)->exists();

            if ($exists) {
                $this->line("  SKIP: {$bic}" . ($isPrefix ? '* (exists)' : ' (exists)'));
                $skipped++;
                continue;
            }

            if (! $dryRun) {
                BicBlacklist::create([
                    'bic' => $bic,
                    'is_prefix' => $isPrefix,
                    'reason' => $this->option('reason'),
                    'source' => $this->option('source'),
                    'blacklisted_by' => $this->option('blacklisted-by'),
                ]);
            }

            $label = $isPrefix ? "{$bic}* (prefix)" : $bic;
            $this->line("  ADD: {$label}");
            $created++;
        }

        $this->newLine();
        $this->info("Import completed: {$created} created, {$skipped} skipped");

        return 0;
    }
}
