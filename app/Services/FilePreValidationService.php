<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FilePreValidationService
{
    private const REQUIRED_HEADERS = ['iban'];
    private const AMOUNT_HEADERS = ['amount', 'sum', 'total', 'price'];
    private const NAME_HEADERS = ['name', 'full_name', 'fullname', 'first_name', 'last_name'];
    private const SAMPLE_SIZE = 10;

    private const IBAN_PATTERN = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{4,30}$/';
    private const AMOUNT_PATTERN = '/^[\d\s.,]+$/';

    public function validate(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'])) {
            return $this->validateCsv($file->getPathname());
        }
        
        if (in_array($extension, ['xlsx', 'xls'])) {
            return $this->validateExcel($file->getPathname());
        }

        return ['valid' => false, 'errors' => ['Unsupported file type.']];
    }

    private function validateCsv(string $path): array
    {
        $errors = [];

        $csv = Reader::createFromPath($path, 'r');
        $csv->setDelimiter($this->detectDelimiter($path));

        $headers = $csv->fetchOne(0);
        if (empty($headers)) {
            return ['valid' => false, 'errors' => ['File is empty or has no headers.']];
        }

        $headers = $this->normalizeHeaders($headers);
        $errors = array_merge($errors, $this->validateHeaders($headers));

        $csv->setHeaderOffset(0);
        $sample = [];
        $count = 0;
        foreach ($csv->getRecords($headers) as $record) {
            if ($count >= self::SAMPLE_SIZE) break;
            $sample[] = $record;
            $count++;
        }

        if (empty($sample)) {
            $errors[] = 'File has headers but no data rows.';
        } else {
            $errors = array_merge($errors, $this->validateSampleRows($sample, $headers));
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'headers' => $headers,
            'sample_count' => count($sample),
        ];
    }

    private function validateExcel(string $path): array
    {
        $errors = [];

        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        if (empty($data) || empty($data[0])) {
            return ['valid' => false, 'errors' => ['File is empty or has no headers.']];
        }

        $headers = $this->normalizeHeaders($data[0]);
        $errors = array_merge($errors, $this->validateHeaders($headers));

        $sample = [];
        for ($i = 1; $i <= min(self::SAMPLE_SIZE, count($data) - 1); $i++) {
            $row = array_combine($headers, array_pad($data[$i], count($headers), null));
            if ($this->isRowNotEmpty($row)) {
                $sample[] = $row;
            }
        }

        if (empty($sample)) {
            $errors[] = 'File has headers but no data rows.';
        } else {
            $errors = array_merge($errors, $this->validateSampleRows($sample, $headers));
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'headers' => $headers,
            'sample_count' => count($sample),
        ];
    }

    private function validateHeaders(array $headers): array
    {
        $errors = [];

        if (empty(array_intersect($headers, self::REQUIRED_HEADERS))) {
            $errors[] = 'Missing required column: IBAN.';
        }

        if (empty(array_intersect($headers, self::AMOUNT_HEADERS))) {
            $errors[] = 'Missing required column: amount (amount, sum, total, or price).';
        }

        if (empty(array_intersect($headers, self::NAME_HEADERS))) {
            $errors[] = 'Missing required column: name (name, full_name, first_name, or last_name).';
        }

        return $errors;
    }

    private function validateSampleRows(array $rows, array $headers): array
    {
        $errors = [];
        $ibans = [];
        $ibanColumn = $this->findColumn($headers, self::REQUIRED_HEADERS);
        $amountColumn = $this->findColumn($headers, self::AMOUNT_HEADERS);

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            if ($ibanColumn && isset($row[$ibanColumn])) {
                $iban = strtoupper(preg_replace('/\s+/', '', $row[$ibanColumn] ?? ''));
                
                if (empty($iban)) {
                    $errors[] = "Row {$rowNum}: IBAN is empty.";
                } elseif (!preg_match(self::IBAN_PATTERN, $iban)) {
                    $errors[] = "Row {$rowNum}: Invalid IBAN format.";
                } elseif (in_array($iban, $ibans)) {
                    $errors[] = "Row {$rowNum}: Duplicate IBAN in file.";
                }
                $ibans[] = $iban;
            }

            if ($amountColumn && isset($row[$amountColumn])) {
                $amount = $row[$amountColumn] ?? '';
                if (!empty($amount) && !preg_match(self::AMOUNT_PATTERN, $amount)) {
                    $errors[] = "Row {$rowNum}: Invalid amount format.";
                }
            }
        }

        return $errors;
    }

    private function findColumn(array $headers, array $possibleNames): ?string
    {
        foreach ($possibleNames as $name) {
            if (in_array($name, $headers)) {
                return $name;
            }
        }
        return null;
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
