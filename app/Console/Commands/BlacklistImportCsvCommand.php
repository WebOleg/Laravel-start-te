<?php

namespace App\Console\Commands;

use App\Models\Blacklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BlacklistImportCsvCommand extends Command
{
    protected $signature = 'blacklist:import-csv
                            {file : Path to CSV file}
                            {--reason=Manual Import : Reason for blacklisting}
                            {--source=csv_import : Source identifier}
                            {--dry-run : Show what would be imported without making changes}';

    protected $description = 'Import blacklist entries from CSV file';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $reason = $this->option('reason');
        $source = $this->option('source');
        $dryRun = $this->option('dry-run');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle);

        $this->info("CSV columns: " . implode(', ', $header));

        $colMap = $this->mapColumns($header);
        $this->info("Mapped: nom={$colMap['nom']}, prenom={$colMap['prenom']}, email={$colMap['email']}, iban={$colMap['iban']}, bic={$colMap['bic']}");

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            $lastName = $this->cleanValue($row[$colMap['nom']] ?? null);
            $firstName = $this->cleanValue($row[$colMap['prenom']] ?? null);
            $email = $this->cleanValue($row[$colMap['email']] ?? null);
            $iban = $this->cleanValue($row[$colMap['iban']] ?? null);
            $bic = $this->cleanValue($row[$colMap['bic']] ?? null);
            $emailPerso = $this->cleanValue($row[$colMap['email_perso']] ?? null);

            $uniqueKey = $this->getUniqueKey($iban, $email, $emailPerso, $firstName, $lastName);

            if (empty($uniqueKey)) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                try {
                    $hashSource = $iban ?? $email ?? $emailPerso ?? ($firstName . $lastName);

                    Blacklist::updateOrCreate(
                        $uniqueKey,
                        [
                            'iban' => $iban,
                            'iban_hash' => hash('sha256', $hashSource),
                            'reason' => $reason,
                            'source' => $source,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'email' => $email ?? $emailPerso,
                            'bic' => $bic,
                        ]
                    );
                    $created++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning("Blacklist import error row {$rowNum}: " . $e->getMessage());
                }
            } else {
                $created++;
            }
        }

        fclose($handle);

        $this->newLine();
        $this->info("Import completed:");
        $this->table(['Metric', 'Count'], [
            ['Created/Updated', $created],
            ['Skipped (no key)', $skipped],
            ['Errors', $errors],
            ['Total rows', $rowNum - 1],
        ]);

        return 0;
    }

    private function mapColumns(array $header): array
    {
        $map = [
            'nom' => 0,
            'prenom' => 1,
            'email' => 2,
            'bic' => 3,
            'iban' => 4,
            'email_perso' => 5,
        ];

        foreach ($header as $index => $col) {
            $col = strtolower(trim($col));
            if (isset($map[$col])) {
                $map[$col] = $index;
            }
        }

        return $map;
    }

    private function cleanValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (in_array(strtolower($value), ['null', '<blank>', '', 'n/a', '-'])) {
            return null;
        }

        return $value;
    }

    private function getUniqueKey(?string $iban, ?string $email, ?string $emailPerso, ?string $firstName, ?string $lastName): array
    {
        if (!empty($iban)) {
            return ['iban' => strtoupper(str_replace(' ', '', $iban))];
        }

        if (!empty($email)) {
            return ['email' => strtolower($email)];
        }

        if (!empty($emailPerso)) {
            return ['email' => strtolower($emailPerso)];
        }

        if (!empty($firstName) && !empty($lastName)) {
            return [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
        }

        if (!empty($lastName)) {
            return ['last_name' => $lastName];
        }

        return [];
    }
}
