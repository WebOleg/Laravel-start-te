<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BlacklistImportCommand extends Command
{
    protected $signature = 'blacklist:import
                            {--path= : Import path (default: database/data/blacklist-seed.json)}
                            {--dry-run : Show what would be imported without making changes}';

    protected $description = 'Import blacklist from JSON file';

    public function handle(): int
    {
        $path = $this->option('path') ?? database_path('data/blacklist-seed.json');
        $dryRun = $this->option('dry-run');

        if (!File::exists($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $entries = json_decode(File::get($path), true);

        if (empty($entries)) {
            $this->warn('No entries found in JSON file');
            return 0;
        }

        $this->info("Found " . count($entries) . " entries in file");

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar(count($entries));
        $bar->start();

        foreach ($entries as $entry) {
            $uniqueKey = $this->getUniqueKey($entry);
            
            if (empty($uniqueKey)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            if (!$dryRun) {
                $hashSource = $entry['iban'] ?? $entry['email'] ?? $entry['bic'] ??
                    (($entry['first_name'] ?? '') . ($entry['last_name'] ?? ''));
                
                $result = Blacklist::updateOrCreate(
                    $uniqueKey,
                    [
                        'iban' => $entry['iban'] ?? null,
                        'iban_hash' => $entry['iban_hash'] ?? hash('sha256', $hashSource),
                        'reason' => $entry['reason'] ?? 'Imported',
                        'source' => $entry['source'] ?? 'import',
                        'added_by' => $entry['added_by'] ?? null,
                        'first_name' => $entry['first_name'] ?? null,
                        'last_name' => $entry['last_name'] ?? null,
                        'email' => $entry['email'] ?? null,
                        'bic' => $entry['bic'] ?? null,
                    ]
                );

                if ($result->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            } else {
                $existing = Blacklist::where($uniqueKey)->first();
                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Import completed:");
        $this->line("  Created: {$created}");
        $this->line("  Updated: {$updated}");
        $this->line("  Skipped: {$skipped}");

        return 0;
    }

    private function getUniqueKey(array $entry): array
    {
        if (!empty($entry['iban'])) {
            return ['iban' => $entry['iban']];
        }
        if (!empty($entry['email'])) {
            return ['email' => $entry['email']];
        }
        if (!empty($entry['first_name']) && !empty($entry['last_name'])) {
            return [
                'first_name' => $entry['first_name'],
                'last_name' => $entry['last_name'],
            ];
        }
        if (!empty($entry['bic'])) {
            return ['bic' => $entry['bic']];
        }
        return [];
    }
}
