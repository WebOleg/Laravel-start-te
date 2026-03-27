<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FilePreValidationService
{
    private const SAMPLE_SIZE = 10;
    private const MAX_LEVENSHTEIN_DISTANCE = 2;

    private const REQUIRED_FIELD_GROUPS = [
        'iban' => [
            'label' => 'IBAN',
            'fields' => ['iban'],
        ],
        'amount' => [
            'label' => 'amount',
            'fields' => ['amount'],
        ],
    ];

    public function validate(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'])) {
            return $this->validateCsv($file->getPathname());
        }

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->validateExcel($file->getPathname());
        }

        return $this->result(false, ['Unsupported file type.']);
    }

    private function validateCsv(string $path): array
    {
        $csv = Reader::from($path, 'r');
        $csv->setDelimiter($this->detectDelimiter($path));

        $headers = $csv->fetchOne(0);
        if (empty($headers)) {
            return $this->result(false, ['File is empty or has no headers.']);
        }

        $headers = $this->normalizeHeaders($headers);
        $validation = $this->validateHeaders($headers);

        if (!empty($validation['errors'])) {
            return $this->result(false, $validation['errors'], $headers, 0, $validation['warnings'], $validation['suggestions']);
        }

        $csv->setHeaderOffset(0);
        $sampleCount = 0;
        foreach ($csv->getRecords($headers) as $record) {
            if ($sampleCount >= self::SAMPLE_SIZE) break;
            $sampleCount++;
        }

        $errors = [];
        if ($sampleCount === 0) {
            $errors[] = 'File has headers but no data rows.';
        }

        return $this->result(
            empty($errors),
            $errors,
            $headers,
            $sampleCount,
            $validation['warnings'],
            $validation['suggestions']
        );
    }

    private function validateExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        if (empty($data) || empty($data[0])) {
            return $this->result(false, ['File is empty or has no headers.']);
        }

        $headers = $this->normalizeHeaders($data[0]);
        $validation = $this->validateHeaders($headers);

        if (!empty($validation['errors'])) {
            return $this->result(false, $validation['errors'], $headers, 0, $validation['warnings'], $validation['suggestions']);
        }

        $sampleCount = 0;
        for ($i = 1; $i <= min(self::SAMPLE_SIZE, count($data) - 1); $i++) {
            if ($this->isRowNotEmpty($data[$i])) {
                $sampleCount++;
            }
        }

        $errors = [];
        if ($sampleCount === 0) {
            $errors[] = 'File has headers but no data rows.';
        }

        return $this->result(
            empty($errors),
            $errors,
            $headers,
            $sampleCount,
            $validation['warnings'],
            $validation['suggestions']
        );
    }

    /**
     * Validate headers by resolving them through the column map and checking
     * that all required field groups are covered. Detects misspelled headers
     * via Levenshtein distance and provides suggestions.
     *
     * @return array{errors: string[], warnings: string[], suggestions: array<string, string>}
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];
        $warnings = [];
        $suggestions = [];

        $columnMap = FileUploadService::COLUMN_MAP;
        $knownHeaders = array_keys($columnMap);
        $mappedFields = [];

        foreach ($headers as $header) {
            if ($header === '') {
                continue;
            }

            if (isset($columnMap[$header])) {
                $mappedFields[] = $columnMap[$header];
                continue;
            }

            // Header not recognized — check for close misspellings
            $suggestion = $this->findClosestHeader($header, $knownHeaders);
            if ($suggestion !== null) {
                $suggestions[$header] = $suggestion;
                $warnings[] = "Unrecognized header '{$header}'. Did you mean '{$suggestion}'?";
            }
        }

        $mappedFields = array_unique($mappedFields);

        // Check each required field group is covered by at least one mapped header
        foreach (self::REQUIRED_FIELD_GROUPS as $group) {
            if (empty(array_intersect($mappedFields, $group['fields']))) {
                $errors[] = "Missing required header: {$group['label']}.";
            }
        }

        // Name requires either 'name' (full name) or BOTH 'first_name' and 'last_name'
        $hasFullName = in_array('name', $mappedFields, true);
        $hasFirstName = in_array('first_name', $mappedFields, true);
        $hasLastName = in_array('last_name', $mappedFields, true);

        if (!$hasFullName && !($hasFirstName && $hasLastName)) {
            $missing = [];
            if (!$hasFirstName) {
                $missing[] = 'first_name';
            }
            if (!$hasLastName) {
                $missing[] = 'last_name';
            }
            $errors[] = "Missing required header: " . implode(', ', $missing) . ". Provide a 'name' column, or both 'first_name' and 'last_name'.";
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Find the closest known header using Levenshtein distance.
     * Returns null if no close match is found within the threshold.
     */
    private function findClosestHeader(string $header, array $knownHeaders): ?string
    {
        $bestMatch = null;
        $bestDistance = self::MAX_LEVENSHTEIN_DISTANCE + 1;

        foreach ($knownHeaders as $known) {
            // Skip very short headers to avoid false positives (e.g., "ref" matching "bic")
            if (strlen($header) <= 2 || strlen($known) <= 2) {
                continue;
            }

            $distance = levenshtein($header, $known);
            if ($distance > 0 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $known;
            }
        }

        return $bestDistance <= self::MAX_LEVENSHTEIN_DISTANCE ? $bestMatch : null;
    }

    private function result(
        bool $valid,
        array $errors,
        array $headers = [],
        int $sampleCount = 0,
        array $warnings = [],
        array $suggestions = []
    ): array {
        return [
            'valid' => $valid,
            'errors' => $errors,
            'headers' => $headers,
            'sample_count' => $sampleCount,
            'warnings' => $warnings,
            'suggestions' => $suggestions,
        ];
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        fclose($handle);

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = strtolower(trim($header ?? ''));
            $header = preg_replace('/[^a-z0-9]+/', '_', $header);
            return trim($header, '_');
        }, $headers);
    }

    private function isRowNotEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }
}
