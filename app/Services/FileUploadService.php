<?php

/**
 * Service for handling file uploads and creating debtor records.
 */

namespace App\Services;

use App\Models\Upload;
use App\Models\Debtor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    private const COLUMN_MAP = [
        'iban' => 'iban',
        'iban_number' => 'iban',
        'bank_account' => 'iban',
        'account_number' => 'iban',
        
        'first_name' => 'first_name',
        'firstname' => 'first_name',
        'vorname' => 'first_name',
        'nombre' => 'first_name',
        
        'last_name' => 'last_name',
        'lastname' => 'last_name',
        'nachname' => 'last_name',
        'apellido' => 'last_name',
        'surname' => 'last_name',
        
        'email' => 'email',
        'e_mail' => 'email',
        'mail' => 'email',
        
        'phone' => 'phone',
        'telephone' => 'phone',
        'telefon' => 'phone',
        'telefono' => 'phone',
        'mobile' => 'phone',
        
        'phone_2' => 'phone_2',
        'phone_3' => 'phone_3',
        'phone_4' => 'phone_4',
        
        'address' => 'address',
        'street' => 'street',
        'strasse' => 'street',
        'calle' => 'street',
        
        'street_number' => 'street_number',
        'house_number' => 'street_number',
        'hausnummer' => 'street_number',
        'numero' => 'street_number',
        
        'floor' => 'floor',
        'etage' => 'floor',
        'piso' => 'floor',
        
        'door' => 'door',
        'apartment' => 'apartment',
        'apt' => 'apartment',
        'wohnung' => 'apartment',
        
        'postcode' => 'postcode',
        'postal_code' => 'postcode',
        'zip' => 'postcode',
        'zip_code' => 'postcode',
        'plz' => 'postcode',
        'codigo_postal' => 'postcode',
        
        'city' => 'city',
        'stadt' => 'city',
        'ciudad' => 'city',
        'ort' => 'city',
        
        'province' => 'province',
        'state' => 'province',
        'bundesland' => 'province',
        'provincia' => 'province',
        
        'country' => 'country',
        'land' => 'country',
        'pais' => 'country',
        
        'amount' => 'amount',
        'betrag' => 'amount',
        'importe' => 'amount',
        'sum' => 'amount',
        'total' => 'amount',
        
        'currency' => 'currency',
        'wahrung' => 'currency',
        'moneda' => 'currency',
        
        'national_id' => 'national_id',
        'dni' => 'national_id',
        'nie' => 'national_id',
        'id_number' => 'national_id',
        'personal_id' => 'national_id',
        'ausweisnummer' => 'national_id',
        
        'birth_date' => 'birth_date',
        'birthdate' => 'birth_date',
        'date_of_birth' => 'birth_date',
        'dob' => 'birth_date',
        'geburtsdatum' => 'birth_date',
        'fecha_nacimiento' => 'birth_date',
        
        'bank_name' => 'bank_name',
        'bank' => 'bank_name',
        'banco' => 'bank_name',
        
        'bank_code' => 'bank_code',
        'blz' => 'bank_code',
        
        'bic' => 'bic',
        'swift' => 'bic',
        'swift_code' => 'bic',
        
        'external_reference' => 'external_reference',
        'reference' => 'external_reference',
        'ref' => 'external_reference',
        'order_id' => 'external_reference',
        'customer_id' => 'external_reference',
    ];

    public function __construct(
        private SpreadsheetParserService $parser,
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Process uploaded file and create debtor records.
     *
     * @return array{upload: Upload, created: int, failed: int, errors: array}
     */
    public function process(UploadedFile $file, ?int $userId = null): array
    {
        $parsed = $this->parser->parse($file);
        $storedPath = $this->storeFile($file);
        $upload = $this->createUploadRecord($file, $storedPath, $parsed['total_rows'], $userId);
        
        $columnMapping = $this->buildColumnMapping($parsed['headers']);
        $upload->update(['column_mapping' => $columnMapping]);
        
        $result = $this->processRows($upload, $parsed['rows'], $columnMapping);
        $this->finalizeUpload($upload, $result);
        
        return [
            'upload' => $upload->fresh(),
            'created' => $result['created'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ];
    }

    private function storeFile(UploadedFile $file): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        return $file->storeAs('uploads', $filename, 'local');
    }

    private function createUploadRecord(
        UploadedFile $file,
        string $storedPath,
        int $totalRows,
        ?int $userId
    ): Upload {
        return Upload::create([
            'filename' => basename($storedPath),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'status' => Upload::STATUS_PROCESSING,
            'total_records' => $totalRows,
            'processed_records' => 0,
            'failed_records' => 0,
            'uploaded_by' => $userId,
            'processing_started_at' => now(),
        ]);
    }

    private function buildColumnMapping(array $headers): array
    {
        $mapping = [];
        
        foreach ($headers as $header) {
            $normalized = strtolower(trim($header));
            
            if (isset(self::COLUMN_MAP[$normalized])) {
                $mapping[$header] = self::COLUMN_MAP[$normalized];
            }
        }
        
        return $mapping;
    }

    /**
     * @return array{created: int, failed: int, errors: array}
     */
    private function processRows(Upload $upload, array $rows, array $columnMapping): array
    {
        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            try {
                $debtorData = $this->mapRowToDebtor($row, $columnMapping);
                $debtorData['upload_id'] = $upload->id;
                
                $this->validateAndEnrichIban($debtorData);
                $this->validateRequiredFields($debtorData, $index);
                
                Debtor::create($debtorData);
                $created++;
                
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $e->getMessage(),
                    'data' => array_slice($row, 0, 3),
                ];
            }
        }

        return compact('created', 'failed', 'errors');
    }

    private function mapRowToDebtor(array $row, array $columnMapping): array
    {
        $data = [
            'status' => Debtor::STATUS_PENDING,
            'currency' => 'EUR',
        ];

        foreach ($row as $header => $value) {
            if (isset($columnMapping[$header]) && $value !== null) {
                $field = $columnMapping[$header];
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

        try {
            $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd-m-Y'];
            
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, trim($value));
                if ($date) {
                    return $date->format('Y-m-d');
                }
            }
            
            $timestamp = strtotime($value);
            if ($timestamp) {
                return date('Y-m-d', $timestamp);
            }
        } catch (\Exception) {
        }

        return null;
    }

    private function validateAndEnrichIban(array &$data): void
    {
        if (empty($data['iban'])) {
            return;
        }

        $result = $this->ibanValidator->validate($data['iban']);
        
        $data['iban'] = $this->ibanValidator->normalize($data['iban']);
        $data['iban_hash'] = $this->ibanValidator->hash($data['iban']);
        $data['iban_valid'] = $result['valid'];
        
        if ($result['valid']) {
            $data['country'] = $data['country'] ?? $result['country_code'];
            $data['bank_code'] = $data['bank_code'] ?? $result['bank_id'];
        }
    }

    private function validateRequiredFields(array $data, int $rowIndex): void
    {
        $errors = [];

        if (empty($data['iban'])) {
            $errors[] = 'IBAN is required';
        } elseif (!($data['iban_valid'] ?? false)) {
            $errors[] = 'IBAN is invalid';
        }

        if (empty($data['first_name']) && empty($data['last_name'])) {
            $errors[] = 'Name is required (first_name or last_name)';
        }

        if (empty($data['amount']) || $data['amount'] <= 0) {
            $errors[] = 'Valid amount is required';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    private function finalizeUpload(Upload $upload, array $result): void
    {
        $status = $result['failed'] === 0 
            ? Upload::STATUS_COMPLETED 
            : ($result['created'] > 0 ? Upload::STATUS_COMPLETED : Upload::STATUS_FAILED);

        $upload->update([
            'status' => $status,
            'processed_records' => $result['created'],
            'failed_records' => $result['failed'],
            'processing_completed_at' => now(),
            'meta' => [
                'errors' => array_slice($result['errors'], 0, 100),
            ],
        ]);
    }
}
