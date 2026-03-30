<?php

/**
 * Service for "File Clearance" — standalone file-cleaning pipeline.
 *
 * Phase 1 (controller, synchronous):
 *   validateAndStore() — uses FilePreValidationService for header validation
 *   (reads only headers + sample, no full parse), then stores the raw file
 *   on S3 via the same pattern as FileUploadService.
 *
 * Phase 2 (background job):
 *   downloadFromS3()  — pulls the file to a temp path (same as ProcessUploadJob).
 *   streamRows()      — generator that yields one row at a time, constant memory.
 *   processRow()      — normalise IBAN → resolve BIC via VOP → blacklist checks → name validation.
 *   openCsvWriter() / writeCsvRow() / closeCsvWriter() — incremental CSV output.
 *
 * Exclusion files (3 separate CSVs):
 *   - excluded_ibans:  rows filtered by IBAN blacklist
 *   - excluded_bics:   rows filtered by BIC blacklist
 *   - invalid_names:   rows with invalid characters in first/last name
 *
 * Constraints:
 *   - VOP (IbanApiService) is limitless — no credit limits.
 *   - Do NOT use BAV anywhere.
 *   - Only hard requirement: an IBAN column must be present.
 */

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;

class FileClearanceService
{
    // Exclusion category identifiers
    public const CATEGORY_IBAN_BLACKLISTED = 'iban_blacklisted';
    public const CATEGORY_BIC_BLACKLISTED  = 'bic_blacklisted';
    public const CATEGORY_INVALID_NAME     = 'invalid_name';

    // CSV file suffixes
    public const SUFFIX_CLEARED       = 'cleared';
    public const SUFFIX_EXCLUDED_IBANS = 'excluded_ibans';
    public const SUFFIX_EXCLUDED_BICS  = 'excluded_bics';
    public const SUFFIX_INVALID_NAMES  = 'invalid_names';

    // Column appended to exclusion CSVs
    public const EXCLUSION_REASON_HEADER = 'exclusion_reason';

    private const COLUMN_MAP = [
        'iban'              => 'iban',
        'iban_number'       => 'iban',
        'bank_account'      => 'iban',
        'account_number'    => 'iban',
        'bic'               => 'bic',
        'swift'             => 'bic',
        'swift_code'        => 'bic',
        'name'              => 'name',
        'full_name'         => 'name',
        'fullname'          => 'name',
        'customer_name'     => 'name',
        'debtor_name'       => 'name',
        'client_name'       => 'name',
        'account_holder'    => 'name',
        'first_name'        => 'first_name',
        'firstname'         => 'first_name',
        'last_name'         => 'last_name',
        'lastname'          => 'last_name',
        'surname'           => 'last_name',
        'email'             => 'email',
        'e_mail'            => 'email',
        'mail'              => 'email',
        'phone'             => 'phone',
        'telephone'         => 'phone',
        'mobile'            => 'mobile',
        'amount'            => 'amount',
        'sum'               => 'amount',
        'total'             => 'amount',
        'price'             => 'amount',
        'currency'          => 'currency',
        'address'           => 'address',
        'street'            => 'street',
        'street_number'     => 'street_number',
        'house_number'      => 'street_number',
        'postcode'          => 'postcode',
        'postal_code'       => 'postcode',
        'zip'               => 'postcode',
        'zip_code'          => 'postcode',
        'city'              => 'city',
        'province'          => 'province',
        'state'             => 'province',
        'country'           => 'country',
        'national_id'       => 'national_id',
        'id_number'         => 'national_id',
        'personal_id'       => 'national_id',
        'dni'               => 'national_id',
        'nie'               => 'national_id',
        'birth_date'        => 'birth_date',
        'birthdate'         => 'birth_date',
        'date_of_birth'     => 'birth_date',
        'dob'               => 'birth_date',
        'bank_name'         => 'bank_name',
        'bank'              => 'bank_name',
        'bank_code'         => 'bank_code',
        'sepa_type'         => 'sepa_type',
        'old_iban'          => 'old_iban',
        'external_reference' => 'external_reference',
        'reference'         => 'external_reference',
        'ref'               => 'external_reference',
        'order_id'          => 'external_reference',
        'customer_id'       => 'external_reference',
    ];

