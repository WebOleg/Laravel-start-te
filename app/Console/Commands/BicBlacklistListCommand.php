<?php

namespace App\Console\Commands;

use App\Models\BicBlacklist;
use Illuminate\Console\Command;

class BicBlacklistListCommand extends Command
{
    protected $signature = 'bic-blacklist:list
                            {--source= : Filter by source (manual/import/auto)}';

    protected $description = 'Show current BIC blacklist entries';

    public function handle(): int
    {
        $query = BicBlacklist::orderBy('source')->orderBy('bic');

        if ($source = $this->option('source')) {
            $query->where('source', $source);
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            $this->info('BIC blacklist is empty');
            return 0;
        }

        $rows = $entries->map(fn ($e) => [
            $e->id,
            $e->bic . ($e->is_prefix ? '*' : ''),
            $e->source,
            $e->reason ?? '-',
            $e->blacklisted_by ?? '-',
            $e->auto_criteria ?? '-',
            $e->created_at->format('Y-m-d H:i'),
        ])->toArray();

        $this->table(
            ['ID', 'BIC', 'Source', 'Reason', 'By', 'Criteria', 'Created'],
            $rows
        );

        $this->info("Total: {$entries->count()} entries");

        return 0;
    }
}
