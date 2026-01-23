<?php

/**
 * Service for creating and managing chargeback records.
 * Handles both billing_attempts queries and Chargeback table operations.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\Chargeback;
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
                'debtor.latestVopLog:vop_logs.id,vop_logs.debtor_id,vop_logs.bank_name,vop_logs.country'
            ])
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($request->has('code')) {
            $chargebacks->where('chargeback_reason_code', $request->input('code'));
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
                        'arn' => $data['arn'] ?? $existing->arn,
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
                    'arn' => $data['arn'] ?? null,
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
            'arn' => $data['arn'] ?? null,
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
            'arn' => $data['arn'] ?? null,
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
