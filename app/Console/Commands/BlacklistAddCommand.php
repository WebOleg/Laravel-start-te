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

        // Check if at least one field provided
        $hasData = $iban || $email || $firstName || $lastName || $bic;

        // Interactive mode only if nothing provided
        if (!$hasData) {
            $this->info('Fill at least one field:');
            $this->newLine();
            
            $iban = $this->ask('IBAN') ?: null;
            $email = $this->ask('Email') ?: null;
            $firstName = $this->ask('First name') ?: null;
            $lastName = $this->ask('Last name') ?: null;
            $bic = $this->ask('BIC') ?: null;
            
            if (!$iban && !$email && !$firstName && !$lastName && !$bic) {
                $this->error('At least one field is required');
                return 1;
            }
            
            $reason = $this->ask('Reason', 'Manual blacklist');
            $source = $this->choice('Source', ['manual', 'support', 'system-auto', 'chargeback'], 0);
        }

        // Generate hash from whatever we have
        $hashSource = $iban ?? $email ?? $bic ?? (($firstName ?? '') . ($lastName ?? '')) ?? 'unknown';

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
                ['BIC', $entry->bic ?? '-'],
                ['Reason', $entry->reason],
                ['Source', $entry->source],
            ]
        );

        return 0;
    }
}