    public function __construct(
        private FilePreValidationService $preValidationService,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // PHASE 1 — Controller (synchronous, lightweight)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Validate headers via FilePreValidationService (reads only header row + sample)
     * and store the raw file on S3 (same pattern as FileUploadService).
     *
     * For clearance the only required column is IBAN — we override the
     * pre-validation result to only check for that.
     *
     * @return array{s3_path: string, headers: string[], header_meta: array, total_rows: int}
     * @throws \InvalidArgumentException
     */
    public function validateAndStore(UploadedFile $file): array
    {
        // 1. Use FilePreValidationService to read headers + sample count
        //    (lightweight — no full parse)
        $preResult = $this->preValidationService->validate($file);

        $headers = $preResult['headers'] ?? [];

        if (empty($headers)) {
            throw new \InvalidArgumentException('File is empty or has no headers.');
        }

        // 2. Build column mapping and check for IBAN
        //    (clearance only requires IBAN — ignore amount/name requirements)
        $columnMapping = $this->buildColumnMapping($headers);
        $ibanHeader    = $this->findHeaderFor('iban', $columnMapping);

        if ($ibanHeader === null) {
            throw new \InvalidArgumentException(
                'Missing required column: IBAN. Accepted variants: iban, iban_number, bank_account, account_number.'
            );
        }

        if (($preResult['sample_count'] ?? 0) === 0) {
            throw new \InvalidArgumentException('File has headers but no data rows.');
        }

        // 3. Store raw file on S3 (same pattern as FileUploadService)
        $s3Path = $this->storeFileOnS3($file);

        $headerMeta = [
            'iban_header'       => $ibanHeader,
            'bic_header'        => $this->findHeaderFor('bic', $columnMapping),
            'first_name_header' => $this->findHeaderFor('first_name', $columnMapping),
            'last_name_header'  => $this->findHeaderFor('last_name', $columnMapping),
            'name_header'       => $this->findHeaderFor('name', $columnMapping),
            'email_header'      => $this->findHeaderFor('email', $columnMapping),
        ];

        // Estimate total rows from sample_count or count via iterator
        $totalRows = $this->countRows($file);

        Log::info('FileClearance: validated and stored', [
            'original_name' => $file->getClientOriginalName(),
            'headers'        => $headers,
            'total_rows'     => $totalRows,
            's3_path'        => $s3Path,
        ]);

        return [
            's3_path'     => $s3Path,
            'headers'     => $headers,
            'header_meta' => $headerMeta,
            'total_rows'  => $totalRows,
        ];
    }

    /**
     * Store file on S3 — same pattern as FileUploadService::storeFile().
     */
    private function storeFileOnS3(UploadedFile $file): string
    {
        $originalFilename = $file->getClientOriginalName();
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs(
            'clearance',
            $filename,
            [
                'disk' => 's3',
                'Metadata' => [
                    'original-filename' => $originalFilename,
                ],
            ],
        );

        if ($path === false) {
            Log::error('FileClearance: failed to store file on S3', [
                'original_filename' => $originalFilename,
                'size'              => $file->getSize(),
            ]);
            throw new \RuntimeException('Failed to store file in S3 storage.');
        }

        Log::info('FileClearance: file stored on S3', [
            'path'              => $path,
            'original_filename' => $originalFilename,
            'size'              => $file->getSize(),
        ]);

        return $path;
    }

    /**
     * Count data rows without loading them into memory.
     */
    private function countRows(UploadedFile $file): int
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path      = $file->getPathname();

        if (in_array($extension, ['csv', 'txt'])) {
            $count = 0;
            $fh = fopen($path, 'r');
            while (fgets($fh) !== false) $count++;
            fclose($fh);
            return max(0, $count - 1);
        }

        if (in_array($extension, ['xlsx', 'xls'])) {
            $options = new \OpenSpout\Reader\XLSX\Options();
            $reader  = new \OpenSpout\Reader\XLSX\Reader($options);
            $reader->open($path);

            $count = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $count++;
                }
                break; // first sheet only
            }

