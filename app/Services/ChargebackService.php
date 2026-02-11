<?php

/**
 * Service for creating and managing chargeback records.
 * Handles both billing_attempts queries and Chargeback table operations.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\Chargeback;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChargebackService
{
    public function getChargebacks(Request $request)
    {
        $chargebacks = BillingAttempt::with([
            'debtor:id,first_name,last_name,email,iban',
            'debtor.latestVopLog:vop_logs.id,vop_logs.debtor_id,vop_logs.bank_name,vop_logs.country',
            'empAccount:id,name,slug'
        ])
        ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($request->has('code')) {
            $chargebacks->where('chargeback_reason_code', $request->input('code'));
        }

        if ($request->has('emp_account_id')) {
            $chargebacks->where('emp_account_id', $request->input('emp_account_id'));
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $chargebacks = $chargebacks->latest()->paginate($perPage);

        return $chargebacks;
    }

    public function getUniqueChargebacksErrorCodes()
    {
        $cacheKey = 'unique_chargeback_error_codes';
        $ttl = config('tether.cache.ttl_long', 300);

        return Cache::remember($cacheKey, $ttl, function () {
            return BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
                ->distinct()
                ->orderBy('chargeback_reason_code', 'asc')
                ->pluck('chargeback_reason_code')
                ->filter()
                ->values()
                ->all();
        });
    }

    public function createFromWebhook(BillingAttempt $billingAttempt, array $webhookData): ?Chargeback
    {
        return $this->createChargeback(
            $billingAttempt,
            Chargeback::SOURCE_WEBHOOK,
            $this->mapWebhookData($webhookData)
        );
    }

    public function createFromApiSync(BillingAttempt $billingAttempt, array $apiResponse): ?Chargeback
    {
        return $this->createChargeback(
            $billingAttempt,
            Chargeback::SOURCE_API_SYNC,
            $this->mapApiResponse($apiResponse)
        );
    }

    /**
     * Get chargeback reasons breakdown for a specific upload
     */
    public function getUploadChargebackReasons(Upload $upload, array $filters = []): array
    {
        // Get upload statistics
        $upload->loadCount([
            'debtors as total_records',
            'debtors as valid_count' => function ($q) {
                $q->where('validation_status', 'valid');
            },
            'billingAttempts as billed_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            },
            'billingAttempts as total_chargebacks' => function ($q) use ($filters) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
                if (!empty($filters['emp_account_id'])) {
                    $q->where('emp_account_id', $filters['emp_account_id']);
                }
                if (!empty($filters['date_from'])) {
                    $q->whereDate('chargebacked_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('chargebacked_at', '<=', $filters['date_to']);
                }
            },
        ]);

        $upload->loadSum([
            'billingAttempts as approved_amount' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            }
        ], 'amount');

        $upload->loadSum([
            'billingAttempts as chargeback_amount' => function ($q) use ($filters) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
                if (!empty($filters['emp_account_id'])) {
                    $q->where('emp_account_id', $filters['emp_account_id']);
                }
                if (!empty($filters['date_from'])) {
                    $q->whereDate('chargebacked_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('chargebacked_at', '<=', $filters['date_to']);
                }
            }
        ], 'amount');

        // Get reason breakdown
        $reasonsQuery = BillingAttempt::select([
            'chargeback_reason_code as code',
            DB::raw('MAX(chargeback_reason_description) as reason'),
            DB::raw('COUNT(*) as cbk_count'),
            DB::raw('SUM(amount) as cbk_amount'),
            DB::raw('MAX(chargebacked_at) as last_occurrence'),
        ])
        ->where('upload_id', $upload->id)
        ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
        ->orderBy('chargeback_reason_code', 'asc');

        if (!empty($filters['emp_account_id'])) {
            $reasonsQuery->where('emp_account_id', $filters['emp_account_id']);
        }

        if (!empty($filters['date_from'])) {
            $reasonsQuery->whereDate('chargebacked_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $reasonsQuery->whereDate('chargebacked_at', '<=', $filters['date_to']);
        }

        $reasons = $reasonsQuery
            ->groupBy('chargeback_reason_code')  // Group ONLY by code
            ->orderByDesc('cbk_count')
            ->get();

        // Calculate percentages
        $totalChargebacks = $upload->total_chargebacks ?? 0;
        $totalRecords = $upload->valid_count ?: ($upload->total_records ?: 1);

        $reasonsWithPercentages = $reasons->map(function ($reason) use ($totalChargebacks, $totalRecords) {
            return [
                'code' => $reason->code,
                'reason' => $reason->reason,
                'cbk_count' => (int) $reason->cbk_count,
                'cbk_amount' => (float) $reason->cbk_amount,
                'cbk_percentage' => $totalChargebacks > 0
                    ? round(($reason->cbk_count / $totalChargebacks) * 100, 2)
                    : 0,
                'total_percentage' => $totalRecords > 0
                    ? round(($reason->cbk_count / $totalRecords) * 100, 2)
                    : 0,
                'last_occurrence' => $reason->last_occurrence,
            ];
        });

        // Summary
        $summary = [
            'total_records' => $upload->total_records ?? 0,
            'valid_count' => $upload->valid_count ?? 0,
            'billed_count' => $upload->billed_count ?? 0,
            'total_chargebacks' => $totalChargebacks,
            'chargeback_amount' => (float) ($upload->chargeback_amount ?? 0),
            'approved_amount' => (float) ($upload->approved_amount ?? 0),
            'cbk_rate' => ($upload->billed_count + $totalChargebacks) > 0
                ? round(($totalChargebacks / ($upload->billed_count + $totalChargebacks)) * 100, 2)
                : 0,
        ];

        return [
            'summary' => $summary,
            'reasons' => $reasonsWithPercentages,
        ];
    }

    /**
     * Get individual billing attempts for a specific chargeback reason code
     */
    public function getUploadChargebackRecordsByCode(Upload $upload, string $code, int $perPage = 100)
    {
        return BillingAttempt::with([
            'debtor:id,first_name,last_name,email,iban',
            'debtor.latestVopLog:vop_logs.id,vop_logs.debtor_id,vop_logs.bank_name,vop_logs.country',
            'empAccount:id,name,slug'
        ])
        ->where('upload_id', $upload->id)
        ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
        ->where('chargeback_reason_code', $code)
        ->latest('chargebacked_at')
        ->paginate($perPage);
    }

    private function createChargeback(BillingAttempt $billingAttempt, string $source, array $data): ?Chargeback
    {
        $originalUniqueId = $billingAttempt->unique_id;

        if (!$originalUniqueId) {
            Log::warning('ChargebackService: Cannot create chargeback without unique_id', [
                'billing_attempt_id' => $billingAttempt->id,
            ]);
            return null;
        }

        try {
            return DB::transaction(function () use ($billingAttempt, $source, $data, $originalUniqueId) {
                $existing = Chargeback::where('original_transaction_unique_id', $originalUniqueId)->first();

                if ($existing) {
                    Log::info('ChargebackService: Chargeback already exists, updating', [
                        'chargeback_id' => $existing->id,
                        'source' => $source,
                    ]);

                    $existing->update(array_filter([
                        'type' => $data['type'] ?? $existing->type,
                        'reason_code' => $data['reason_code'] ?? $existing->reason_code,
                        'reason_description' => $data['reason_description'] ?? $existing->reason_description,
                        'chargeback_amount' => $data['chargeback_amount'] ?? $existing->chargeback_amount,
                        'chargeback_currency' => $data['chargeback_currency'] ?? $existing->chargeback_currency,
                        'post_date' => $data['post_date'] ?? $existing->post_date,
                        'import_date' => $data['import_date'] ?? $existing->import_date,
                        'api_response' => $source === Chargeback::SOURCE_API_SYNC
                            ? $data['api_response']
                            : $existing->api_response,
                    ], fn($v) => $v !== null));

                    return $existing;
                }

                $chargeback = Chargeback::create([
                    'billing_attempt_id' => $billingAttempt->id,
                    'debtor_id' => $billingAttempt->debtor_id,
                    'original_transaction_unique_id' => $originalUniqueId,
                    'type' => $data['type'] ?? null,
                    'reason_code' => $data['reason_code'] ?? null,
                    'reason_description' => $data['reason_description'] ?? null,
                    'chargeback_amount' => $data['chargeback_amount'] ?? null,
                    'chargeback_currency' => $data['chargeback_currency'] ?? 'EUR',
                    'post_date' => $data['post_date'] ?? null,
                    'import_date' => $data['import_date'] ?? null,
                    'source' => $source,
                    'api_response' => $data['api_response'] ?? null,
                ]);

                Log::info('ChargebackService: Chargeback created', [
                    'chargeback_id' => $chargeback->id,
                    'billing_attempt_id' => $billingAttempt->id,
                    'source' => $source,
                    'reason_code' => $chargeback->reason_code,
                ]);

                return $chargeback;
            });
        } catch (\Exception $e) {
            Log::error('ChargebackService: Failed to create chargeback', [
                'billing_attempt_id' => $billingAttempt->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function mapWebhookData(array $data): array
    {
        return [
            'type' => $data['type'] ?? null,
            'reason_code' => $data['reason_code']
                ?? $data['rc_code']
                ?? $data['error_code']
                ?? null,
            'reason_description' => $data['reason']
                ?? $data['rc_description']
                ?? $data['reason_description']
                ?? null,
            'chargeback_amount' => isset($data['amount'])
                ? $this->normalizeAmount($data['amount'])
                : null,
            'chargeback_currency' => $data['currency'] ?? 'EUR',
            'post_date' => $this->parseDate($data['post_date'] ?? null),
            'import_date' => null,
            'api_response' => $data,
        ];
    }

    private function mapApiResponse(array $data): array
    {
        return [
            'type' => $data['type'] ?? null,
            'reason_code' => $data['reason_code'] ?? null,
            'reason_description' => $data['reason_description'] ?? null,
            'chargeback_amount' => isset($data['chargeback_amount'])
                ? (float) $data['chargeback_amount']
                : null,
            'chargeback_currency' => $data['chargeback_currency'] ?? 'EUR',
            'post_date' => $this->parseDate($data['post_date'] ?? null),
            'import_date' => $this->parseDate($data['import_date'] ?? null),
            'api_response' => $data,
        ];
    }

    private function normalizeAmount(mixed $amount): ?float
    {
        if ($amount === null) {
            return null;
        }

        $value = (float) $amount;

        if ($value > 1000) {
            return $value / 100;
        }

        return $value;
    }

    private function parseDate(?string $date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }
}
