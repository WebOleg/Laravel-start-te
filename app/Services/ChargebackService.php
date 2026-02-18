<?php

/**
 * Service for creating and managing chargeback records.
 * Handles both billing_attempts queries and Chargeback table operations.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\Chargeback;
use App\Models\Upload;
use App\Traits\HasDatePeriods;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChargebackService
{
    use HasDatePeriods;

    public function getChargebacks(Request $request)
    {
        $chargebacks = BillingAttempt::with([
            'debtor:id,first_name,last_name,email,iban',
            'debtor.latestVopLog:vop_logs.id,vop_logs.debtor_id,vop_logs.bank_name,vop_logs.country',
            'empAccount:id,name,slug'
        ])
        ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($request->filled('code'))
        {
            $chargebacks->where('chargeback_reason_code', $request->input('code'));
        }

        if ($request->filled('emp_account_id'))
        {
            $chargebacks->where('emp_account_id', $request->input('emp_account_id'));
        }

        if ($request->has('period'))
        {
            $period = $request->input('period');
            $dateMode = $request->input('date_mode', 'transaction');
            $startDate = $this->getStartDateFromPeriod($period);
            
            if ($period !== 'all') {
                if ($dateMode === 'chargeback') {
                    $chargebacks->where('chargebacked_at', '>=', $startDate);
                } else {
                    // transaction mode (default)
                    $chargebacks->whereRaw('COALESCE(billing_attempts.emp_created_at, billing_attempts.created_at) >= ?', [$startDate]);
                }
            }
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
        $this->loadUploadCounts($upload);

        $approvedStats = $this->getApprovedStats($upload->id);
        $chargebackStats = $this->getChargebackStats($upload->id, $filters);
        $reasons = $this->getChargebackReasonBreakdown($upload->id, $filters);

        $reasonsWithPercentages = $this->calculateReasonPercentages(
            $reasons,
            $chargebackStats->count ?? 0,
            $upload->total_records ?: 1
        );

        $summary = $this->buildUploadSummary($upload, $approvedStats, $chargebackStats);

        return [
            'summary' => $summary,
            'reasons' => $reasonsWithPercentages,
        ];
    }

    /**
     * Load basic upload counts (total records and valid debtors)
     */
    private function loadUploadCounts(Upload $upload): void
    {
        $upload->loadCount([
            'billingAttempts as total_records' => function ($q) {
                $q->whereIn('status', [
                    BillingAttempt::STATUS_APPROVED,
                    BillingAttempt::STATUS_CHARGEBACKED,
                    BillingAttempt::STATUS_DECLINED,
                    BillingAttempt::STATUS_VOIDED,
                    BillingAttempt::STATUS_ERROR,
                ]);
            },
            'debtors as valid_count' => function ($q) {
                $q->where('validation_status', 'valid');
            },
        ]);
    }

    /**
     * Get approved billing attempts statistics (count and sum)
     */
    private function getApprovedStats(int $uploadId): object
    {
        return BillingAttempt::where('upload_id', $uploadId)
            ->where('status', BillingAttempt::STATUS_APPROVED)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as sum')
            ->first();
    }

    /**
     * Get chargeback statistics with optional filters (count and sum)
     */
    private function getChargebackStats(int $uploadId, array $filters = []): object
    {
        $query = BillingAttempt::where('upload_id', $uploadId)
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        $this->applyChargebackFilters($query, $filters);

        return $query
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as sum')
            ->first();
    }

    /**
     * Get detailed chargeback reason breakdown
     */
    private function getChargebackReasonBreakdown(int $uploadId, array $filters = []): \Illuminate\Support\Collection
    {
        $query = BillingAttempt::select([
            'chargeback_reason_code as code',
            DB::raw('MAX(chargeback_reason_description) as reason'),
            DB::raw('COUNT(*) as cb_count'),
            DB::raw('SUM(amount) as cb_amount'),
            DB::raw('MAX(chargebacked_at) as last_occurrence'),
        ])
        ->where('upload_id', $uploadId)
        ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        $this->applyChargebackFilters($query, $filters);

        return $query
            ->groupBy('chargeback_reason_code')
            ->orderByDesc('cb_count')
            ->get();
    }

    /**
     * Apply common chargeback filters to a query
     */
    private function applyChargebackFilters($query, array $filters): void
    {
        if (!empty($filters['emp_account_id'])) {
            $query->where('emp_account_id', $filters['emp_account_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->whereDate('chargebacked_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('chargebacked_at', '<=', $filters['end_date']);
        }
    }

    /**
     * Calculate percentages for each chargeback reason
     */
    private function calculateReasonPercentages($reasons, int $totalChargebacks, int $totalRecords): array
    {
        return $reasons->map(function ($reason) use ($totalChargebacks, $totalRecords) {
            return [
                'code' => $reason->code,
                'reason' => $reason->reason,
                'cb_count' => (int) $reason->cb_count,
                'cb_amount' => (float) $reason->cb_amount,
                'cb_percentage' => $totalChargebacks > 0
                    ? round(($reason->cb_count / $totalChargebacks) * 100, 2)
                    : 0,
                'total_percentage' => $totalRecords > 0
                    ? round(($reason->cb_count / $totalRecords) * 100, 2)
                    : 0,
                'last_occurrence' => $reason->last_occurrence,
                'total_records' => $totalRecords
            ];
        })->toArray();
    }

    /**
     * Build summary statistics for upload chargebacks
     */
    private function buildUploadSummary(Upload $upload, object $approvedStats, object $chargebackStats): array
    {
        $billedCount = $approvedStats->count ?? 0;
        $totalChargebacks = $chargebackStats->count ?? 0;

        return [
            'total_records' => $upload->total_records ?? 0,
            'valid_count' => $upload->valid_count ?? 0,
            'billed_count' => $billedCount,
            'total_chargebacks' => $totalChargebacks,
            'cb_amount' => (float) ($chargebackStats->sum ?? 0),
            'approved_amount' => (float) ($approvedStats->sum ?? 0),
            'cb_rate' => ($billedCount + $totalChargebacks) > 0
                ? round(($totalChargebacks / ($billedCount + $totalChargebacks)) * 100, 2)
                : 0,
        ];
    }

    /**
     * Get individual billing attempts for a specific chargeback reason code
     */
    public function getUploadChargebackRecordsByCode(Upload $upload, string $code, int $perPage = 100)
    {
        $query = BillingAttempt::with([
            'debtor:id,first_name,last_name,email,iban',
            'debtor.latestVopLog:vop_logs.id,vop_logs.debtor_id,vop_logs.bank_name,vop_logs.country',
            'empAccount:id,name,slug'
        ])
        ->where('upload_id', $upload->id)
        ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($code === null || $code === 'null') {
            $query->whereNull('chargeback_reason_code');
        } else {
            $query->where('chargeback_reason_code', $code);
        }

        return $query
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


    /**
     * Get comprehensive chargeback statistics (optimized — single query)
     */
    public function getChargebackStatistics(Request $request): array
    {
        $period = $request->input('period', 'all');
        $dateMode = $request->input('date_mode', 'transaction');
        $empAccountId = $request->filled('emp_account_id') ? $request->input('emp_account_id') : null;
        $code = $request->filled('code') ? $request->input('code') : null;

        $cacheKey = $this->buildStatsCacheKey($period, $dateMode, $empAccountId, $code);
        $ttl = config('tether.cache.ttl_short', 900);

        return Cache::remember($cacheKey, $ttl, function () use ($period, $dateMode, $empAccountId, $code) {
            $cb = BillingAttempt::STATUS_CHARGEBACKED;
            $ap = BillingAttempt::STATUS_APPROVED;

            $excludedCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

            // Build shared date/emp conditions as raw SQL fragments + bindings
            $conditions = [];
            $bindings = [];

            if ($empAccountId) {
                $conditions[] = 'ba.emp_account_id = ?';
                $bindings[] = $empAccountId;
            }

            if ($period !== 'all') {
                $startDate = $this->getStartDateFromPeriod($period);
                $conditions[] = $dateMode === 'chargeback'
                    ? 'ba.chargebacked_at >= ?'
                    : 'COALESCE(ba.emp_created_at, ba.created_at) >= ?';
                $bindings[] = $startDate;
            }

            $whereClause = count($conditions) ? 'AND ' . implode(' AND ', $conditions) : '';
            $codeFilter = $code ? 'AND ba.chargeback_reason_code = ?' : '';
            $codeBindings = $code ? [$code] : [];

            // Exclusion filter for chargebacked rows
            $excludeFilter = '';
            $excludeBindings = [];
            if (!empty($excludedCodes)) {
                $placeholders = implode(', ', array_fill(0, count($excludedCodes), '?'));
                $excludeFilter = "AND (ba.status != ? OR ba.chargeback_reason_code NOT IN ({$placeholders}))";
                $excludeBindings = array_merge([$cb], $excludedCodes);
            }

            // Single raw query using CTE
            $sql = "
                WITH filtered AS (
                    SELECT 
                        ba.status,
                        ba.amount,
                        ba.emp_account_id,
                        ba.debtor_id,
                        ba.chargeback_reason_code
                    FROM billing_attempts ba
                    WHERE ba.status IN (?, ?)
                      {$whereClause}
                      -- code filter only applies to chargebacked rows
                      AND (ba.status = ? OR (ba.status = ? {$codeFilter}))
                      -- exclude configured reason codes from chargebacked rows
                      {$excludeFilter}
                ),
                stats AS (
                    SELECT
                        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cb_count,
                        COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as cb_amount,
                        COUNT(DISTINCT CASE WHEN status = ? THEN emp_account_id END) as affected_accounts,
                        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved_count,
                        COUNT(DISTINCT CASE WHEN status = ? THEN debtor_id END) as unique_debtors,
                        COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as approved_amount
                    FROM filtered
                ),
                top_reason AS (
                    SELECT chargeback_reason_code as code, COUNT(*) as reason_count
                    FROM filtered
                    WHERE status = ?
                    GROUP BY chargeback_reason_code
                    ORDER BY reason_count DESC
                    LIMIT 1
                )
                SELECT s.*, tr.code as top_reason_code, tr.reason_count as top_reason_count
                FROM stats s
                LEFT JOIN top_reason tr ON true
            ";

            $allBindings = array_merge(
                [$ap, $cb],                         // WHERE status IN (?, ?)
                $bindings,                           // date/emp conditions
                [$ap, $cb],                          // AND (status = ? OR (status = ? ...))
                $codeBindings,                       // code filter inside OR
                $excludeBindings,                    // exclusion filter
                [$cb, $cb, $cb, $ap, $cb, $ap],     // stats CTE CASE WHENs
                [$cb],                               // top_reason CTE WHERE
            );

            $result = DB::selectOne($sql, $allBindings);

            $totalCount = (int) $result->cb_count;
            $totalAmount = (float) $result->cb_amount;
            $approvedCount = (int) $result->approved_count;

            return [
                'total_chargebacks_count' => $totalCount,
                'total_chargeback_amount' => round($totalAmount, 2),
                'chargeback_rate' => ($approvedCount + $totalCount) > 0
                    ? round(($totalCount / ($approvedCount + $totalCount)) * 100, 2)
                    : 0,
                'average_chargeback_amount' => $totalCount > 0
                    ? round($totalAmount / $totalCount, 2)
                    : 0,
                'most_common_reason_code' => $result->top_reason_code ? [
                    'code' => $result->top_reason_code,
                    'count' => (int) $result->top_reason_count,
                ] : null,
                'affected_accounts' => (int) $result->affected_accounts,
                // --- new statistics ---
                'unique_debtors_count' => (int) $result->unique_debtors,
                'total_approved_amount' => round((float) $result->approved_amount, 2),
            ];
        });
    }

    /**
     * Build the cache key for chargeback statistics.
     * Embeds a version number so that bumping the version invalidates all permutations at once.
     */
    private function buildStatsCacheKey(?string $period = 'all', ?string $dateMode = 'transaction', ?string $empAccountId = null, ?string $code = null): string
    {
        $version = Cache::get('chargeback_stats_version', 1);

        return 'chargeback_stats:v' . $version . ':' . md5(json_encode([
            'period'         => $period,
            'date_mode'      => $dateMode,
            'emp_account_id' => $empAccountId,
            'code'           => $code,
        ]));
    }

    /**
     * Clear cached chargeback statistics.
     *
     * Bumps the cache version, which effectively orphans every existing
     * chargeback_stats entry regardless of filter combination (period,
     * date_mode, emp_account_id, code). Orphaned entries expire naturally
     * via their TTL — no enumeration needed.
     */
    public function clearChargebackStatisticsCache(): void
    {
        Cache::increment('chargeback_stats_version');
    }
}
