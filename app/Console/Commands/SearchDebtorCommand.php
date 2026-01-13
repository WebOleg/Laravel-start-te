<?php

namespace App\Console\Commands;

use App\Models\Debtor;
use Illuminate\Console\Command;

class SearchDebtorCommand extends Command
{
    protected $signature = 'debtor:search
        {--name= : Search by name (first or last)}
        {--email= : Search by email}
        {--iban= : Search by IBAN (partial match)}
        {--phone= : Search by phone}
        {--limit=10 : Max results}';

    protected $description = 'Search debtors by name, email, phone to find IBAN';

    public function handle(): int
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $iban = $this->option('iban');
        $phone = $this->option('phone');
        $limit = (int) $this->option('limit');

        if (!$name && !$email && !$iban && !$phone) {
            $this->error('Please provide at least one search option: --name, --email, --iban, or --phone');
            return 1;
        }

        $query = Debtor::query();

        if ($name) {
            $query->where(function ($q) use ($name) {
                $q->where('first_name', 'ILIKE', "%{$name}%")
                  ->orWhere('last_name', 'ILIKE', "%{$name}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", ["%{$name}%"]);
            });
        }

        if ($email) {
            $query->where('email', 'ILIKE', "%{$email}%");
        }

        if ($iban) {
            $query->where('iban', 'ILIKE', "%{$iban}%");
        }

        if ($phone) {
            $query->where('phone', 'ILIKE', "%{$phone}%");
        }

        $debtors = $query->limit($limit)->get();

        if ($debtors->isEmpty()) {
            $this->warn('No debtors found.');
            return 0;
        }

        $this->info("Found {$debtors->count()} debtor(s):");
        $this->newLine();

        $rows = [];
        foreach ($debtors as $debtor) {
            $rows[] = [
                $debtor->id,
                $debtor->first_name . ' ' . $debtor->last_name,
                $debtor->email ?: '-',
                $debtor->iban,
                $debtor->phone ?: '-',
                $debtor->country,
                $debtor->upload_id,
            ];
        }

        $this->table(
            ['ID', 'Name', 'Email', 'IBAN', 'Phone', 'Country', 'Upload'],
            $rows
        );

        // Show copy-paste ready for blacklist
        if ($debtors->count() === 1) {
            $d = $debtors->first();
            $this->newLine();
            $this->info('Quick blacklist command:');
            $cmd = "php artisan blacklist:add --iban={$d->iban}";
            if ($d->email) {
                $cmd .= " --email={$d->email}";
            }
            $cmd .= " --first-name=\"{$d->first_name}\" --last-name=\"{$d->last_name}\"";
            $cmd .= ' --reason="Customer complaint" --source=support';
            $this->line($cmd);
        }

        return 0;
    }
}
