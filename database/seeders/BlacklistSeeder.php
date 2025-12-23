<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BlacklistSeeder extends Seeder
{
    public function run(): void
    {
        $csvFile = database_path('seeders/blacklist_export.csv');
        
        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");
            return;
        }
        
        $handle = fopen($csvFile, 'r');
        $header = fgetcsv($handle, 0, ';');
        
        $count = 0;
        $skipped = 0;
        
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $data = array_combine($header, $row);
            
            $iban = trim($data['iban'] ?? '') ?: null;
            $email = trim($data['email'] ?? '') ?: null;
            $firstName = trim($data['first_name'] ?? '') ?: null;
            $lastName = trim($data['last_name'] ?? '') ?: null;
            
            // Skip if nothing to identify
            if (empty($iban) && empty($email) && empty($lastName)) {
                $skipped++;
                continue;
            }
            
            // Hash source: IBAN first, then email, then name
            $hashSource = $iban ?: $email ?: ($lastName . $firstName);
            $ibanHash = hash('sha256', $hashSource);
            
            $exists = DB::table('blacklists')
                ->where('iban_hash', $ibanHash)
                ->exists();
            
            if ($exists) {
                $skipped++;
                continue;
            }
            
            DB::table('blacklists')->insert([
                'iban' => $iban,
                'iban_hash' => $ibanHash,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'reason' => $data['reason'] ?? 'v1 migration',
                'source' => $data['source'] ?? 'v1_migration',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $count++;
        }
        
        fclose($handle);
        
        $this->command->info("Imported {$count} records, skipped {$skipped} duplicates");
    }
}
