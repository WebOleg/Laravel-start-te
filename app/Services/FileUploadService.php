<?php

/**
 * Service for handling file uploads and creating debtor records.
 * Resolves tether_instance_id for tenant-scoped upload processing.
 */

namespace App\Services;

use App\Enums\BillingModel;
use App\Jobs\ProcessUploadJob;
use App\Models\BillingAttempt;
use App\Models\EmpAccount;
use App\Models\TetherInstance;
use App\Models\Upload;
use App\Traits\ParsesDebtorData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class FileUploadService
{
    use ParsesDebtorData;

    private const COLUMN_MAP = [
        'iban' => 'iban',
        'iban_number' => 'iban',
        'bank_account' => 'iban',
        'account_number' => 'iban',
        'name' => 'name',
        'full_name' => 'name',
        'fullname' => 'name',
        'customer_name' => 'name',
        'debtor_name' => 'name',
        'client_name' => 'name',
        'account_holder' => 'name',
        'first_name' => 'first_name',
        'firstname' => 'first_name',
        'last_name' => 'last_name',
        'lastname' => 'last_name',
        'surname' => 'last_name',
        'email' => 'email',
        'e_mail' => 'email',
        'mail' => 'email',
        'phone' => 'phone',
        'phone_1' => 'phone',
        'telephone' => 'phone',
        'mobile' => 'mobile',
        'phone_2' => 'phone_2',
        'phone_3' => 'phone_3',
        'phone_4' => 'phone_4',
        'primary_phone' => 'primary_phone',
        'address' => 'address',
        'street' => 'street',
        'street_number' => 'street_number',
        'house_number' => 'street_number',
        'floor' => 'floor',
        'door' => 'door',
        'apartment' => 'apartment',
        'apt' => 'apartment',
        'postcode' => 'postcode',
        'postal_code' => 'postcode',
        'zip' => 'postcode',
        'zip_code' => 'postcode',
        'city' => 'city',
        'province' => 'province',
        'state' => 'province',
        'country' => 'country',
        'amount' => 'amount',
        'sum' => 'amount',
        'total' => 'amount',
        'price' => 'amount',
        'currency' => 'currency',
        'national_id' => 'national_id',
        'id_number' => 'national_id',
        'personal_id' => 'national_id',
        'dni' => 'national_id',
        'nie' => 'national_id',
        'birth_date' => 'birth_date',
        'birthdate' => 'birth_date',
        'date_of_birth' => 'birth_date',
        'dob' => 'birth_date',
        'bank_name' => 'bank_name',
        'bank' => 'bank_name',
        'bank_code' => 'bank_code',
        'bic' => 'bic',
        'swift' => 'bic',
        'swift_code' => 'bic',
        'sepa_type' => 'sepa_type',
        'old_iban' => 'old_iban',
        'external_reference' => 'external_reference',
        'reference' => 'external_reference',
        'ref' => 'external_reference',
        'order_id' => 'external_reference',
        'customer_id' => 'external_reference',
    ];

    public function __construct(
        private SpreadsheetParserService $parser,
        private DebtorImportService $debtorImportService
    ) {}

    /**
     * Process file asynchronously via queue.
     *
     * @return array{upload: Upload, queued: bool}
     */
    public function processAsync(
        UploadedFile $file,
        ?int $userId = null,
        BillingModel $billingModel = BillingModel::Legacy,
        ?int $empAccountId = null,
        bool $applyGlobalLock = false,
        ?int $tetherInstanceId = null
    ): array {
        $parsed = $this->parser->parse($file);
        $storedPath = $this->storeFile($file);
        $upload = $this->createUploadRecord(
            $file,
            $storedPath,
            $parsed['total_rows'],
            $userId,
            $billingModel,
            $empAccountId,
            $applyGlobalLock,
            $tetherInstanceId
        );

        $columnMapping = $this->buildColumnMapping($parsed['headers']);
        $upload->update([
            'column_mapping' => $columnMapping,
            'headers' => $parsed['headers'],
        ]);

        ProcessUploadJob::dispatch($upload, $columnMapping);

        return [
            'upload' => $upload->fresh(),
            'queued' => true,
        ];
    }

    /**
     * Process file synchronously.
     *
     * @return array{upload: Upload, created: int, failed: int, skipped: array, errors: array}
     */
    public function process(
        UploadedFile $file,
        ?int $userId = null,
        BillingModel $billingModel = BillingModel::Legacy,
        ?int $empAccountId = null,
        bool $applyGlobalLock = false,
        ?int $tetherInstanceId = null
    ): array {
        $parsed = $this->parser->parse($file);
        $storedPath = $this->storeFile($file);
        $upload = $this->createUploadRecord(
            $file,
            $storedPath,
            $parsed['total_rows'],
            $userId,
            $billingModel,
            $empAccountId,
            $applyGlobalLock,
            $tetherInstanceId
        );

        $columnMapping = $this->buildColumnMapping($parsed['headers']);
        $upload->update([
            'column_mapping' => $columnMapping,
            'headers' => $parsed['headers'],
        ]);

        $rows = $parsed['rows'];
        $globalLockErrors = [];
        $skippedCount = 0;

        if ($applyGlobalLock) {
            $lockResult = self::filterCrossAccountIbans(
                $rows,
                $upload->tether_instance_id,
                $columnMapping
            );
            $rows = $lockResult['rows'];
            $globalLockErrors = $lockResult['errors'];
            $skippedCount = $lockResult['excluded_count'];
        }

        $result = $this->debtorImportService->importRows(
            $upload,
            $rows,
            $columnMapping
        );

        if (!isset($result['skipped']) || !is_array($result['skipped'])) {
            $result['skipped'] = [
                'total' => 0,
                'skipped_locked' => 0,
            ];
        }

        $result['errors'] = array_merge($globalLockErrors, $result['errors']);
        $result['skipped']['total'] = ($result['skipped']['total'] ?? 0) + $skippedCount;

        $this->finalizeUpload($upload, $result);

        return [
            'upload' => $upload->fresh(),
            'created' => $result['created'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * Filters rows containing IBANs billed on other tether instances.
     * Prevents cross-instance billing for the same IBAN.
     */
    public static function filterCrossAccountIbans(array $rows, ?int $currentTetherInstanceId, array $columnMapping): array
    {
        if ($currentTetherInstanceId === null) {
            return ['rows' => $rows, 'excluded_count' => 0, 'errors' => []];
        }

        $ibanHeader = null;
        foreach ($columnMapping as $header => $field) {
            if ($field === 'iban') {
                $ibanHeader = $header;
                break;
            }
        }

        if (!$ibanHeader) {
            return ['rows' => $rows, 'excluded_count' => 0, 'errors' => []];
        }

        $ibans = [];
        foreach ($rows as $row) {
            if (!empty($row[$ibanHeader])) {
                $ibans[] = $row[$ibanHeader];
            }
        }

        if (empty($ibans)) {
            return ['rows' => $rows, 'excluded_count' => 0, 'errors' => []];
        }

        $lockedIbans = DB::table('debtors')
            ->join('billing_attempts', 'debtors.id', '=', 'billing_attempts.debtor_id')
            ->where('billing_attempts.status', BillingAttempt::STATUS_APPROVED)
            ->where('debtors.tether_instance_id', '!=', $currentTetherInstanceId)
            ->whereIn('debtors.iban', $ibans)
            ->distinct()
            ->pluck('debtors.tether_instance_id', 'debtors.iban')
            ->toArray();

        if (empty($lockedIbans)) {
            return ['rows' => $rows, 'excluded_count' => 0, 'errors' => []];
        }

        $filteredRows = [];
        $errors = [];
        $excludedCount = 0;

        foreach ($rows as $row) {
            $iban = $row[$ibanHeader] ?? null;

            if ($iban && isset($lockedIbans[$iban])) {
                $originalInstance = $lockedIbans[$iban];
                $msg = "Locked to TetherInstance #{$originalInstance}";

                $errors[] = [
                    'row' => $row,
                    'error' => $msg,
                    'iban' => $iban,
                ];
                $excludedCount++;
                continue;
            }

            $filteredRows[] = $row;
        }

        Log::info('Cross-instance IBAN lock applied', [
            'tether_instance_id' => $currentTetherInstanceId,
            'excluded_count' => $excludedCount,
        ]);

        return [
            'rows' => $filteredRows,
            'excluded_count' => $excludedCount,
            'errors' => $errors,
        ];
    }

    private function storeFile(UploadedFile $file): string
    {
        $originalFilename = $file->getClientOriginalName();
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs(
            'uploads',
            $filename,
            [
                'disk'  => 's3',
                'Metadata' => [
                    'original-filename' => $originalFilename,
                ],
            ],
        );

        if ($path === false) {
            Log::error('Failed to store file in S3', [
                'original_filename' => $originalFilename,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);
            throw new \RuntimeException('Failed to store file in S3 storage');
        }

        Log::info('File stored in S3', [
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        return $path;
    }

    /**
     * Create upload record.
     * Resolves emp_account from request or default active account.
     * Resolves tether_instance from request or active EMP instance.
     */
    private function createUploadRecord(
        UploadedFile $file,
        string $storedPath,
        int $totalRows,
        ?int $userId,
        BillingModel $billingModel,
        ?int $empAccountId = null,
        bool $applyGlobalLock = false,
        ?int $tetherInstanceId = null
    ): Upload {
        if ($empAccountId === null) {
            $activeAccount = EmpAccount::getActive();
            $empAccountId = $activeAccount?->id;
        }

        if ($tetherInstanceId === null) {
            $instance = TetherInstance::where('acquirer_type', 'emp')
                ->where('is_active', true)
                ->first();
            $tetherInstanceId = $instance?->id;
        }

        return Upload::create([
            'filename' => basename($storedPath),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'status' => Upload::STATUS_PENDING,
            'billing_model' => $billingModel->value,
            'emp_account_id' => $empAccountId,
            'tether_instance_id' => $tetherInstanceId,
            'total_records' => $totalRows,
            'processed_records' => 0,
            'failed_records' => 0,
            'uploaded_by' => $userId,
            'meta' => [
                'apply_global_lock' => $applyGlobalLock,
            ],
        ]);
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

    private function finalizeUpload(Upload $upload, array $result): void
    {
        $status = $result['failed'] === 0
            ? Upload::STATUS_COMPLETED
            : ($result['created'] > 0 ? Upload::STATUS_COMPLETED : Upload::STATUS_FAILED);

        $currentMeta = $upload->meta ?? [];

        $newMeta = [
            'errors' => array_slice($result['errors'], 0, 100),
            'skipped' => $result['skipped'],
            'skipped_rows' => array_slice($result['skipped_rows'] ?? [], 0, 100),
        ];

        $upload->update([
            'status' => $status,
            'processed_records' => $result['created'],
            'failed_records' => $result['failed'],
            'processing_completed_at' => now(),
            'meta' => array_merge($currentMeta, $newMeta),
        ]);
    }
}