            $reader->close();
            return max(0, $count - 1); // subtract header row
        }

        return 0;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PHASE 2 — Background Job
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Download file from S3 to a temp path — same pattern as ProcessUploadJob.
     */
    public function downloadFromS3(string $s3Path): string
    {
        if (!Storage::disk('s3')->exists($s3Path)) {
            throw new \RuntimeException("File not found in S3: {$s3Path}");
        }

        $content = Storage::disk('s3')->get($s3Path);
        if ($content === null) {
            throw new \RuntimeException("Failed to download file from S3: {$s3Path}");
        }

        $extension    = pathinfo($s3Path, PATHINFO_EXTENSION);
        $tempFilePath = sys_get_temp_dir() . '/clearance_' . uniqid() . '.' . $extension;

        if (file_put_contents($tempFilePath, $content) === false) {
            throw new \RuntimeException("Failed to write temp file: {$tempFilePath}");
        }

        return $tempFilePath;
    }

    /**
     * Delete the source file from S3 after processing.
     */
    public function deleteFromS3(string $s3Path): void
    {
        try {
            Storage::disk('s3')->delete($s3Path);
        } catch (\Throwable $e) {
            Log::warning('FileClearance: failed to delete S3 file', [
                's3_path' => $s3Path,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stream rows from a local file one-by-one via generator.
     * Never loads the full dataset into memory.
     *
     * @return \Generator<array{int, array}>  yields [int $rowIndex, array $row]
     */
    public function streamRows(string $filePath, array $headers): \Generator
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'])) {
            yield from $this->streamCsvRows($filePath, $headers);
        } elseif (in_array($extension, ['xlsx', 'xls'])) {
            yield from $this->streamExcelRows($filePath, $headers);
        } else {
            throw new \RuntimeException("Unsupported file type: {$extension}");
        }
    }

    /**
     * Process a single row: normalize IBAN, resolve BIC via VOP, check blacklists, validate names.
     *
     * Returns categorized exclusion reasons so the job can route rows
     * to the correct exclusion file(s).
     *
     * Exclusion categories (a row can match multiple):
     *   - 'iban_blacklisted'  → IBAN is on the blacklist
     *   - 'bic_blacklisted'   → BIC is on the blacklist
     *   - 'invalid_name'      → first or last name contains invalid characters / exceeds max length
     *
     * Other exclusion reasons (missing IBAN, invalid IBAN, non-SEPA, email blacklist)
     * are kept in the general excluded_details but do NOT produce a separate file.
     *
     * @return array{
     *     row: ?array,
     *     excluded: ?array,
     *     exclusion_categories: string[],
     *     vop_resolved: bool,
     *     vop_failed: bool,
     * }
     */
    public function processRow(
        array $row,
        int   $displayRowIndex,
        array $headerMeta,
        IbanApiService   $ibanApiService,
        IbanValidator    $ibanValidator,
        BlacklistService $blacklistService,
        ?string $preResolvedBic = null,
    ): array {
        $ibanHeader      = $headerMeta['iban_header'];
        $bicHeader       = $headerMeta['bic_header'];
        $firstNameHeader = $headerMeta['first_name_header'];
        $lastNameHeader  = $headerMeta['last_name_header'];
        $nameHeader      = $headerMeta['name_header'];
        $emailHeader     = $headerMeta['email_header'];
        $injectBic       = ($bicHeader === null);

        $iban = trim($row[$ibanHeader] ?? '');

        if (empty($iban)) {
            return [
                'row'                  => null,
                'excluded'             => ['row_index' => $displayRowIndex, 'iban' => null, 'bic' => null, 'reasons' => ['Missing IBAN']],
                'exclusion_categories' => [],
                'vop_resolved'         => false,
                'vop_failed'           => false,
            ];
        }

        $iban        = $ibanValidator->normalize($iban);
        $vopResolved = false;
        $vopFailed   = false;

        // Validate IBAN structure
        $ibanValidation = $ibanValidator->validate($iban);
        if (!$ibanValidation['valid']) {
            return [
                'row'                  => null,
                'excluded'             => [
                    'row_index' => $displayRowIndex,
                    'iban'      => $this->maskIban($iban),
                    'bic'       => null,
                    'reasons'   => ['Invalid IBAN: ' . implode(', ', $ibanValidation['errors'])],
                ],
                'exclusion_categories' => [],
                'vop_resolved'         => false,
                'vop_failed'           => false,
            ];
        }

        // Check SEPA zone
        if (!$ibanValidation['is_sepa']) {
            return [
                'row'                  => null,
                'excluded'             => [
                    'row_index' => $displayRowIndex,
                    'iban'      => $this->maskIban($iban),
                    'bic'       => null,
                    'reasons'   => ['Country ' . $ibanValidation['country_code'] . ' is not in SEPA zone'],
                ],
                'exclusion_categories' => [],
                'vop_resolved'         => false,
                'vop_failed'           => false,
            ];
        }

        // Use pre-resolved BIC from batch call, or fall back to single call
        if ($preResolvedBic !== null) {
            $bic         = $preResolvedBic;
            $vopResolved = true;
        } else {
            $resolvedBic = $ibanApiService->getBic($iban);
            if (!empty($resolvedBic)) {
                $bic         = $resolvedBic;
                $vopResolved = true;
            } else {
                $vopFailed = true;
                $bic = $bicHeader !== null ? trim($row[$bicHeader] ?? '') : '';
            }
        }

        // Collect all reasons + categorise for separate files
        $reasons             = [];
        $exclusionCategories = [];

        // IBAN blacklist
        if ($blacklistService->isBlacklisted($iban)) {
            $reasons[]             = 'IBAN is blacklisted';
            $exclusionCategories[] = self::CATEGORY_IBAN_BLACKLISTED;
        }

        // BIC blacklist
        if (!empty($bic) && $blacklistService->isBicBlacklisted($bic)) {
            $reasons[]             = 'BIC is blacklisted';
            $exclusionCategories[] = self::CATEGORY_BIC_BLACKLISTED;
        }

        // Name validation (invalid characters + length — mirrors DebtorValidationService)
        $firstName = $firstNameHeader ? trim($row[$firstNameHeader] ?? '') : '';
        $lastName  = $lastNameHeader  ? trim($row[$lastNameHeader] ?? '')  : '';
        if (empty($firstName) && empty($lastName) && $nameHeader) {
            $parts     = explode(' ', trim($row[$nameHeader] ?? ''), 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '';
        }

        $nameErrors = DebtorValidationService::validateNameStrings($firstName, $lastName);
        if (!empty($nameErrors)) {
            $reasons               = array_merge($reasons, $nameErrors);
            $exclusionCategories[] = self::CATEGORY_INVALID_NAME;
        }

        // Name blacklist (separate from invalid-name validation)
        if (!empty($firstName) && !empty($lastName) && $blacklistService->isNameBlacklisted($firstName, $lastName)) {
            $reasons[] = 'Name is blacklisted';
        }

        // Email blacklist
        $email = $emailHeader ? trim($row[$emailHeader] ?? '') : '';
        if (!empty($email) && $blacklistService->isEmailBlacklisted($email)) {
            $reasons[] = 'Email is blacklisted';
        }

        if (!empty($reasons)) {
            return [
                'row'                  => null,
                'excluded'             => [
                    'row_index' => $displayRowIndex,
                    'iban'      => $this->maskIban($iban),
                    'bic'       => $bic ?: null,
                    'reasons'   => $reasons,
                ],
                'exclusion_categories' => array_unique($exclusionCategories),
                'vop_resolved'         => $vopResolved,
                'vop_failed'           => $vopFailed,
            ];
        }

        // Clean — always write VOP-resolved BIC
        if ($injectBic) {
            $row['_resolved_bic'] = $bic;
        } elseif ($bicHeader !== null && !empty($bic)) {
            $row[$bicHeader] = $bic;
        }

        $row[$ibanHeader] = $iban;

        return [
            'row'                  => $row,
            'excluded'             => null,
            'exclusion_categories' => [],
            'vop_resolved'         => $vopResolved,
            'vop_failed'           => $vopFailed,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // INCREMENTAL CSV WRITER — writes to temp file, uploads to S3 on close
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Open a CSV for incremental writing (temp file).
     *
     * @param  string  $suffix  e.g. 'cleared', 'excluded_ibans', 'excluded_bics', 'invalid_names'
     * @return array{resource, string, string}  [$handle, $tempPath, $fileName]
     */
    public function openCsvWriter(array $headers, string $originalName, string $suffix = 'cleared'): array
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $fileName = $baseName . '_' . $suffix . '_' . now()->format('Ymd_His') . '.csv';
        $tempPath = sys_get_temp_dir() . '/clearance_out_' . $suffix . '_' . uniqid() . '.csv';

        $handle = fopen($tempPath, 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM for Excel
        fputcsv($handle, $headers);

        return [$handle, $tempPath, $fileName];
    }

    public function writeCsvRow($handle, array $headers, array $row, bool $bicInjected): void
    {
        $line = [];
        foreach ($headers as $h) {
            if ($bicInjected && $h === 'bic') {
                $line[] = $row['_resolved_bic'] ?? '';
            } else {
                $line[] = $row[$h] ?? '';
            }
        }
        fputcsv($handle, $line);
    }

    /**
     * Write an exclusion row: original row data + a "reason" column appended.
     */
    public function writeExclusionCsvRow($handle, array $headers, array $row, string $reason): void
    {
        $line = [];
        foreach ($headers as $h) {
            if ($h === self::EXCLUSION_REASON_HEADER) {
                $line[] = $reason;
            } else {
                $line[] = $row[$h] ?? '';
            }
        }
        fputcsv($handle, $line);
    }

    /**
     * Close the CSV handle and upload the result to S3.
     * If the file has no data rows (only header), skip upload and return null.
     *
     * @return string|null  S3 path of the uploaded file, or null if empty.
     */
    public function closeCsvWriter($handle, string $tempPath, string $fileName, bool $skipIfEmpty = false): ?string
    {
        fclose($handle);

        // If requested, check whether the file has any data rows beyond the header
        if ($skipIfEmpty) {
            $lineCount = 0;
            $fh = fopen($tempPath, 'r');
            while (fgets($fh) !== false) {
                $lineCount++;
                if ($lineCount > 1) break; // header + at least 1 data row
            }
            fclose($fh);

            if ($lineCount <= 1) {
                @unlink($tempPath);
                return null;
            }
        }

        $s3Path = 'clearance/results/' . Str::uuid() . '/' . $fileName;

        $uploaded = Storage::disk('s3')->put($s3Path, file_get_contents($tempPath), [
            'ContentType' => 'text/csv; charset=UTF-8',
            'Metadata'    => [
                'original-filename' => $fileName,
            ],
        ]);

        // Clean up temp file
        @unlink($tempPath);

        if (!$uploaded) {
            throw new \RuntimeException("Failed to upload cleared CSV to S3: {$s3Path}");
        }

        Log::info('FileClearance: CSV uploaded to S3', [
            's3_path'   => $s3Path,
            'file_name' => $fileName,
        ]);

        return $s3Path;
    }

    /**
     * Stream the cleared CSV from S3 for download.
     *
     * @param string $s3Path
     * @param string $fileName
     * @return StreamedResponse
     */
    public function streamDownloadFromS3(string $s3Path, string $fileName): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!Storage::disk('s3')->exists($s3Path)) {
            throw new \RuntimeException("Cleared file not found in S3: {$s3Path}");
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        return $disk->download($s3Path, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function streamCsvRows(string $filePath, array $headers): \Generator
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setDelimiter($this->detectDelimiter($filePath));
        $csv->setHeaderOffset(0);

        $rowIndex = 0;
        foreach ($csv->getRecords($headers) as $record) {
            yield [$rowIndex, $record];
            $rowIndex++;
        }
    }

    private function streamExcelRows(string $filePath, array $headers): \Generator
    {
        $options = new XlsxOptions();
        $reader  = new XlsxReader($options);
        $reader->open($filePath);

        foreach ($reader->getSheetIterator() as $sheet) {
            $rowIndex = 0;
            foreach ($sheet->getRowIterator() as $excelRow) {
                if ($rowIndex === 0) { $rowIndex++; continue; }
                $cells = $excelRow->toArray();
                $row   = [];
                foreach ($headers as $i => $header) {
                    $row[$header] = $cells[$i] ?? '';
                }
                yield [$rowIndex - 1, $row];
                $rowIndex++;
            }
            break;
        }
        $reader->close();
    }

    private function buildColumnMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $normalized = $this->normalizeColumnName($header);
            if (isset(self::COLUMN_MAP[$normalized])) {
                $mapping[$header] = self::COLUMN_MAP[$normalized];
            }
        }
        return $mapping;
    }

    private function normalizeColumnName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        return trim($name, '_');
    }

    private function findHeaderFor(string $field, array $columnMapping): ?string
    {
        foreach ($columnMapping as $rawHeader => $mapped) {
            if ($mapped === $field) {
                return $rawHeader;
            }
        }
        return null;
    }

    private function detectDelimiter(string $path): string
    {
        $handle    = fopen($path, 'r');
        $firstLine = fgets($handle);
        fclose($handle);
        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    private function maskIban(string $iban): string
    {
        $n = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban));
        return strlen($n) >= 8 ? substr($n, 0, 4) . '****' . substr($n, -4) : '****';
    }
}
