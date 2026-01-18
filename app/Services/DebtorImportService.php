<?php

namespace App\Services;

use App\Enums\BillingModel;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Traits\ParsesDebtorData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DebtorImportService
{
    use ParsesDebtorData;

    public function __construct(
        private readonly IbanValidator $ibanValidator,
        private readonly DeduplicationService $deduplicationService
    ) {}

    /**
     * Import rows for a given upload.
     *
     * @param array<int, array<string,mixed>> $rows
     * @param array<string,string> $columnMapping  OriginalHeader => internal_field
     *
     * @return array{
     *   created:int,
     *   failed:int,
     *   skipped:array<string,int>,
     *   skipped_rows:array<int,array<string,mixed>>,
     *   errors:array<int,array{row:int,message:string,data?:array<int,mixed>}>
     * }
     */
    public function importRows(
        Upload $upload,
        array $rows,
        array $columnMapping,
        bool $validateBasicStructure = false,
        ?int $startRow = null
    ): array {
        $created = 0;
        $failed = 0;
        $errors = [];

        $skipped = [
            'total' => 0,
            DeduplicationService::SKIP_BLACKLISTED => 0,
            DeduplicationService::SKIP_BLACKLISTED_NAME => 0,
            DeduplicationService::SKIP_BLACKLISTED_EMAIL => 0,
            DeduplicationService::SKIP_CHARGEBACKED => 0,
            DeduplicationService::SKIP_RECOVERED => 0,
            DeduplicationService::SKIP_RECENTLY_ATTEMPTED => 0,

            DeduplicationService::SKIP_MODEL_CONFLICT => 0,
            DeduplicationService::SKIP_EXISTING_LEGACY_IBAN => 0,
        ];
        $skippedRows = [];

        $uploadModel = $upload->billing_model ?? BillingModel::Legacy->value;

        // 1) Map + normalize all rows first (batch dedupe + batch profile prefetch)
        $debtorDataList = [];
        $ibanHashes = [];

        foreach ($rows as $index => $row) {
            $debtorData = $this->mapRowToDebtor($row, $columnMapping);
            $this->normalizeIban($debtorData);

            $debtorDataList[$index] = $debtorData;

            if (!empty($debtorData['iban_hash'])) {
                $ibanHashes[] = $debtorData['iban_hash'];
            }
        }

        // 2) Batch dedupe: IBAN + name + email (your existing service)
        $dedupeResults = $this->deduplicationService->checkDebtorBatch($debtorDataList, $upload->id);

        // 3) Prefetch profiles by iban_hash to avoid N+1
        $profilesByHash = [];
        $ibanHashes = array_values(array_unique($ibanHashes));

        if (!empty($ibanHashes)) {
            $profilesByHash = DebtorProfile::query()
                ->whereIn('iban_hash', $ibanHashes)
                ->get()
                ->keyBy('iban_hash')
                ->all();
        }


        // Initialize a tracker for skip reasons
        $skipReasonStats = [];

        foreach ($rows as $index => $row) {
            try {
                $debtorData = $debtorDataList[$index];

                $rowAmount = isset($debtorData['amount']) ? (float) $debtorData['amount'] : null;
                $rowModel = $this->resolveRowBillingModel($uploadModel, $rowAmount);

                // 4.1) Dedupe skip
                if (isset($dedupeResults[$index])) {
                    $skipInfo = $dedupeResults[$index];
                    $reason = $skipInfo['reason']; // Capture reason

                    $this->pushSkip($skipped, $skippedRows, $index, $debtorData, $reason, $skipInfo);

                    // Track stat
                    $skipReasonStats[$reason] = ($skipReasonStats[$reason] ?? 0) + 1;
                    continue;
                }

                // 4.2) IBAN-level exclusivity
                $ibanHash = $debtorData['iban_hash'] ?? null;

                if (!empty($ibanHash)) {
                    $profile = $profilesByHash[$ibanHash] ?? null;

                    if (!$profile) {
                        $profile = $this->createDebtorProfileFromRowModel($rowModel, $debtorData);
                        if ($profile) {
                            $profilesByHash[$ibanHash] = $profile;
                        }
                    }

                    if ($profile) {
                        // If profile is legacy and row is flywheel/recovery: keep old logic stable -> skip
                        if ($profile->billing_model === BillingModel::Legacy->value
                            && $rowModel !== BillingModel::Legacy->value
                        ) {
                            $reason = DeduplicationService::SKIP_EXISTING_LEGACY_IBAN; // Capture reason

                            $this->pushSkip($skipped, $skippedRows, $this->rowNumber($index, $startRow), $debtorData, $reason, [
                                'profile_model' => $profile->billing_model,
                                'upload_model' => $uploadModel,
                                'row_model' => $rowModel,
                            ]);

                            // Track stat
                            $skipReasonStats[$reason] = ($skipReasonStats[$reason] ?? 0) + 1;
                            continue;
                        }

                        // Flywheel vs Recovery conflict
                        if ($profile->billing_model !== BillingModel::Legacy->value
                            && $rowModel !== BillingModel::Legacy->value
                            && $profile->billing_model !== $rowModel
                        ) {
                            $reason = DeduplicationService::SKIP_MODEL_CONFLICT; // Capture reason

                            $this->pushSkip($skipped, $skippedRows, $this->rowNumber($index, $startRow), $debtorData, $reason, [
                                'profile_model' => $profile->billing_model,
                                'upload_model' => $uploadModel,
                                'row_model' => $rowModel,
                            ]);

                            // Track stat
                            $skipReasonStats[$reason] = ($skipReasonStats[$reason] ?? 0) + 1;
                            continue;
                        }

                        // If rowModel is legacy but profile is flywheel/recovery -> skip (avoid mixing pipelines)
                        if ($rowModel === BillingModel::Legacy->value
                            && $profile->billing_model !== BillingModel::Legacy->value
                        ) {
                            $reason = DeduplicationService::SKIP_MODEL_CONFLICT; // Capture reason

                            $this->pushSkip($skipped, $skippedRows, $this->rowNumber($index, $startRow), $debtorData, $reason, [
                                'profile_model' => $profile->billing_model,
                                'upload_model' => $uploadModel,
                                'row_model' => $rowModel,
                            ]);

                            // Track stat
                            $skipReasonStats[$reason] = ($skipReasonStats[$reason] ?? 0) + 1;
                            continue;
                        }

                        // Link debtor to profile and use profile model as the source of truth
                        $debtorData['debtor_profile_id'] = $profile->id;
                        $debtorData['billing_model'] = $profile->billing_model;
                    } else {
                        $debtorData['billing_model'] = $rowModel;
                    }
                } else {
                    // no iban_hash => rowModel (can be legacy/flywheel/recovery based on amount)
                    $debtorData['billing_model'] = $rowModel;
                }

                // 4.3) Trace autoswitch (row model differs from upload/controller model)
                if (($debtorData['billing_model'] ?? $uploadModel) !== $uploadModel) {
                    $debtorData['meta'] = array_merge($debtorData['meta'] ?? [], [
                        'upload_billing_model' => $uploadModel,
                        'row_billing_model' => $debtorData['billing_model'],
                        'row_model_autoswitched' => true,
                        'row_amount' => $rowAmount !== null ? round($rowAmount, 2) : null,
                    ]);
                }

                // 4.4) Create Debtor row
                $debtorData['upload_id'] = $upload->id;
                $debtorData['raw_data'] = $row;

                if ($validateBasicStructure) {
                    $this->validateBasicStructure($debtorData);
                }

                $this->enrichCountryFromIban($debtorData);
                $debtorData['validation_status'] = Debtor::VALIDATION_PENDING;

                Debtor::create($debtorData);
                $created++;

            } catch (\Throwable $e) {
                $rowNumber = $this->rowNumber($index, $startRow);

                $failed++;
                if (count($errors) < 100) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                        'data' => array_slice(array_values((array) $rows[$index]), 0, 3),
                    ];
                }

                Log::warning('Debtor import row failed', [
                    'upload_id' => $upload->id,
                    'row' => $rowNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }


        // Log the final skip summary
        if ($skipped > 0) {
            Log::info('Debtor import skipped rows summary', [
                'upload_id' => $upload->id,
                'total_created' => $created,
                'total_skipped' => $skipped,
                'skip_reasons_breakdown' => $skipReasonStats,
            ]);
        }

        // -------------------------

        return [
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped,
            'skipped_rows' => $skippedRows,
            'errors' => $errors,
        ];
    }

    private function rowNumber(int $index, ?int $startRow): int
    {
        return ($startRow ?? 1) + $index + 1;
    }

    /**
     * Model comes from upload/request, but row can override:
     * - if amount fits upload model => keep upload model
     * - else if amount fits other model => autoswitch
     * - else => legacy (out of range)
     */
    private function resolveRowBillingModel(string $uploadModel, ?float $amount): string
    {
        if ($uploadModel === BillingModel::Legacy->value) {
            return BillingModel::Legacy->value;
        }

        return match ($uploadModel) {
            BillingModel::Flywheel->value => BillingModel::isFlywheelAmount($amount)
                ? BillingModel::Flywheel->value
                : (BillingModel::isRecoveryAmount($amount) ? BillingModel::Recovery->value : BillingModel::Legacy->value),

            BillingModel::Recovery->value => BillingModel::isRecoveryAmount($amount)
                ? BillingModel::Recovery->value
                : (BillingModel::isFlywheelAmount($amount) ? BillingModel::Flywheel->value : BillingModel::Legacy->value),

            default => BillingModel::fromAmount($amount)->value,
        };
    }

    /**
     * Creates IBAN-level profile for new IBANs.
     * - billing_model is taken from resolved row model (legacy/flywheel/recovery)
     * - billing_amount is the row amount (we charge file amount)
     */
    private function createDebtorProfileFromRowModel(string $rowModel, array $debtorData): ?DebtorProfile
    {
        $ibanHash = $debtorData['iban_hash'] ?? null;
        if (empty($ibanHash)) {
            return null;
        }

        $amount = isset($debtorData['amount']) ? (float) $debtorData['amount'] : null;

        $defaults = [
            'iban_hash' => $ibanHash,
            'iban_masked' => $this->ibanValidator->mask($debtorData['iban'] ?? ''),
            'billing_model' => $rowModel,
            'is_active' => true,
            'currency' => $debtorData['currency'] ?? 'EUR',
            'billing_amount' => $amount !== null ? round($amount, 2) : null,
        ];

        try {
            return DebtorProfile::create($defaults);
        } catch (QueryException $e) {
            // someone created same iban_hash in parallel
            if (str_contains($e->getMessage(), 'debtor_profiles_iban_hash_unique')
                || str_contains($e->getMessage(), 'debtor_profiles_iban_hash_key')
            ) {
                return DebtorProfile::where('iban_hash', $ibanHash)->first();
            }
            throw $e;
        }
    }

    /**
     * Convert one raw row to internal debtor data using header mapping.
     */
    private function mapRowToDebtor(array $row, array $columnMapping): array
    {
        $data = [
            'status' => Debtor::STATUS_PENDING,
            'currency' => 'EUR',
            'amount' => 0,
        ];

        foreach ($row as $header => $value) {
            if (!isset($columnMapping[$header]) || $value === null) {
                continue;
            }

            $field = $columnMapping[$header];
            $data[$field] = $this->castValue($field, $value);
        }

        $this->splitFullName($data);

        return $data;
    }

    private function normalizeIban(array &$data): void
    {
        if (empty($data['iban'])) {
            $data['iban'] = '';
            $data['iban_hash'] = null;
            $data['iban_valid'] = false;
            return;
        }

        $data['iban'] = $this->ibanValidator->normalize($data['iban']);
        $data['iban_hash'] = $this->ibanValidator->hash($data['iban']);

        $result = $this->ibanValidator->validate($data['iban']);
        $data['iban_valid'] = $result['valid'];

        if ($result['valid']) {
            $data['bank_code'] = $data['bank_code'] ?? $result['bank_id'];
        }
    }

    private function validateBasicStructure(array $data): void
    {
        $errors = [];

        if (empty($data['first_name']) && empty($data['last_name'])) {
            $errors[] = 'Name is required';
        }

        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            $errors[] = 'Valid amount is required';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    private function pushSkip(
        array &$skipped,
        array &$skippedRows,
        int $rowNumber,
        array $debtorData,
        string $reason,
        array $extra = []
    ): void {
        $skipped['total']++;

        if (isset($skipped[$reason])) {
            $skipped[$reason]++;
        } else {
            $skipped[$reason] = 1;
        }

        $skippedRows[] = array_filter([
            'row' => $rowNumber,
            'iban_masked' => $this->ibanValidator->mask($debtorData['iban'] ?? ''),
            'name' => trim(($debtorData['first_name'] ?? '') . ' ' . ($debtorData['last_name'] ?? '')),
            'email' => $debtorData['email'] ?? null,
            'reason' => $reason,

            'profile_model' => $extra['profile_model'] ?? null,
            'upload_model' => $extra['upload_model'] ?? null,
            'row_model' => $extra['row_model'] ?? null,

            'days_ago' => $extra['days_ago'] ?? null,
            'last_status' => $extra['last_status'] ?? null,
        ], static fn ($v) => $v !== null);
    }


    public function finalizeUpload(Upload $upload, array $result): void
    {
        $created = (int) ($result['created'] ?? 0);
        $failed  = (int) ($result['failed'] ?? 0);

        $status = $failed === 0
            ? Upload::STATUS_COMPLETED
            : ($created > 0 ? Upload::STATUS_COMPLETED : Upload::STATUS_FAILED);

        $upload->update([
            'status' => $status,
            'processed_records' => $created,
            'failed_records' => $failed,
            'processing_completed_at' => now(),
            'meta' => array_merge($upload->meta ?? [], [
                'errors' => array_slice($result['errors'] ?? [], 0, 100),
                'skipped' => $result['skipped'] ?? [],
                'skipped_rows' => array_slice($result['skipped_rows'] ?? [], 0, 100),
            ]),
        ]);
    }
}
