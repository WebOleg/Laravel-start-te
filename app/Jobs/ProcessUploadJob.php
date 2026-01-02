<?php

/**
 * Job for processing uploaded files with batch support for large files.
 */

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Debtor;
use App\Services\SpreadsheetParserService;
use App\Services\IbanValidator;
use App\Services\BlacklistService;
use App\Services\DeduplicationService;
use App\Traits\ParsesDebtorData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    public function handle(
        SpreadsheetParserService $parser,
        IbanValidator $ibanValidator,
        BlacklistService $blacklistService,
        DeduplicationService $deduplicationService
    ): void {
        Log::info("ProcessUploadJob started", ['upload_id' => $this->upload->id]);

        $tempFilePath = null;

        try {
            $this->upload->update([
                'status' => Upload::STATUS_PROCESSING,
                'processing_started_at' => now(),
            ]);

            $tempFilePath = $this->downloadFromS3();

            $parsed = $this->parseFile($parser, $tempFilePath);
            $rows = $parsed['rows'];

            if (count($rows) > self::BATCH_THRESHOLD) {
                $this->processWithBatching($rows);
            } else {
                $this->processDirectly($rows, $ibanValidator, $deduplicationService);
            }

        } catch (\Exception $e) {
            Log::error("ProcessUploadJob failed", [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->cleanupTempFile($tempFilePath);
        }
    }

    /**
     * Download file from S3 to temporary location.
     *
     * @throws \RuntimeException If file not found or download fails
     */
    private function downloadFromS3(): string
    {
        $s3Path = $this->upload->file_path;

        if (!Storage::disk('s3')->exists($s3Path)) {
            Log::error('File not found in S3', [
                'upload_id' => $this->upload->id,
                'path' => $s3Path,
            ]);
            throw new \RuntimeException("File not found in S3: {$s3Path}");
        }

        $content = Storage::disk('s3')->get($s3Path);

        if ($content === null) {
            throw new \RuntimeException("Failed to download file from S3: {$s3Path}");
        }

        $extension = pathinfo($s3Path, PATHINFO_EXTENSION);
        $tempFilePath = sys_get_temp_dir() . '/upload_' . $this->upload->id . '_' . uniqid() . '.' . $extension;

        if (file_put_contents($tempFilePath, $content) === false) {
            throw new \RuntimeException("Failed to write temp file: {$tempFilePath}");
        }

        Log::info('File downloaded from S3 to temp', [
            'upload_id' => $this->upload->id,
            's3_path' => $s3Path,
            'temp_path' => $tempFilePath,
            'size' => strlen($content),
        ]);

        return $tempFilePath;
    }

    /**
     * Clean up temporary file.
     */
    private function cleanupTempFile(?string $tempFilePath): void
    {
        if ($tempFilePath && file_exists($tempFilePath)) {
            @unlink($tempFilePath);
            Log::debug('Temp file cleaned up', ['path' => $tempFilePath]);
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

    private function processDirectly(
        array $rows,
        IbanValidator $ibanValidator,
        DeduplicationService $deduplicationService
    ): void {
        $created = 0;
        $failed = 0;
        $errors = [];
        
        $skipped = [
            'total' => 0,
            DeduplicationService::SKIP_BLACKLISTED => 0,
            DeduplicationService::SKIP_BLACKLISTED_NAME => 0,
            DeduplicationService::SKIP_BLACKLISTED_EMAIL => 0,
            DeduplicationService::SKIP_CHARGEBACKED => 0,
            DeduplicationService::SKIP_RECOVERED => 0,
            DeduplicationService::SKIP_RECENTLY_ATTEMPTED => 0,
        ];
        $skippedRows = [];

        // Prepare debtor data for batch check
        $debtorDataList = [];
        foreach ($rows as $index => $row) {
            $debtorData = $this->mapRowToDebtor($row);
            $this->normalizeIban($debtorData, $ibanValidator);
            $debtorDataList[$index] = $debtorData;
        }

        // Batch check IBAN + name + email
        $dedupeResults = $deduplicationService->checkDebtorBatch($debtorDataList, $this->upload->id);

        foreach ($rows as $index => $row) {
            try {
                $debtorData = $debtorDataList[$index];

                // Check deduplication result
                if (isset($dedupeResults[$index])) {
                    $skipInfo = $dedupeResults[$index];
                    $skipped['total']++;
                    
                    if (isset($skipped[$skipInfo['reason']])) {
                        $skipped[$skipInfo['reason']]++;
                    }
                    
                    $skippedRows[] = [
                        'row' => $index + 2,
                        'iban_masked' => $ibanValidator->mask($debtorData['iban'] ?? ''),
                        'name' => trim(($debtorData['first_name'] ?? '') . ' ' . ($debtorData['last_name'] ?? '')),
                        'email' => $debtorData['email'] ?? null,
                        'reason' => $skipInfo['reason'],
                    ];
                    continue;
                }

                $debtorData['upload_id'] = $this->upload->id;
                $debtorData['raw_data'] = $row;

                $this->validateBasicStructure($debtorData);
                $this->enrichCountryFromIban($debtorData);

                $debtorData['validation_status'] = Debtor::VALIDATION_PENDING;

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

        $this->finalizeUpload($created, $failed, $errors, $skipped, $skippedRows);

        Log::info("ProcessUploadJob completed directly", [
            'upload_id' => $this->upload->id,
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped['total'],
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

    private function validateBasicStructure(array $data): void
    {
        $errors = [];

        if (empty($data['first_name']) && empty($data['last_name'])) {
            $errors[] = 'Name is required';
        }

        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            $errors[] = 'Valid amount is required';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    private function normalizeIban(array &$data, IbanValidator $ibanValidator): void
    {
        if (empty($data['iban'])) {
            $data['iban'] = '';
            $data['iban_hash'] = null;
            $data['iban_valid'] = false;
            return;
        }

        $data['iban'] = $ibanValidator->normalize($data['iban']);
        $data['iban_hash'] = $ibanValidator->hash($data['iban']);

        $result = $ibanValidator->validate($data['iban']);
        $data['iban_valid'] = $result['valid'];

        if ($result['valid']) {
            $data['bank_code'] = $data['bank_code'] ?? $result['bank_id'];
        }
    }

    private function finalizeUpload(int $created, int $failed, array $errors, array $skipped = [], array $skippedRows = []): void
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
                'skipped' => $skipped,
                'skipped_rows' => array_slice($skippedRows, 0, 100),
            ]),
        ]);
    }
}
