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
        $firstName = $this->option('first-name');
        $lastName = $this->option('last-name');

        // Interactive mode if no unique identifier provided
        if (!$iban && !$email && !($firstName && $lastName)) {
            $this->info('Provide at least one: IBAN, Email, or Full Name');
            $this->newLine();
            
            $iban = $this->ask('IBAN (or press Enter to skip)') ?: null;
            
            if (!$iban) {
                $email = $this->ask('Email (or press Enter to skip)') ?: null;
            }
            
            if (!$iban && !$email) {
                $firstName = $this->ask('First name (required if no IBAN/email)');
                $lastName = $this->ask('Last name (required if no IBAN/email)');
                
                if (!$firstName || !$lastName) {
                    $this->error('Must provide IBAN, Email, or Full Name');
                    return 1;
                }
            }
        }

        // Build unique key for checking existing
        $uniqueKey = $this->getUniqueKey($iban, $email, $firstName, $lastName);
        if (empty($uniqueKey)) {
            $this->error('Must provide IBAN, Email, or Full Name');
            return 1;
        }

        // Check if already exists
        $existing = Blacklist::where($uniqueKey)->first();
        if ($existing) {
            $this->warn("Entry already blacklisted (ID: {$existing->id})");
            if (!$this->confirm('Update existing entry?')) {
                return 0;
            }
        }

        // Gather other fields if not provided
        if (!$firstName) {
            $firstName = $this->option('first-name') ?? $this->ask('First name (optional)') ?: null;
        }
        if (!$lastName) {
            $lastName = $this->option('last-name') ?? $this->ask('Last name (optional)') ?: null;
        }
        if (!$email && !$iban) {
            $email = $this->ask('Email (optional)') ?: null;
        }
        
        $bic = $this->option('bic') ?? $this->ask('BIC (optional)') ?: null;
        $reason = $this->option('reason') ?? $this->ask('Reason', 'Manual blacklist');
        $source = $this->option('source') ?? $this->choice('Source', ['manual', 'support', 'system-auto', 'chargeback'], 0);

        // Generate hash
        $hashSource = $iban ?? $email ?? (($firstName ?? '') . ($lastName ?? ''));

        $entry = Blacklist::updateOrCreate(
            $uniqueKey,
            [
                'iban' => $iban,
                'iban_hash' => hash('sha256', $hashSource),
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'bic' => $bic,
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
                ['IBAN', $entry->iban ?? '-'],
                ['Email', $entry->email ?? '-'],
                ['Name', trim(($entry->first_name ?? '') . ' ' . ($entry->last_name ?? '')) ?: '-'],
                ['Reason', $entry->reason],
                ['Source', $entry->source],
            ]
        );

        return 0;
    }

    private function getUniqueKey(?string $iban, ?string $email, ?string $firstName, ?string $lastName): array
    {
        if ($iban) {
            return ['iban' => $iban];
        }
        if ($email) {
            return ['email' => $email];
        }
        if ($firstName && $lastName) {
            return ['first_name' => $firstName, 'last_name' => $lastName];
        }
        return [];
    }
}
