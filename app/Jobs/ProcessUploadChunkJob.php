<?php

/**
 * Job for processing a chunk of rows from uploaded file.
 */

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Debtor;
use App\Services\IbanValidator;
use App\Services\DeduplicationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessUploadChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public Upload $upload,
        public array $rows,
        public array $columnMapping,
        public int $chunkIndex,
        public int $startRow
    ) {
        $this->onQueue('default');
    }

    public function handle(IbanValidator $ibanValidator, DeduplicationService $deduplicationService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info("ProcessUploadChunkJob started", [
            'upload_id' => $this->upload->id,
            'chunk' => $this->chunkIndex,
            'rows' => count($this->rows),
        ]);

        $created = 0;
        $failed = 0;
        $skippedByReason = [];
        $errors = [];

        $debtorDataList = [];
        foreach ($this->rows as $index => $row) {
            $debtorData = $this->mapRowToDebtor($row);
            $this->validateAndEnrichIban($debtorData, $ibanValidator);
            $debtorDataList[$index] = $debtorData;
        }

        $dedupeResults = $deduplicationService->checkDebtorBatch($debtorDataList, $this->upload->id);

        foreach ($this->rows as $index => $row) {
            try {
                $debtorData = $debtorDataList[$index];

                if (isset($dedupeResults[$index])) {
                    $reason = $dedupeResults[$index]['reason'];
                    $skippedByReason[$reason] = ($skippedByReason[$reason] ?? 0) + 1;
                    Log::debug("Row skipped due to deduplication", [
                        'upload_id' => $this->upload->id,
                        'row' => $this->startRow + $index + 1,
                        'reason' => $reason,
                    ]);
                    continue;
                }

                $debtorData['upload_id'] = $this->upload->id;
                $debtorData['raw_data'] = $row;
                $debtorData['validation_status'] = Debtor::VALIDATION_PENDING;

                $this->validateRequiredFields($debtorData);

                Debtor::create($debtorData);
                $created++;

            } catch (\Exception $e) {
                $failed++;
                if (count($errors) < 10) {
                    $errors[] = [
                        'row' => $this->startRow + $index + 1,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        $skippedTotal = array_sum($skippedByReason);
        $this->updateUploadProgressAtomic($created, $failed, $skippedTotal, $skippedByReason, $errors);

        Log::info("ProcessUploadChunkJob completed", [
            'upload_id' => $this->upload->id,
            'chunk' => $this->chunkIndex,
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skippedTotal,
            'skipped_by_reason' => $skippedByReason,
        ]);
    }

    private function updateUploadProgressAtomic(int $created, int $failed, int $skipped, array $skippedByReason, array $errors): void
    {
        $this->upload->increment('processed_records', $created);
        $this->upload->increment('failed_records', $failed);

        DB::statement("
            UPDATE uploads 
            SET meta = jsonb_set(
                COALESCE(meta, '{}'::jsonb),
                '{skipped}',
                jsonb_build_object(
                    'total', COALESCE((meta->'skipped'->>'total')::int, 0) + ?,
                    'blacklisted', COALESCE((meta->'skipped'->>'blacklisted')::int, 0) + ?,
                    'blacklisted_name', COALESCE((meta->'skipped'->>'blacklisted_name')::int, 0) + ?,
                    'blacklisted_email', COALESCE((meta->'skipped'->>'blacklisted_email')::int, 0) + ?,
                    'blacklisted_bic', COALESCE((meta->'skipped'->>'blacklisted_bic')::int, 0) + ?,
                    'chargebacked', COALESCE((meta->'skipped'->>'chargebacked')::int, 0) + ?,
                    'already_recovered', COALESCE((meta->'skipped'->>'already_recovered')::int, 0) + ?,
                    'recently_attempted', COALESCE((meta->'skipped'->>'recently_attempted')::int, 0) + ?,
                    'duplicates', COALESCE((meta->'skipped'->>'duplicates')::int, 0)
                )
            )
            WHERE id = ?
        ", [
            $skipped,
            $skippedByReason[DeduplicationService::SKIP_BLACKLISTED] ?? 0,
            $skippedByReason[DeduplicationService::SKIP_BLACKLISTED_NAME] ?? 0,
            $skippedByReason[DeduplicationService::SKIP_BLACKLISTED_EMAIL] ?? 0,
            $skippedByReason[DeduplicationService::SKIP_BLACKLISTED_BIC] ?? 0,
            $skippedByReason[DeduplicationService::SKIP_CHARGEBACKED] ?? 0,
            $skippedByReason[DeduplicationService::SKIP_RECOVERED] ?? 0,
            $skippedByReason[DeduplicationService::SKIP_RECENTLY_ATTEMPTED] ?? 0,
            $this->upload->id,
        ]);

        if (!empty($errors)) {
            $this->upload->refresh();
            $existingMeta = $this->upload->meta ?? [];
            $existingErrors = $existingMeta['errors'] ?? [];
            $mergedErrors = array_slice(array_merge($existingErrors, $errors), 0, 100);
            
            DB::table('uploads')
                ->where('id', $this->upload->id)
                ->update([
                    'meta' => DB::raw("jsonb_set(COALESCE(meta, '{}'::jsonb), '{errors}', '" . json_encode($mergedErrors) . "'::jsonb)")
                ]);
        }
    }

    private function mapRowToDebtor(array $row): array
    {
        $data = [
            'status' => Debtor::STATUS_UPLOADED,
            'currency' => 'EUR',
        ];

        foreach ($row as $header => $value) {
            if (isset($this->columnMapping[$header]) && $value !== null) {
                $field = $this->columnMapping[$header];
                $data[$field] = $this->castValue($field, $value);
            }
        }

        if (isset($data['name']) && !isset($data['first_name'])) {
            $parts = preg_split('/\s+/', trim($data['name']), 2);
            $data['first_name'] = $parts[0] ?? '';
            $data['last_name'] = $parts[1] ?? '';
            unset($data['name']);
        }

        return $data;
    }

    private function castValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'amount' => $this->parseAmount($value),
            'birth_date' => $this->parseDate($value),
            'country' => strtoupper(substr(trim($value), 0, 2)),
            'currency' => strtoupper(substr(trim($value), 0, 3)),
            default => trim((string) $value),
        };
    }

    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(' ', '', (string) $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            if (preg_match('/,\d{1,2}$/', $value)) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        return (float) $value;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd-m-Y'];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, trim($value));
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function validateAndEnrichIban(array &$data, IbanValidator $ibanValidator): void
    {
        if (empty($data['iban'])) {
            $data['iban'] = '';
            $data['iban_hash'] = null;
            $data['iban_valid'] = false;
            return;
        }

        $result = $ibanValidator->validate($data['iban']);

        $data['iban'] = $ibanValidator->normalize($data['iban']);
        $data['iban_hash'] = $ibanValidator->hash($data['iban']);
        $data['iban_valid'] = $result['valid'];

        if ($result['valid']) {
            $data['country'] = $data['country'] ?? $result['country_code'];
            $data['bank_code'] = $data['bank_code'] ?? $result['bank_id'];
        }
    }

    private function validateRequiredFields(array $data): void
    {
        $errors = [];

        if (empty($data['first_name']) && empty($data['last_name'])) {
            $errors[] = 'Name is required';
        }

        if (empty($data['amount']) || $data['amount'] < 1) {
            $errors[] = 'Valid amount is required';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }
}
