<?php

/**
 * Service for standalone BAV batch verification.
 * Handles CSV parsing with auto-detect column format, batch creation, and processing.
 * Uses bav_verified_ibans table for deduplication across all BAV sources.
 */

namespace App\Services;

use App\Models\BavBatch;
use App\Models\BavVerifiedIban;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BavBatchService
{
    private const MAX_BATCH_SIZE = 200000;

    private const IBAN_PATTERNS = ['iban', 'IBAN', 'Iban', 'iban_number'];
    private const FIRST_NAME_PATTERNS = ['first_name', 'firstname', 'FirstName', 'first', 'prenom', 'Prenom'];
    private const LAST_NAME_PATTERNS = ['last_name', 'lastname', 'LastName', 'last', 'nom', 'Nom', 'name', 'Name'];
    private const BIC_PATTERNS = ['bic', 'BIC', 'Bic', 'swift', 'SWIFT'];

    private IbanBavService $bavService;
    private IbanValidator $ibanValidator;

    public function __construct(IbanBavService $bavService, IbanValidator $ibanValidator)
    {
        $this->bavService = $bavService;
        $this->ibanValidator = $ibanValidator;
    }

    /**
     * Upload and validate a CSV file, create a BavBatch record.
     *
     * @return array{success: bool, batch: ?BavBatch, error: ?string, preview: ?array}
     */
    public function uploadAndValidate(UploadedFile $file, int $userId): array
    {
        $rows = $this->parseCSV($file->getPathname());

        if (empty($rows)) {
            return ['success' => false, 'batch' => null, 'error' => 'CSV file is empty or unreadable', 'preview' => null];
        }

        if (count($rows) > self::MAX_BATCH_SIZE) {
            return ['success' => false, 'batch' => null, 'error' => 'CSV exceeds maximum of ' . number_format(self::MAX_BATCH_SIZE) . ' records', 'preview' => null];
        }

        $mapping = $this->detectColumns($rows);

        if ($mapping['iban'] === null) {
            return ['success' => false, 'batch' => null, 'error' => 'Could not detect IBAN column. Ensure CSV contains valid IBANs.', 'preview' => null];
        }

        if ($mapping['first_name'] === null && $mapping['last_name'] === null) {
            return ['success' => false, 'batch' => null, 'error' => 'Could not detect name columns. Need at least first_name or last_name.', 'preview' => null];
        }

        $path = $file->store('bav-batches', 's3');

        $totalDataRows = count($rows) - ($mapping['has_header'] ? 1 : 0);

        // Pre-calculate already verified count for metadata
        $alreadyVerified = $this->countAlreadyVerified($rows, $mapping);

        $batch = BavBatch::create([
            'user_id' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => BavBatch::STATUS_PENDING,
            'total_records' => $totalDataRows,
            'column_mapping' => $mapping,
            'meta' => [
                'already_verified' => $alreadyVerified,
                'eligible' => $totalDataRows - $alreadyVerified,
            ],
        ]);

        $preview = $this->buildPreview($rows, $mapping, 5);

        return ['success' => true, 'batch' => $batch, 'error' => null, 'preview' => $preview];
    }

    /**
     * Count how many IBANs in the file are already verified in bav_verified_ibans.
     */
    private function countAlreadyVerified(array $rows, array $mapping): int
    {
        $startRow = $mapping['has_header'] ? 1 : 0;
        $ibanHashes = [];

        for ($i = $startRow; $i < count($rows); $i++) {
            $iban = $this->cleanIban($rows[$i][$mapping['iban']] ?? '');
            if (!empty($iban)) {
                $normalized = $this->ibanValidator->normalize($iban);
                $ibanHashes[] = $this->ibanValidator->hash($normalized);
            }
        }

        if (empty($ibanHashes)) {
            return 0;
        }

        $uniqueHashes = array_unique($ibanHashes);
        $verified = BavVerifiedIban::findVerified($uniqueHashes);

        return count($verified);
    }

    /**
     * Process records in a BavBatch with random selection and deduplication.
     */
    public function processBatch(BavBatch $batch, int $delayMs = 500): void
    {
        $content = Storage::disk('s3')->get($batch->file_path);
        $tempPath = tempnam(sys_get_temp_dir(), 'bav_');
        file_put_contents($tempPath, $content);

        $rows = $this->parseCSV($tempPath);
        unlink($tempPath);

        $mapping = $batch->column_mapping;
        $startRow = $mapping['has_header'] ? 1 : 0;
        $recordLimit = $batch->record_limit ?? $batch->total_records;

        // Collect all data rows with their IBAN hashes
        $dataRows = [];
        for ($i = $startRow; $i < count($rows); $i++) {
            $row = $rows[$i];
            $iban = $this->cleanIban($row[$mapping['iban']] ?? '');
            $hash = null;

            if (!empty($iban)) {
                $normalized = $this->ibanValidator->normalize($iban);
                $hash = $this->ibanValidator->hash($normalized);
            }

            $dataRows[] = ['row' => $row, 'iban' => $iban, 'hash' => $hash];
        }

        // Dedupe: find already verified IBANs
        $allHashes = array_filter(array_column($dataRows, 'hash'));
        $alreadyVerified = BavVerifiedIban::findVerified(array_unique($allHashes));

        // Split into eligible (not yet verified) and skipped (already verified)
        $eligible = [];
        $skippedRows = [];

        foreach ($dataRows as $entry) {
            if ($entry['hash'] && isset($alreadyVerified[$entry['hash']])) {
                $cached = $alreadyVerified[$entry['hash']];
                $skippedRows[] = array_merge($entry['row'], [
                    $cached->name_match === 'yes' || $cached->name_match === 'partial' ? 'true' : 'false',
                    $cached->name_match ?? '',
                    $cached->bic ?? '',
                    (string) ($cached->bav_score ?? 0),
                    $cached->bav_result ?? '',
                    'already_verified',
                ]);
            } else {
                $eligible[] = $entry;
            }
        }

        // Shuffle eligible for random selection
        shuffle($eligible);

        // Prepare output
        $outputRows = [];
        $headerRow = $mapping['has_header'] ? $rows[0] : [];
        $outputHeader = array_merge($headerRow, ['bav_valid', 'bav_name_match', 'bav_bic', 'bav_score', 'bav_result', 'bav_error']);
        $outputRows[] = $outputHeader;

        // Add skipped rows first (with cached results)
        foreach ($skippedRows as $skippedRow) {
            $outputRows[] = $skippedRow;
        }

        // Process eligible rows up to record_limit
        $processed = 0;

        Log::channel('bav')->info('BavBatchService: Processing batch', [
            'batch_id' => $batch->id,
            'total_rows' => count($dataRows),
            'already_verified' => count($skippedRows),
            'eligible' => count($eligible),
            'record_limit' => $recordLimit,
        ]);

        foreach ($eligible as $entry) {
            if ($processed >= $recordLimit) {
                break;
            }

            $row = $entry['row'];
            $iban = $entry['iban'];
            $hash = $entry['hash'];

            $firstName = $mapping['first_name'] !== null ? trim($row[$mapping['first_name']] ?? '') : '';
            $lastName = $mapping['last_name'] !== null ? trim($row[$mapping['last_name']] ?? '') : '';
            $fullName = trim("{$firstName} {$lastName}");

            if (empty($iban)) {
                $outputRows[] = array_merge($row, ['false', '', '', '0', '', 'Empty IBAN']);
                $batch->incrementProcessed(false);
                $processed++;
                continue;
            }

            try {
                $result = $this->bavService->verify($iban, $fullName);

                $outputRows[] = array_merge($row, [
                    $result['valid'] ? 'true' : 'false',
                    $result['name_match'] ?? '',
                    $result['bic'] ?? '',
                    (string) ($result['vop_score'] ?? 0),
                    $result['vop_result'] ?? '',
                    $result['error'] ?? '',
                ]);

                $batch->incrementProcessed($result['success']);

                // Record in global BAV cache
                if ($result['success'] && $hash) {
                    BavVerifiedIban::recordVerification(
                        ibanHash: $hash,
                        ibanMasked: $this->ibanValidator->mask($iban),
                        fullName: $fullName,
                        nameMatch: $result['name_match'],
                        bic: $result['bic'],
                        bavScore: $result['vop_score'],
                        bavResult: $result['vop_result'],
                        source: BavVerifiedIban::SOURCE_STANDALONE_BATCH,
                        sourceId: $batch->id
                    );
                }
            } catch (\Exception $e) {
                $outputRows[] = array_merge($row, ['false', '', '', '0', '', $e->getMessage()]);
                $batch->incrementProcessed(false);

                Log::channel('bav')->error('BavBatchService: Error processing row', [
                    'batch_id' => $batch->id,
                    'iban' => substr($iban, 0, 4) . '****',
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        // Update batch meta with final stats
        $batch->update([
            'meta' => array_merge($batch->meta ?? [], [
                'already_verified' => count($skippedRows),
                'eligible' => count($eligible),
                'processed_new' => $processed,
            ]),
        ]);

        $resultsPath = 'bav-batches/results_' . $batch->id . '_' . now()->format('Ymd_His') . '.csv';
        $csvContent = $this->buildCSVContent($outputRows, $mapping['delimiter']);
        Storage::disk('s3')->put($resultsPath, $csvContent);

        $batch->update(['results_path' => $resultsPath]);
        $batch->markCompleted();
    }

    /**
     * Auto-detect column positions from CSV data.
     *
     * @return array{has_header: bool, delimiter: string, iban: ?int, first_name: ?int, last_name: ?int, bic: ?int}
     */
    public function detectColumns(array $rows): array
    {
        if (empty($rows)) {
            return $this->emptyMapping();
        }

        $delimiter = $this->detectDelimiter($rows);
        $firstRow = $rows[0];

        $mapping = [
            'has_header' => false,
            'delimiter' => $delimiter,
            'iban' => null,
            'first_name' => null,
            'last_name' => null,
            'bic' => null,
        ];

        if ($this->looksLikeHeader($firstRow)) {
            $mapping['has_header'] = true;
            $mapping['iban'] = $this->findColumnByName($firstRow, self::IBAN_PATTERNS);
            $mapping['first_name'] = $this->findColumnByName($firstRow, self::FIRST_NAME_PATTERNS);
            $mapping['last_name'] = $this->findColumnByName($firstRow, self::LAST_NAME_PATTERNS);
            $mapping['bic'] = $this->findColumnByName($firstRow, self::BIC_PATTERNS);

            if ($mapping['iban'] !== null) {
                return $mapping;
            }
        }

        return $this->detectByContent($rows, $mapping);
    }

    /**
     * Detect columns by analyzing actual cell content.
     */
    private function detectByContent(array $rows, array $mapping): array
    {
        $startRow = $mapping['has_header'] ? 1 : 0;
        $sampleSize = min(10, count($rows) - $startRow);
        $colCount = count($rows[$startRow] ?? []);

        $colScores = [];
        for ($col = 0; $col < $colCount; $col++) {
            $colScores[$col] = ['iban' => 0, 'bic' => 0, 'name' => 0, 'total' => 0];
        }

        for ($i = $startRow; $i < $startRow + $sampleSize && $i < count($rows); $i++) {
            $row = $rows[$i];
            for ($col = 0; $col < $colCount; $col++) {
                $val = trim($row[$col] ?? '');
                if (empty($val)) {
                    continue;
                }

                $colScores[$col]['total']++;

                if ($this->looksLikeIban($val)) {
                    $colScores[$col]['iban']++;
                } elseif ($this->looksLikeBic($val)) {
                    $colScores[$col]['bic']++;
                }

                if ($this->looksLikeName($val)) {
                    $colScores[$col]['name']++;
                }
            }
        }

        $bestIban = null;
        $bestIbanScore = 0;
        $bestBic = null;
        $bestBicScore = 0;

        foreach ($colScores as $col => $scores) {
            if ($scores['total'] === 0) {
                continue;
            }

            $ibanRatio = $scores['iban'] / $scores['total'];
            if ($ibanRatio > 0.5 && $scores['iban'] > $bestIbanScore) {
                $bestIban = $col;
                $bestIbanScore = $scores['iban'];
            }

            $bicRatio = $scores['bic'] / $scores['total'];
            if ($bicRatio > 0.5 && $scores['bic'] > $bestBicScore) {
                $bestBic = $col;
                $bestBicScore = $scores['bic'];
            }
        }

        $mapping['iban'] = $bestIban;
        $mapping['bic'] = $bestBic;

        $usedColumns = array_filter([$bestIban, $bestBic]);
        $nameColumns = [];

        foreach ($colScores as $col => $scores) {
            if (in_array($col, $usedColumns, true)) {
                continue;
            }
            if ($scores['total'] === 0) {
                continue;
            }

            $nameRatio = $scores['name'] / $scores['total'];
            if ($nameRatio > 0.5) {
                $nameColumns[] = $col;
            }
        }

        if (count($nameColumns) >= 2) {
            $mapping['first_name'] = $nameColumns[0];
            $mapping['last_name'] = $nameColumns[1];
        } elseif (count($nameColumns) === 1) {
            $mapping['last_name'] = $nameColumns[0];
        }

        return $mapping;
    }

    private function looksLikeIban(string $val): bool
    {
        $clean = preg_replace('/\s+/', '', $val);
        return (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/i', $clean);
    }

    private function looksLikeBic(string $val): bool
    {
        $val = trim($val);
        $len = strlen($val);
        if ($len !== 8 && $len !== 11) {
            return false;
        }

        if (!preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/i', $val)) {
            return false;
        }

        if (preg_match('/\d/', $val)) {
            return true;
        }

        if (str_ends_with(strtoupper($val), 'XXX')) {
            return true;
        }

        return false;
    }

    private function looksLikeName(string $val): bool
    {
        $val = trim($val);
        if (strlen($val) < 2 || strlen($val) > 50) {
            return false;
        }
        if (preg_match('/\d/', $val)) {
            return false;
        }

        if ($this->looksLikeIban($val)) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\s\'-]+$/u', $val);
    }

    private function looksLikeHeader(array $row): bool
    {
        $headerKeywords = array_merge(
            self::IBAN_PATTERNS, self::FIRST_NAME_PATTERNS,
            self::LAST_NAME_PATTERNS, self::BIC_PATTERNS
        );

        foreach ($row as $cell) {
            $clean = strtolower(trim($cell));
            foreach ($headerKeywords as $keyword) {
                if ($clean === strtolower($keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findColumnByName(array $header, array $patterns): ?int
    {
        foreach ($patterns as $pattern) {
            foreach ($header as $idx => $cell) {
                if (strtolower(trim($cell)) === strtolower($pattern)) {
                    return $idx;
                }
            }
        }
        return null;
    }

    private function detectDelimiter(array $rows): string
    {
        return ',';
    }

    private function cleanIban(string $iban): string
    {
        return preg_replace('/\s+/', '', trim($iban));
    }

    private function parseCSV(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine) {
            $semicolons = substr_count($firstLine, ';');
            $commas = substr_count($firstLine, ',');
            if ($semicolons > $commas) {
                $delimiter = ';';
            }
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === 1 && empty($row[0])) {
                continue;
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function buildPreview(array $rows, array $mapping, int $limit = 5): array
    {
        $startRow = $mapping['has_header'] ? 1 : 0;
        $preview = [];

        for ($i = $startRow; $i < min($startRow + $limit, count($rows)); $i++) {
            $row = $rows[$i];
            $preview[] = [
                'iban' => $this->cleanIban($row[$mapping['iban']] ?? ''),
                'first_name' => $mapping['first_name'] !== null ? trim($row[$mapping['first_name']] ?? '') : '',
                'last_name' => $mapping['last_name'] !== null ? trim($row[$mapping['last_name']] ?? '') : '',
                'bic' => $mapping['bic'] !== null ? trim($row[$mapping['bic']] ?? '') : '',
            ];
        }

        return $preview;
    }

    private function buildCSVContent(array $rows, string $delimiter = ','): string
    {
        $output = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($output, $row, $delimiter);
        }
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        return $content;
    }

    private function emptyMapping(): array
    {
        return [
            'has_header' => false,
            'delimiter' => ',',
            'iban' => null,
            'first_name' => null,
            'last_name' => null,
            'bic' => null,
        ];
    }
}
