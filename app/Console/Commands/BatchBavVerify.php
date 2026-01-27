<?php

namespace App\Console\Commands;

use App\Services\IbanBavService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BatchBavVerify extends Command
{
    protected $signature = 'bav:batch {file : Path to CSV file} {--output= : Output CSV path} {--limit=0 : Limit records} {--delay=500 : Delay between requests in ms}';
    
    protected $description = 'Run BAV verification for all records in a CSV file';

    public function handle(IbanBavService $bavService): int
    {
        $inputPath = $this->argument('file');
        $outputPath = $this->option('output') ?: storage_path('app/bav_results_' . date('Y-m-d_His') . '.csv');
        $limit = (int) $this->option('limit');
        $delayMs = (int) $this->option('delay');

        if (!file_exists($inputPath)) {
            $this->error("File not found: {$inputPath}");
            return 1;
        }

        $handle = fopen($inputPath, 'r');
        $header = fgetcsv($handle, 0, ';');
        
        $this->info("Input columns: " . implode(', ', $header));
        
        // Find IBAN and name columns
        $ibanCol = $this->findColumn($header, ['iban', 'Iban', 'IBAN']);
        $firstNameCol = $this->findColumn($header, ['first_name', 'FirstName', 'firstname']);
        $lastNameCol = $this->findColumn($header, ['last_name', 'LastName', 'lastname']);
        
        if ($ibanCol === null) {
            $this->error("IBAN column not found");
            return 1;
        }

        $this->info("IBAN column: {$ibanCol}, First name: {$firstNameCol}, Last name: {$lastNameCol}");

        // Prepare output
        $output = fopen($outputPath, 'w');
        fputcsv($output, array_merge($header, [
            'bav_success',
            'bav_valid', 
            'bav_name_match',
            'bav_bic',
            'bav_score',
            'bav_result',
            'bav_error'
        ]), ';');

        $processed = 0;
        $success = 0;
        $failed = 0;

        $this->info("Starting BAV verification...");
        $bar = $this->output->createProgressBar();
        $bar->start();

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $iban = $row[$ibanCol] ?? '';
            $firstName = $firstNameCol !== null ? ($row[$firstNameCol] ?? '') : '';
            $lastName = $lastNameCol !== null ? ($row[$lastNameCol] ?? '') : '';
            $fullName = trim("{$firstName} {$lastName}");

            if (empty($iban)) {
                $row = array_merge($row, ['false', 'false', '', '', '0', '', 'Empty IBAN']);
                fputcsv($output, $row, ';');
                $failed++;
                $processed++;
                $bar->advance();
                continue;
            }

            try {
                $result = $bavService->verify($iban, $fullName);
                
                $row = array_merge($row, [
                    $result['success'] ? 'true' : 'false',
                    $result['valid'] ? 'true' : 'false',
                    $result['name_match'] ?? '',
                    $result['bic'] ?? '',
                    $result['vop_score'] ?? 0,
                    $result['vop_result'] ?? '',
                    $result['error'] ?? ''
                ]);

                if ($result['success']) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $row = array_merge($row, ['false', 'false', '', '', '0', '', $e->getMessage()]);
                $failed++;
                Log::error("BAV error for IBAN {$iban}: " . $e->getMessage());
            }

            fputcsv($output, $row, ';');
            $processed++;
            $bar->advance();

            // Rate limiting
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        fclose($handle);
        fclose($output);

        $this->info("Completed!");
        $this->table(['Metric', 'Value'], [
            ['Total processed', $processed],
            ['Success', $success],
            ['Failed', $failed],
            ['Output file', $outputPath],
        ]);

        return 0;
    }

    private function findColumn(array $header, array $names): ?int
    {
        foreach ($names as $name) {
            $index = array_search($name, $header);
            if ($index !== false) {
                return $index;
            }
        }
        return null;
    }
}
