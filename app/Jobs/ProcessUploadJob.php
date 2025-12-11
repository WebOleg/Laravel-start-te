<?php

/**
 * Job for processing uploaded files with batch support for large files.
 */

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Debtor;
use App\Services\SpreadsheetParserService;
use App\Services\IbanValidator;
use App\Traits\ParsesDebtorData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessUploadJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ParsesDebtorData;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [30, 60, 120];

    private const CHUNK_SIZE = 500;
    private const BATCH_THRESHOLD = 100;

    public function __construct(
        public Upload $upload,
        public array $columnMapping = []
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'upload_' . $this->upload->id;
    }

    public function handle(SpreadsheetParserService $parser, IbanValidator $ibanValidator): void
    {
        Log::info("ProcessUploadJob started", ['upload_id' => $this->upload->id]);

        try {
            $this->upload->update([
                'status' => Upload::STATUS_PROCESSING,
                'processing_started_at' => now(),
            ]);

            $filePath = storage_path('app/' . $this->upload->file_path);

            if (!file_exists($filePath)) {
                throw new \RuntimeException("File not found: {$filePath}");
            }

            $parsed = $this->parseFile($parser, $filePath);
            $rows = $parsed['rows'];

            if (count($rows) > self::BATCH_THRESHOLD) {
                $this->processWithBatching($rows);
            } else {
                $this->processDirectly($rows, $ibanValidator);
            }

        } catch (\Exception $e) {
            Log::error("ProcessUploadJob failed", [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessUploadJob permanently failed", [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
        ]);

        $this->upload->update([
            'status' => Upload::STATUS_FAILED,
            'processing_completed_at' => now(),
            'meta' => array_merge($this->upload->meta ?? [], [
                'error' => $exception->getMessage(),
            ]),
        ]);
    }

    private function processWithBatching(array $rows): void
    {
        $chunks = array_chunk($rows, self::CHUNK_SIZE);
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessUploadChunkJob(
                upload: $this->upload,
                rows: $chunk,
                columnMapping: $this->columnMapping,
                chunkIndex: $index,
                startRow: $index * self::CHUNK_SIZE + 1
            );
        }

        $uploadId = $this->upload->id;

        Bus::batch($jobs)
            ->name("Upload #{$uploadId}")
            ->allowFailures()
            ->finally(function () use ($uploadId) {
                $upload = Upload::find($uploadId);
                if ($upload) {
                    $status = $upload->failed_records === $upload->total_records
                        ? Upload::STATUS_FAILED
                        : Upload::STATUS_COMPLETED;

                    $upload->update([
                        'status' => $status,
                        'processing_completed_at' => now(),
                    ]);

                    Log::info("Upload batch completed", [
                        'upload_id' => $uploadId,
                        'status' => $status,
                        'processed' => $upload->processed_records,
                        'failed' => $upload->failed_records,
                    ]);
                }
            })
            ->dispatch();

        Log::info("ProcessUploadJob dispatched batch", [
            'upload_id' => $this->upload->id,
            'chunks' => count($chunks),
            'total_rows' => count($rows),
        ]);
    }

    private function processDirectly(array $rows, IbanValidator $ibanValidator): void
    {
        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            try {
                $debtorData = $this->mapRowToDebtor($row);
                $debtorData['upload_id'] = $this->upload->id;

                $this->validateAndEnrichIban($debtorData, $ibanValidator);
                $this->enrichCountryFromIban($debtorData);
                $this->validateRequiredFields($debtorData);

                Debtor::create($debtorData);
                $created++;

            } catch (\Exception $e) {
                $failed++;
                if (count($errors) < 100) {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        $this->finalizeUpload($created, $failed, $errors);

        Log::info("ProcessUploadJob completed directly", [
            'upload_id' => $this->upload->id,
            'created' => $created,
            'failed' => $failed,
        ]);
    }

    private function parseFile(SpreadsheetParserService $parser, string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return match (strtolower($extension)) {
            'csv', 'txt' => $parser->parseCsv($filePath),
            'xlsx', 'xls' => $parser->parseExcel($filePath),
            default => throw new \RuntimeException("Unsupported file type: {$extension}"),
        };
    }

    private function mapRowToDebtor(array $row): array
    {
        $data = [
            'status' => Debtor::STATUS_PENDING,
            'currency' => 'EUR',
        ];

        foreach ($row as $header => $value) {
            if (isset($this->columnMapping[$header]) && $value !== null) {
                $field = $this->columnMapping[$header];
                $data[$field] = $this->castValue($field, $value);
            }
        }

        $this->splitFullName($data);

        return $data;
    }

    private function validateAndEnrichIban(array &$data, IbanValidator $ibanValidator): void
    {
        if (empty($data['iban'])) {
            return;
        }

        $result = $ibanValidator->validate($data['iban']);

        $data['iban'] = $ibanValidator->normalize($data['iban']);
        $data['iban_hash'] = $ibanValidator->hash($data['iban']);
        $data['iban_valid'] = $result['valid'];

        if ($result['valid']) {
            $data['bank_code'] = $data['bank_code'] ?? $result['bank_id'];
        }
    }

    private function validateRequiredFields(array $data): void
    {
        $errors = [];

        if (empty($data['iban'])) {
            $errors[] = 'IBAN is required';
        } elseif (!($data['iban_valid'] ?? false)) {
            $errors[] = 'IBAN is invalid';
        }

        if (empty($data['first_name']) && empty($data['last_name'])) {
            $errors[] = 'Name is required';
        }

        if (empty($data['amount']) || $data['amount'] <= 0) {
            $errors[] = 'Valid amount is required';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    private function finalizeUpload(int $created, int $failed, array $errors): void
    {
        $status = $failed === 0
            ? Upload::STATUS_COMPLETED
            : ($created > 0 ? Upload::STATUS_COMPLETED : Upload::STATUS_FAILED);

        $this->upload->update([
            'status' => $status,
            'processed_records' => $created,
            'failed_records' => $failed,
            'processing_completed_at' => now(),
            'meta' => array_merge($this->upload->meta ?? [], [
                'errors' => $errors,
            ]),
        ]);
    }
}
