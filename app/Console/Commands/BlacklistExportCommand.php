<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BlacklistExportCommand extends Command
{
    protected $signature = 'blacklist:export
                            {--path= : Export path (default: database/data/blacklist.json)}';

    protected $description = 'Export blacklist to JSON file for version control';

    public function handle(): int
    {
        $path = $this->option('path') ?? database_path('data/blacklist.json');
        
        // Ensure directory exists
        $dir = dirname($path);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $entries = Blacklist::orderBy('id')->get()->map(function ($entry) {
            return [
                'id' => $entry->id,
                'iban' => $entry->iban,
                'iban_hash' => $entry->iban_hash,
                'reason' => $entry->reason,
                'source' => $entry->source,
                'added_by' => $entry->added_by,
                'first_name' => $entry->first_name,
                'last_name' => $entry->last_name,
                'email' => $entry->email,
                'bic' => $entry->bic,
                'created_at' => $entry->created_at?->toISOString(),
                'updated_at' => $entry->updated_at?->toISOString(),
            ];
        });

        File::put($path, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Exported {$entries->count()} entries to: {$path}");
        $this->newLine();
        $this->comment("Don't forget to commit this file:");
        $this->line("  git add {$path}");
        $this->line("  git commit -m 'chore: update blacklist data'");

        return 0;
    }
}
