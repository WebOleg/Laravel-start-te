<?php

/**
 * Job to export clean users to CSV file.
 * Used for large exports (>10,000 records).
 * Stores files in S3 (MinIO) for reliable access.
 */

namespace App\Jobs;

use App\Models\BillingAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExportCleanUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;
    public int $maxExceptions = 1;

    private string $jobId;
    private int $limit;
    private int $minDays;

    public function __construct(string $jobId, int $limit, int $minDays = 30)
    {
        $this->jobId = $jobId;
        $this->limit = $limit;
        $this->minDays = $minDays;
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        Log::info("ExportCleanUsersJob started", [
            'job_id' => $this->jobId,
            'limit' => $this->limit,
            'min_days' => $this->minDays,
        ]);

        $this->updateStatus('processing', 0);

        try {
            $cutoffDate = now()->subDays($this->minDays);
            $filename = 'clean_users_' . now()->format('Y-m-d_His') . '.csv';
            $path = 'exports/' . $this->jobId . '/' . $filename;

            $chargebackedSubquery = BillingAttempt::select('debtor_id')
                ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
                ->whereNotNull('debtor_id')
                ->distinct();

            // Build query WITHOUT limit - we'll enforce it manually
            $query = BillingAttempt::query()
                ->with('debtor:id,first_name,last_name,iban')
                ->select('id', 'debtor_id', 'amount', 'currency')
                ->where('status', BillingAttempt::STATUS_APPROVED)
                ->where('attempt_number', 1)
                ->where('emp_created_at', '<=', $cutoffDate)
                ->whereNotNull('debtor_id')
                ->whereNotIn('debtor_id', $chargebackedSubquery)
                ->oldest('emp_created_at');

            $written = 0;

            // Create temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'clean_users_');
            $handle = fopen($tempFile, 'w');

            // Write CSV header
            fputcsv($handle, ['name', 'iban', 'amount', 'currency']);

            // Process with lazy() for memory efficiency, manual limit check
            foreach ($query->lazy(1000) as $attempt) {
                // Stop when limit reached
                if ($written >= $this->limit) {
                    break;
                }

                $debtor = $attempt->debtor;
                if (!$debtor || !$debtor->iban) {
                    continue;
                }

                fputcsv($handle, [
                    trim($debtor->first_name . ' ' . $debtor->last_name),
                    $debtor->iban,
                    number_format($attempt->amount, 2, '.', ''),
                    $attempt->currency ?? 'EUR',
                ]);

                $written++;

                // Update progress every 1000 records
                if ($written % 1000 === 0) {
                    $progress = round(($written / $this->limit) * 100);
                    $this->updateStatus('processing', min($progress, 99), $written);
                }
            }

            fclose($handle);

            // Upload to S3 (MinIO)
            Storage::disk('s3')->put($path, file_get_contents($tempFile), 'private');
            unlink($tempFile);

            $size = Storage::disk('s3')->size($path);

            $this->updateStatus('completed', 100, $written, [
                'filename' => $filename,
                'path' => $path,
                'size' => $size,
                'download_expires_at' => now()->addHours(24)->toISOString(),
            ]);

            Log::info("ExportCleanUsersJob completed", [
                'job_id' => $this->jobId,
                'processed' => $written,
                'size' => $size,
            ]);

        } catch (\Exception $e) {
            Log::error("ExportCleanUsersJob failed", [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            $this->updateStatus('failed', 0, 0, [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExportCleanUsersJob failed permanently", [
            'job_id' => $this->jobId,
            'error' => $exception->getMessage(),
        ]);

        $this->updateStatus('failed', 0, 0, [
            'error' => $exception->getMessage(),
        ]);
    }

    private function updateStatus(string $status, int $progress, int $processed = 0, array $extra = []): void
    {
        Cache::put("clean_users_export:{$this->jobId}", array_merge([
            'status' => $status,
            'progress' => $progress,
            'processed' => $processed,
            'limit' => $this->limit,
            'min_days' => $this->minDays,
            'updated_at' => now()->toISOString(),
        ], $extra), now()->addHours(24));
    }
}
