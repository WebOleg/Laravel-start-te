<?php

/**
 * Service for parsing CSV and XLSX files into standardized row arrays.
 */

namespace App\Services;

use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class SpreadsheetParserService
{
    public const TYPE_CSV = 'csv';
    public const TYPE_XLSX = 'xlsx';
    public const TYPE_XLS = 'xls';

    private const SUPPORTED_TYPES = [
        'text/csv' => self::TYPE_CSV,
        'application/csv' => self::TYPE_CSV,
        'text/plain' => self::TYPE_CSV,
        'application/vnd.ms-excel' => self::TYPE_XLS,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => self::TYPE_XLSX,
    ];

    private const SUPPORTED_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * Parse uploaded file and return rows with headers as keys.
     *
     * @return array{headers: array, rows: array, total_rows: int}
     * @throws InvalidArgumentException
     */
    public function parse(UploadedFile $file): array
    {
        $this->validateFile($file);

        $type = $this->detectType($file);

        return match ($type) {
            self::TYPE_CSV => $this->parseCsv($file->getPathname()),
            self::TYPE_XLSX, self::TYPE_XLS => $this->parseExcel($file->getPathname()),
            default => throw new InvalidArgumentException("Unsupported file type: {$type}"),
        };
    }

    /**
     * Parse CSV file.
     *
     * @return array{headers: array, rows: array, total_rows: int}
     */
    public function parseCsv(string $path): array
    {
        $csv = Reader::createFromPath($path, 'r');
        
        // Detect delimiter
        $csv->setDelimiter($this->detectDelimiter($path));
        
        // Get headers from first row
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        $headers = $this->normalizeHeaders($headers);

        // Get all records
        $rows = [];
        foreach ($csv->getRecords($headers) as $record) {
            $rows[] = $this->normalizeRow($record);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($rows),
        ];
    }

    /**
     * Parse Excel file (XLSX/XLS).
     *
     * @return array{headers: array, rows: array, total_rows: int}
     */
    public function parseExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        if (empty($data)) {
            return ['headers' => [], 'rows' => [], 'total_rows' => 0];
        }

        // First row is headers
        $headers = $this->normalizeHeaders($data[0]);
        
        // Rest are data rows
        $rows = [];
        for ($i = 1; $i < count($data); $i++) {
            $row = array_combine($headers, array_pad($data[$i], count($headers), null));
            if ($row && $this->isRowNotEmpty($row)) {
                $rows[] = $this->normalizeRow($row);
            }
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($rows),
        ];
    }

    /**
     * Detect file type from extension or MIME.
     */
    public function detectType(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        
        // TXT files are parsed as CSV
        if ($extension === 'txt') {
            return self::TYPE_CSV;
        }
        
        if (in_array($extension, self::SUPPORTED_EXTENSIONS)) {
            return $extension === 'xls' ? self::TYPE_XLS : $extension;
        }

        $mime = $file->getMimeType();
        
        return self::SUPPORTED_TYPES[$mime] ?? throw new InvalidArgumentException(
            "Unsupported file type. Allowed: CSV, TXT, XLSX, XLS"
        );
    }

    /**
     * Validate uploaded file.
     */
    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException("File upload failed: " . $file->getErrorMessage());
        }

        $extension = strtolower($file->getClientOriginalExtension());
        
        if (!in_array($extension, self::SUPPORTED_EXTENSIONS)) {
            $mime = $file->getMimeType();
            if (!isset(self::SUPPORTED_TYPES[$mime])) {
                throw new InvalidArgumentException(
                    "Unsupported file type. Allowed: CSV, TXT, XLSX, XLS"
                );
            }
        }
    }

    /**
     * Detect CSV delimiter (comma or semicolon).
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        fclose($handle);

        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');

        return $semicolons > $commas ? ';' : ',';
    }

    /**
     * Normalize headers to snake_case.
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = trim($header ?? '');
            $header = strtolower($header);
            $header = preg_replace('/[^a-z0-9]+/', '_', $header);
            return trim($header, '_');
        }, $headers);
    }

    /**
     * Normalize row values.
     */
    private function normalizeRow(array $row): array
    {
        return array_map(function ($value) {
            if ($value === null) {
                return null;
            }
            $value = trim((string) $value);
            return $value === '' ? null : $value;
        }, $row);
    }

    /**
     * Check if row has any non-empty values.
     */
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
