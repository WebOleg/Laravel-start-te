<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use Illuminate\Console\Command;

class BlacklistAddCommand extends Command
{
    protected $signature = 'blacklist:add
                            {--iban= : IBAN to blacklist}
                            {--email= : Email to blacklist}
                            {--first-name= : First name}
                            {--last-name= : Last name}
                            {--bic= : BIC code}
                            {--reason= : Reason for blacklisting}
                            {--source= : Source (manual, support, system-auto)}';

    protected $description = 'Add entry to blacklist';

    public function handle(): int
    {
        $iban = $this->option('iban');
        $email = $this->option('email');

        // Interactive mode if no IBAN provided
        if (!$iban) {
            $iban = $this->ask('IBAN (required)');
            if (!$iban) {
                $this->error('IBAN is required');
                return 1;
            }
        }

        // Check if already exists
        $existing = Blacklist::where('iban', $iban)->first();
        if ($existing) {
            $this->warn("IBAN already blacklisted (ID: {$existing->id})");
            if (!$this->confirm('Update existing entry?')) {
                return 0;
            }
        }

        // Gather other fields
        $email = $email ?? $this->ask('Email (optional)');
        $firstName = $this->option('first-name') ?? $this->ask('First name (optional)');
        $lastName = $this->option('last-name') ?? $this->ask('Last name (optional)');
        $bic = $this->option('bic') ?? $this->ask('BIC (optional)');
        $reason = $this->option('reason') ?? $this->ask('Reason', 'Manual blacklist');
        $source = $this->option('source') ?? $this->choice('Source', ['manual', 'support', 'system-auto', 'chargeback'], 0);

        $entry = Blacklist::updateOrCreate(
            ['iban' => $iban],
            [
                'iban_hash' => hash('sha256', $iban),
                'email' => $email ?: null,
                'first_name' => $firstName ?: null,
                'last_name' => $lastName ?: null,
                'bic' => $bic ?: null,
                'reason' => $reason,
                'source' => $source,
            ]
        );

        $action = $entry->wasRecentlyCreated ? 'Added' : 'Updated';
        $this->info("{$action} blacklist entry ID: {$entry->id}");
        
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

        return 0;
    }
}
