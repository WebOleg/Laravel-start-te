<?php

/**
 * Job for processing a chunk of rows from uploaded file.
 */

namespace App\Jobs;

use App\Models\Upload;
use App\Models\Debtor;
use App\Services\IbanValidator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(IbanValidator $ibanValidator): void
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
        $errors = [];

        foreach ($this->rows as $index => $row) {
            try {
                $debtorData = $this->mapRowToDebtor($row);
                $debtorData['upload_id'] = $this->upload->id;

                $this->validateAndEnrichIban($debtorData, $ibanValidator);
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

        $this->updateUploadProgress($created, $failed, $errors);

        Log::info("ProcessUploadChunkJob completed", [
            'upload_id' => $this->upload->id,
            'chunk' => $this->chunkIndex,
            'created' => $created,
            'failed' => $failed,
        ]);
    }

    private function updateUploadProgress(int $created, int $failed, array $errors): void
    {
        $this->upload->increment('processed_records', $created);
        $this->upload->increment('failed_records', $failed);

        if (!empty($errors)) {
            $existingMeta = $this->upload->meta ?? [];
            $existingErrors = $existingMeta['errors'] ?? [];
            $mergedErrors = array_merge($existingErrors, $errors);
            
            $this->upload->update([
                'meta' => array_merge($existingMeta, [
                    'errors' => array_slice($mergedErrors, 0, 100),
                ]),
            ]);
        }
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
}
