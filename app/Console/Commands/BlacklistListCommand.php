<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use Illuminate\Console\Command;

class BlacklistListCommand extends Command
{
    protected $signature = 'blacklist:list
                            {--search= : Search by IBAN, email, or name}
                            {--source= : Filter by source}
                            {--limit=20 : Number of entries to show}';

    protected $description = 'List blacklist entries';

    public function handle(): int
    {
        $query = Blacklist::query();

        if ($search = $this->option('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('iban', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%")
                  ->orWhere('first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('last_name', 'ILIKE', "%{$search}%");
            });
        }

        if ($source = $this->option('source')) {
            $query->where('source', $source);
        }

        $limit = (int) $this->option('limit');
        $total = $query->count();
        $entries = $query->orderByDesc('created_at')->limit($limit)->get();

        if ($entries->isEmpty()) {
            $this->info('No blacklist entries found');
            return 0;
        }

        $this->info("Showing {$entries->count()} of {$total} entries");
        $this->newLine();

        $rows = $entries->map(fn($e) => [
            $e->id,
            $e->iban,
            $e->email ?? '-',
            trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')) ?: '-',
            \Str::limit($e->reason, 30),
            $e->source ?? '-',
            $e->created_at->format('Y-m-d'),
        ]);

        $this->table(
            ['ID', 'IBAN', 'Email', 'Name', 'Reason', 'Source', 'Created'],
            $rows
        );

        return 0;
    }
}
