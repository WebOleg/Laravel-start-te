<?php

namespace App\Console\Commands;

use App\Models\BavVerifiedIban;
use App\Services\IbanBavService;
use App\Services\IbanValidator;
use App\Traits\WithLogContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BatchBavVerify extends Command
{
    use WithLogContext;

    protected $signature = 'bav:batch {file : Path to CSV file} {--output= : Output CSV path} {--limit=0 : Limit records} {--delay=500 : Delay between requests in ms} {--random : Shuffle rows before processing} {--skip-verified : Skip IBANs already in bav_verified_ibans}';

    protected $description = 'Run BAV verification for all records in a CSV file';

    public function handle(IbanBavService $bavService, IbanValidator $ibanValidator): int
    {
        // Initialize the context
        $this->initLogContext();

        $inputPath = $this->argument('file');
        $outputPath = $this->option('output') ?: storage_path('app/bav_results_' . date('Y-m-d_His') . '.csv');
        $limit = (int) $this->option('limit');
        $delayMs = (int) $this->option('delay');
        $randomize = $this->option('random');
        $skipVerified = $this->option('skip-verified');

        if (!file_exists($inputPath)) {
            $this->error("File not found: {$inputPath}");
            return 1;
        }

        $handle = fopen($inputPath, 'r');
        $header = fgetcsv($handle, 0, ';');

        $this->info("Input columns: " . implode(', ', $header));

        $ibanCol = $this->findColumn($header, ['iban', 'Iban', 'IBAN']);
        $firstNameCol = $this->findColumn($header, ['first_name', 'FirstName', 'firstname']);
        $lastNameCol = $this->findColumn($header, ['last_name', 'LastName', 'lastname']);

        if ($ibanCol === null) {
            $this->error("IBAN column not found");
            return 1;
        }

        $this->info("IBAN column: {$ibanCol}, First name: {$firstNameCol}, Last name: {$lastNameCol}");

        // Read all rows into memory for random/dedupe
        $allRows = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $allRows[] = $row;
        }
        fclose($handle);

        $this->info("Loaded " . count($allRows) . " rows");

        // Dedupe: skip already verified IBANs
        $skippedVerified = 0;
        if ($skipVerified) {
            $hashes = [];
            foreach ($allRows as $row) {
                $iban = $row[$ibanCol] ?? '';
                if (!empty($iban)) {
                    $normalized = $ibanValidator->normalize($iban);
                    $hashes[] = $ibanValidator->hash($normalized);
                }
            }

            $alreadyVerified = BavVerifiedIban::findVerified(array_unique($hashes));

            $filtered = [];
            foreach ($allRows as $row) {
                $iban = $row[$ibanCol] ?? '';
                if (!empty($iban)) {
                    $normalized = $ibanValidator->normalize($iban);
                    $hash = $ibanValidator->hash($normalized);
                    if (isset($alreadyVerified[$hash])) {
                        $skippedVerified++;
                        continue;
                    }
                }
                $filtered[] = $row;
            }

            $allRows = $filtered;
            $eligible = count($allRows);
            $this->info("Skipped {$skippedVerified} already verified IBANs, {$eligible} eligible");
        }

        // Shuffle for random selection
        if ($randomize) {
            shuffle($allRows);
            $this->info("Rows shuffled for random selection");
        }

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
        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, count($allRows)) : count($allRows));
        $bar->start();

        foreach ($allRows as $row) {
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

                    // Record in global BAV cache
                    $normalized = $ibanValidator->normalize($iban);
                    BavVerifiedIban::recordVerification(
                        ibanHash: $ibanValidator->hash($normalized),
                        ibanMasked: $ibanValidator->mask($iban),
                        fullName: $fullName,
                        nameMatch: $result['name_match'],
                        bic: $result['bic'],
                        bavScore: $result['vop_score'],
                        bavResult: $result['vop_result'],
                        source: BavVerifiedIban::SOURCE_ARTISAN,
                        sourceId: null
                    );
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

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        fclose($output);

        $this->info("Completed!");
        $this->table(['Metric', 'Value'], [
            ['Total processed', $processed],
            ['Success', $success],
            ['Failed', $failed],
            ['Skipped (already verified)', $skippedVerified],
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
