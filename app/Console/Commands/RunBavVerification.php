<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RunBavVerification extends Command
{
    protected $signature = 'bav:verify {file} {--output=bav_results.csv}';
    protected $description = 'Run BAV verification on CSV file';

    private const API_URL = 'https://api.iban.com/clients/api/verify/v3/';

    public function handle(): int
    {
        $apiKey = config('services.iban.api_key');
        $inputFile = $this->argument('file');
        $outputFile = $this->option('output');

        if (!file_exists($inputFile)) {
            $this->error("File not found: {$inputFile}");
            return 1;
        }

        $records = [];
        if (($handle = fopen($inputFile, 'r')) !== false) {
            $headers = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $records[] = array_combine($headers, $row);
            }
            fclose($handle);
        }

        $this->info("Loaded " . count($records) . " records");
        $this->info("Output: {$outputFile}");

        $stats = ['total' => 0, 'yes' => 0, 'partial' => 0, 'no' => 0, 'unavailable' => 0, 'error' => 0];

        $out = fopen($outputFile, 'w');
        fputcsv($out, ['row','iban','name','bav_success','bav_valid','bav_name_match','bav_bic','bav_error','address','postal_code','city','country','amount']);

        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        foreach ($records as $i => $row) {
            $firstName = trim($row['contact_first_name'] ?? '');
            $lastName = trim($row['contact_last_name'] ?? '');
            $name = trim("{$firstName} {$lastName}");
            $iban = trim($row['iban'] ?? '');

            if (!$iban || !$name) {
                $bar->advance();
                continue;
            }

            $result = $this->verifyBav($apiKey, $iban, $name);
            
            $stats['total']++;
            $nm = $result['name_match'];
            $stats[$nm] = ($stats[$nm] ?? 0) + 1;

            fputcsv($out, [
                $i + 1,
                $iban,
                $name,
                $result['success'] ? 'true' : 'false',
                $result['valid'] ? 'true' : 'false',
                $result['name_match'],
                $result['bic'],
                $result['error'],
                $row['address'] ?? '',
                $row['postal_code'] ?? '',
                $row['city'] ?? '',
                $row['country'] ?? '',
                $row['amount'] ?? ''
            ]);

            if ($i % 10 === 0) fflush($out);
            
            $bar->advance();
            usleep(300000); // 0.3s delay
        }

        fclose($out);
        $bar->finish();

        $this->newLine(2);
        $this->info("=== SUMMARY ===");
        $this->info("Total: {$stats['total']}");
        $this->info("YES: {$stats['yes']}");
        $this->info("PARTIAL: {$stats['partial']}");
        $this->info("NO: {$stats['no']}");
        $this->info("UNAVAILABLE: {$stats['unavailable']}");
        $this->info("ERROR: {$stats['error']}");
        $this->info("Saved to: {$outputFile}");

        return 0;
    }

    private function verifyBav(string $apiKey, string $iban, string $name): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post(self::API_URL, [
                'IBAN' => $iban,
                'name' => $name,
            ]);

            $data = $response->json();

            return [
                'success' => $data['query']['success'] ?? false,
                'valid' => $data['result']['valid'] ?? false,
                'name_match' => $data['result']['name_match'] ?? 'error',
                'bic' => $data['result']['bic'] ?? '',
                'error' => $data['error'] ?? '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'valid' => false,
                'name_match' => 'error',
                'bic' => '',
                'error' => $e->getMessage(),
            ];
        }
    }
}
