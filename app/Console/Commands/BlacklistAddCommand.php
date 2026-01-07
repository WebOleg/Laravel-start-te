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
        $iban = $this->option('iban') ?: null;
        $email = $this->option('email') ?: null;
        $firstName = $this->option('first-name') ?: null;
        $lastName = $this->option('last-name') ?: null;
        $bic = $this->option('bic') ?: null;
        $reason = $this->option('reason') ?: 'Manual blacklist';
        $source = $this->option('source') ?: 'manual';

        // Check if we have at least one unique identifier
        $hasUniqueKey = $iban || $email || ($firstName && $lastName);

        // Interactive mode only if no unique identifier provided via options
        if (!$hasUniqueKey) {
            $this->info('Provide at least one: IBAN, Email, or Full Name');
            $this->newLine();
            
            $iban = $this->ask('IBAN (or press Enter to skip)') ?: null;
            
            if (!$iban) {
                $email = $this->ask('Email (or press Enter to skip)') ?: null;
            }
            
            if (!$iban && !$email) {
                $firstName = $this->ask('First name');
                $lastName = $this->ask('Last name');
                
                if (!$firstName || !$lastName) {
                    $this->error('Must provide IBAN, Email, or Full Name');
                    return 1;
                }
            }
            
            $reason = $this->ask('Reason', 'Manual blacklist');
            $source = $this->choice('Source', ['manual', 'support', 'system-auto', 'chargeback'], 0);
        }

        // Build unique key
        $uniqueKey = $this->getUniqueKey($iban, $email, $firstName, $lastName);
        if (empty($uniqueKey)) {
            $this->error('Must provide IBAN, Email, or Full Name');
            return 1;
        }

        // Check if already exists
        $existing = Blacklist::where($uniqueKey)->first();
        if ($existing) {
            $this->warn("Entry already blacklisted (ID: {$existing->id})");
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $existing->id],
                    ['IBAN', $existing->iban ?? '-'],
                    ['Email', $existing->email ?? '-'],
                    ['Name', trim(($existing->first_name ?? '') . ' ' . ($existing->last_name ?? '')) ?: '-'],
                    ['Reason', $existing->reason],
                ]
            );
            return 0;
        }

        // Generate hash
        $hashSource = $iban ?? $email ?? (($firstName ?? '') . ($lastName ?? ''));

        $entry = Blacklist::create([
            'iban' => $iban,
            'iban_hash' => hash('sha256', $hashSource),
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'bic' => $bic,
            'reason' => $reason,
            'source' => $source,
        ]);

        $this->info("Added blacklist entry ID: {$entry->id}");
        
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
