<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use Illuminate\Console\Command;

class BlacklistRemoveCommand extends Command
{
    protected $signature = 'blacklist:remove
                            {--id= : Remove by ID}
                            {--iban= : Remove by IBAN}';

    protected $description = 'Remove entry from blacklist';

    public function handle(): int
    {
        $id = $this->option('id');
        $iban = $this->option('iban');

        if (!$id && !$iban) {
            $iban = $this->ask('Enter IBAN or ID to remove');
            if (is_numeric($iban)) {
                $id = $iban;
                $iban = null;
            }
        }

        $entry = $id 
            ? Blacklist::find($id)
            : Blacklist::where('iban', $iban)->first();

        if (!$entry) {
            $this->error('Blacklist entry not found');
            return 1;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $entry->id],
                ['IBAN', $entry->iban],
                ['Email', $entry->email ?? '-'],
                ['Name', trim(($entry->first_name ?? '') . ' ' . ($entry->last_name ?? '')) ?: '-'],
                ['Reason', $entry->reason],
                ['Source', $entry->source],
            ]
        );

        if (!$this->confirm('Remove this entry?')) {
            $this->info('Cancelled');
            return 0;
        }

        $entry->delete();
        $this->info("Removed blacklist entry ID: {$entry->id}");

        return 0;
    }
}
