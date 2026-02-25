<?php

/**
 * Job for processing uploaded files with batch support for large files.
 */

namespace App\Jobs;

use App\Models\Upload;
use App\Services\DebtorImportService;
use App\Services\FileUploadService;
use App\Services\SpreadsheetParserService;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        DebtorImportService $importer,
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

            // Update total records count immediately
            $this->upload->update(['total_records' => count($rows)]);

            $meta = $this->upload->meta ?? [];

            if (!empty($meta['apply_global_lock'])) {
                $lockResult = FileUploadService::filterCrossAccountIbans(
                    $rows,
                    $this->upload->emp_account_id,
                    $this->columnMapping
                );

                $rows = $lockResult['rows'];

                // Update meta with skipped counts/errors
                $currentMeta = $this->upload->meta ?? [];
                $currentMeta['skipped_locked'] = $lockResult['excluded_count'];
                $currentMeta['errors'] = array_merge(
                    $currentMeta['errors'] ?? [],
                    array_slice($lockResult['errors'], 0, 50)
                );

                $this->upload->update(['meta' => $currentMeta]);

                Log::info("Global Lock Applied in Async Job", [
                    'upload_id' => $this->upload->id,
                    'excluded' => $lockResult['excluded_count']
                ]);
            }

            if (count($rows) > self::BATCH_THRESHOLD) {
                $this->processWithBatching($rows);
                return;
            }

            // For small files, process directly via Service
            $result = $importer->importRows($this->upload, $rows, $this->columnMapping, false, 1);

            // Adjust skipped counts if lock logic ran
            if (!empty($meta['apply_global_lock'])) {
                $lockedCount = $lockResult['excluded_count'] ?? 0;

                if (!isset($result['skipped']) || !is_array($result['skipped'])) {
                    $result['skipped'] = ['total' => 0, 'skipped_locked' => 0];
                }

                $result['skipped']['skipped_locked'] = ($result['skipped']['skipped_locked'] ?? 0) + $lockedCount;
                $result['skipped']['total'] = ($result['skipped']['total'] ?? 0) + $lockedCount;
            }

            $importer->finalizeUpload($this->upload, $result);

            Log::info("ProcessUploadJob completed directly", [
                'upload_id' => $this->upload->id,
                'created' => $result['created'] ?? null,
                'failed' => $result['failed'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error("ProcessUploadJob failed", [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $this->cleanupTempFile($tempFilePath);
        }
    }

    private function processWithBatching(array $rows): void
    {
        $chunks = array_chunk($rows, self::CHUNK_SIZE);
        $jobs = [];

        $upload = $this->upload;

        foreach ($chunks as $index => $chunk) {
            $jobs[] = new ProcessUploadChunkJob(
                $upload,
                $chunk,
                $this->columnMapping,
                $index,
                $index * self::CHUNK_SIZE + 1
            );
        }

        Bus::batch($jobs)
            ->name("Upload #{$this->upload->id}")
            ->allowFailures()
            ->finally(function () use ($upload) {
                // Refresh model to get latest counts from chunk jobs
                $upload->refresh();

                $status = $upload->failed_records === $upload->total_records && $upload->total_records > 0
                    ? Upload::STATUS_FAILED
                    : Upload::STATUS_COMPLETED;

                $upload->update([
                    'status' => $status,
                    'processing_completed_at' => now(),
                ]);

                Log::info("Upload batch completed", [
                    'upload_id' => $upload->id,
                    'status' => $status,
                    'processed' => $upload->processed_records,
                    'failed' => $upload->failed_records,
                ]);
            })
            ->dispatch();

        Log::info("ProcessUploadJob dispatched batch", [
            'upload_id' => $this->upload->id,
            'chunks' => count($chunks),
            'total_rows' => count($rows),
        ]);
    }

    private function downloadFromS3(): string
    {
        $s3Path = $this->upload->file_path;

        if (!Storage::disk('s3')->exists($s3Path)) {
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

        return $tempFilePath;
    }

    private function cleanupTempFile(?string $tempFilePath): void
    {
        if ($tempFilePath && file_exists($tempFilePath)) {
            @unlink($tempFilePath);
        }
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

    public function failed(\Throwable $exception): void
    {
        $this->upload->update([
            'status' => Upload::STATUS_FAILED,
            'processing_completed_at' => now(),
            'meta' => array_merge($this->upload->meta ?? [], [
                'error' => $exception->getMessage(),
            ]),
        ]);
    }
}
